<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\PageSaveCompleteHandler;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\PageSaveCompleteHandler
 */
class PageSaveCompleteHandlerTest extends MediaWikiUnitTestCase {

	private const int REVISION_ID = 456;
	private const array LINK_SPAM_POLICIES = [ 'test-link-spam-policy' ];

	/** @dataProvider provideEarlyExit */
	public function testOnPageSaveCompleteEarlyExit(
		bool $flagEnabled,
		bool $isNullEdit,
		int|null $revisionId,
		bool $expectGetIdCalled
	): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )
			->method( 'lazyPush' );

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->expects( $expectGetIdCalled ? $this->once() : $this->never() )
			->method( 'getId' )
			->willReturn( $revisionId );

		$editResult = $this->createMock( EditResult::class );
		$editResult->expects( $flagEnabled ? $this->once() : $this->never() )
			->method( 'isNullEdit' )
			->willReturn( $isNullEdit );

		$handler = $this->newHandler( $flagEnabled, [], $jobQueueGroup );
		$this->savePage( $handler, $revisionRecord, $editResult );
	}

	public static function provideEarlyExit(): array {
		return [
			'feature flag disabled' => [
				'flagEnabled' => false,
				'isNullEdit' => false,
				'revisionId' => self::REVISION_ID,
				'expectGetIdCalled' => false,
			],
			'null edit' => [
				'flagEnabled' => true,
				'isNullEdit' => true,
				'revisionId' => self::REVISION_ID,
				'expectGetIdCalled' => false,
			],
			'revision id is null' => [
				'flagEnabled' => true,
				'isNullEdit' => false,
				'revisionId' => null,
				'expectGetIdCalled' => true,
			],
		];
	}

	public function testOnPageSaveCompletePushesJobAfterCommit(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->with( $this->callback( function ( IJobSpecification $spec ): bool {
				$this->assertSame( 'wikimediaAntiAbuseCheckRevision', $spec->getType() );
				$this->assertArrayHasKey( 'revisionId', $spec->getParams() );
				$this->assertSame( self::REVISION_ID, $spec->getParams()['revisionId'] );
				$this->assertFalse(
					$spec->getParams()['onlyExternalLinkPolicies'],
					'this job should not enqueue external link policy checks'
				);

				return true;
			} ) );

		$handler = $this->newHandler( true, self::LINK_SPAM_POLICIES, $jobQueueGroup );
		$this->savePage( $handler, $this->newSavedRevision(), $this->newEditResult() );
	}

	public function testOnLinksUpdateCompletePushesJobForTheSavedRevision(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$pushedSpecs = [];
		$jobQueueGroup->method( 'lazyPush' )
			->willReturnCallback( static function ( IJobSpecification $spec ) use ( &$pushedSpecs ): void {
				$pushedSpecs[] = $spec;
			} );

		$handler = $this->newHandler( true, self::LINK_SPAM_POLICIES, $jobQueueGroup );
		$this->savePage( $handler, $this->newSavedRevision(), $this->newEditResult() );
		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( self::REVISION_ID, [ 'https://example.com' ] ),
			null
		);

		$this->assertCount( 2, $pushedSpecs, 'the edit is queued once for each set of content policies' );
		$this->assertSame(
			[ false, true ],
			array_map(
				static fn ( IJobSpecification $spec ): bool =>
					$spec->getParams()['onlyExternalLinkPolicies'],
				$pushedSpecs
			)
		);
		$this->assertSame(
			[ self::REVISION_ID, self::REVISION_ID ],
			array_map(
				static fn ( IJobSpecification $spec ): int => $spec->getParams()['revisionId'],
				$pushedSpecs
			)
		);
	}

	/** @dataProvider provideLinksUpdateEarlyExit */
	public function testOnLinksUpdateCompleteEarlyExit(
		bool $flagEnabled,
		array $externalLinkPolicies,
		bool $pageWasSavedInThisRequest,
		?int $revisionId,
		array $addedExternalLinks
	): void {
		$externalLinkJobs = 0;
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->method( 'lazyPush' )
			->willReturnCallback( static function ( IJobSpecification $spec ) use ( &$externalLinkJobs ): void {
				if ( $spec->getParams()['onlyExternalLinkPolicies'] ) {
					$externalLinkJobs++;
				}
			} );

		$handler = $this->newHandler( $flagEnabled, $externalLinkPolicies, $jobQueueGroup );
		if ( $pageWasSavedInThisRequest ) {
			$this->savePage( $handler, $this->newSavedRevision(), $this->newEditResult() );
		}

		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( $revisionId, $addedExternalLinks ),
			null
		);

		$this->assertSame( 0, $externalLinkJobs, 'the external link policies were not queued' );
	}

	public static function provideLinksUpdateEarlyExit(): array {
		return [
			'Model checks disabled' => [
				'flagEnabled' => false,
				'externalLinkPolicies' => self::LINK_SPAM_POLICIES,
				'pageWasSavedInThisRequest' => true,
				'revisionId' => self::REVISION_ID,
				'addedExternalLinks' => [ 'https://example.com' ],
			],
			'No policy needs external links' => [
				'flagEnabled' => true,
				'externalLinkPolicies' => [],
				'pageWasSavedInThisRequest' => true,
				'revisionId' => self::REVISION_ID,
				'addedExternalLinks' => [ 'https://example.com' ],
			],
			'Links of a revision this request did not save' => [
				'flagEnabled' => true,
				'externalLinkPolicies' => self::LINK_SPAM_POLICIES,
				'pageWasSavedInThisRequest' => false,
				'revisionId' => self::REVISION_ID,
				'addedExternalLinks' => [ 'https://example.com' ],
			],
			'Links of another page, reparsed because the saved page changed' => [
				'flagEnabled' => true,
				'externalLinkPolicies' => self::LINK_SPAM_POLICIES,
				'pageWasSavedInThisRequest' => true,
				'revisionId' => self::REVISION_ID + 1,
				'addedExternalLinks' => [ 'https://example.com' ],
			],
			'No revision on the links update' => [
				'flagEnabled' => true,
				'externalLinkPolicies' => self::LINK_SPAM_POLICIES,
				'pageWasSavedInThisRequest' => true,
				'revisionId' => null,
				'addedExternalLinks' => [ 'https://example.com' ],
			],
			'Edit added no external link' => [
				'flagEnabled' => true,
				'externalLinkPolicies' => self::LINK_SPAM_POLICIES,
				'pageWasSavedInThisRequest' => true,
				'revisionId' => self::REVISION_ID,
				'addedExternalLinks' => [],
			],
		];
	}

	public function testOnLinksUpdateCompleteQueuesAnEditOnlyOnce(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' );

		$handler = $this->newHandler( true, self::LINK_SPAM_POLICIES, $jobQueueGroup );
		$this->savePage( $handler, $this->newSavedRevision(), $this->newEditResult() );
		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( self::REVISION_ID, [] ),
			null
		);
		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( self::REVISION_ID, [ 'https://example.com' ] ),
			null
		);
	}

	public function testOnLinksUpdateCompleteWhenTheRequestSavedThePageTwice(): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$pushedRevisionIds = [];
		$jobQueueGroup->method( 'lazyPush' )
			->willReturnCallback( static function ( IJobSpecification $spec ) use ( &$pushedRevisionIds ): void {
				if ( $spec->getParams()['onlyExternalLinkPolicies'] ) {
					$pushedRevisionIds[] = $spec->getParams()['revisionId'];
				}
			} );

		$handler = $this->newHandler( true, self::LINK_SPAM_POLICIES, $jobQueueGroup );
		$this->savePage( $handler, $this->newSavedRevision( self::REVISION_ID ), $this->newEditResult() );
		$this->savePage( $handler, $this->newSavedRevision( self::REVISION_ID + 1 ), $this->newEditResult() );
		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( self::REVISION_ID, [ 'https://example.com' ] ),
			null
		);
		$handler->onLinksUpdateComplete(
			$this->newLinksUpdate( self::REVISION_ID + 1, [ 'https://example.com' ] ),
			null
		);

		$this->assertSame( [ self::REVISION_ID, self::REVISION_ID + 1 ], $pushedRevisionIds );
	}

	private function newSavedRevision( int $revisionId = self::REVISION_ID ): RevisionRecord {
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->method( 'getId' )->willReturn( $revisionId );

		return $revisionRecord;
	}

	private function newEditResult(): EditResult {
		$editResult = $this->createMock( EditResult::class );
		$editResult->method( 'isNullEdit' )->willReturn( false );

		return $editResult;
	}

	/**
	 * @param ?int $revisionId
	 * @param string[] $addedExternalLinks
	 */
	private function newLinksUpdate( ?int $revisionId, array $addedExternalLinks ): LinksUpdate {
		$revisionRecord = null;
		if ( $revisionId !== null ) {
			$revisionRecord = $this->createMock( RevisionRecord::class );
			$revisionRecord->method( 'getId' )->willReturn( $revisionId );
		}

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getRevisionRecord' )->willReturn( $revisionRecord );
		$linksUpdate->method( 'getAddedExternalLinks' )->willReturn( $addedExternalLinks );

		return $linksUpdate;
	}

	private function savePage(
		PageSaveCompleteHandler $handler,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		$handler->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			new UserIdentityValue( 1, 'Test user' ),
			'test summary',
			0,
			$revisionRecord,
			$editResult
		);
	}

	/**
	 * @param bool $flagEnabled
	 * @param string[] $externalLinkPolicies
	 * @param JobQueueGroup $jobQueueGroup
	 */
	private function newHandler(
		bool $flagEnabled,
		array $externalLinkPolicies,
		JobQueueGroup $jobQueueGroup
	): PageSaveCompleteHandler {
		$database = $this->createMock( IDatabase::class );
		$database->method( 'onTransactionCommitOrIdle' )
			->willReturnCallback( static function ( callable $callback ): void {
				$callback();
			} );

		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->method( 'getPrimaryDatabase' )
			->willReturn( $database );

		return new PageSaveCompleteHandler(
			new HashConfig( [
				'WikimediaAntiAbuseEnableModelChecks' => $flagEnabled,
				'WikimediaAntiAbuseExternalLinkContentPolicies' => $externalLinkPolicies,
			] ),
			$jobQueueGroup,
			$connectionProvider
		);
	}
}

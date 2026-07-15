<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
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

		$this->invokeHandler( $flagEnabled, $jobQueueGroup, $revisionRecord, $editResult );
	}

	public function testOnPageSaveCompletePushesJobAfterCommit(): void {
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->expects( $this->once() )
			->method( 'getId' )
			->willReturn( 456 );

		$editResult = $this->createMock( EditResult::class );
		$editResult->expects( $this->once() )
			->method( 'isNullEdit' )
			->willReturn( false );

		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->with( $this->callback( function ( IJobSpecification $spec ): bool {
				$this->assertSame( 'wikimediaAntiAbuseCheckRevision', $spec->getType() );
				$this->assertArrayHasKey( 'revisionId', $spec->getParams() );
				$this->assertSame( 456, $spec->getParams()['revisionId'] );

				return true;
			} ) );

		$this->invokeHandler( true, $jobQueueGroup, $revisionRecord, $editResult );
	}

	public static function provideEarlyExit(): array {
		return [
			'feature flag disabled' => [
				'flagEnabled' => false,
				'isNullEdit' => false,
				'revisionId' => 456,
				'expectGetIdCalled' => false,
			],
			'null edit' => [
				'flagEnabled' => true,
				'isNullEdit' => true,
				'revisionId' => 456,
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

	private function invokeHandler(
		bool $flagEnabled,
		JobQueueGroup $jobQueueGroup,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	): void {
		$database = $this->createMock( IDatabase::class );
		$database->method( 'onTransactionCommitOrIdle' )
			->willReturnCallback( static function ( callable $callback ): void {
				$callback();
			} );

		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->method( 'getPrimaryDatabase' )
			->willReturn( $database );

		$handler = new PageSaveCompleteHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnableModelChecks' => $flagEnabled ] ),
			$jobQueueGroup,
			$connectionProvider
		);
		$handler->onPageSaveComplete(
			$this->createMock( WikiPage::class ),
			new UserIdentityValue( 1, 'Test user' ),
			'test summary',
			0,
			$revisionRecord,
			$editResult
		);
	}
}

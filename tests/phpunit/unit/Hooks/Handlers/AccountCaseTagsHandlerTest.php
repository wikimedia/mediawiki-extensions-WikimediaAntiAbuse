<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AccountCaseTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AccountCaseTagsHandler
 */
class AccountCaseTagsHandlerTest extends MediaWikiUnitTestCase {

	private const int REVISION_ID = 123;

	/** @dataProvider provideNoJob */
	public function testDoesNotQueueJob( int|null $revisionId, bool $queueEnabled, array $addedTags ): void {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->never() )
			->method( 'lazyPush' );
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->expects( $this->never() )
			->method( 'getPrimaryDatabase' );
		$handler = new AccountCaseTagsHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => $queueEnabled ] ),
			$jobQueueGroup,
			$connectionProvider
		);

		$handler->onChangeTagsAfterUpdateTags( $addedTags, [], [], null, $revisionId, null, null, null, null );
	}

	public static function provideNoJob(): array {
		return [
			'No revision ID' => [
				'revisionId' => null,
				'queueEnabled' => true,
				'addedTags' => [ ChangeTagsHandler::VANDALISM_TAG ],
			],
			'Queue disabled' => [
				'revisionId' => self::REVISION_ID,
				'queueEnabled' => false,
				'addedTags' => [ ChangeTagsHandler::VANDALISM_TAG ],
			],
			'Only verdict tags added' => [
				'revisionId' => self::REVISION_ID,
				'queueEnabled' => true,
				'addedTags' => [
					ChangeTagsHandler::VANDALISM_FALSE_POSITIVE_TAG,
					ChangeTagsHandler::PERSONAL_INFO_NO_FURTHER_ACTION_TAG,
				],
			],
			'Only an unrelated tag added' => [
				'revisionId' => self::REVISION_ID,
				'queueEnabled' => true,
				'addedTags' => [ 'mw-reverted' ],
			],
		];
	}

	/** @dataProvider provideJob */
	public function testQueuesJobAfterCommit( int|string $revisionId, array $addedTags ): void {
		$pushedSpecs = [];
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->willReturnCallback( static function ( IJobSpecification $spec ) use ( &$pushedSpecs ): void {
				$pushedSpecs[] = $spec;
			} );
		$onCommit = null;
		$database = $this->createMock( IDatabase::class );
		$database->expects( $this->once() )
			->method( 'onTransactionCommitOrIdle' )
			->willReturnCallback( static function ( callable $callback ) use ( &$onCommit ): void {
				$onCommit = $callback;
			} );
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->expects( $this->once() )
			->method( 'getPrimaryDatabase' )
			->with( false )
			->willReturn( $database );
		$handler = new AccountCaseTagsHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => true ] ),
			$jobQueueGroup,
			$connectionProvider
		);

		$handler->onChangeTagsAfterUpdateTags( $addedTags, [], [], null, $revisionId, null, null, null, null );
		$this->assertSame( [], $pushedSpecs, 'No job is queued before the transaction commits' );

		$onCommit();
		[ $spec ] = $pushedSpecs;
		$this->assertSame( RecountFlaggedEditsJob::TYPE, $spec->getType(), 'The job updates the case of the author' );
		$this->assertSame(
			self::REVISION_ID,
			$spec->getParams()['revisionId'],
			'The job gets the ID of the flagged revision as an integer'
		);
		$this->assertNull( $spec->getReleaseTimestamp(), 'The job is not delayed' );
	}

	public static function provideJob(): array {
		return [
			'Personal info flag added' => [
				'revisionId' => self::REVISION_ID,
				'addedTags' => [ ChangeTagsHandler::PERSONAL_INFO_TAG ],
			],
			'Link spam flag added with an unrelated tag' => [
				'revisionId' => self::REVISION_ID,
				'addedTags' => [ 'mw-reverted', ChangeTagsHandler::LINK_SPAM_TAG ],
			],
			'Two flags added' => [
				'revisionId' => self::REVISION_ID,
				'addedTags' => [ ChangeTagsHandler::VANDALISM_TAG, ChangeTagsHandler::LINK_SPAM_TAG ],
			],
			'Revision ID read from the database as a string' => [
				'revisionId' => (string)self::REVISION_ID,
				'addedTags' => [ ChangeTagsHandler::VANDALISM_TAG ],
			],
		];
	}
}

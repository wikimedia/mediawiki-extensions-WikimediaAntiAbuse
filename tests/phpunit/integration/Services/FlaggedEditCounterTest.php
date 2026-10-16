<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter
 * @group Database
 */
class FlaggedEditCounterTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	private const string NOW = '20261007000000';

	private const string VANDALISM = ChangeTagsHandler::VANDALISM_TAG;
	private const string VANDALISM_FALSE_POSITIVE = ChangeTagsHandler::VANDALISM_FALSE_POSITIVE_TAG;
	private const string VANDALISM_NO_FURTHER_ACTION = ChangeTagsHandler::VANDALISM_NO_FURTHER_ACTION_TAG;
	private const string PERSONAL_INFO = ChangeTagsHandler::PERSONAL_INFO_TAG;

	private const array FLAGS = [ self::PERSONAL_INFO, self::VANDALISM ];

	private const array ALL_RIGHTS = [ 'viewsuppressed', 'deletedhistory' ];

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( self::NOW );
	}

	private function getCounter(): FlaggedEditCounter {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseFlaggedEditCounter' );
	}

	/**
	 * @dataProvider provideGetFlaggedEditCounts
	 * @param string[] $rights The rights of the authority
	 * @param array[] $edits Each edit has the key tags, and optionally the keys daysAgo, revDeleted,
	 *   pageDeleted and pageSuppressed
	 * @param array<string,int> $expectedCounts
	 */
	public function testGetFlaggedEditCounts( array $rights, array $edits, array $expectedCounts ): void {
		$user = $this->getMutableTestUser()->getUser();
		foreach ( $edits as $edit ) {
			$this->makeEdit( $user, $edit );
		}
		ConvertibleTimestamp::setFakeTime( self::NOW );

		$this->assertSame(
			$expectedCounts,
			$this->getCounter()->getFlaggedEditCounts(
				$this->mockRegisteredAuthorityWithPermissions( $rights ),
				$user,
				self::FLAGS
			)
		);
	}

	public static function provideGetFlaggedEditCounts(): array {
		return [
			'Counts each flag' => [
				self::ALL_RIGHTS,
				[
					[ 'tags' => [ self::VANDALISM ] ],
					[ 'tags' => [ self::VANDALISM ] ],
					[ 'tags' => [ self::PERSONAL_INFO ] ],
				],
				[ self::PERSONAL_INFO => 1, self::VANDALISM => 2 ],
			],
			'Does not count false positives' => [
				self::ALL_RIGHTS,
				[
					[ 'tags' => [ self::VANDALISM, self::VANDALISM_FALSE_POSITIVE ] ],
					[ 'tags' => [ self::PERSONAL_INFO ] ],
				],
				[ self::PERSONAL_INFO => 1, self::VANDALISM => 0 ],
			],
			'False positive of one flag does not hide the other flag' => [
				self::ALL_RIGHTS,
				[ [ 'tags' => [ self::VANDALISM, self::VANDALISM_FALSE_POSITIVE, self::PERSONAL_INFO ] ] ],
				[ self::PERSONAL_INFO => 1, self::VANDALISM => 0 ],
			],
			'Counts edits with no further action' => [
				self::ALL_RIGHTS,
				[ [ 'tags' => [ self::VANDALISM, self::VANDALISM_NO_FURTHER_ACTION ] ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Does not count edits older than the maximum age' => [
				self::ALL_RIGHTS,
				[
					[ 'tags' => [ self::VANDALISM ], 'daysAgo' => FlaggedEditCounter::MAX_AGE_DAYS + 1 ],
					[ 'tags' => [ self::VANDALISM ], 'daysAgo' => FlaggedEditCounter::MAX_AGE_DAYS - 1 ],
				],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Counts edits on deleted pages' => [
				self::ALL_RIGHTS,
				[ [ 'tags' => [ self::VANDALISM ], 'pageDeleted' => true ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Does not count edits on deleted pages without deletedhistory' => [
				[],
				[ [ 'tags' => [ self::VANDALISM ], 'pageDeleted' => true ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			],
			'Does not count edits with a hidden username without deletedhistory' => [
				[],
				[ [ 'tags' => [ self::VANDALISM ], 'revDeleted' => RevisionRecord::DELETED_USER ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			],
			'Counts edits with a hidden username with deletedhistory' => [
				[ 'deletedhistory' ],
				[ [ 'tags' => [ self::VANDALISM ], 'revDeleted' => RevisionRecord::DELETED_USER ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Does not count edits with a suppressed username without viewsuppressed' => [
				[ 'deletedhistory' ],
				[ [ 'tags' => [ self::VANDALISM ], 'revDeleted' => RevisionRecord::SUPPRESSED_USER ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			],
			'Counts edits with a suppressed username with viewsuppressed' => [
				self::ALL_RIGHTS,
				[ [ 'tags' => [ self::VANDALISM ], 'revDeleted' => RevisionRecord::SUPPRESSED_USER ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Counts edits on deleted pages with a hidden username with deletedhistory' => [
				[ 'deletedhistory' ],
				[ [
					'tags' => [ self::VANDALISM ],
					'revDeleted' => RevisionRecord::DELETED_USER,
					'pageDeleted' => true,
				] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
			'Does not count edits on deleted pages with a suppressed username without viewsuppressed' => [
				[ 'deletedhistory' ],
				[ [
					'tags' => [ self::VANDALISM ],
					'revDeleted' => RevisionRecord::SUPPRESSED_USER,
					'pageDeleted' => true,
				] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			],
			'Does not count edits on suppressed deleted pages without viewsuppressed' => [
				[ 'deletedhistory' ],
				[ [ 'tags' => [ self::VANDALISM ], 'pageSuppressed' => true ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			],
			'Counts edits on suppressed deleted pages with viewsuppressed' => [
				self::ALL_RIGHTS,
				[ [ 'tags' => [ self::VANDALISM ], 'pageSuppressed' => true ] ],
				[ self::PERSONAL_INFO => 0, self::VANDALISM => 1 ],
			],
		];
	}

	public function testGetFlaggedEditCountsForUserWithNoActor(): void {
		$this->assertSame(
			[ self::PERSONAL_INFO => 0, self::VANDALISM => 0 ],
			$this->getCounter()->getFlaggedEditCounts(
				$this->mockRegisteredAuthorityWithPermissions( self::ALL_RIGHTS ),
				UserIdentityValue::newRegistered( 123456, 'NoActorUser' ),
				self::FLAGS
			)
		);
	}

	private function makeEdit( User $user, array $edit ): void {
		ConvertibleTimestamp::setFakeTime(
			ConvertibleTimestamp::convert( TS_UNIX, self::NOW ) - ( $edit['daysAgo'] ?? 0 ) * 86400
		);
		$page = $this->getNonexistingTestPage();
		$status = $this->editPage( $page, 'test content', '', NS_MAIN, $user );
		$this->assertStatusGood( $status );
		$revId = $status->getNewRevision()->getId();
		$this->getServiceContainer()->getChangeTagsStore()->addTags( $edit['tags'], null, $revId );

		if ( isset( $edit['revDeleted'] ) ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( 'revision' )
				->set( [ 'rev_deleted' => $edit['revDeleted'] ] )
				->where( [ 'rev_id' => $revId ] )
				->caller( __METHOD__ )
				->execute();
		}
		if ( $edit['pageDeleted'] ?? false ) {
			$this->deletePage( $page );
		}
		if ( $edit['pageSuppressed'] ?? false ) {
			$this->assertStatusGood(
				$this->getServiceContainer()->getDeletePageFactory()
					->newDeletePage( $page, $this->getTestSysop()->getUser() )
					->setSuppress( true )
					->deleteUnsafe( 'test' )
			);
		}
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Jobs;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob
 * @group Database
 */
class RecountFlaggedEditsJobTest extends MediaWikiIntegrationTestCase {

	use TempUserTestTrait;

	private const string PERSONAL_INFO = ChangeTagsHandler::PERSONAL_INFO_TAG;
	private const string VANDALISM = ChangeTagsHandler::VANDALISM_TAG;
	private const string LINK_SPAM = ChangeTagsHandler::LINK_SPAM_TAG;
	private const array ALL_FLAGS = [ self::PERSONAL_INFO, self::VANDALISM, self::LINK_SPAM ];

	private const string FIRST_TIME = '20260101000000';
	private const string NEXT_DAY_TIME = '20260102000000';
	private const string BEFORE_DECISION_TIME = '20260131000000';
	private const string DECISION_TIME = '20260201000000';
	private const string LATER_TIME = '20260301000000';

	private ChangeTagsStore $changeTagsStore;
	private AbuseReviewAccountCaseLookup $caseLookup;
	private AbuseReviewAccountCaseManager $caseManager;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => true,
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
			'WikimediaAntiAbuseEnableVandalismTag' => true,
			'WikimediaAntiAbuseEnableLinkSpamTag' => true,
		] );
		$services = $this->getServiceContainer();
		$this->changeTagsStore = $services->getChangeTagsStore();
		$this->caseLookup = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseLookup' );
		$this->caseManager = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseManager' );
		ConvertibleTimestamp::setFakeTime( self::FIRST_TIME );
	}

	public function testOpensCaseOnThirdFlagWithCountsOfEnabledFlags(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableLinkSpamTag', false );
		// The override resets the services. Use the new store, so that the job sees the IDs of new tags.
		$this->changeTagsStore = $this->getServiceContainer()->getChangeTagsStore();
		$user = $this->getTestUser()->getUser();
		$this->makeEdit( $user, [ self::VANDALISM, self::LINK_SPAM ] );
		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );
		$this->assertNull( $this->fetchCase( $user ), 'Two flags of enabled types do not open a case' );

		$this->makeEdit( $user, [ self::PERSONAL_INFO ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertEquals(
			[
				'flagged_edit_count:mw-private-personal-info' => 1,
				'flagged_edit_count:mw-private-vandalism' => 2,
				'count_start_timestamp' => 20251202000000,
			],
			$this->fetchCase( $user )['properties'] ?? null,
			'The third flag opens a case with the count of each enabled flag type'
		);
	}

	public function testUpdatesOpenCaseOnlyWhenCountsChange(): void {
		$user = $this->getTestUser()->getUser();
		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->makeEdit( $user, [ self::VANDALISM ] );
		$thirdEdit = $this->makeEdit( $user, [ self::PERSONAL_INFO ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		ConvertibleTimestamp::setFakeTime( self::NEXT_DAY_TIME );
		$this->getServiceContainer()->getJobQueueGroup()->push(
			RecountFlaggedEditsJob::newSpec( $thirdEdit->getId() )
		);
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );
		$this->assertSame(
			self::FIRST_TIME,
			$this->fetchCase( $user )['updatedTimestamp'] ?? null,
			'A job that finds the same counts does not update the open case'
		);

		$this->makeEdit( $user, [ self::LINK_SPAM ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertEquals(
			[
				'state' => AbuseReviewCaseState::OPEN->value,
				'updatedTimestamp' => self::NEXT_DAY_TIME,
				'properties' => [
					'flagged_edit_count:mw-private-personal-info' => 1,
					'flagged_edit_count:mw-private-vandalism' => 2,
					'flagged_edit_count:mw-private-link-spam' => 1,
					'count_start_timestamp' => 20251203000000,
				],
			],
			$this->fetchCase( $user ),
			'A new flag that changes a count updates the open case'
		);
	}

	public function testCountsOnlyFlagsAfterTheLastDecision(): void {
		$user = $this->getTestUser()->getUser();
		ConvertibleTimestamp::setFakeTime( self::BEFORE_DECISION_TIME );
		$oldEdit = $this->makeEdit( $user, [] );
		$this->caseManager->openCase( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS, [] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [
				'arc_state' => AbuseReviewCaseState::HANDLED->value,
				'arc_updated_timestamp' => $this->getDb()->timestamp( self::DECISION_TIME ),
			] )
			->where( [ 'arc_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();

		ConvertibleTimestamp::setFakeTime( self::LATER_TIME );
		$this->changeTagsStore->addTags( self::ALL_FLAGS, null, $oldEdit->getId() );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );
		$this->assertSame(
			self::DECISION_TIME,
			$this->fetchCase( $user )['updatedTimestamp'] ?? null,
			'Flags on edits made before the decision do not change the handled case'
		);

		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->makeEdit( $user, [ self::LINK_SPAM ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );
		$this->assertEquals(
			[
				'state' => AbuseReviewCaseState::OPEN->value,
				'updatedTimestamp' => self::LATER_TIME,
				'properties' => [
					'flagged_edit_count:mw-private-personal-info' => 0,
					'flagged_edit_count:mw-private-vandalism' => 2,
					'flagged_edit_count:mw-private-link-spam' => 1,
					'count_start_timestamp' => (int)self::DECISION_TIME,
				],
			],
			$this->fetchCase( $user ),
			'Three flags after the decision open the case again with only their counts'
		);

		$this->makeEdit( $user, [ self::PERSONAL_INFO ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertEquals(
			[
				'flagged_edit_count:mw-private-personal-info' => 1,
				'flagged_edit_count:mw-private-vandalism' => 2,
				'flagged_edit_count:mw-private-link-spam' => 1,
				'count_start_timestamp' => (int)self::DECISION_TIME,
			],
			$this->fetchCase( $user )['properties'] ?? null,
			'The open case counts only the flags on edits made at or after its last decision'
		);
	}

	public function testCountsOnlyFlagsOnEditsOfTheLast30Days(): void {
		$user = $this->getTestUser()->getUser();
		$this->makeEdit( $user, self::ALL_FLAGS );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		ConvertibleTimestamp::setFakeTime( self::LATER_TIME );
		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );
		$this->assertSame(
			self::FIRST_TIME,
			$this->fetchCase( $user )['updatedTimestamp'] ?? null,
			'One new flag does not update the open case when its other flags are on edits older than 30 days'
		);

		$this->makeEdit( $user, [ self::VANDALISM ] );
		$this->makeEdit( $user, [ self::LINK_SPAM ] );
		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertEquals(
			[
				'state' => AbuseReviewCaseState::OPEN->value,
				'updatedTimestamp' => self::LATER_TIME,
				'properties' => [
					'flagged_edit_count:mw-private-personal-info' => 0,
					'flagged_edit_count:mw-private-vandalism' => 2,
					'flagged_edit_count:mw-private-link-spam' => 1,
					'count_start_timestamp' => 20260130000000,
				],
			],
			$this->fetchCase( $user ),
			'Three flags on edits of the last 30 days update the open case with only their counts'
		);
	}

	public function testCountsFlagsOnRevisionOfDeletedPage(): void {
		$user = $this->getTestUser()->getUser();
		$this->deletePage( $this->makeEdit( $user, self::ALL_FLAGS )->getPage() );

		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertNotNull( $this->fetchCase( $user ), 'The flags on a revision of a deleted page open a case' );
	}

	/** @dataProvider provideHiddenUsername */
	public function testCountsEditsWhoseUsernameAdminsCanSee( int $revDeleted, bool $expectCase ): void {
		$user = $this->getTestUser()->getUser();
		$revisionId = $this->makeEdit( $user, self::ALL_FLAGS )->getId();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'revision' )
			->set( [ 'rev_deleted' => $revDeleted ] )
			->where( [ 'rev_id' => $revisionId ] )
			->caller( __METHOD__ )
			->execute();

		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertSame(
			$expectCase,
			$this->fetchCase( $user ) !== null,
			'The flags of an edit with a hidden username count only if admins can see the username'
		);
	}

	public static function provideHiddenUsername(): array {
		return [
			'Deleted username' => [ 'revDeleted' => RevisionRecord::DELETED_USER, 'expectCase' => true ],
			'Suppressed username' => [ 'revDeleted' => RevisionRecord::SUPPRESSED_USER, 'expectCase' => false ],
		];
	}

	public function testOpensCaseForTemporaryAccount(): void {
		$this->enableAutoCreateTempUser();
		$temporaryAccount = User::createNew( '~2026-1' );
		$this->makeEdit( $temporaryAccount, self::ALL_FLAGS );

		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertNotNull( $this->fetchCase( $temporaryAccount ), 'A temporary account gets a case' );
	}

	public function testKeepsHandledCaseOfBlockedAccount(): void {
		$user = $this->getTestUser()->getUser();
		$this->caseManager->openCase( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS, [] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_state' => AbuseReviewCaseState::HANDLED->value ] )
			->where( [ 'arc_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->makeEdit( $user, self::ALL_FLAGS );
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'expiry' => 'infinity',
		] );
		$handledCase = $this->fetchCase( $user );

		$this->runJobs( runOptions: [ 'type' => RecountFlaggedEditsJob::TYPE ] );

		$this->assertSame(
			$handledCase,
			$this->fetchCase( $user ),
			'New flags do not change the handled case of an indefinitely blocked account'
		);
	}

	private function makeEdit( User $author, array $flags ): RevisionRecord {
		$status = $this->editPage( $this->getNonexistingTestPage(), 'test content', performer: $author );
		$this->assertStatusGood( $status );
		$revision = $status->getNewRevision();
		$this->changeTagsStore->addTags( $flags, null, $revision->getId() );

		return $revision;
	}

	/** @return array|null The state, last update time and properties of the case of the user */
	private function fetchCase( User $user ): array|null {
		$row = $this->newSelectQueryBuilder()
			->select( [ 'arc_id', 'arc_state', 'arc_updated_timestamp' ] )
			->from( 'abuse_review_case' )
			->where( [ 'arc_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}

		return [
			'state' => (int)$row->arc_state,
			'updatedTimestamp' => ConvertibleTimestamp::convert( TimestampFormat::MW, $row->arc_updated_timestamp ),
			'properties' => $this->caseLookup->getCaseProperties( (int)$row->arc_id ),
		];
	}
}

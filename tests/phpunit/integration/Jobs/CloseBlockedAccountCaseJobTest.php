<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Jobs;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob
 * @group Database
 */
class CloseBlockedAccountCaseJobTest extends MediaWikiIntegrationTestCase {

	private const AbuseReviewAccountQueue QUEUE = AbuseReviewAccountQueue::FLAGGED_ACCOUNTS;

	private AbuseReviewAccountCaseManager $caseManager;
	private User $user;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableFlaggedAccountsQueue', true );
		$this->caseManager = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseManager' );
		$this->user = $this->getTestUser()->getUser();
	}

	public function testClosesOpenCaseOnIndefiniteBlock(): void {
		$this->caseManager->openCase( $this->user, self::QUEUE, [] );

		$this->placeIndefiniteBlock();
		$this->getServiceContainer()->getJobQueueGroup()
			->push( CloseBlockedAccountCaseJob::newSpec( $this->user->getId(), false ) );
		$this->runJobs( runOptions: [ 'type' => CloseBlockedAccountCaseJob::TYPE ] );

		$this->newSelectQueryBuilder()
			->select( 'arc_state' )
			->from( 'abuse_review_case' )
			->where( [ 'arc_actor' => $this->user->getActorId() ] )
			->caller( __METHOD__ )
			->assertFieldValue( AbuseReviewCaseState::HANDLED->value, 'The block marks the case handled' );
	}

	public function testKeepsCaseOpenAfterUnblock(): void {
		$this->caseManager->openCase( $this->user, self::QUEUE, [] );
		$this->placeIndefiniteBlock();
		$unblockStatus = $this->getServiceContainer()->getUnblockUserFactory()
			->newUnblockUser( $this->user, $this->getTestSysop()->getAuthority(), 'Test unblock' )
			->unblock();
		$this->assertStatusGood( $unblockStatus );
		$this->getServiceContainer()->getJobQueueGroup()
			->push( CloseBlockedAccountCaseJob::newSpec( $this->user->getId(), false ) );

		$this->runJobs( runOptions: [ 'type' => CloseBlockedAccountCaseJob::TYPE ] );

		$this->newSelectQueryBuilder()
			->select( 'arc_state' )
			->from( 'abuse_review_case' )
			->where( [ 'arc_actor' => $this->user->getActorId() ] )
			->caller( __METHOD__ )
			->assertFieldValue(
				AbuseReviewCaseState::OPEN->value,
				'The case stays open when the block is removed before the job runs'
			);
	}

	private function placeIndefiniteBlock(): void {
		$blockStatus = $this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser( $this->user, $this->getTestSysop()->getAuthority(), 'infinite' )
			->placeBlock();
		$this->assertStatusGood( $blockStatus );
	}
}

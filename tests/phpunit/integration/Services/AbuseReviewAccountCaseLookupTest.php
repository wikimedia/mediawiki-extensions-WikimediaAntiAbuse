<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState
 * @group Database
 */
class AbuseReviewAccountCaseLookupTest extends MediaWikiIntegrationTestCase {

	private const AbuseReviewAccountQueue QUEUE = AbuseReviewAccountQueue::FLAGGED_ACCOUNTS;
	private const int OTHER_QUEUE_ID = 2;
	private const string NOW = '20261103120000';

	private AbuseReviewAccountCaseLookup $caseLookup;
	private AbuseReviewAccountCaseManager $caseManager;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->caseLookup = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseLookup' );
		$this->caseManager = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseManager' );
	}

	public function testGetCaseForUserWithoutActor(): void {
		$this->assertNull(
			$this->caseLookup->getCase( UserIdentityValue::newRegistered( 123456, 'NoActorUser' ), self::QUEUE ),
			'A user who has no actor has no case'
		);
	}

	public function testGetCaseWhenUserHasNoCaseInQueue(): void {
		$user = $this->getTestUser()->getUser();
		$this->caseManager->openCase( $user, self::QUEUE, [] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_queue_id' => self::OTHER_QUEUE_ID ] )
			->where( [ 'arc_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->caseManager->openCase( $this->getTestSysop()->getUser(), self::QUEUE, [] );

		$this->assertNull(
			$this->caseLookup->getCase( $user, self::QUEUE ),
			'The case of the user in another queue and the case of another user in the queue are not returned'
		);
	}

	/** @dataProvider provideCaseStates */
	public function testGetCase( AbuseReviewCaseState $state ): void {
		ConvertibleTimestamp::setFakeTime( self::NOW );
		$user = $this->getTestUser()->getUser();
		$this->caseManager->openCase( $user, self::QUEUE, [] );
		$caseId = $this->getCaseId();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_state' => $state->value ] )
			->where( [ 'arc_id' => $caseId ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertEquals(
			new AbuseReviewCase( $caseId, $state, self::NOW ),
			$this->caseLookup->getCase( $user, self::QUEUE ),
			'The case has the ID, state and last update time of its row'
		);
	}

	public static function provideCaseStates(): array {
		return [
			'Open case' => [ 'state' => AbuseReviewCaseState::OPEN ],
			'Handled case' => [ 'state' => AbuseReviewCaseState::HANDLED ],
		];
	}

	public function testGetCaseProperties(): void {
		$this->caseManager->openCase(
			$this->getTestUser()->getUser(),
			self::QUEUE,
			[ 'flagged_edit_count' => 3, 'edit_count' => 0, 'last_decision_timestamp' => (int)self::NOW ]
		);

		$properties = $this->caseLookup->getCaseProperties( $this->getCaseId() );

		$this->assertArrayEquals(
			[ 'flagged_edit_count' => 3, 'edit_count' => 0, 'last_decision_timestamp' => (int)self::NOW ],
			$properties,
			named: true,
			message: 'The lookup returns the value of each property of the case, keyed by property name'
		);
		$this->assertContainsOnly( 'int', $properties, message: 'Each property value is an integer' );
	}

	private function getCaseId(): int {
		return (int)$this->newSelectQueryBuilder()
			->select( 'arc_id' )
			->from( 'abuse_review_case' )
			->caller( __METHOD__ )
			->fetchField();
	}
}

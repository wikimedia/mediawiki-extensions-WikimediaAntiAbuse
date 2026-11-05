<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseOffset;
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
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseOffset
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
		// Makes the actor IDs differ from the user IDs and the case IDs.
		$services->getActorNormalization()->acquireActorId(
			UserIdentityValue::newExternal( 'imported', 'Offset' ),
			$this->getDb()
		);
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
			new AbuseReviewCase( $caseId, $user->getActorId(), $state, self::NOW ),
			$this->caseLookup->getCase( $user, self::QUEUE ),
			'The case has the ID, actor, state and last update time of its row'
		);
	}

	public static function provideCaseStates(): array {
		return [
			'Open case' => [ 'state' => AbuseReviewCaseState::OPEN ],
			'Handled case' => [ 'state' => AbuseReviewCaseState::HANDLED ],
		];
	}

	/** @dataProvider provideGetCases */
	public function testGetCases(
		AbuseReviewCaseState $state,
		int $limit,
		string|null $offsetFixture,
		array $expectedFixtures
	): void {
		$cases = [
			'tiedFirst' => $this->openCaseAt( '20261102000000' ),
			'tiedSecond' => $this->openCaseAt( '20261102000000' ),
			'oldest' => $this->openCaseAt( '20261101000000' ),
			'handled' => $this->openCaseAt( '20261103000000', AbuseReviewCaseState::HANDLED ),
		];
		$otherQueueCase = $this->openCaseAt( '20261103000000' );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_queue_id' => self::OTHER_QUEUE_ID ] )
			->where( [ 'arc_id' => $otherQueueCase->id ] )
			->caller( __METHOD__ )
			->execute();
		$offset = $offsetFixture === null
			? null
			: new AbuseReviewCaseOffset( $cases[$offsetFixture]->updatedTimestamp, $cases[$offsetFixture]->id );
		$expectedCases = [];
		foreach ( $expectedFixtures as $fixture ) {
			$expectedCases[] = $cases[$fixture];
		}

		$this->assertEquals(
			$expectedCases,
			$this->caseLookup->getCases( self::QUEUE, $state, $limit, $offset ),
			'The lookup returns the cases of the queue in the state, most recently updated first, from the offset'
		);
	}

	public static function provideGetCases(): array {
		return [
			'Handled cases' => [
				'state' => AbuseReviewCaseState::HANDLED,
				'limit' => 10,
				'offsetFixture' => null,
				'expectedFixtures' => [ 'handled' ],
			],
			'Open cases up to the limit' => [
				'state' => AbuseReviewCaseState::OPEN,
				'limit' => 2,
				'offsetFixture' => null,
				'expectedFixtures' => [ 'tiedSecond', 'tiedFirst' ],
			],
			'Open cases from an offset that has the time of another case' => [
				'state' => AbuseReviewCaseState::OPEN,
				'limit' => 10,
				'offsetFixture' => 'tiedFirst',
				'expectedFixtures' => [ 'tiedFirst', 'oldest' ],
			],
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

	public function testGetPropertiesOfCases(): void {
		$firstCaseId = $this->openCaseAt( self::NOW, properties: [ 'flagged_edit_count' => 3, 'edit_count' => 0 ] )->id;
		$secondCaseId = $this->openCaseAt( self::NOW, properties: [ 'flagged_edit_count' => 4 ] )->id;
		$caseIdWithoutProperties = $this->openCaseAt( self::NOW )->id;
		$this->openCaseAt( self::NOW, properties: [ 'flagged_edit_count' => 5 ] );

		$this->assertArrayEquals(
			[
				$firstCaseId => [ 'flagged_edit_count' => 3, 'edit_count' => 0 ],
				$secondCaseId => [ 'flagged_edit_count' => 4 ],
			],
			$this->caseLookup->getPropertiesOfCases( [ $firstCaseId, $secondCaseId, $caseIdWithoutProperties ] ),
			named: true,
			message: 'The lookup returns the properties of each given case that has properties, keyed by case ID'
		);
	}

	private function getCaseId(): int {
		return (int)$this->newSelectQueryBuilder()
			->select( 'arc_id' )
			->from( 'abuse_review_case' )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function openCaseAt(
		string $timestamp,
		AbuseReviewCaseState $state = AbuseReviewCaseState::OPEN,
		array $properties = []
	): AbuseReviewCase {
		ConvertibleTimestamp::setFakeTime( $timestamp );
		$user = $this->getMutableTestUser()->getUser();
		$this->caseManager->openCase( $user, self::QUEUE, $properties );
		$caseId = (int)$this->newSelectQueryBuilder()
			->select( 'arc_id' )
			->from( 'abuse_review_case' )
			->where( [ 'arc_actor' => $user->getActorId() ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_state' => $state->value ] )
			->where( [ 'arc_id' => $caseId ] )
			->caller( __METHOD__ )
			->execute();

		return new AbuseReviewCase( $caseId, $user->getActorId(), $state, $timestamp );
	}
}

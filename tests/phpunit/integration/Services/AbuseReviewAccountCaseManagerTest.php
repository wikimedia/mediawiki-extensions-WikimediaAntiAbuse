<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager
 * @group Database
 */
class AbuseReviewAccountCaseManagerTest extends MediaWikiIntegrationTestCase {

	private const AbuseReviewAccountQueue QUEUE = AbuseReviewAccountQueue::FLAGGED_ACCOUNTS;
	private const int OTHER_QUEUE_ID = 2;
	private const string NOW = '20260101000000';

	private AbuseReviewAccountCaseManager $caseManager;
	private User $user;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->caseManager = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseManager' );
		$this->user = $this->getTestUser()->getUser();
	}

	public function testOpenCaseCreatesOpenCase(): void {
		ConvertibleTimestamp::setFakeTime( self::NOW );

		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3, 'edit_count' => 7 ] );

		$this->assertEquals(
			[
				'arc_actor' => $this->user->getActorId(),
				'arc_queue_id' => self::QUEUE->getId(),
				'arc_state' => AbuseReviewCaseState::OPEN->value,
				'arc_updated_timestamp' => $this->getDb()->timestamp( self::NOW ),
				'arc_handled_by_actor' => 0,
			],
			(array)$this->newSelectQueryBuilder()
				->select( [
					'arc_actor', 'arc_queue_id', 'arc_state', 'arc_updated_timestamp', 'arc_handled_by_actor',
				] )
				->from( 'abuse_review_case' )
				->caller( __METHOD__ )
				->fetchRow(),
			'The case of the user in the queue is open, has no handler and was updated now'
		);
		$this->assertArrayEquals(
			[ 'flagged_edit_count' => 3, 'edit_count' => 7 ],
			$this->getProperties( $this->user, self::QUEUE->getId() ),
			named: true,
			message: 'The case has the given properties'
		);
	}

	public function testOpenCaseLeavesOtherCasesAlone(): void {
		$otherUser = $this->getTestSysop()->getUser();
		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 1 ] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_queue_id' => self::OTHER_QUEUE_ID ] )
			->where( [ 'arc_actor' => $this->user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->caseManager->openCase( $otherUser, self::QUEUE, [ 'flagged_edit_count' => 2 ] );

		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3 ] );

		$this->assertSame(
			[ 'flagged_edit_count' => 1 ],
			$this->getProperties( $this->user, self::OTHER_QUEUE_ID ),
			'The case of the user in another queue keeps its properties'
		);
		$this->assertSame(
			[ 'flagged_edit_count' => 2 ],
			$this->getProperties( $otherUser, self::QUEUE->getId() ),
			'The case of another user in the queue keeps its properties'
		);
		$this->assertSame(
			[ 'flagged_edit_count' => 3 ],
			$this->getProperties( $this->user, self::QUEUE->getId() ),
			'The case of the user in the queue gets the given properties'
		);
	}

	public function testOpenCaseUpdatesTheOpenCaseThatAnotherJobOpened(): void {
		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3 ] );

		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 4 ] );

		$this->assertSame(
			1,
			$this->newSelectQueryBuilder()
				->from( 'abuse_review_case' )
				->caller( __METHOD__ )
				->fetchRowCount(),
			'The user still has one case in the queue'
		);
		$this->assertSame(
			[ 'flagged_edit_count' => 4 ],
			$this->getProperties( $this->user, self::QUEUE->getId() ),
			'The open case gets the new properties'
		);
	}

	public function testOpenCaseKeepsACaseWithADecision(): void {
		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3 ] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [ 'arc_state' => AbuseReviewCaseState::HANDLED->value ] )
			->where( [ 'arc_actor' => $this->user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();

		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 4 ] );

		$this->assertSame(
			1,
			$this->newSelectQueryBuilder()
				->from( 'abuse_review_case' )
				->caller( __METHOD__ )
				->fetchRowCount(),
			'The user still has one case in the queue'
		);
		$this->assertSame(
			AbuseReviewCaseState::HANDLED->value,
			(int)$this->newSelectQueryBuilder()
				->select( 'arc_state' )
				->from( 'abuse_review_case' )
				->caller( __METHOD__ )
				->fetchField(),
			'The case keeps its state'
		);
		$this->assertSame(
			[ 'flagged_edit_count' => 3 ],
			$this->getProperties( $this->user, self::QUEUE->getId() ),
			'The case keeps its properties'
		);
	}

	public function testReopenCaseOpensHandledCaseAgainAndSetsTheGivenProperties(): void {
		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3, 'edit_count' => 7 ] );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [
				'arc_state' => AbuseReviewCaseState::HANDLED->value,
				'arc_handled_by_actor' => $this->getTestSysop()->getUser()->getActorId(),
			] )
			->where( [ 'arc_actor' => $this->user->getActorId() ] )
			->caller( __METHOD__ )
			->execute();
		$case = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseLookup' )
			->getCase( $this->user, self::QUEUE );

		ConvertibleTimestamp::setFakeTime( self::NOW );
		$this->caseManager->reopenCase( $case, [ 'flagged_edit_count' => 4, 'block_count' => 1 ] );

		$this->assertEquals(
			[
				'arc_id' => $case->id,
				'arc_state' => AbuseReviewCaseState::OPEN->value,
				'arc_updated_timestamp' => $this->getDb()->timestamp( self::NOW ),
				'arc_handled_by_actor' => 0,
			],
			(array)$this->newSelectQueryBuilder()
				->select( [ 'arc_id', 'arc_state', 'arc_updated_timestamp', 'arc_handled_by_actor' ] )
				->from( 'abuse_review_case' )
				->caller( __METHOD__ )
				->fetchRow(),
			'The handled case is open again, with no handler and the time of the update'
		);
		$this->assertArrayEquals(
			[ 'flagged_edit_count' => 4, 'edit_count' => 7, 'block_count' => 1 ],
			$this->getProperties( $this->user, self::QUEUE->getId() ),
			named: true,
			message: 'A given property is inserted or replaced, and a property that is not given keeps its value'
		);
	}

	public function testReopenCaseLeavesOtherCasesAlone(): void {
		$otherUser = $this->getTestSysop()->getUser();
		$this->caseManager->openCase( $otherUser, self::QUEUE, [ 'flagged_edit_count' => 2 ] );
		$this->caseManager->openCase( $this->user, self::QUEUE, [ 'flagged_edit_count' => 3 ] );
		$case = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewAccountCaseLookup' )
			->getCase( $this->user, self::QUEUE );

		ConvertibleTimestamp::setFakeTime( self::NOW );
		$this->caseManager->reopenCase( $case, [ 'flagged_edit_count' => 4 ] );

		$this->assertEquals(
			[ $case->id ],
			$this->newSelectQueryBuilder()
				->select( 'arc_id' )
				->from( 'abuse_review_case' )
				->where( [ 'arc_updated_timestamp' => $this->getDb()->timestamp( self::NOW ) ] )
				->caller( __METHOD__ )
				->fetchFieldValues(),
			'Only the given case gets the time of the update'
		);
		$this->assertSame(
			[ 'flagged_edit_count' => 2 ],
			$this->getProperties( $otherUser, self::QUEUE->getId() ),
			'The case of another user keeps its properties'
		);
	}

	/** @return array<string,int> The value of each property, keyed by property name */
	private function getProperties( User $user, int $queueId ): array {
		$rows = $this->newSelectQueryBuilder()
			->select( [ 'arcp_name', 'arcp_value' ] )
			->from( 'abuse_review_case_property' )
			->join( 'abuse_review_case', null, 'arcp_case_id = arc_id' )
			->where( [
				'arc_actor' => $user->getActorId(),
				'arc_queue_id' => $queueId,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$properties = [];
		foreach ( $rows as $row ) {
			$properties[$row->arcp_name] = (int)$row->arcp_value;
		}

		return $properties;
	}
}

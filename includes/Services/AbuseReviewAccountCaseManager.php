<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\RawSQLValue;

/**
 * Writes the cases of the AbuseReview queues.
 */
class AbuseReviewAccountCaseManager {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly ActorNormalization $actorNormalization,
	) {
	}

	/**
	 * Creates the case of the user in the queue with the given properties.
	 * If the case exists, updates the properties of an open case, and keeps a case with a decision as it is.
	 *
	 * @param UserIdentity $user
	 * @param AbuseReviewAccountQueue $queue
	 * @param array<string,int> $properties The value of each property, keyed by property name
	 */
	public function openCase( UserIdentity $user, AbuseReviewAccountQueue $queue, array $properties ): void {
		$actorId = $this->actorNormalization->acquireActorId( $user, $this->dbProvider->getPrimaryDatabase() );

		$dbw = $this->dbProvider->getPrimaryDatabase( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN );
		$dbw->startAtomic( __METHOD__ );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'abuse_review_case' )
			->ignore()
			->row( [
				'arc_actor' => $actorId,
				'arc_queue_id' => $queue->getId(),
				'arc_state' => AbuseReviewCaseState::OPEN->value,
				'arc_handled_by_actor' => 0,
				'arc_updated_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		if ( $dbw->affectedRows() === 0 ) {
			// Another job opened the case since the caller looked for it, or the caller read an old replica.
			// Update the counts of an open case, and keep a case with a decision as it is.
			$openCaseId = $this->getOpenCaseId( $dbw, $actorId, $queue );
			if ( $openCaseId !== null ) {
				$this->upsertProperties( $dbw, $openCaseId, $properties );
			}
		} else {
			$this->upsertProperties( $dbw, $dbw->insertId(), $properties );
		}
		$dbw->endAtomic( __METHOD__ );
	}

	/**
	 * Opens the case again with the given properties, and moves it to the top of the queue. Keeps other properties.
	 *
	 * @param AbuseReviewCase $case
	 * @param array<string,int> $properties The value of each property, keyed by property name
	 */
	public function reopenCase( AbuseReviewCase $case, array $properties ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN );
		$dbw->startAtomic( __METHOD__ );
		$dbw->newUpdateQueryBuilder()
			->update( 'abuse_review_case' )
			->set( [
				'arc_state' => AbuseReviewCaseState::OPEN->value,
				'arc_handled_by_actor' => 0,
				'arc_updated_timestamp' => $dbw->timestamp(),
			] )
			->where( [ 'arc_id' => $case->id ] )
			->caller( __METHOD__ )
			->execute();
		$this->upsertProperties( $dbw, $case->id, $properties );
		$dbw->endAtomic( __METHOD__ );
	}

	/**
	 * Inserts each property of the case, or replaces its value if the property exists.
	 */
	private function upsertProperties( IDatabase $dbw, int $caseId, array $properties ): void {
		if ( !$properties ) {
			return;
		}

		$propertyRows = [];
		foreach ( $properties as $name => $value ) {
			$propertyRows[] = [ 'arcp_case_id' => $caseId, 'arcp_name' => $name, 'arcp_value' => $value ];
		}
		$dbw->newInsertQueryBuilder()
			->insertInto( 'abuse_review_case_property' )
			->rows( $propertyRows )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'arcp_case_id', 'arcp_name' ] )
			->set( [ 'arcp_value' => new RawSQLValue( $dbw->buildExcludedValue( 'arcp_value' ) ) ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Returns the ID of the open case of the actor in the queue, or null if the case has a decision.
	 */
	private function getOpenCaseId( IDatabase $dbw, int $actorId, AbuseReviewAccountQueue $queue ): ?int {
		// A shared lock reads the latest committed row, so the read sees a case that another job just opened.
		$caseId = $dbw->newSelectQueryBuilder()
			->select( 'arc_id' )
			->from( 'abuse_review_case' )
			->where( [
				'arc_actor' => $actorId,
				'arc_queue_id' => $queue->getId(),
				'arc_state' => AbuseReviewCaseState::OPEN->value,
			] )
			->lockInShareMode()
			->caller( __METHOD__ )
			->fetchField();

		return $caseId === false ? null : (int)$caseId;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseOffset;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Reads the cases of the AbuseReview queues.
 */
class AbuseReviewAccountCaseLookup {

	/** The database domain of the abuse_review_case and abuse_review_case_property tables. */
	public const string VIRTUAL_DB_DOMAIN = 'virtual-wikimediaantiabuse';

	private const array CASE_FIELDS = [ 'arc_id', 'arc_actor', 'arc_state', 'arc_updated_timestamp' ];

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly ActorNormalization $actorNormalization,
	) {
	}

	/**
	 * Returns the case of the user in the queue, or null if the user has no case.
	 */
	public function getCase( UserIdentity $user, AbuseReviewAccountQueue $queue ): ?AbuseReviewCase {
		$actorId = $this->actorNormalization->findActorId( $user, $this->dbProvider->getReplicaDatabase() );
		if ( $actorId === null ) {
			return null;
		}

		return $this->fetchCases( $queue, 1, actorId: $actorId )[0] ?? null;
	}

	/**
	 * Returns the cases in the given state of the queue, the most recently updated case first. If an offset is
	 * given, starts at the offset case.
	 *
	 * @return AbuseReviewCase[]
	 */
	public function getCases(
		AbuseReviewAccountQueue $queue,
		AbuseReviewCaseState $state,
		int $limit,
		?AbuseReviewCaseOffset $offset
	): array {
		return $this->fetchCases( $queue, $limit, state: $state, offset: $offset );
	}

	/**
	 * Returns the cases in the queue, the most recently updated case first. If an actor ID or a state is given,
	 * returns only the cases of the actor or in the state. If an offset is given, starts at the offset case.
	 *
	 * @return AbuseReviewCase[]
	 */
	private function fetchCases(
		AbuseReviewAccountQueue $queue,
		int $limit,
		?int $actorId = null,
		?AbuseReviewCaseState $state = null,
		?AbuseReviewCaseOffset $offset = null
	): array {
		$dbr = $this->dbProvider->getReplicaDatabase( self::VIRTUAL_DB_DOMAIN );
		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( self::CASE_FIELDS )
			->from( 'abuse_review_case' )
			->where( [ 'arc_queue_id' => $queue->getId() ] )
			->orderBy( [ 'arc_updated_timestamp', 'arc_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $actorId !== null ) {
			$queryBuilder->andWhere( [ 'arc_actor' => $actorId ] );
		}
		if ( $state !== null ) {
			$queryBuilder->andWhere( [ 'arc_state' => $state->value ] );
		}
		if ( $offset !== null ) {
			$queryBuilder->andWhere( $dbr->buildComparison( '<=', [
				'arc_updated_timestamp' => $dbr->timestamp( $offset->updatedTimestamp ),
				'arc_id' => $offset->id,
			] ) );
		}

		$cases = [];
		foreach ( $queryBuilder->fetchResultSet() as $row ) {
			$cases[] = $this->newCaseFromRow( $row );
		}

		return $cases;
	}

	private function newCaseFromRow( stdClass $row ): AbuseReviewCase {
		return new AbuseReviewCase(
			(int)$row->arc_id,
			(int)$row->arc_actor,
			AbuseReviewCaseState::from( (int)$row->arc_state ),
			ConvertibleTimestamp::convert( TimestampFormat::MW, $row->arc_updated_timestamp )
		);
	}

	/**
	 * Returns the properties of the case, or an empty array if the case has no properties.
	 *
	 * @return array<string,int> The value of each property, keyed by property name
	 */
	public function getCaseProperties( int $caseId ): array {
		return $this->getPropertiesOfCases( [ $caseId ] )[$caseId] ?? [];
	}

	/**
	 * Returns the properties of each case. A case without properties has no entry.
	 *
	 * @param int[] $caseIds
	 * @return array<int,array<string,int>> The value of each property, keyed by case ID and property name
	 */
	public function getPropertiesOfCases( array $caseIds ): array {
		if ( !$caseIds ) {
			return [];
		}

		$result = $this->dbProvider->getReplicaDatabase( self::VIRTUAL_DB_DOMAIN )->newSelectQueryBuilder()
			->select( [ 'arcp_case_id', 'arcp_name', 'arcp_value' ] )
			->from( 'abuse_review_case_property' )
			->where( [ 'arcp_case_id' => $caseIds ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$properties = [];
		foreach ( $result as $row ) {
			$properties[(int)$row->arcp_case_id][$row->arcp_name] = (int)$row->arcp_value;
		}

		return $properties;
	}
}

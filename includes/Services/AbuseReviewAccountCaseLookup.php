<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Reads the cases of the AbuseReview queues.
 */
class AbuseReviewAccountCaseLookup {

	/** The database domain of the abuse_review_case and abuse_review_case_property tables. */
	public const string VIRTUAL_DB_DOMAIN = 'virtual-wikimediaantiabuse';

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
	 * Returns the cases in the queue. If an actor ID is given, returns only the cases of the actor.
	 *
	 * @return AbuseReviewCase[]
	 */
	private function fetchCases( AbuseReviewAccountQueue $queue, int $limit, ?int $actorId = null ): array {
		$queryBuilder = $this->dbProvider->getReplicaDatabase( self::VIRTUAL_DB_DOMAIN )->newSelectQueryBuilder()
			->select( [ 'arc_id', 'arc_state', 'arc_updated_timestamp' ] )
			->from( 'abuse_review_case' )
			->where( [ 'arc_queue_id' => $queue->getId() ] )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $actorId !== null ) {
			$queryBuilder->andWhere( [ 'arc_actor' => $actorId ] );
		}

		$cases = [];
		foreach ( $queryBuilder->fetchResultSet() as $row ) {
			$cases[] = new AbuseReviewCase(
				(int)$row->arc_id,
				AbuseReviewCaseState::from( (int)$row->arc_state ),
				ConvertibleTimestamp::convert( TimestampFormat::MW, $row->arc_updated_timestamp )
			);
		}

		return $cases;
	}

	/**
	 * Returns the properties of the case, or an empty array if the case has no properties.
	 *
	 * @return array<string,int> The value of each property, keyed by property name
	 */
	public function getCaseProperties( int $caseId ): array {
		$result = $this->dbProvider->getReplicaDatabase( self::VIRTUAL_DB_DOMAIN )->newSelectQueryBuilder()
			->select( [ 'arcp_name', 'arcp_value' ] )
			->from( 'abuse_review_case_property' )
			->where( [ 'arcp_case_id' => $caseId ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$properties = [];
		foreach ( $result as $row ) {
			$properties[$row->arcp_name] = (int)$row->arcp_value;
		}

		return $properties;
	}
}

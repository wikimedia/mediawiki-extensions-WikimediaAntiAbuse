<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IConnectionProvider;

class AbuseReviewVerdictPerformerLookup {

	private const int BATCH_SIZE = 1000;

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly AbuseReviewVerdictAttribution $verdictAttribution,
		private readonly IConnectionProvider $dbProvider,
		private readonly AbuseReviewEnabledTagsProvider $abuseReviewEnabledTagsProvider,
	) {
	}

	/**
	 * Looks up who last acted on the given review flag of each revision.
	 *
	 * @param int[] $revisionIds
	 * @param string $tag The review flag tag
	 * @return array<int,UserIdentity> The reviewer, keyed by revision ID. A verdict the revision
	 *   holds gives who recorded it, the flag itself who last sent the revision back. Only the
	 *   tags and reviewers the authority may see are named.
	 */
	public function lookUpPerformers( array $revisionIds, string $tag, Authority $authority ): array {
		$enabledTags = $this->abuseReviewEnabledTagsProvider->getEnabledReviewableTags();
		if ( !$revisionIds || !in_array( $tag, $enabledTags, true ) ) {
			return [];
		}

		$verdictTags = array_values( ChangeTagsHandler::REVIEWABLE_TAGS[$tag] );
		$viewableTags = $this->changeTagsStore->filterViewableTags(
			array_merge( [ $tag ], $verdictTags ),
			$authority
		);

		$tagIds = $this->changeTagsStore->getTagIdsFromNames( $viewableTags );
		if ( !$tagIds ) {
			return [];
		}

		return $this->resolvePerformers(
			$this->attributedActorIds(
				$this->fetchActorIds( $revisionIds, array_flip( $tagIds ) ),
				$tag,
				$verdictTags
			),
			$authority
		);
	}

	private function attributedActorIds( array $actorIds, string $tag, array $verdictTags ): array {
		$attributed = [];
		foreach ( $actorIds as $revisionId => $actorIdsByTag ) {
			$attributedTag = $tag;
			foreach ( $verdictTags as $verdictTag ) {
				if ( array_key_exists( $verdictTag, $actorIdsByTag ) ) {
					$attributedTag = $verdictTag;
					break;
				}
			}

			$actorId = $actorIdsByTag[$attributedTag] ?? null;
			if ( $actorId !== null ) {
				$attributed[$revisionId] = $actorId;
			}
		}

		return $attributed;
	}

	private function fetchActorIds( array $revisionIds, array $tagIdToNames ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$actorIds = [];
		foreach ( array_chunk( $revisionIds, self::BATCH_SIZE ) as $revisionIdBatch ) {
			$tagRows = $dbr->newSelectQueryBuilder()
				->select( [ 'ct_rev_id', 'ct_tag_id', 'ct_params' ] )
				->from( 'change_tag' )
				->where( [
					'ct_rev_id' => $revisionIdBatch,
					'ct_tag_id' => array_keys( $tagIdToNames ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $tagRows as $tagRow ) {
				$revisionId = (int)$tagRow->ct_rev_id;
				$tagName = $tagIdToNames[(int)$tagRow->ct_tag_id];
				$actorIds[$revisionId][$tagName] = $this->verdictAttribution->decodeActorId(
					$tagRow->ct_params,
					$revisionId,
					$tagName
				);
			}
		}

		return $actorIds;
	}

	private function resolvePerformers( array $actorIds, Authority $authority ): array {
		$usersByActorId = $this->fetchUsersByActorId(
			array_unique( array_values( $actorIds ) ),
			$authority
		);

		$performers = [];
		foreach ( $actorIds as $revisionId => $actorId ) {
			$user = $usersByActorId[$actorId] ?? null;
			if ( $user !== null ) {
				$performers[$revisionId] = $user;
			}
		}

		return $performers;
	}

	private function fetchUsersByActorId( array $actorIds, Authority $authority ): array {
		$usersByActorId = [];
		foreach ( array_chunk( $actorIds, self::BATCH_SIZE ) as $actorIdBatch ) {
			$queryBuilder = $this->userIdentityLookup->newSelectQueryBuilder()
				->select( [ 'actor_id', 'actor_name', 'actor_user' ] )
				->where( [ 'actor_id' => $actorIdBatch ] )
				->caller( __METHOD__ );
			if ( !$authority->isAllowed( 'hideuser' ) ) {
				$queryBuilder->hidden( false );
			}

			foreach ( $queryBuilder->fetchResultSet() as $actorRow ) {
				$usersByActorId[(int)$actorRow->actor_id] =
					new UserIdentityValue( (int)$actorRow->actor_user, $actorRow->actor_name );
			}
		}

		return $usersByActorId;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Rdbms\Subquery;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Counts the edits of a user that have abuse review flags.
 */
class FlaggedEditCounter {

	public const int MAX_AGE_DAYS = 90;

	/** Without a limit, the query reads every edit of the user, which is slow for accounts with many edits. */
	private const int REVISION_LIMIT = 500;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly UserIdentityLookup $userIdentityLookup,
	) {
	}

	/**
	 * Counts the edits of the user in the last MAX_AGE_DAYS days that have each flag, but not the false positive
	 * verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity $user
	 * @param string[] $flags
	 * @return array<string,int> The count for each flag
	 */
	public function getFlaggedEditCounts( Authority $authority, UserIdentity $user, array $flags ): array {
		$cutoff = ConvertibleTimestamp::convert(
			TimestampFormat::MW,
			ConvertibleTimestamp::time() - self::MAX_AGE_DAYS * 86400
		);

		return $this->getFlaggedEditCountsSince( $authority, $user, $flags, $cutoff );
	}

	/**
	 * Counts the edits of the user that have each flag, but not the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity $user
	 * @param string[] $flags
	 * @param ?string $since TS_MW timestamp of the oldest edits to count, or null for no time limit
	 * @return array<string,int> The count for each flag
	 */
	public function getFlaggedEditCountsSince(
		Authority $authority,
		UserIdentity $user,
		array $flags,
		?string $since
	): array {
		return array_map(
			static fn ( array $stats ) => $stats['count'],
			$this->getFlaggedEditStats( $authority, $user, $flags, $since )
		);
	}

	/**
	 * Counts the edits of each user that has each flag, but not the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity[] $users
	 * @param string[] $flags
	 * @param ?string $since TS_MW timestamp of the oldest edits to count, or null for no time limit
	 * @return array<int,array<string,int>> The count for each flag, per user
	 */
	public function getFlaggedEditCountsByUsersSince(
		Authority $authority,
		array $users,
		array $flags,
		?string $since
	): array {
		$flaggedEditStatsByUser = $this->getFlaggedEditStatsByUsers( $authority, $users, $flags, $since );
		foreach ( $flaggedEditStatsByUser as $userId => $flaggedEditStats ) {
			$flaggedEditStatsByUser[$userId] = array_map(
				static fn ( array $stats ) => $stats['count'],
				$flaggedEditStats
			);
		}
		return $flaggedEditStatsByUser;
	}

	/**
	 * Counts the edits of the user per abuse review flag along with the timestamp of the latest edit that has
	 * that flag, excluding edits that have the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity $user
	 * @param string[] $flags
	 * @param ?string $since TS_MW timestamp of the oldest edits to count, or null for no time limit
	 * @return array<string,array{count:int,latestTimestamp:?string}> The stats for each flag. The
	 * timestamp is in MediaWiki timestamp format, or null if no recent edit has the flag.
	 */
	public function getFlaggedEditStats(
		Authority $authority,
		UserIdentity $user,
		array $flags,
		?string $since
	): array {
		$stats = $this->getFlaggedEditStatsByUsers( $authority, [ $user ], $flags, $since );

		if ( count( $stats ) === 0 ) {
			return array_fill_keys( $flags, [ 'count' => 0, 'latestTimestamp' => null ] );
		}

		return $stats[$user->getId()];
	}

	/**
	 * Counts the edits of each user per abuse review flag along with the timestamp of the latest edit that has
	 * that flag, excluding edits that have the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity[] $users
	 * @param string[] $flags
	 * @param ?string $since TS_MW timestamp of the oldest edits to count, or null for no time limit
	 * @return array<int,array<string,array{count:int,latestTimestamp:?string}>> The stats for each flag,
	 * grouped by user. The timestamp is in MediaWiki timestamp format, or null if no recent edit has the flag.
	 */
	public function getFlaggedEditStatsByUsers(
		Authority $authority,
		array $users,
		array $flags,
		?string $since
	): array {
		// Get actor IDs first so we can build the array to be returned
		$dbr = $this->dbProvider->getReplicaDatabase();
		$actorIds = $this->getActorIds( $users );

		if ( count( $actorIds ) === 0 ) {
			return [];
		}

		$stats = array_fill_keys( $flags, [ 'count' => 0, 'latestTimestamp' => null ] );
		$userIds = array_flip( $actorIds );
		$statsByUser = array_fill_keys( $userIds, $stats );

		$falsePositiveTags = [];
		foreach ( $flags as $flag ) {
			$falsePositiveTags[$flag] = ChangeTagsHandler::REVIEWABLE_TAGS[$flag]['falsePositive'];
		}

		$tagIds = $this->changeTagsStore->getTagIdsFromNames(
			array_merge( $flags, array_values( $falsePositiveTags ) )
		);
		if ( !array_intersect_key( $tagIds, $stats ) ) {
			return $statsByUser;
		}

		$tables = [ 'revision' => [ 'rev_id', 'rev_actor', 'rev_timestamp', 'rev_deleted' ] ];
		if ( $authority->isAllowed( 'deletedhistory' ) ) {
			$tables['archive'] = [ 'ar_rev_id', 'ar_actor', 'ar_timestamp', 'ar_deleted' ];
		}

		$tagNamesById = array_flip( $tagIds );
		$revisions = [];
		foreach ( $tables as $table => $fields ) {
			$rows = $this->getTaggedRevisions( $dbr, $authority, $table, $fields, $actorIds, $tagIds, $since );
			foreach ( $rows as $row ) {
				$revisions[$userIds[$row->rev_actor]][$row->rev_id]['timestamp'] = ConvertibleTimestamp::convert(
					TimestampFormat::MW,
					$row->rev_timestamp
				);
				$revisions[$userIds[$row->rev_actor]][$row->rev_id]['tags'][] = $tagNamesById[$row->ct_tag_id];
			}
		}

		// A false positive verdict removes the flag, but the model can add the flag again later.
		foreach ( $revisions as $userId => $userStats ) {
			foreach ( $userStats as [ 'timestamp' => $timestamp, 'tags' => $tags ] ) {
				foreach ( $flags as $flag ) {
					if ( in_array( $flag, $tags, true ) && !in_array( $falsePositiveTags[$flag], $tags, true ) ) {
						$statsByUser[$userId][$flag]['count']++;
						$statsByUser[$userId][$flag]['latestTimestamp'] = max(
							$statsByUser[$userId][$flag]['latestTimestamp'] ?? '',
							$timestamp
						);
					}
				}
			}
		}
		return $statsByUser;
	}

	/**
	 * Get the actor IDs of the given users.
	 *
	 * @param UserIdentity[] $users
	 * @return array<int,int> An array of actor IDs, where the key is the corresponding user ID
	 */
	private function getActorIds(
		array $users
	): array {
		if ( count( $users ) === 0 ) {
			return [];
		}

		$userIds = [];
		foreach ( $users as $user ) {
			$userIds[] = $user->getId();
		}

		$result = [];
		foreach ( array_chunk( $userIds, 500 ) as $userIdsBatch ) {
			$queryBuilder = $this->userIdentityLookup->newSelectQueryBuilder()
				->select( [ 'actor_id', 'actor_user' ] )
				->whereUserIds( $userIdsBatch )
				->caller( __METHOD__ );

			foreach ( $queryBuilder->fetchResultSet() as $row ) {
				$result[$row->actor_user] = $row->actor_id;
			}
		}

		return $result;
	}

	/**
	 * @param IReadableDatabase $dbr
	 * @param Authority $authority
	 * @param string $table
	 * @param string[] $fields The revision ID, actor, timestamp and deleted fields of $table
	 * @param int[] $actorIds
	 * @param int[] $tagIds
	 * @param ?string $since
	 * @return IResultWrapper Rows with the rev_id, rev_timestamp, rev_actor and ct_tag_id fields
	 */
	private function getTaggedRevisions(
		IReadableDatabase $dbr,
		Authority $authority,
		string $table,
		array $fields,
		array $actorIds,
		array $tagIds,
		?string $since
	): IResultWrapper {
		[ $revIdField, $actorField, $timestampField, $deletedField ] = $fields;
		$result = [];
		foreach ( array_chunk( $actorIds, 25 ) as $actorIdsBatch ) {
			$latestRevisionsUnion = $dbr->newUnionQueryBuilder()->all()->caller( __METHOD__ );
			foreach ( $actorIdsBatch as $actorId ) {
				$latestRevisions = $dbr->newSelectQueryBuilder()
				->select( [ 'rev_id' => $revIdField, 'rev_timestamp' => $timestampField, 'rev_actor' => $actorField ] )
				->from( $table )
				->where( [ $actorField => $actorId ] );

				if ( $dbr->unionSupportsOrderAndLimit() ) {
					$latestRevisions->orderBy( $timestampField, SelectQueryBuilder::SORT_DESC )
					->limit( self::REVISION_LIMIT );
				}

				if ( $since !== null ) {
					$latestRevisions->where( $dbr->expr( $timestampField, '>=', $dbr->timestamp( $since ) ) );
				}

				// Do not count an edit if the authority cannot see that the user made it.
				if ( !$authority->isAllowed( 'deletedhistory' ) ) {
					$latestRevisions->where( $dbr->bitAnd( $deletedField, RevisionRecord::DELETED_USER ) . ' = 0' );
				} elseif ( !$authority->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
					$latestRevisions->where(
						$dbr->bitAnd( $deletedField, RevisionRecord::SUPPRESSED_USER ) . ' != '
							. RevisionRecord::SUPPRESSED_USER
					);
				}

				$latestRevisionsUnion->add( $latestRevisions );
			}

			$latestRevisionsSubquery = new Subquery( $latestRevisionsUnion->getSQL() );

			$rows = $dbr->newSelectQueryBuilder()
				->select( [
					'rev_id' => 'latest_revisions.rev_id',
					'rev_timestamp' => 'latest_revisions.rev_timestamp',
					'rev_actor' => 'latest_revisions.rev_actor',
					'ct_tag_id',
				] )
				->from( $latestRevisionsSubquery, 'latest_revisions' )
				->join( 'change_tag', null, [
					'ct_rev_id = latest_revisions.rev_id',
					'ct_tag_id' => array_values( $tagIds ),
				] )
				->caller( __METHOD__ )
				->fetchResultSet();
			array_push( $result, ...$rows );
		}
		return new FakeResultWrapper( $result );
	}
}

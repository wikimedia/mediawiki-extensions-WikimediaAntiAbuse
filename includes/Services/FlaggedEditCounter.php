<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;
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
		private readonly ActorNormalization $actorNormalization,
		private readonly ChangeTagsStore $changeTagsStore,
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
	 * Counts the edits of the user per abuse review flag along with the timestamp of the latest edit that has
	 * that flag, excluding edits that have the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity $user
	 * @param string[] $flags
	 * @param ?string $since TS_MW timestamp of the oldest edits to count, or null for no time limit
	 * @return array<string,array{count:int,latestTimestamp:?string}> The stats for each flag. The
	 *   timestamp is in MediaWiki timestamp format, or null if no recent edit has the flag.
	 */
	public function getFlaggedEditStats(
		Authority $authority,
		UserIdentity $user,
		array $flags,
		?string $since
	): array {
		$stats = array_fill_keys( $flags, [ 'count' => 0, 'latestTimestamp' => null ] );
		$falsePositiveTags = [];
		foreach ( $flags as $flag ) {
			$falsePositiveTags[$flag] = ChangeTagsHandler::REVIEWABLE_TAGS[$flag]['falsePositive'];
		}

		$tagIds = $this->changeTagsStore->getTagIdsFromNames(
			array_merge( $flags, array_values( $falsePositiveTags ) )
		);
		if ( !array_intersect_key( $tagIds, $stats ) ) {
			return $stats;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$actorId = $this->actorNormalization->findActorId( $user, $dbr );
		if ( $actorId === null ) {
			return $stats;
		}

		$tables = [ 'revision' => [ 'rev_id', 'rev_actor', 'rev_timestamp', 'rev_deleted' ] ];
		if ( $authority->isAllowed( 'deletedhistory' ) ) {
			$tables['archive'] = [ 'ar_rev_id', 'ar_actor', 'ar_timestamp', 'ar_deleted' ];
		}

		$tagNamesById = array_flip( $tagIds );
		$revisions = [];
		foreach ( $tables as $table => $fields ) {
			$rows = $this->getTaggedRevisions( $dbr, $authority, $table, $fields, $actorId, $tagIds, $since );
			foreach ( $rows as $row ) {
				$revisions[$row->rev_id]['timestamp'] = ConvertibleTimestamp::convert(
					TimestampFormat::MW,
					$row->rev_timestamp
				);
				$revisions[$row->rev_id]['tags'][] = $tagNamesById[$row->ct_tag_id];
			}
		}

		// A false positive verdict removes the flag, but the model can add the flag again later.
		foreach ( $revisions as [ 'timestamp' => $timestamp, 'tags' => $tags ] ) {
			foreach ( $flags as $flag ) {
				if ( in_array( $flag, $tags, true ) && !in_array( $falsePositiveTags[$flag], $tags, true ) ) {
					$stats[$flag]['count']++;
					$stats[$flag]['latestTimestamp'] = max( $stats[$flag]['latestTimestamp'] ?? '', $timestamp );
				}
			}
		}
		return $stats;
	}

	/**
	 * @param IReadableDatabase $dbr
	 * @param Authority $authority
	 * @param string $table
	 * @param string[] $fields The revision ID, actor, timestamp and deleted fields of $table
	 * @param int $actorId
	 * @param int[] $tagIds
	 * @param ?string $since
	 * @return IResultWrapper Rows with the rev_id, rev_timestamp and ct_tag_id fields
	 */
	private function getTaggedRevisions(
		IReadableDatabase $dbr,
		Authority $authority,
		string $table,
		array $fields,
		int $actorId,
		array $tagIds,
		?string $since
	): IResultWrapper {
		[ $revIdField, $actorField, $timestampField, $deletedField ] = $fields;
		$latestRevisions = $dbr->newSelectQueryBuilder()
			->select( [ 'rev_id' => $revIdField, 'rev_timestamp' => $timestampField ] )
			->from( $table )
			->where( [ $actorField => $actorId ] )
			->orderBy( $timestampField, SelectQueryBuilder::SORT_DESC )
			->limit( self::REVISION_LIMIT );

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

		return $dbr->newSelectQueryBuilder()
			->select( [
				'rev_id' => 'latest_revisions.rev_id',
				'rev_timestamp' => 'latest_revisions.rev_timestamp',
				'ct_tag_id',
			] )
			->from( $latestRevisions, 'latest_revisions' )
			->join( 'change_tag', null, [
				'ct_rev_id = latest_revisions.rev_id',
				'ct_tag_id' => array_values( $tagIds ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
	}
}

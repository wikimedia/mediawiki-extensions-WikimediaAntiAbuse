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

/**
 * Counts the recent edits of a user that have abuse review flags.
 */
class FlaggedEditCounter {

	public const int MAX_AGE_DAYS = 90;

	/** Without a limit, the query reads every recent edit of the user, which is slow for accounts with many edits. */
	private const int REVISION_LIMIT = 500;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly ActorNormalization $actorNormalization,
		private readonly ChangeTagsStore $changeTagsStore,
	) {
	}

	/**
	 * Counts the recent edits of the user that have each flag, but not the false positive verdict of that flag.
	 *
	 * @param Authority $authority
	 * @param UserIdentity $user
	 * @param string[] $flags
	 * @return array<string,int> The count for each flag
	 */
	public function getFlaggedEditCounts( Authority $authority, UserIdentity $user, array $flags ): array {
		$counts = array_fill_keys( $flags, 0 );
		$falsePositiveTags = [];
		foreach ( $flags as $flag ) {
			$falsePositiveTags[$flag] = ChangeTagsHandler::REVIEWABLE_TAGS[$flag]['falsePositive'];
		}

		$tagIds = $this->changeTagsStore->getTagIdsFromNames(
			array_merge( $flags, array_values( $falsePositiveTags ) )
		);
		if ( !array_intersect_key( $tagIds, $counts ) ) {
			return $counts;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$actorId = $this->actorNormalization->findActorId( $user, $dbr );
		if ( $actorId === null ) {
			return $counts;
		}

		$tables = [ 'revision' => [ 'rev_id', 'rev_actor', 'rev_timestamp', 'rev_deleted' ] ];
		if ( $authority->isAllowed( 'deletedhistory' ) ) {
			$tables['archive'] = [ 'ar_rev_id', 'ar_actor', 'ar_timestamp', 'ar_deleted' ];
		}

		$tagNamesById = array_flip( $tagIds );
		$tagsByRevision = [];
		foreach ( $tables as $table => $fields ) {
			foreach ( $this->getTaggedRevisions( $dbr, $authority, $table, $fields, $actorId, $tagIds ) as $row ) {
				$tagsByRevision[$row->rev_id][] = $tagNamesById[$row->ct_tag_id];
			}
		}

		// A false positive verdict removes the flag, but the model can add the flag again later.
		foreach ( $tagsByRevision as $tags ) {
			foreach ( $flags as $flag ) {
				if ( in_array( $flag, $tags, true ) && !in_array( $falsePositiveTags[$flag], $tags, true ) ) {
					$counts[$flag]++;
				}
			}
		}
		return $counts;
	}

	/**
	 * @param IReadableDatabase $dbr
	 * @param Authority $authority
	 * @param string $table
	 * @param string[] $fields The revision ID, actor, timestamp and deleted fields of $table
	 * @param int $actorId
	 * @param int[] $tagIds
	 * @return IResultWrapper Rows with the rev_id and ct_tag_id fields
	 */
	private function getTaggedRevisions(
		IReadableDatabase $dbr, Authority $authority, string $table, array $fields, int $actorId, array $tagIds
	): IResultWrapper {
		[ $revIdField, $actorField, $timestampField, $deletedField ] = $fields;
		$cutoff = ConvertibleTimestamp::time() - self::MAX_AGE_DAYS * 86400;
		$latestRevisions = $dbr->newSelectQueryBuilder()
			->select( [ 'rev_id' => $revIdField ] )
			->from( $table )
			->where( [
				$actorField => $actorId,
				$dbr->expr( $timestampField, '>=', $dbr->timestamp( $cutoff ) ),
			] )
			->orderBy( $timestampField, SelectQueryBuilder::SORT_DESC )
			->limit( self::REVISION_LIMIT );

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
			->select( [ 'rev_id' => 'latest_revisions.rev_id', 'ct_tag_id' ] )
			->from( $latestRevisions, 'latest_revisions' )
			->join( 'change_tag', null, [
				'ct_rev_id = latest_revisions.rev_id',
				'ct_tag_id' => array_values( $tagIds ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
	}
}

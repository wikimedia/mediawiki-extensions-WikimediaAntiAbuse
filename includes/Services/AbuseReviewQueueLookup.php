<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use LogicException;
use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use stdClass;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Queries the revisions of an AbuseReview queue from the revision and archive tables.
 */
class AbuseReviewQueueLookup {

	/** @internal Only for use by ServiceWiring.php */
	public const array CONSTRUCTOR_OPTIONS = [
		'WikimediaAntiAbuseAbuseReviewDelayMinutes',
	];

	public const array INDEX_COLUMNS = [ 'timestamp', 'rev_id' ];

	public const string HANDLED_REASON_SUPPRESSED = 'suppressed';
	public const string HANDLED_REASON_PAGE_DELETED = 'pagedeleted';
	public const string HANDLED_REASON_REVISION_DELETED = 'revisiondeleted';
	public const string HANDLED_REASON_REVERTED = 'reverted';

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly IConnectionProvider $dbProvider,
		private readonly RevisionStore $revisionStore,
		private readonly ChangeTagsStore $changeTagsStore,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Fetches one page of the rows of a queue, as {@link IndexPager::reallyDoQuery} does. The offset is empty or
	 * the {@link self::INDEX_COLUMNS} values joined by '|', and the order is an IndexPager::QUERY_* constant.
	 */
	public function fetchRows(
		AbuseReviewQueueFilters $filters,
		Authority $authority,
		string $offset,
		int $limit,
		bool $order,
		bool $includeOffset
	): IResultWrapper {
		$queryInfo = $this->buildQueryInfo( $filters, $authority, $offset, $limit, $order, $includeOffset );
		$dbr = $this->dbProvider->getReplicaDatabase();

		// Short circuit if only one table is being queried, to avoid the overhead of merging and sorting.
		if ( count( $queryInfo ) === 1 ) {
			return $dbr->newSelectQueryBuilder()
				->queryInfo( $queryInfo[0] )
				->fetchResultSet();
		}

		$rows = [];
		foreach ( $queryInfo as $tableQueryInfo ) {
			$rows = array_merge(
				$rows,
				iterator_to_array(
					$dbr->newSelectQueryBuilder()
						->queryInfo( $tableQueryInfo )
						->fetchResultSet()
				)
			);
		}

		// Group the rows by timestamp with each row indexed by its rev_id, then sort these groups
		$groupedRows = [];
		foreach ( $rows as $row ) {
			if ( !array_key_exists( $row->timestamp, $groupedRows ) ) {
				$groupedRows[$row->timestamp] = [];
			}

			$groupedRows[$row->timestamp][$row->rev_id] = $row;
		}

		if ( $order === IndexPager::QUERY_DESCENDING ) {
			krsort( $groupedRows );
			array_walk( $groupedRows, static fn ( &$value ) => krsort( $value ) );
		} else {
			ksort( $groupedRows );
			array_walk( $groupedRows, static fn ( &$value ) => ksort( $value ) );
		}

		// Flatten the now sorted results into a single array and slice it to the requested limit
		$sortedRows = [];
		array_walk_recursive( $groupedRows, static function ( $value ) use ( &$sortedRows ) {
			$sortedRows[] = $value;
		} );

		return new FakeResultWrapper( array_slice( $sortedRows, 0, $limit ) );
	}

	/**
	 * Returns the tables to read the revisions of this queue from, mapped to their revision ID field. The vandalism
	 * queue skips the archive table unless handled revisions are included, because every revision of a deleted
	 * page counts as handled there.
	 *
	 * @return array<string,string>
	 */
	public function getTablesToQuery( AbuseReviewQueueFilters $filters, Authority $authority ): array {
		$tablesToQuery = [ 'revision' => 'rev_id' ];
		if ( ( $filters->includeHandledRevisions || $filters->abuseReviewTag !== ChangeTagsHandler::VANDALISM_TAG )
			&& $authority->isAllowed( 'deletedhistory' )
		) {
			$tablesToQuery['archive'] = 'ar_rev_id';
		}

		return $tablesToQuery;
	}

	/**
	 * Builds the query information. This is the same code as written in {@link IndexPager::buildQueryInfo}
	 * but modifies it to return the per-table query info applying the arguments to each table's query info.
	 *
	 * @return array[] One query info array per queried table, in the format accepted by
	 *   {@link SelectQueryBuilder::queryInfo}
	 */
	private function buildQueryInfo(
		AbuseReviewQueueFilters $filters,
		Authority $authority,
		string $offset,
		int $limit,
		bool $order,
		bool $includeOffset
	): array {
		// Copied, with modification, from IndexPager::buildQueryInfo
		$dbr = $this->dbProvider->getReplicaDatabase();
		$queryInfo = [];

		foreach ( array_keys( $this->getTablesToQuery( $filters, $authority ) ) as $table ) {
			$info = $this->getQueryInfo( $table, $filters, $authority );
			$tables = $info['tables'];
			$fields = $info['fields'];
			$conds = $info['conds'] ?? [];
			$options = $info['options'] ?? [];
			$joinConds = $info['join_conds'] ?? [];
			$indexColumns = self::INDEX_COLUMNS;

			if ( $order === IndexPager::QUERY_ASCENDING ) {
				$options['ORDER BY'] = self::INDEX_COLUMNS;
				$operator = $includeOffset ? '>=' : '>';
			} else {
				$orderBy = [];
				foreach ( self::INDEX_COLUMNS as $col ) {
					$orderBy[] = $col . ' DESC';
				}
				$options['ORDER BY'] = $orderBy;
				$operator = $includeOffset ? '<=' : '<';
			}
			if ( $offset ) {
				$offsets = explode( '|', $offset, count( $indexColumns ) );
				$indexColumns = array_slice( $indexColumns, 0, count( $offsets ) );

				// Convert the index columns to the correct table column names for the revision and archive tables.
				$indexColumns = array_map( static fn ( $value ) => match ( $value ) {
				  'timestamp' => $table === 'revision' ? 'rev_timestamp' : 'ar_timestamp',
				  'rev_id' => $table === 'revision' ? 'rev_id' : 'ar_rev_id',
				}, $indexColumns );

				$conds[] = $dbr->buildComparison( $operator, array_combine( $indexColumns, $offsets ) );
			}
			$options['LIMIT'] = $limit;

			// Add the data that would normally be returned by this method to an array
			// so that it can be returned for both tables
			$queryInfo[] = [
				'tables' => $tables,
				'fields' => $fields,
				'conds' => $conds,
				'caller' => __METHOD__,
				'options' => $options,
				'join_conds' => $joinConds
			];
		}
		return $queryInfo;
	}

	/** Returns the query info that selects the rows of a queue from the given table. */
	public function getQueryInfo( string $table, AbuseReviewQueueFilters $filters, Authority $authority ): array {
		if ( !in_array( $table, [ 'revision', 'archive' ], true ) ) {
			throw new LogicException(
				'This ::getQueryInfo method must be provided with a valid table to generate ' .
				'the correct query info'
			);
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		if ( $table === 'revision' ) {
			$queryBuilder = $this->revisionStore->newSelectQueryBuilder( $dbr )
				->joinPage()
				->joinComment()
				->clearFields()
				->select( [
					'title' => 'page_title',
					'namespace' => 'page_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'rev_deleted',
					'rev_id' => 'rev_id',
					'timestamp' => 'rev_timestamp',
					'comment_text' => 'comment_rev_comment.comment_text',
					'comment_data' => 'comment_rev_comment.comment_data',
					'comment_cid' => 'comment_rev_comment.comment_id',
					'is_archive' => '0',
					'page_latest' => 'page_latest',
					'parent_id' => 'rev_parent_id',
				] );
		} else {
			$queryBuilder = $this->revisionStore->newArchiveSelectQueryBuilder( $dbr )
				->joinComment()
				->clearFields()
				->select( [
					'title' => 'ar_title',
					'namespace' => 'ar_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'ar_deleted',
					'rev_id' => 'ar_rev_id',
					'timestamp' => 'ar_timestamp',
					'comment_text' => 'comment_ar_comment.comment_text',
					'comment_data' => 'comment_ar_comment.comment_data',
					'comment_cid' => 'comment_ar_comment.comment_id',
					'is_archive' => '1',
					// An archived page has no latest revision
					'page_latest' => '0',
					'parent_id' => 'ar_parent_id',
				] );
		}

		// If no abuse review tag is defined or if it's not a valid reviewable tag, then show no rows as the
		// false positive and handled revisions filters won't work
		if ( !array_key_exists( $filters->abuseReviewTag, ChangeTagsHandler::REVIEWABLE_TAGS ) ) {
			$queryBuilder->where( '1=0' );
			return $queryBuilder->getQueryInfo();
		}

		$tagsFilter = [ $filters->abuseReviewTag ];
		if ( $filters->includeFalsePositives ) {
			$tagsFilter[] = ChangeTagsHandler::REVIEWABLE_TAGS[$filters->abuseReviewTag]['falsePositive'];
		}
		$this->changeTagsStore->addTagsToDisplayQuery( $queryBuilder, $table, $authority, $tagsFilter );

		$revIdField = $table === 'revision' ? 'rev_id' : 'ar_rev_id';

		if ( !$filters->includeHandledRevisions ) {
			$deletedField = $table === 'revision' ? 'rev_deleted' : 'ar_deleted';

			if ( $filters->abuseReviewTag === ChangeTagsHandler::PERSONAL_INFO_TAG ) {
				$queryBuilder->where( $dbr->orExpr( [
					new RawSQLExpression( $dbr->bitAnd(
							$deletedField,
							RevisionRecord::DELETED_RESTRICTED
						) . ' = 0' ),
					new RawSQLExpression( $dbr->bitAnd(
							$deletedField,
							RevisionRecord::DELETED_TEXT
						) . ' = 0' ),
				] ) );
			}
			if ( $filters->abuseReviewTag === ChangeTagsHandler::VANDALISM_TAG ) {
				$queryBuilder->where( new RawSQLExpression(
					$dbr->bitAnd( $deletedField, RevisionRecord::DELETED_TEXT ) . ' = 0'
				) );
				// mw-reverted may not exist on the wiki, so only apply the exclusion
				// if the ID exists
				$revertedTagIds = $this->changeTagsStore->getTagIdsFromNames( [
					ChangeTags::TAG_REVERTED
				] );
				$revertedTagId = array_pop( $revertedTagIds );
				if ( $revertedTagId ) {
					$queryBuilder->leftJoin(
						'change_tag',
						'changetagalreadyreverted',
						[
							'changetagalreadyreverted.ct_rev_id=' . $revIdField,
							'changetagalreadyreverted.ct_tag_id=' . $revertedTagId,
						]
					);
					$queryBuilder->andWhere( [ 'changetagalreadyreverted.ct_tag_id' => null ] );
				}
			}

			// A verdict tag has no ID until it is first applied, so there may be none to exclude.
			$noFurtherActionTagIds = array_values( $this->changeTagsStore->getTagIdsFromNames( [
				ChangeTagsHandler::REVIEWABLE_TAGS[$filters->abuseReviewTag]['noFurtherAction']
			] ) );
			if ( $noFurtherActionTagIds !== [] ) {
				$queryBuilder->leftJoin(
					'change_tag',
					'changetagnofurtheraction',
					[
						'changetagnofurtheraction.ct_rev_id=' . $revIdField,
						'changetagnofurtheraction.ct_tag_id' => $noFurtherActionTagIds,
					]
				);
				$queryBuilder->andWhere( [ 'changetagnofurtheraction.ct_tag_id' => null ] );
			}
		}

		if ( $filters->usernames ) {
			$queryBuilder->where( $dbr->expr( 'actor_name', '=', $filters->usernames ) );
		}

		if ( $filters->revisionIds ) {
			$queryBuilder->where( $dbr->expr( $revIdField, '=', $filters->revisionIds ) );
		}

		if ( $filters->pages ) {
			$titleField = $table === 'revision' ? 'page_title' : 'ar_title';
			$namespaceField = $table === 'revision' ? 'page_namespace' : 'ar_namespace';
			$queryBuilder->where( $dbr->orExpr( array_map(
				static fn ( Title $title ) => $dbr->andExpr( [
					$dbr->expr( $titleField, '=', $title->getDBkey() ),
					$dbr->expr( $namespaceField, '=', $title->getNamespace() )
				] ),
				$filters->pages
			) ) );
		}

		$delayMinutes = $filters->includeRecentEdits ? 0 : $this->getDelayMinutes( $filters->abuseReviewTag );
		if ( $delayMinutes > 0 && !$filters->revisionIds ) {
			$timestampField = $table === 'revision' ? 'rev_timestamp' : 'ar_timestamp';
			$cutoff = $dbr->timestamp(
				ConvertibleTimestamp::time() - $delayMinutes * 60
			);
			$queryBuilder->where( $dbr->expr( $timestampField, '<', $cutoff ) );
		}

		return $queryBuilder->getQueryInfo();
	}

	/** Gets the number of minutes for which the given queue hides a new revision. */
	public function getDelayMinutes( string $abuseReviewTag ): int {
		$delayMinutesByTag = $this->options->get( 'WikimediaAntiAbuseAbuseReviewDelayMinutes' );

		return max( 0, (int)( $delayMinutesByTag[$abuseReviewTag] ?? 0 ) );
	}

	/**
	 * Returns the verdict held for the row, or `null` if no verdict has been applied.
	 */
	public function getVerdict( stdClass $row, string $abuseReviewTag ): ?string {
		if ( $this->rowHasVerdictTag( $row->ts_tags, $abuseReviewTag, 'falsePositive' ) ) {
			return 'falsePositive';
		}
		if ( $this->rowHasVerdictTag( $row->ts_tags, $abuseReviewTag, 'noFurtherAction' ) ) {
			return 'noFurtherAction';
		}

		return null;
	}

	/**
	 * Whether the row carries the given verdict tag for its reviewable tag.
	 *
	 * @param string|null $tsTags
	 * @param string $reviewableTag
	 * @param string $verdict A key of a {@link ChangeTagsHandler::REVIEWABLE_TAGS} entry
	 * @return bool
	 */
	private function rowHasVerdictTag( ?string $tsTags, string $reviewableTag, string $verdict ): bool {
		return in_array(
			ChangeTagsHandler::REVIEWABLE_TAGS[$reviewableTag][$verdict],
			$this->splitTags( $tsTags ),
			true
		);
	}

	/**
	 * Whether the row comes from the archive table, its page having been deleted.
	 *
	 * @param stdClass $row
	 * @return bool
	 */
	public function isArchivedRow( stdClass $row ): bool {
		// A select-list literal, so the database hands it back as the string '0' or '1'.
		return (int)$row->is_archive !== 0;
	}

	/**
	 * Returns the reason why the revision on this row has been handled outside AbuseReview, as a HANDLED_REASON_*
	 * constant, or null if it has not. For the personal info tag this is if the content is suppressed, and for the
	 * vandalism tag if the page is deleted, the text is deleted or suppressed, or the edit has been reverted.
	 *
	 * This should match the checks in {@link self::getQueryInfo} and {@link self::getTablesToQuery} that exclude
	 * handled rows based on these checks. If updating this method, make sure to update there too.
	 */
	public function getHandledReason( stdClass $row, string $abuseReviewTag ): ?string {
		if ( $abuseReviewTag === ChangeTagsHandler::PERSONAL_INFO_TAG ) {
			$deleted = (int)$row->deleted;
			$isTextSuppressed = ( $deleted & RevisionRecord::DELETED_TEXT ) !== 0
				&& ( $deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0;

			return $isTextSuppressed ? self::HANDLED_REASON_SUPPRESSED : null;
		}

		if ( $this->isArchivedRow( $row ) ) {
			return self::HANDLED_REASON_PAGE_DELETED;
		}
		if ( ( (int)$row->deleted & RevisionRecord::DELETED_TEXT ) !== 0 ) {
			return self::HANDLED_REASON_REVISION_DELETED;
		}
		if ( in_array( ChangeTags::TAG_REVERTED, $this->splitTags( $row->ts_tags ), true ) ) {
			return self::HANDLED_REASON_REVERTED;
		}

		return null;
	}

	/**
	 * @param string|null $tsTags Comma-separated tags from a row's ts_tags field
	 * @return string[]
	 */
	private function splitTags( ?string $tsTags ): array {
		return $tsTags !== null && $tsTags !== '' ? explode( ',', $tsTags ) : [];
	}
}

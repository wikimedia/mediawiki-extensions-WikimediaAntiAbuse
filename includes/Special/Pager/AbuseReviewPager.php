<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLExpression;

class AbuseReviewPager extends CodexTablePager {

	private const string HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';

	// Marks every control/tag whose visibility flips when a row is marked/unmarked.
	private const string TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle';

	/** @var true Always default to paging in a descending order */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly UserEditTracker $userEditTracker,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly array $tagsFilter,
		private readonly bool $includeRevisionsWithSuppressedText,
	) {
		parent::__construct(
			$context->msg( 'wikimediaantiabuse-special-abuse-review-caption' )->text(),
			$context,
			$linkRenderer
		);
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'timestamp' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-revision' )->text(),
			'user_text' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-author' )->text(),
			'ts_tags' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-tags' )->text(),
			'actions' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-actions' )->text(),
		];
	}

	/**
	 * @param string $name
	 * @param string|null $value
	 * @return string
	 */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'timestamp':
				$timestamp = $this->getLanguage()->userTimeAndDate( $value, $this->getUser() );
				$title = Title::makeTitle( $row->namespace, $row->title );

				if ( RevisionRecord::userCanBitfield(
					(int)$row->deleted,
					RevisionRecord::DELETED_TEXT,
					$this->getAuthority(),
					$title
				) ) {
					if ( $row->is_archive ) {
						$dateLink = $this->getLinkRenderer()->makeKnownLink(
							SpecialPage::getTitleValueFor( 'Undelete' ),
							$timestamp,
							[],
							[
								'target' => $title->getPrefixedText(),
								'timestamp' => $row->timestamp,
								'diff' => 'prev',
							],
						);
					} else {
						$dateLink = $this->getLinkRenderer()->makeKnownLink(
							$title,
							$timestamp,
							[],
							[ 'diff' => 'prev', 'oldid' => $row->rev_id ],
						);
					}
				} else {
					$dateLink = htmlspecialchars( $timestamp );
				}

				// Strike out the timestamp for a revision with deleted text, doubly for a
				// suppressed one, matching Special:Contributions.
				if ( ( (int)$row->deleted & RevisionRecord::DELETED_TEXT ) !== 0 ) {
					$deletedClass = 'history-deleted';
					if ( ( (int)$row->deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0 ) {
						$deletedClass .= ' mw-history-suppressed';
					}
					$dateLink = Html::rawElement( 'span', [ 'class' => $deletedClass ], $dateLink );
				}

				return $dateLink;
			case 'ts_tags':
				$tag = $this->getFirstReviewableTag( $value );
				if ( $tag === null ) {
					return '';
				}

				// Render both tag variants; the one not matching the row's state starts hidden.
				$isFalsePositive = $this->isFalsePositiveRow( $value, $tag );
				return $this->renderTagVariant( $tag, false, $isFalsePositive ) .
					$this->renderTagVariant( ChangeTagsHandler::REVIEWABLE_TAGS[$tag], true, $isFalsePositive );
			case 'user_text':
				if ( !RevisionRecord::userCanBitfield(
					(int)$row->deleted,
					RevisionRecord::DELETED_USER,
					$this->getAuthority(),
					Title::makeTitle( $row->namespace, $row->title )
				) ) {
					return Html::element(
						'span',
						[ 'class' => 'history-deleted' ],
						$this->msg( 'rev-deleted-user' )->text()
					);
				}

				$author = new UserIdentityValue( (int)$row->user, $row->user_text );
				return $this->getLinkRenderer()->makeUserLink( $author, $this->getContext() ) .
					Linker::userToolLinks( $author->getId(), $author->getName(), true );
			case 'actions':
				$tag = $this->getFirstReviewableTag( $row->ts_tags );
				if ( $tag === null ) {
					return '';
				}

				$buttonAttributes = [
					'data-rev-id' => $row->rev_id,
					'data-abuse-review-tag' => $tag,
				];

				$markLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-mark-false-positive'
				)->text();
				$unmarkLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-unmark-false-positive'
				)->text();

				// A flagged row shows "mark"; an already-handled row shows "unmark". Hide the other.
				$isFalsePositive = $this->isFalsePositiveRow( $row->ts_tags, $tag );
				$markClass = 'mw-wikimediaantiabuse-abuse-review-mark-button ' . self::TOGGLE_CLASS;
				$unmarkClass = 'mw-wikimediaantiabuse-abuse-review-unmark-button ' . self::TOGGLE_CLASS;
				if ( $isFalsePositive ) {
					$markClass .= ' ' . self::HIDDEN_CLASS;
				} else {
					$unmarkClass .= ' ' . self::HIDDEN_CLASS;
				}

				// A suppressed revision has been handled as a true positive, so it cannot be
				// marked as a false positive, but can be unmarked as a false positive.
				$isSuppressed = $this->isSuppressedRow( $row );

				$noteId = 'mw-wikimediaantiabuse-abuse-review-suppressed-note-' . $row->rev_id;

				$markAttributes = [ ...$buttonAttributes, 'class' => $markClass ];
				if ( $isSuppressed ) {
					$markAttributes['aria-describedby'] = $noteId;
				}

				$codex = new Codex();
				$markButton = $codex->button()
					->setLabel( $markLabel )
					->setType( 'button' )
					->setAction( 'progressive' )
					->setDisabled( $isSuppressed )
					->setAttributes( $markAttributes )
					->build()
					->getHtml();
				$unmarkButton = $codex->button()
					->setLabel( $unmarkLabel )
					->setType( 'button' )
					->setAttributes( [ ...$buttonAttributes, 'class' => $unmarkClass ] )
					->build()
					->getHtml();

				$actionsContent = $markButton . $unmarkButton;
				if ( $isSuppressed ) {
					// Always show the note on a suppressed row, whether or not it is a false
					// positive, so reviewers can see at a glance which rows are already handled.
					$actionsContent .= Html::element(
						'span',
						[
							'id' => $noteId,
							'class' => 'mw-wikimediaantiabuse-abuse-review-suppressed-note',
						],
						$this->msg( 'wikimediaantiabuse-special-abuse-review-already-suppressed-note' )->text()
					);
				}

				return Html::rawElement(
					'div',
					[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions' ],
					$actionsContent
				);
			default:
				throw new InvalidArgumentException( "Unable to format $name" );
		}
	}

	/** @inheritDoc */
	public function reallyDoQuery( $offset, $limit, $order ): IResultWrapper {
		$queryInfo = $this->buildQueryInfo( $offset, $limit, $order );

		// Short circuit if only one table is being queried, to avoid the overhead of merging and sorting.
		if ( count( $queryInfo ) === 1 ) {
			return $this->getDatabase()->newSelectQueryBuilder()
				->queryInfo( $queryInfo[0] )
				->fetchResultSet();
		}

		$rows = [];
		foreach ( $queryInfo as $tableQueryInfo ) {
			$rows = array_merge(
				$rows,
				iterator_to_array(
					$this->getDatabase()->newSelectQueryBuilder()
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

		if ( $order === self::QUERY_DESCENDING ) {
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
	 * Builds the query information. This is the same code as written in {@link IndexPager::buildQueryInfo}
	 * but modifies it to return the per-table query info applying the arguments to each table's query info.
	 *
	 * @inheritDoc
	 * @return array[] One query info array per queried table, in the format accepted by
	 *   {@link SelectQueryBuilder::queryInfo}
	 */
	protected function buildQueryInfo( $offset, $limit, $order ): array {
		// Copied, with modification, from IndexPager::buildQueryInfo
		$fname = __METHOD__ . ' (' . $this->getSqlComment() . ')';
		$queryInfo = [];

		$tablesToQuery = [ 'revision' ];
		if ( $this->getAuthority()->isAllowed( 'deletedhistory' ) ) {
			$tablesToQuery[] = 'archive';
		}

		foreach ( $tablesToQuery as $table ) {
			$info = $this->getQueryInfo( $table );
			$tables = $info['tables'];
			$fields = $info['fields'];
			$conds = $info['conds'] ?? [];
			$options = $info['options'] ?? [];
			$joinConds = $info['join_conds'] ?? [];
			$indexColumns = (array)$this->mIndexField;
			$sortColumns = array_merge( $indexColumns, $this->mExtraSortFields );

			if ( $order === self::QUERY_ASCENDING ) {
				$options['ORDER BY'] = $sortColumns;
				$operator = $this->mIncludeOffset ? '>=' : '>';
			} else {
				$orderBy = [];
				foreach ( $sortColumns as $col ) {
					$orderBy[] = $col . ' DESC';
				}
				$options['ORDER BY'] = $orderBy;
				$operator = $this->mIncludeOffset ? '<=' : '<';
			}
			if ( $offset ) {
				$offsets = explode( '|', $offset, count( $indexColumns ) );
				$indexColumns = array_slice( $indexColumns, 0, count( $offsets ) );

				// Convert the index columns to the correct table column names for the revision and archive tables.
				$indexColumns = array_map( static fn ( $value ) => match ( $value ) {
				  'timestamp' => $table === 'revision' ? 'rev_timestamp' : 'ar_timestamp',
				  'rev_id' => $table === 'revision' ? 'rev_id' : 'ar_rev_id',
				}, $indexColumns );

				$conds[] = $this->getDatabase()->buildComparison( $operator, array_combine( $indexColumns, $offsets ) );
			}
			$options['LIMIT'] = intval( $limit );

			// Add the data that would normally be returned by this method to an array
			// so that it can be returned for both tables
			$queryInfo[] = [
				'tables' => $tables,
				'fields' => $fields,
				'conds' => $conds,
				'caller' => $fname,
				'options' => $options,
				'join_conds' => $joinConds
			];
		}
		return $queryInfo;
	}

	/** @inheritDoc */
	public function getQueryInfo( ?string $table = null ): array {
		if ( !in_array( $table, [ 'revision', 'archive' ], true ) ) {
			throw new LogicException(
				'This ::getQueryInfo method must be provided with a valid table to generate ' .
				'the correct query info'
			);
		}

		if ( $table === 'revision' ) {
			$queryBuilder = $this->revisionStore->newSelectQueryBuilder( $this->getDatabase() )
				->joinPage()
				->clearFields()
				->select( [
					'title' => 'page_title',
					'namespace' => 'page_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'rev_deleted',
					'rev_id' => 'rev_id',
					'timestamp' => 'rev_timestamp',
					'is_archive' => '0',
				] );
		} else {
			$queryBuilder = $this->revisionStore->newArchiveSelectQueryBuilder( $this->getDatabase() )
				->clearFields()
				->select( [
					'title' => 'ar_title',
					'namespace' => 'ar_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'ar_deleted',
					'rev_id' => 'ar_rev_id',
					'timestamp' => 'ar_timestamp',
					'is_archive' => '1',
				] );
		}

		if ( $this->tagsFilter ) {
			$this->changeTagsStore->addTagsToDisplayQuery(
				$queryBuilder, $table, $this->getAuthority(), $this->tagsFilter
			);
		} else {
			$queryBuilder->where( '1=0' );
		}

		if ( !$this->includeRevisionsWithSuppressedText ) {
			$deletedField = $table === 'revision' ? 'rev_deleted' : 'ar_deleted';
			$queryBuilder->where( $this->getDatabase()->orExpr( [
				new RawSQLExpression( $this->getDatabase()->bitAnd(
					$deletedField,
					RevisionRecord::DELETED_RESTRICTED
				) . ' = 0' ),
				new RawSQLExpression( $this->getDatabase()->bitAnd(
					$deletedField,
					RevisionRecord::DELETED_TEXT
				) . ' = 0' ),
			] ) );
		}

		return $queryBuilder->getQueryInfo();
	}

	/** @inheritDoc */
	protected function doBatchLookups(): void {
		parent::doBatchLookups();

		$lb = $this->linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
		$users = [];
		foreach ( $this->mResult as $row ) {
			$user = new UserIdentityValue( (int)$row->user, $row->user_text );
			$users[] = $user;
			$lb->addUser( $user );
		}

		$lb->execute();
		$this->userEditTracker->preloadUserEditCountCache( $users );
	}

	/** @inheritDoc */
	protected function getRowClass( $row ): string {
		return 'mw-wikimediaantiabuse-abuse-review-row';
	}

	/**
	 * The first reviewable tag on a row, normalised to its non-false-positive (base) form.
	 *
	 * A row is only ever displayed for one reviewable tag: if it somehow carries more than
	 * one, the first is the one shown and acted on.
	 *
	 * @param string|null $tsTags
	 * @return string|null Null if the row carries no reviewable tag
	 */
	private function getFirstReviewableTag( ?string $tsTags ): ?string {
		$falsePositiveToTag = array_flip( ChangeTagsHandler::REVIEWABLE_TAGS );

		foreach ( $this->splitTags( $tsTags ) as $tag ) {
			if ( isset( ChangeTagsHandler::REVIEWABLE_TAGS[$tag] ) ) {
				return $tag;
			}
			if ( isset( $falsePositiveToTag[$tag] ) ) {
				return $falsePositiveToTag[$tag];
			}
		}

		return null;
	}

	private function isFalsePositiveRow( ?string $tsTags, string $reviewableTag ): bool {
		return in_array(
			ChangeTagsHandler::REVIEWABLE_TAGS[$reviewableTag],
			$this->splitTags( $tsTags ),
			true
		);
	}

	/**
	 * Whether the revision on this row has been handled by suppressing its text.
	 *
	 * This mirrors the rows hidden by default in {@link self::getQueryInfo}: a revision is only
	 * treated as handled once both its text and the restriction (suppression) bit are set.
	 *
	 * @param \stdClass $row
	 * @return bool
	 */
	private function isSuppressedRow( \stdClass $row ): bool {
		$deleted = (int)$row->deleted;
		return ( $deleted & RevisionRecord::DELETED_TEXT ) !== 0
			&& ( $deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0;
	}

	/**
	 * @param string|null $tsTags Comma-separated tags from a row's ts_tags field
	 * @return string[]
	 */
	private function splitTags( ?string $tsTags ): array {
		return $tsTags !== null && $tsTags !== '' ? explode( ',', $tsTags ) : [];
	}

	private function renderTagVariant( string $tag, bool $falsePositive, bool $rowIsFalsePositive ): string {
		$classes = [
			'mw-wikimediaantiabuse-abuse-review-tag',
			$falsePositive
				? 'mw-wikimediaantiabuse-abuse-review-tag--false-positive'
				: 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive',
			self::TOGGLE_CLASS,
		];
		// A variant starts hidden when it does not match the row's current state.
		if ( $falsePositive !== $rowIsFalsePositive ) {
			$classes[] = self::HIDDEN_CLASS;
		}
		return Html::rawElement(
			'span',
			[ 'class' => $classes ],
			$this->changeTagsFormatter->getTagDescription( $tag, $this->getContext() )
		);
	}

	/** @inheritDoc */
	protected function getRowAttrs( $row ): array {
		return array_merge(
			parent::getRowAttrs( $row ),
			[ 'data-rev-id' => $row->rev_id ]
		);
	}

	/** @inheritDoc */
	public function getIndexField(): array {
		return [ 'timestamp' => [ 'timestamp', 'rev_id' ] ];
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'timestamp';
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field === 'timestamp';
	}
}

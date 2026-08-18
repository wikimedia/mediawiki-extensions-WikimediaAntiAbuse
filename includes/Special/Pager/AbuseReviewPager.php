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

	// Mark the controls and tags that flip when a verdict changes, one scope per action.
	private const string FALSE_POSITIVE_TOGGLE_CLASS =
		'mw-wikimediaantiabuse-abuse-review-toggle-false-positive';
	private const string NO_FURTHER_ACTION_TOGGLE_CLASS =
		'mw-wikimediaantiabuse-abuse-review-toggle-no-further-action';

	/** @var true Always default to paging in a descending order */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/** @var array<string,string> Tag description HTML, keyed by tag name */
	private array $tagDescriptions = [];

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly UserEditTracker $userEditTracker,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly array $tagsFilter,
		private readonly bool $includeHandledRevisions,
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

				// Render every tag state; the ones not matching the row's state start hidden.
				$isFalsePositive = $this->rowHasVerdictTag( $value, $tag, 'falsePositive' );
				return Html::rawElement(
					'div',
					[ 'class' => 'mw-wikimediaantiabuse-abuse-review-tags' ],
					$this->renderTag(
						$tag,
						'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive',
						self::FALSE_POSITIVE_TOGGLE_CLASS,
						$isFalsePositive
					) .
					$this->renderTag(
						ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'],
						'mw-wikimediaantiabuse-abuse-review-tag--false-positive',
						self::FALSE_POSITIVE_TOGGLE_CLASS,
						!$isFalsePositive
					) .
					$this->renderTag(
						ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['noFurtherAction'],
						'mw-wikimediaantiabuse-abuse-review-tag--no-further-action',
						self::NO_FURTHER_ACTION_TOGGLE_CLASS,
						!$this->rowHasVerdictTag( $value, $tag, 'noFurtherAction' )
					)
				);
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
				$markNoFurtherActionLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action'
				)->text();
				$unmarkNoFurtherActionLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action'
				)->text();

				$isFalsePositive = $this->rowHasVerdictTag( $row->ts_tags, $tag, 'falsePositive' );
				$isNoFurtherAction = $this->rowHasVerdictTag( $row->ts_tags, $tag, 'noFurtherAction' );

				// A mark button joins both toggle scopes: either verdict being set hides it.
				$bothToggleClasses = self::FALSE_POSITIVE_TOGGLE_CLASS . ' ' .
					self::NO_FURTHER_ACTION_TOGGLE_CLASS;
				$markClass = 'mw-wikimediaantiabuse-abuse-review-mark-button ' . $bothToggleClasses;
				$markNoFurtherActionClass =
					'mw-wikimediaantiabuse-abuse-review-mark-no-further-action-button ' . $bothToggleClasses;
				$unmarkClass = 'mw-wikimediaantiabuse-abuse-review-unmark-button ' .
					self::FALSE_POSITIVE_TOGGLE_CLASS;
				$unmarkNoFurtherActionClass = 'mw-wikimediaantiabuse-abuse-review-unmark-no-further-action-button ' .
					self::NO_FURTHER_ACTION_TOGGLE_CLASS;

				if ( $isFalsePositive || $isNoFurtherAction ) {
					$markClass .= ' ' . self::HIDDEN_CLASS;
					$markNoFurtherActionClass .= ' ' . self::HIDDEN_CLASS;
				}
				if ( !$isFalsePositive ) {
					$unmarkClass .= ' ' . self::HIDDEN_CLASS;
				}
				if ( !$isNoFurtherAction ) {
					$unmarkNoFurtherActionClass .= ' ' . self::HIDDEN_CLASS;
				}

				// A suppressed revision is already handled, so it cannot take a new verdict.
				$isSuppressed = $this->isSuppressedRow( $row );

				$noteId = 'mw-wikimediaantiabuse-abuse-review-suppressed-note-' . $row->rev_id;

				$markAttributes = [ ...$buttonAttributes, 'class' => $markClass ];
				$markNoFurtherActionAttributes = [ ...$buttonAttributes, 'class' => $markNoFurtherActionClass ];
				if ( $isSuppressed ) {
					$markAttributes['aria-describedby'] = $noteId;
					$markNoFurtherActionAttributes['aria-describedby'] = $noteId;
				}

				$codex = new Codex();
				$markButton = $this->renderActionButton(
					$codex, $markLabel, $markAttributes, true, $isSuppressed
				);
				$unmarkButton = $this->renderActionButton(
					$codex, $unmarkLabel, [ ...$buttonAttributes, 'class' => $unmarkClass ], false, false
				);
				$markNoFurtherActionButton = $this->renderActionButton(
					$codex, $markNoFurtherActionLabel, $markNoFurtherActionAttributes, false, $isSuppressed
				);
				$unmarkNoFurtherActionButton = $this->renderActionButton(
					$codex,
					$unmarkNoFurtherActionLabel,
					[ ...$buttonAttributes, 'class' => $unmarkNoFurtherActionClass ],
					false,
					false
				);

				$actionsContent = $markButton . $unmarkButton .
					$markNoFurtherActionButton . $unmarkNoFurtherActionButton;
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

		if ( !$this->includeHandledRevisions ) {
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

			// A verdict tag has no ID until it is first applied, so there may be none to exclude.
			$noFurtherActionTagIds = array_values( $this->changeTagsStore->getTagIdsFromNames(
				array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'noFurtherAction' )
			) );
			if ( $noFurtherActionTagIds !== [] ) {
				$revIdField = $table === 'revision' ? 'rev_id' : 'ar_rev_id';
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
		$falsePositiveToTag = [];
		foreach ( ChangeTagsHandler::REVIEWABLE_TAGS as $baseTag => $verdictTags ) {
			$falsePositiveToTag[$verdictTags['falsePositive']] = $baseTag;
		}

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
	 * Whether the revision on this row has been handled by suppressing its text.
	 *
	 * This matches the suppression check in {@link self::getQueryInfo}, which by default
	 * also hides revisions marked as needing no further action.
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

	/**
	 * @param Codex $codex
	 * @param string $label
	 * @param array $attributes Including the button's own classes
	 * @param bool $progressive Whether the button takes the progressive colour
	 * @param bool $disabled
	 * @return string
	 */
	private function renderActionButton(
		Codex $codex,
		string $label,
		array $attributes,
		bool $progressive,
		bool $disabled
	): string {
		$button = $codex->button()
			->setLabel( $label )
			->setType( 'button' )
			->setDisabled( $disabled )
			->setAttributes( $attributes );
		if ( $progressive ) {
			$button->setAction( 'progressive' );
		}
		return $button->build()->getHtml();
	}

	/**
	 * @param string $tag
	 * @param string $variantClass Marks which of the row's tag states this chip shows
	 * @param string $toggleClass Marks the action whose controls flip with this chip
	 * @param bool $hidden Whether the chip does not match the row's current state
	 * @return string
	 */
	private function renderTag(
		string $tag,
		string $variantClass,
		string $toggleClass,
		bool $hidden
	): string {
		$classes = [ 'mw-wikimediaantiabuse-abuse-review-tag', $variantClass, $toggleClass ];
		if ( $hidden ) {
			$classes[] = self::HIDDEN_CLASS;
		}
		return Html::rawElement(
			'span',
			[ 'class' => $classes ],
			$this->getTagDescription( $tag )
		);
	}

	/** A tag description is the same on every row, so parse each one only once per page. */
	private function getTagDescription( string $tag ): string {
		$this->tagDescriptions[$tag] ??= $this->changeTagsFormatter->getTagDescription(
			$tag,
			$this->getContext()
		);
		return $this->tagDescriptions[$tag];
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

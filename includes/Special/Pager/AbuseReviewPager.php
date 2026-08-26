<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\RevisionSnippetGenerator;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use stdClass;
use Wikimedia\Codex\Component\HtmlSnippet;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLExpression;

class AbuseReviewPager extends CodexTablePager {

	private const string HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';

	// The sortable column, showing when the flagged revision was made.
	private const string TIMESTAMP_FIELD = 'timestamp';

	// The unlabelled column, whose every cell holds the body of one review row.
	private const string FIELD = 'revision';

	private const int MAX_DIFF_LINES = 10;

	/** @var true Always default to paging in a descending order */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/** @var array<string,string> Tag description HTML, keyed by tag name */
	private array $tagDescriptions = [];

	/** @var string[] Formatted edit summaries, keyed by revision ID */
	private array $formattedComments = [];

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly RevisionSnippetGenerator $revisionSnippetGenerator,
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
		// The review column is left unlabelled: each of its cells is a block, not a
		// single value. Only the timestamp column takes a heading, which also carries
		// the sort control.
		return [
			self::TIMESTAMP_FIELD =>
				$this->msg( 'wikimediaantiabuse-special-abuse-review-heading-revision' )->text(),
			self::FIELD => '',
		];
	}

	/** @inheritDoc */
	protected function getEmptyBody(): string {
		return Html::rawElement(
			'tr',
			[ 'class' => 'cdx-table__table__empty-state' ],
			Html::rawElement(
				'td',
				[
					'class' => 'cdx-table__table__empty-state-content',
					'colspan' => count( $this->getFieldNames() ),
				],
				$this->buildEmptyState()
			)
		);
	}

	private function buildEmptyState(): string {
		$mark = Html::element(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-empty-mark' ]
		);
		$heading = Html::element(
			'strong',
			[],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-empty-title' )->text()
		);
		$description = Html::element(
			'div',
			[],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-empty-description' )->text()
		);
		$hint = Html::element(
			'div',
			[],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-empty-hint' )->text()
		);

		return $mark . $heading . $description . $hint;
	}

	/**
	 * @param string $name
	 * @param string|null $value
	 * @return string
	 */
	public function formatValue( $name, $value ): string {
		if ( $name !== self::TIMESTAMP_FIELD && $name !== self::FIELD ) {
			throw new InvalidArgumentException( "Unable to format $name" );
		}

		$row = $this->mCurrentRow;
		$title = Title::makeTitle( $row->namespace, $row->title );

		if ( $name === self::TIMESTAMP_FIELD ) {
			return $this->buildTimestamp( $title, $row );
		}

		$tag = $this->getFirstReviewableTag( $row->ts_tags );

		$summary = Html::rawElement( 'summary', [], $this->buildSummary( $title, $row, $tag ) );
		$actions = $this->buildRevisionActions( $title, $row )
			. $this->buildVerdictsApp( $row, $tag );
		$editSummary = $this->buildEditSummary( $title, $row );
		$changes = $this->buildChanges( $title, $row );
		$content = Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__content' ],
			$actions . $editSummary . $changes
		);

		return Html::rawElement(
			'details',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__details' ],
			$summary . $content
		);
	}

	/**
	 * When the flagged revision was made, linking to its diff. A revision the viewer may
	 * not see the text of is left unlinked, and one whose text is deleted is struck
	 * through, doubly for a suppressed one, matching Special:Contributions.
	 */
	private function buildTimestamp( Title $title, stdClass $row ): string {
		$timestamp = $this->getLanguage()->userTimeAndDate( $row->timestamp, $this->getUser() );

		if ( !RevisionRecord::userCanBitfield(
			(int)$row->deleted,
			RevisionRecord::DELETED_TEXT,
			$this->getAuthority(),
			$title
		) ) {
			$dateLink = htmlspecialchars( $timestamp );
		} elseif ( $this->isArchivedRow( $row ) ) {
			$dateLink = $this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleValueFor( 'Undelete' ),
				$timestamp,
				[],
				$this->buildUndeleteQuery( $title, $row )
			);
		} else {
			$dateLink = $this->getLinkRenderer()->makeKnownLink(
				$title,
				$timestamp,
				[],
				[ 'diff' => 'prev', 'oldid' => $row->rev_id ]
			);
		}

		$visibilityClasses = $this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_TEXT );
		if ( !$visibilityClasses ) {
			return $dateLink;
		}

		return Html::rawElement( 'span', [ 'class' => $visibilityClasses ], $dateLink );
	}

	/**
	 * The always-visible part of a row: page title, author, tag and the show/hide toggle.
	 *
	 * The title is this column's primary link. Strike the title of a revision whose text
	 * is deleted, doubly for a suppressed one, so a reviewer can see what has already
	 * been hidden without opening the row.
	 */
	private function buildSummary( Title $title, stdClass $row, ?string $tag ): string {
		$pageClasses = array_merge(
			[ 'mw-wikimediaantiabuse-abuse-review-row__page' ],
			$this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_TEXT )
		);

		$pageLink = Html::rawElement(
			'span',
			[ 'class' => $pageClasses ],
			$this->buildPageLink( $title, $row )
		);

		$meta = Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__author' ],
			$this->buildAuthor( $title, $row )
		);
		if ( $tag !== null ) {
			// Render every tag state; the ones not matching the row's state start hidden.
			$isFalsePositive = $this->rowHasVerdictTag( $row->ts_tags, $tag, 'falsePositive' );
			$flagChip = $this->renderTag(
				$tag,
				'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive',
				$isFalsePositive
			);
			$falsePositiveChip = $this->renderTag(
				ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'],
				'mw-wikimediaantiabuse-abuse-review-tag--false-positive',
				!$isFalsePositive
			);
			$noFurtherActionChip = $this->renderTag(
				ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['noFurtherAction'],
				'mw-wikimediaantiabuse-abuse-review-tag--no-further-action',
				!$this->rowHasVerdictTag( $row->ts_tags, $tag, 'noFurtherAction' )
			);
			$meta .= Html::rawElement(
				'span',
				[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__tags' ],
				$flagChip . $falsePositiveChip . $noFurtherActionChip
			);
		}

		$showLabel = Html::element(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__toggle-label--show' ],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-show-details' )->text()
		);
		$hideLabel = Html::element(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__toggle-label--hide' ],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-hide-details' )->text()
		);
		$toggle = Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__toggle' ],
			$showLabel . $hideLabel
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__info' ],
			$pageLink . Html::rawElement(
				'span',
				[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__meta' ],
				$meta
			)
		) . $toggle;
	}

	/**
	 * The page a row is about. A deleted page can no longer be linked to, so its title
	 * points at the page's deleted revisions on Special:Undelete instead.
	 */
	private function buildPageLink( Title $title, stdClass $row ): string {
		if ( !$this->isArchivedRow( $row ) ) {
			return $this->getLinkRenderer()->makeKnownLink( $title );
		}

		return $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleValueFor( 'Undelete', $title->getPrefixedDBkey() ),
			$title->getPrefixedText()
		);
	}

	/** @return array<string,string> Query parameters addressing an archived revision's diff on Special:Undelete */
	private function buildUndeleteQuery( Title $title, stdClass $row ): array {
		return [ 'target' => $title->getPrefixedText(), 'timestamp' => $row->timestamp, 'diff' => 'prev' ];
	}

	private function buildAuthor( Title $title, stdClass $row ): string {
		$visibilityClasses = $this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_USER );

		if ( !RevisionRecord::userCanBitfield(
			(int)$row->deleted,
			RevisionRecord::DELETED_USER,
			$this->getAuthority(),
			$title
		) ) {
			return Html::element(
				'span',
				[ 'class' => $visibilityClasses ],
				$this->msg( 'rev-deleted-user' )->text()
			);
		}

		$author = new UserIdentityValue( (int)$row->user, $row->user_text );
		$userLink = $this->getLinkRenderer()->makeUserLink( $author, $this->getContext() );
		if ( !$visibilityClasses ) {
			return $userLink;
		}

		return Html::rawElement( 'span', [ 'class' => $visibilityClasses ], $userLink );
	}

	private function buildRevisionActions( Title $title, stdClass $row ): string {
		// Special:RevisionDelete addresses an archived revision as type=archive keyed on
		// ar_timestamp, so a type=revision link built from ar_rev_id resolves to nothing.
		$revisionDeleteUrl = null;
		if ( !$this->isArchivedRow( $row ) && $this->getAuthority()->isAllowed( 'deleterevision' ) ) {
			$revisionDeleteUrl = SpecialPage::getTitleFor( 'Revisiondelete' )->getLocalURL( [
				'type' => 'revision',
				'target' => $title->getPrefixedText(),
				'ids' => $row->rev_id,
			] );
		}
		// Suppression has no URL of its own: it is the wpHideRestricted checkbox inside
		// Special:RevisionDelete. The history is sent instead, for its checkbox interface,
		// which is where a reviewer picks the revisions to hide. Core builds those checkboxes
		// for deleterevision, so without it the history has nothing to offer.
		$suppressUrl = null;
		if ( !$this->isArchivedRow( $row )
			&& $this->getAuthority()->isAllowedAll( 'deleterevision', 'suppressrevision' )
		) {
			$suppressUrl = $title->getLocalURL( [ 'action' => 'history' ] );
		}
		// Undo resolves its revision against the live revision table, so an archived one is
		// never found. The first revision of a page has nothing to restore, and core refuses
		// to undo when either revision's text is deleted.
		$revertUrl = null;
		if ( !$this->isArchivedRow( $row )
			&& $row->parent_id
			&& ( (int)$row->deleted & RevisionRecord::DELETED_TEXT ) === 0
			&& !$this->parentTextIsDeleted( (int)$row->parent_id )
			&& $this->getAuthority()->probablyCan( 'edit', $title )
		) {
			$revertUrl = $title->getLocalURL( [
				'action' => 'edit',
				'undoafter' => $row->parent_id,
				'undo' => $row->rev_id,
			] );
		}

		// A URL is either null or a real one, so the default filter drops exactly the
		// actions this viewer is not offered.
		$actionUrls = array_filter( [
			'wikimediaantiabuse-special-abuse-review-action-suppress' => $suppressUrl,
			'wikimediaantiabuse-special-abuse-review-action-revision-delete' => $revisionDeleteUrl,
			'wikimediaantiabuse-special-abuse-review-action-revert' => $revertUrl,
		] );
		if ( !$actionUrls ) {
			return '';
		}

		$links = '';
		foreach ( $actionUrls as $messageKey => $url ) {
			$links .= $this->buildActionLink( $url, $messageKey );
		}

		$heading = Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions-heading' ],
			Html::element(
				'h4',
				[],
				$this->msg( 'wikimediaantiabuse-special-abuse-review-actions-heading' )->text()
			)
		);
		$container = Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions' ],
			$links
		);

		return $heading . $container;
	}

	/**
	 * The mount point the verdict controls are rendered into. A row with no reviewable tag
	 * and nothing suppressed has no verdict to offer, so it gets none.
	 */
	private function buildVerdictsApp( stdClass $row, ?string $tag ): string {
		$isSuppressed = $this->isSuppressedRow( $row );
		if ( $tag === null && !$isSuppressed ) {
			return '';
		}

		return Html::rawElement( 'div', [
			'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts-app',
			'data-verdicts' => json_encode( [
				'tag' => $tag,
				'isFalsePositive' => $tag !== null
					&& $this->rowHasVerdictTag( $row->ts_tags, $tag, 'falsePositive' ),
				'isNoFurtherAction' => $tag !== null
					&& $this->rowHasVerdictTag( $row->ts_tags, $tag, 'noFurtherAction' ),
				'isSuppressed' => $isSuppressed,
			], JSON_THROW_ON_ERROR ),
		] );
	}

	/**
	 * One of the row's actions, as the link the app renders it as.
	 */
	private function buildActionLink( string $url, string $messageKey ): string {
		return Html::element(
			'a',
			[
				'class' => [
					'cdx-button',
					'cdx-button--fake-button',
					'cdx-button--fake-button--enabled',
				],
				'href' => $url,
				'target' => '_blank',
			],
			$this->msg( $messageKey )->text()
		);
	}

	/**
	 * A parent revision that is missing counts as deleted: the undo resolves it against the
	 * live revision table, so one it cannot find there is one it will refuse.
	 */
	private function parentTextIsDeleted( int $parentId ): bool {
		$parent = $this->revisionStore->getRevisionById( $parentId );

		return $parent === null || $parent->isDeleted( RevisionRecord::DELETED_TEXT );
	}

	private function buildEditSummary( Title $title, stdClass $row ): string {
		if ( !RevisionRecord::userCanBitfield(
			(int)$row->deleted,
			RevisionRecord::DELETED_COMMENT,
			$this->getAuthority(),
			$title
		) ) {
			$comment = Html::element(
				'span',
				[ 'class' => $this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_COMMENT ) ],
				$this->msg( 'rev-deleted-comment' )->text()
			);
		} else {
			$comment = $this->formattedComments[(int)$row->rev_id] ?? '';
			$classes = $this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_COMMENT );
			if ( $comment !== '' && $classes ) {
				$comment = Html::rawElement( 'span', [ 'class' => $classes ], $comment );
			}
		}

		if ( $comment === '' ) {
			return '';
		}

		return ( new Codex() )->message()
			->setType( 'notice' )
			->setContentHtml( new HtmlSnippet(
				Html::element(
					'strong',
					[],
					$this->msg( 'wikimediaantiabuse-special-abuse-review-edit-summary' )->text()
				) . ' ' . $comment,
				[]
			) )
			->setAttributes( [ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__summary' ] )
			->build()
			->getHtml();
	}

	private function buildChanges( Title $title, stdClass $row ): string {
		if ( !RevisionRecord::userCanBitfield(
			(int)$row->deleted,
			RevisionRecord::DELETED_TEXT,
			$this->getAuthority(),
			$title
		) ) {
			return '';
		}

		$revision = $this->lookUpRevision( $title, (int)$row->rev_id );
		if ( $revision === null ) {
			return '';
		}

		$header = $this->buildChangesHeader( $title, $row );

		// Both sides of a diff are derived from both revisions: the lines the edit left alone
		// are the parent's text as much as this revision's. Showing one side alone would let
		// the rest of a parent the viewer may not see be read off the side that was shown, so
		// core withholds the whole diff, and so must this preview.
		$parentId = $revision->getParentId();
		if ( $parentId ) {
			$parent = $this->lookUpRevision( $title, $parentId );
			if ( !$this->canSeeText( $parent, $title ) ) {
				return $header . $this->buildWithheldDiffNotice( $title, $row, $parent );
			}
		}

		$removedBlock = $this->renderDiffBlock(
			$this->revisionSnippetGenerator->getRemovedLines( $revision ) ?? '', '-', 'removed'
		);
		$addedBlock = $this->renderDiffBlock(
			$this->revisionSnippetGenerator->getAddedLines( $revision ) ?? '', '+', 'added'
		);
		$blocks = $removedBlock . $addedBlock;

		// The link stays even with nothing to preview, that being when it is most wanted.
		if ( $blocks === '' ) {
			return $header;
		}

		$pageLanguage = $title->getPageLanguage();
		return $header . Html::rawElement(
			'div',
			[
				// Struck as the row title is, so text already hidden from readers says so.
				'class' => array_merge(
					[ 'mw-wikimediaantiabuse-abuse-review-row__diff' ],
					$this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_TEXT )
				),
				'lang' => $pageLanguage->getHtmlCode(),
				'dir' => $pageLanguage->getDir(),
			],
			$blocks
		);
	}

	private function buildChangesHeader( Title $title, stdClass $row ): string {
		$label = Html::element(
			'strong',
			[],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-changes-made' )->text()
		);
		$fullDiffLink = Html::element(
			'a',
			[
				'class' => 'mw-wikimediaantiabuse-abuse-review-row__full-diff',
				'href' => $this->buildFullDiffUrl( $title, $row ),
				'target' => '_blank',
			],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-open-full-diff' )->text()
		);

		return Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__changes-header' ],
			$label . $fullDiffLink
		);
	}

	/**
	 * Returns core's own explanation for a diff it will not show, so a reviewer meets the same
	 * wording here as on the full diff this row links to. Either revision being suppressed picks
	 * the suppressed wording, as it does in core.
	 */
	private function buildWithheldDiffNotice( Title $title, stdClass $row, ?RevisionRecord $parent ): string {
		$suppressed = ( (int)$row->deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0
			|| ( $parent !== null && $parent->isDeleted( RevisionRecord::DELETED_RESTRICTED ) );

		return ( new Codex() )->message()
			->setType( 'warning' )
			->setContentHtml( new HtmlSnippet(
				// The page the deletion log is linked for, rather than this special page.
				$this->msg( $suppressed ? 'rev-suppressed-no-diff' : 'rev-deleted-no-diff' )
					->page( $title )
					->parse(),
				[]
			) )
			->setAttributes( [
				'class' => 'mw-wikimediaantiabuse-abuse-review-row__withheld-diff plainlinks',
			] )
			->build()
			->getHtml();
	}

	/**
	 * Where a row's full diff lives: on the page itself, or on Special:Undelete once the page
	 * has been deleted and its revisions have left the revision table.
	 */
	private function buildFullDiffUrl( Title $title, stdClass $row ): string {
		if ( !$this->isArchivedRow( $row ) ) {
			return $title->getLocalURL( [ 'diff' => 'prev', 'oldid' => $row->rev_id ] );
		}

		return SpecialPage::getTitleFor( 'Undelete' )->getLocalURL( $this->buildUndeleteQuery( $title, $row ) );
	}

	/**
	 * The classes core marks deleted content with, for content the viewer may still see.
	 *
	 * @return string[]
	 */
	private function visibilityClasses( int $deleted, int $field ): array {
		if ( ( $deleted & $field ) === 0 ) {
			return [];
		}

		$classes = [ 'history-deleted' ];
		if ( ( $deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0 ) {
			$classes[] = 'mw-history-suppressed';
		}
		return $classes;
	}

	/**
	 * A revision from whichever table holds it: the revisions of a deleted page are in the
	 * archive table, and a partial undeletion can leave one page's history split across both.
	 *
	 * The archive table is only read if the viewer has the deletedtext right.
	 */
	private function lookUpRevision( Title $title, int $revisionId ): ?RevisionRecord {
		$revision = $this->revisionStore->getRevisionById( $revisionId );
		if ( $revision !== null ) {
			return $revision;
		}

		if ( !$this->getAuthority()->isAllowed( 'deletedtext' ) ) {
			return null;
		}

		return $this->archivedRevisionLookup->getArchivedRevisionRecord( $title, $revisionId );
	}

	/**
	 * Whether the viewer may see a revision's text.
	 *
	 * A revision that could not be loaded counts as one they may not: RevisionSnippetGenerator
	 * falls back to the primary and then to the archive table, so it can still diff against a
	 * parent this gate did not find, and a parent it cannot see is one it must not permit.
	 */
	private function canSeeText( ?RevisionRecord $revision, Title $title ): bool {
		return $revision !== null && RevisionRecord::userCanBitfield(
			$revision->getVisibility(),
			RevisionRecord::DELETED_TEXT,
			$this->getAuthority(),
			$title
		);
	}

	private function renderDiffBlock( string $lines, string $prefix, string $modifier ): string {
		if ( $lines === '' ) {
			return '';
		}

		$allLines = explode( "\n", $lines );
		$shownLines = array_slice( $allLines, 0, self::MAX_DIFF_LINES );
		$text = implode(
			"\n",
			array_map( static fn ( string $line ): string => "$prefix $line", $shownLines )
		);

		$remaining = count( $allLines ) - count( $shownLines );
		if ( $remaining > 0 ) {
			$text .= "\n" . $this->msg( 'wikimediaantiabuse-special-abuse-review-diff-truncated' )
				->numParams( $remaining )->text();
		}

		return Html::element(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__diff-' . $modifier ],
			$text
		);
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
				->joinComment()
				->clearFields()
				->select( [
					'title' => 'page_title',
					'namespace' => 'page_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'rev_deleted',
					'rev_id' => 'rev_id',
					'parent_id' => 'rev_parent_id',
					'timestamp' => 'rev_timestamp',
					'comment_text' => 'comment_rev_comment.comment_text',
					'comment_data' => 'comment_rev_comment.comment_data',
					'comment_cid' => 'comment_rev_comment.comment_id',
					'is_archive' => '0',
				] );
		} else {
			$queryBuilder = $this->revisionStore->newArchiveSelectQueryBuilder( $this->getDatabase() )
				->joinComment()
				->clearFields()
				->select( [
					'title' => 'ar_title',
					'namespace' => 'ar_namespace',
					'user' => 'actor_user',
					'user_text' => 'actor_name',
					'deleted' => 'ar_deleted',
					'rev_id' => 'ar_rev_id',
					'parent_id' => 'ar_parent_id',
					'timestamp' => 'ar_timestamp',
					'comment_text' => 'comment_ar_comment.comment_text',
					'comment_data' => 'comment_ar_comment.comment_data',
					'comment_cid' => 'comment_ar_comment.comment_id',
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
		foreach ( $this->mResult as $row ) {
			$lb->addUser( new UserIdentityValue( (int)$row->user, $row->user_text ) );
		}

		$lb->execute();

		$this->formattedComments = $this->rowCommentFormatter->formatRows(
			$this->mResult, 'comment', 'namespace', 'title', 'rev_id'
		);
	}

	/** @inheritDoc */
	protected function getRowClass( $row ): string {
		return 'mw-wikimediaantiabuse-abuse-review-row';
	}

	/** @inheritDoc */
	protected function getTableClass(): string {
		return 'cdx-table__table mw-wikimediaantiabuse-abuse-review-table';
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
	 * Whether the row comes from the archive table, its page having been deleted.
	 *
	 * @param stdClass $row
	 * @return bool
	 */
	private function isArchivedRow( stdClass $row ): bool {
		// A select-list literal, so the database hands it back as the string '0' or '1'.
		return (int)$row->is_archive !== 0;
	}

	/**
	 * Whether the revision on this row has been handled by suppressing its text.
	 *
	 * This matches the suppression check in {@link self::getQueryInfo}, which by default
	 * also hides revisions marked as needing no further action.
	 *
	 * @param stdClass $row
	 * @return bool
	 */
	private function isSuppressedRow( stdClass $row ): bool {
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
	 * @param string $tag
	 * @param string $variantClass Marks which of the row's tag states this chip shows
	 * @param bool $hidden Whether the chip does not match the row's current state
	 * @return string
	 */
	private function renderTag( string $tag, string $variantClass, bool $hidden ): string {
		$classes = [ 'mw-wikimediaantiabuse-abuse-review-tag', $variantClass ];
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
		return self::TIMESTAMP_FIELD;
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field === self::TIMESTAMP_FIELD;
	}

	/**
	 * Keep the pager bar even when everything fits on one page, so the rows-per-page
	 * control stays available.
	 *
	 * @inheritDoc
	 */
	protected function isNavigationBarShown(): bool {
		return $this->getNumRows() > 0;
	}
}

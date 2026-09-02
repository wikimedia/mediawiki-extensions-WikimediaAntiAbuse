<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Diff\DifferenceEngine;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewPagerNavigationBuilder;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Navigation\CodexPagerNavigationBuilder;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use stdClass;
use Wikimedia\Codex\Component\HtmlSnippet;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLExpression;

class AbuseReviewPager extends CodexTablePager {

	private const string TARGET_FIELD = 'target';
	private const string FLAGS_FIELD = 'flags';
	private const string TIMESTAMP_FIELD = 'timestamp';
	private const string DETAILS_FIELD = 'details';
	private const array ROW_DATA_FIELDS = [ self::TARGET_FIELD, self::FLAGS_FIELD, self::TIMESTAMP_FIELD ];

	private const int MAX_DIFF_BYTES = 8192;

	/** @var bool Whether a row has been rendered yet; used to make first row expanded by default */
	private bool $rowRendered = false;

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
		private readonly array $tagsFilter,
		private readonly bool $includeHandledRevisions,
		private readonly array $usernamesFilter,
		private readonly int $numberOfFiltersApplied,
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
			self::TARGET_FIELD =>
				$this->msg( 'wikimediaantiabuse-special-abuse-review-heading-revision' )->text(),
			self::FLAGS_FIELD =>
				$this->msg( 'wikimediaantiabuse-special-abuse-review-heading-flags' )->text(),
			self::TIMESTAMP_FIELD =>
				$this->msg( 'wikimediaantiabuse-special-abuse-review-heading-timestamp' )->text(),
			self::DETAILS_FIELD => '',
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
	 * A `details` element cannot span table cells, so each row is one spanning cell
	 * with the columns laid out inside the row's `summary`.
	 *
	 * @inheritDoc
	 */
	public function formatRow( $row ): string {
		$this->mCurrentRow = $row;

		$fields = array_keys( $this->getFieldNames() );
		$columns = '';
		foreach ( $fields as $field ) {
			$value = $row->$field ?? null;
			$columns .= Html::rawElement(
				'div',
				$this->getCellAttrs( $field, $value ),
				$this->formatValue( $field, $value )
			);
		}

		$detailsAttribs = [ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__details' ];
		if ( !$this->rowRendered ) {
			$detailsAttribs['open'] = '';
		}
		$this->rowRendered = true;

		$details = Html::rawElement(
			'details',
			$detailsAttribs,
			Html::rawElement( 'summary', [], $columns ) . $this->buildRowContent( $row )
		);

		return Html::rawElement(
			'tr',
			$this->getRowAttrs( $row ),
			Html::rawElement(
				'td',
				[
					'class' => 'mw-wikimediaantiabuse-abuse-review-row__cell',
					'colspan' => count( $fields ),
				],
				$details
			)
		) . "\n";
	}

	/**
	 * The value is not used.
	 *
	 * @inheritDoc
	 */
	public function formatValue( $name, $value ): string {
		if ( $name === self::DETAILS_FIELD ) {
			return $this->buildToggle();
		}

		if ( !in_array( $name, self::ROW_DATA_FIELDS, true ) ) {
			throw new InvalidArgumentException( "Unable to format $name" );
		}

		$row = $this->mCurrentRow;
		$title = Title::makeTitle( $row->namespace, $row->title );

		return match ( $name ) {
			self::TARGET_FIELD => $this->buildTarget( $title, $row ),
			self::FLAGS_FIELD => $this->buildFlags( $row ),
			self::TIMESTAMP_FIELD => $this->buildTimestamp( $title, $row ),
		};
	}

	private function buildRowContent( stdClass $row ): string {
		$title = Title::makeTitle( $row->namespace, $row->title );

		return Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__content' ],
			$this->buildEditSummary( $title, $row )
				. $this->buildChanges( $title, $row )
				. $this->buildRevisionActions( $title, $row )
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
	 * The title is this column's primary link. Strike the title of a revision whose text
	 * is deleted, doubly for a suppressed one, so a reviewer can see what has already
	 * been hidden without opening the row.
	 */
	private function buildTarget( Title $title, stdClass $row ): string {
		$pageClasses = array_merge(
			[ 'mw-wikimediaantiabuse-abuse-review-row__page' ],
			$this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_TEXT )
		);

		return Html::rawElement(
			'span',
			[ 'class' => $pageClasses ],
			$this->buildPageLink( $title, $row )
		) . Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__author' ],
			$this->buildAuthor( $title, $row )
		);
	}

	private function buildFlags( stdClass $row ): string {
		$tag = $this->getFirstReviewableTag( $row->ts_tags );
		if ( $tag === null ) {
			return '';
		}

		$isFalsePositive = $this->rowHasVerdictTag( $row->ts_tags, $tag, 'falsePositive' );
		$isNoFurtherAction = $this->rowHasVerdictTag( $row->ts_tags, $tag, 'noFurtherAction' );

		$isSuppressed = $this->isSuppressedRow( $row );
		$mountPoint = Html::rawElement(
			'span',
			[
				'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts-app',
				'data-verdicts' => json_encode( [
					'tag' => $tag,
					'isFalsePositive' => $isFalsePositive,
					'isNoFurtherAction' => $isNoFurtherAction,
					'isSuppressed' => $isSuppressed,
				], JSON_THROW_ON_ERROR ),
			],
			$this->buildVerdictButtons(
				(int)$row->rev_id,
				$isFalsePositive,
				$isNoFurtherAction,
				$isSuppressed,
				// The first row arrives open, all others do not
				!$this->rowRendered
			)
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__tags' ],
			$this->getTagDescription( $tag )
		) . $mountPoint;
	}

	private function buildVerdictButtons(
		int $revId,
		bool $isFalsePositive,
		bool $isNoFurtherAction,
		bool $isSuppressed,
		bool $isOpen
	): string {
		// A suppressed revision takes no new verdict, but one it holds can be cleared.
		$suppressedBlocksMark = $isSuppressed && !$isFalsePositive && !$isNoFurtherAction;
		// A reviewer judges an edit only after seeing it, so a closed row takes no verdict.
		$rowRefuses = $suppressedBlocksMark || !$isOpen;

		$noteMessage = null;
		if ( $suppressedBlocksMark ) {
			$noteMessage = 'wikimediaantiabuse-special-abuse-review-already-suppressed-note';
		} elseif ( !$isOpen ) {
			$noteMessage = 'wikimediaantiabuse-special-abuse-review-closed-row-note';
		}

		$note = '';
		$noteId = null;
		if ( $noteMessage !== null ) {
			$noteId = 'mw-wikimediaantiabuse-abuse-review-disabled-note-' . $revId;
			$note = Html::element(
				'span',
				[ 'id' => $noteId, 'class' => 'mw-wikimediaantiabuse-abuse-review-disabled-note' ],
				$this->msg( $noteMessage )->text()
			);
		}

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts' ],
			$this->buildVerdictButton(
				'no-further-action',
				$isNoFurtherAction,
				$rowRefuses,
				$isFalsePositive,
				$noteId,
				$noteMessage
			) . $this->buildVerdictButton(
				'false-positive',
				$isFalsePositive,
				$rowRefuses,
				$isNoFurtherAction,
				$noteId,
				$noteMessage
			) . $note
		);
	}

	/**
	 * @param string $verdict
	 * @param bool $pressed Whether the row holds this verdict
	 * @param bool $rowRefuses Whether the row itself refuses it, which the note explains
	 * @param bool $otherVerdictHeld Whether the row holds the other verdict instead
	 * @param string|null $noteId
	 * @param string|null $noteMessage
	 * @return string
	 */
	private function buildVerdictButton(
		string $verdict,
		bool $pressed,
		bool $rowRefuses,
		bool $otherVerdictHeld,
		?string $noteId,
		?string $noteMessage
	): string {
		$disabled = $rowRefuses || $otherVerdictHeld;
		$label = $this->msg(
			'wikimediaantiabuse-special-abuse-review-action-'
				. ( $pressed ? 'unmark-' : 'mark-' ) . $verdict
		)->text();

		$attribs = [
			'type' => 'button',
			'aria-pressed' => $pressed ? 'true' : 'false',
			'aria-label' => $label,
			'title' => $rowRefuses && $noteMessage !== null
				? $this->msg( $noteMessage )->text()
				: $label,
			'class' => [
				'cdx-toggle-button',
				'cdx-toggle-button--framed',
				$pressed ? 'cdx-toggle-button--toggled-on' : 'cdx-toggle-button--toggled-off',
				'cdx-toggle-button--icon-only',
				'cdx-toggle-button--size-small',
			],
		];
		if ( $disabled ) {
			$attribs['disabled'] = true;
		}
		if ( $rowRefuses && $noteId !== null ) {
			$attribs['aria-describedby'] = $noteId;
		}

		return Html::rawElement( 'button', $attribs, Html::element( 'span', [
			'class' => [
				'cdx-icon',
				'cdx-icon--medium',
				'mw-wikimediaantiabuse-abuse-review-verdict-icon',
				'mw-wikimediaantiabuse-abuse-review-verdict-icon--' . $verdict,
			],
		] ) );
	}

	private function buildToggle(): string {
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

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__toggle' ],
			$showLabel . $hideLabel
		);
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

		$heading = Html::element(
			'h4',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions-heading' ],
			$this->msg( 'wikimediaantiabuse-special-abuse-review-revision-actions-heading' )->text()
		);
		$container = Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions' ],
			$links
		);

		return $heading . $container;
	}

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

		return ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )->message()
			->setType( 'notice' )
			->setContent( new HtmlSnippet(
				Html::element(
					'strong',
					[],
					$this->msg( 'wikimediaantiabuse-special-abuse-review-edit-summary' )->text()
				) . Html::rawElement( 'div', [], $comment )
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

		$parent = null;
		$parentId = $revision->getParentId();
		if ( $parentId ) {
			$parent = $this->lookUpRevision( $title, $parentId );
			if ( !$this->canSeeText( $parent, $title ) ) {
				return $header . $this->buildWithheldDiffNotice( $title, $row, $parent );
			}
		}

		$diff = $this->buildDiffTable( $title, $revision, $parent );
		if ( strlen( $diff ) > self::MAX_DIFF_BYTES ) {
			return $header . $this->buildOversizeDiffNotice();
		}

		// The link stays even with nothing to preview, that being when it is most wanted.
		if ( $diff === '' ) {
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
			$diff
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

		return ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )->message()
			->setType( 'warning' )
			->setContent( new HtmlSnippet(
				// The page the deletion log is linked for, rather than this special page.
				$this->msg( $suppressed ? 'rev-suppressed-no-diff' : 'rev-deleted-no-diff' )
					->page( $title )
					->parse()
			) )
			->setAttributes( [
				'class' => 'mw-wikimediaantiabuse-abuse-review-row__withheld-diff plainlinks',
			] )
			->build()
			->getHtml();
	}

	private function buildOversizeDiffNotice(): string {
		return ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )->message()
			->setType( 'notice' )
			->setContent( $this->msg( 'wikimediaantiabuse-special-abuse-review-diff-too-large' )->text() )
			->setAttributes( [ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__oversize-diff' ] )
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

	private function canSeeText( ?RevisionRecord $revision, Title $title ): bool {
		return $revision !== null && RevisionRecord::userCanBitfield(
			$revision->getVisibility(),
			RevisionRecord::DELETED_TEXT,
			$this->getAuthority(),
			$title
		);
	}

	/** @return string Empty string when there is no diff to show */
	private function buildDiffTable( Title $title, RevisionRecord $revision, ?RevisionRecord $parent ): string {
		if ( $parent === null ) {
			$content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::RAW );
			if ( $content === null ) {
				return '';
			}
			$parent = new MutableRevisionRecord( $title );
			$parent->setContent( SlotRecord::MAIN, $content->getContentHandler()->makeEmptyContent() );
		}

		$context = new DerivativeContext( $this->getContext() );
		$context->setTitle( $title );

		$differenceEngine = new DifferenceEngine( $context );
		$differenceEngine->setSlotDiffOptions( [ 'diff-type' => 'inline' ] );
		$differenceEngine->setRevisions( $parent, $revision );

		$body = $differenceEngine->getDiffBody();
		if ( !$body ) {
			return '';
		}

		return $differenceEngine->addHeader( $body, '', '' );
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

		if ( $this->usernamesFilter ) {
			$queryBuilder->where( $this->getDatabase()->expr( 'actor_name', '=', $this->usernamesFilter ) );
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
		$tableClasses = [
			'mw-wikimediaantiabuse-abuse-review-table',
		];
		if ( $this->isNavigationBarShown() ) {
			$tableClasses[] = 'mw-wikimediaantiabuse-abuse-review-table-with-navigation-bar';
		}
		return parent::getTableClass() . ' ' . implode( ' ', $tableClasses );
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

	/**
	 * Renders the button used to open the filters dialog along with the table caption
	 *
	 * @inheritDoc
	 */
	protected function getHeader(): string {
		$tableCaption = Html::element(
			'div',
			[ 'class' => 'cdx-table__header__caption', 'aria-hidden' => 'true' ],
			$this->mCaption
		);

		return Html::rawElement(
			'div',
			[ 'class' => 'cdx-table__header' ],
			$tableCaption . $this->getNavigationBuilder()->getFilterButton()
		);
	}

	protected function createNavigationBuilder(): CodexPagerNavigationBuilder {
		$builder = new AbuseReviewPagerNavigationBuilder(
			$this->getContext(),
			$this->getRequest()->getValues(),
			$this->numberOfFiltersApplied
		);
		$builder->setNavClass( $this->getNavClass() );
		return $builder;
	}

	/**
	 * @return AbuseReviewPagerNavigationBuilder
	 */
	public function getNavigationBuilder(): AbuseReviewPagerNavigationBuilder {
		// @phan-suppress-next-line PhanTypeMismatchReturnSuperType
		return parent::getNavigationBuilder();
	}
}

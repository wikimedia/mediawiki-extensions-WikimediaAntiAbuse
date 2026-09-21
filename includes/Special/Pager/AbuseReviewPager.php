<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Diff\DifferenceEngine;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewLinkClickHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttributionFormatter;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictPerformerLookup;
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
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use stdClass;
use Wikimedia\Codex\Component\HtmlSnippet;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Timestamp\ConvertibleTimestamp;

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

	/** @var string[] Formatted edit summaries, keyed by revision ID */
	private array $formattedComments = [];

	/** @var array<int,UserIdentity> The performer for each revision ID */
	private array $verdictPerformers = [];

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly AbuseReviewVerdictPerformerLookup $verdictPerformerLookup,
		private readonly AbuseReviewVerdictAttributionFormatter $verdictAttributionFormatter,
		private readonly string $abuseReviewTag,
		private readonly bool $includeFalsePositives,
		private readonly bool $includeHandledRevisions,
		private readonly array $usernamesFilter,
		private readonly array $revisionsFilter,
		private readonly array $pagesFilter,
		private readonly int $delayMinutes,
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
				$this->msg( 'wikimediaantiabuse-special-abuse-review-heading-flag' )->text(),
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
		if ( !$this->rowRendered || $this->revisionsFilter ) {
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
		);
	}

	/**
	 * HTML containing a link to the diff for a revision.
	 * If the viewer does not have access to view the revision, no link is returned.
	 * Otherwise, the visibility classes for deleted/suppressed are added.
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
		} else {
			[ $target, $query ] = $this->diffLinkTarget(
				$title,
				$row,
				AbuseReviewLinkClickHandler::SUBTYPE_TIMESTAMP
			);
			$dateLink = $this->getLinkRenderer()->makeKnownLink( $target, $timestamp, [], $query );
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
		$heldVerdict = $this->heldVerdict( $row, $this->abuseReviewTag );
		$isHandledOutsideAbuseReview = $this->isHandledOutsideAbuseReview( $row );
		$attributionHtml = $this->verdictAttributionFormatter->formatFor(
			$this->getContext(),
			$this->verdictPerformers,
			(int)$row->rev_id,
			$heldVerdict !== null
		);
		$bylineHtml = $this->buildByline( $attributionHtml );
		$isOpen = !$this->rowRendered || $this->revisionsFilter;
		// @phan-suppress-next-line SecurityCheck-DoubleEscaped
		$mountPoint = Html::rawElement(
			'span',
			[
				'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts-app',
				'data-verdicts' => json_encode( [
					'tag' => $this->abuseReviewTag,
					'isFalsePositive' => $heldVerdict === 'falsePositive',
					'isNoFurtherAction' => $heldVerdict === 'noFurtherAction',
					'isHandledOutsideAbuseReview' => $isHandledOutsideAbuseReview,
					'attributionHtml' => $attributionHtml,
				], JSON_THROW_ON_ERROR ),
			],
			$heldVerdict === null
				? $this->buildVerdictButtons(
					(int)$row->rev_id,
					$this->abuseReviewTag,
					$isHandledOutsideAbuseReview,
					$isOpen,
					$bylineHtml
				) : $this->buildHeldVerdict( $heldVerdict, $bylineHtml )
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__tags' ],
			$this->buildFlagChip( $this->abuseReviewTag )
		) . $mountPoint;
	}

	private function buildVerdictButtons(
		int $revId,
		string $tag,
		bool $isHandledOutsideAbuseReview,
		bool $isOpen,
		string $bylineHtml
	): string {
		// A reviewer judges an edit only after seeing it, so a closed row takes no verdict.
		$rowRefuses = $isHandledOutsideAbuseReview || !$isOpen;

		$noteMessage = null;
		if ( $isHandledOutsideAbuseReview ) {
			$noteMessage = 'wikimediaantiabuse-special-abuse-review-handled-outside-abuse-review-' . $tag;
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

		$controls = Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-verdict-controls' ],
			$this->buildVerdictButton( 'no-further-action', $rowRefuses, $noteId, $noteMessage )
				. $this->buildVerdictButton( 'false-positive', $rowRefuses, $noteId, $noteMessage )
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts' ],
			$controls
				. $note
				. $bylineHtml
		);
	}

	private function buildFlagChip( string $tag ): string {
		// Generates:
		// * wikimediaantiabuse-special-abuse-review-flag-chip-mw-private-personal-info
		// * wikimediaantiabuse-special-abuse-review-flag-chip-mw-private-vandalism
		$label = $this->msg( 'wikimediaantiabuse-special-abuse-review-flag-chip-' . $tag )->text();

		return ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )
			->infoChip()
			->setStatus( 'notice' )
			->setText( $label )
			->getHtml();
	}

	private function buildHeldVerdict( string $heldVerdict, string $bylineHtml ): string {
		$chipLabelMsgKey = match ( $heldVerdict ) {
			'falsePositive' => 'wikimediaantiabuse-special-abuse-review-verdict-chip-false-positive',
			'noFurtherAction' => 'wikimediaantiabuse-special-abuse-review-verdict-chip-no-further-action',
		};
		$chipHtml = ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )
			->infoChip()
			->setStatus( $heldVerdict === 'falsePositive' ? 'warning' : 'success' )
			->setText( $this->msg( $chipLabelMsgKey )->text() )
			->getHtml();

		return Html::rawElement(
			'span',
			[
				'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts',
				'data-verdict-held' => $heldVerdict,
			],
			$chipHtml . $bylineHtml
		);
	}

	private function buildByline( ?string $attributionHtml ): string {
		if ( $attributionHtml === null ) {
			return '';
		}

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-verdict-performer' ],
			$attributionHtml
		);
	}

	/**
	 * @param string $verdict
	 * @param bool $rowRefuses Whether the row itself refuses it, which the note explains
	 * @param string|null $noteId
	 * @param string|null $noteMessage
	 * @return string
	 */
	private function buildVerdictButton(
		string $verdict,
		bool $rowRefuses,
		?string $noteId,
		?string $noteMessage
	): string {
		$label = $this->msg( 'wikimediaantiabuse-special-abuse-review-action-mark-' . $verdict )->text();

		$attribs = [
			'type' => 'button',
			'aria-label' => $label,
			'title' => $rowRefuses && $noteMessage !== null
				? $this->msg( $noteMessage )->text()
				: $label,
			'class' => [
				'cdx-button',
				'cdx-button--action-default',
				'cdx-button--weight-normal',
				'cdx-button--size-small',
				'cdx-button--framed',
				'cdx-button--icon-only',
			],
		];
		if ( $rowRefuses ) {
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
		$query = $this->linkClickQuery( AbuseReviewLinkClickHandler::SUBTYPE_PAGE_TITLE, $row );
		if ( !$this->isArchivedRow( $row ) ) {
			return $this->getLinkRenderer()->makeKnownLink( $title, null, [], $query );
		}

		return $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleValueFor( 'Undelete', $title->getPrefixedDBkey() ),
			$title->getPrefixedText(),
			[],
			$query
		);
	}

	/**
	 * The parameters that name a link click, which the page the link opens reports.
	 *
	 * @return array<string,string|int>
	 */
	private function linkClickQuery( string $subtype, stdClass $row ): array {
		return [
			AbuseReviewLinkClickHandler::SUBTYPE_PARAM => $subtype,
			AbuseReviewLinkClickHandler::REVISION_PARAM => $row->rev_id,
		];
	}

	/** @return array<string,string> Query parameters addressing an archived revision's diff on Special:Undelete */
	private function buildUndeleteQuery( Title $title, stdClass $row ): array {
		return [ 'target' => $title->getPrefixedText(), 'timestamp' => $row->timestamp, 'diff' => 'prev' ];
	}

	/**
	 * Page and query for the diff of a given revision. If the page has been deleted, it goes
	 * to Special:Undelete, otherwise it links to the page
	 *
	 * @return array{0:Title,1:array<string,string|int>}
	 */
	private function diffLinkTarget( Title $title, stdClass $row, string $subtype ): array {
		$query = $this->linkClickQuery( $subtype, $row );
		if ( !$this->isArchivedRow( $row ) ) {
			return [ $title, array_merge( [ 'diff' => 'prev', 'oldid' => $row->rev_id ], $query ) ];
		}

		return [
			SpecialPage::getTitleFor( 'Undelete' ),
			array_merge( $this->buildUndeleteQuery( $title, $row ), $query ),
		];
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

	private function buildFullDiffUrl( Title $title, stdClass $row ): string {
		[ $target, $query ] = $this->diffLinkTarget(
			$title,
			$row,
			AbuseReviewLinkClickHandler::SUBTYPE_FULL_DIFF
		);

		return $target->getLocalURL( $query );
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
					'timestamp' => 'ar_timestamp',
					'comment_text' => 'comment_ar_comment.comment_text',
					'comment_data' => 'comment_ar_comment.comment_data',
					'comment_cid' => 'comment_ar_comment.comment_id',
					'is_archive' => '1',
				] );
		}

		// If no abuse review tag is defined or if it's not a valid reviewable tag, then show no rows as the
		// false positive and handled revisions filters won't work
		if ( !array_key_exists( $this->abuseReviewTag, ChangeTagsHandler::REVIEWABLE_TAGS ) ) {
			$queryBuilder->where( '1=0' );
			return $queryBuilder->getQueryInfo();
		}

		$tagsFilter = [ $this->abuseReviewTag ];
		if ( $this->includeFalsePositives ) {
			$tagsFilter[] = ChangeTagsHandler::REVIEWABLE_TAGS[$this->abuseReviewTag]['falsePositive'];
		}
		$this->changeTagsStore->addTagsToDisplayQuery( $queryBuilder, $table, $this->getAuthority(), $tagsFilter );

		$revIdField = $table === 'revision' ? 'rev_id' : 'ar_rev_id';

		if ( !$this->includeHandledRevisions ) {

			if ( $this->abuseReviewTag === ChangeTagsHandler::PERSONAL_INFO_TAG ) {
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
			if ( $this->abuseReviewTag === ChangeTagsHandler::VANDALISM_TAG ) {
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
				ChangeTagsHandler::REVIEWABLE_TAGS[$this->abuseReviewTag]['noFurtherAction']
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

		if ( $this->usernamesFilter ) {
			$queryBuilder->where( $this->getDatabase()->expr( 'actor_name', '=', $this->usernamesFilter ) );
		}

		if ( $this->revisionsFilter ) {
			$queryBuilder->where( $this->getDatabase()->expr( $revIdField, '=', $this->revisionsFilter ) );
		}

		if ( $this->pagesFilter ) {
			$titleField = $table === 'revision' ? 'page_title' : 'ar_title';
			$namespaceField = $table === 'revision' ? 'page_namespace' : 'ar_namespace';
			$queryBuilder->where( $this->getDatabase()->orExpr( array_map(
				fn ( Title $title ) => $this->getDatabase()->andExpr( [
					$this->getDatabase()->expr( $titleField, '=', $title->getDBkey() ),
					$this->getDatabase()->expr( $namespaceField, '=', $title->getNamespace() )
				] ),
				$this->pagesFilter
			) ) );
		}

		if ( $this->delayMinutes > 0 && !$this->revisionsFilter ) {
			$timestampField = $table === 'revision' ? 'rev_timestamp' : 'ar_timestamp';
			$cutoff = $this->getDatabase()->timestamp(
				ConvertibleTimestamp::time() - $this->delayMinutes * 60
			);
			$queryBuilder->where( $this->getDatabase()->expr( $timestampField, '<', $cutoff ) );
		}

		return $queryBuilder->getQueryInfo();
	}

	/** @inheritDoc */
	protected function doBatchLookups(): void {
		parent::doBatchLookups();

		$lb = $this->linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
		$revisionIds = [];
		foreach ( $this->mResult as $row ) {
			$lb->addUser( new UserIdentityValue( (int)$row->user, $row->user_text ) );
			$revisionIds[] = (int)$row->rev_id;
		}

		$performers = $this->verdictPerformerLookup->lookUpPerformers(
			$revisionIds,
			$this->abuseReviewTag,
			$this->getAuthority()
		);
		$this->verdictPerformers = $performers;
		foreach ( $performers as $performer ) {
			$lb->addUser( $performer );
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
	 * Returns the verdict held for the row, or `null` if no verdict has been applied.
	 */
	private function heldVerdict( stdClass $row, string $tag ): ?string {
		if ( $this->rowHasVerdictTag( $row->ts_tags, $tag, 'falsePositive' ) ) {
			return 'falsePositive';
		}
		if ( $this->rowHasVerdictTag( $row->ts_tags, $tag, 'noFurtherAction' ) ) {
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
	private function isArchivedRow( stdClass $row ): bool {
		// A select-list literal, so the database hands it back as the string '0' or '1'.
		return (int)$row->is_archive !== 0;
	}

	/**
	 * Whether the revision on this row has been handled outside AbuseReview.
	 * For the personal info tag this is if the content is suppressed, and for the vandalism tag this edit has been
	 * reverted.
	 *
	 * This should match the checks in {@link self::getQueryInfo} that exclude handled rows based on
	 * these checks. If updating this method, make sure to update there too.
	 *
	 * @param stdClass $row
	 * @return bool
	 */
	private function isHandledOutsideAbuseReview( stdClass $row ): bool {
		if ( $this->abuseReviewTag === ChangeTagsHandler::PERSONAL_INFO_TAG ) {
			$deleted = (int)$row->deleted;
			return ( $deleted & RevisionRecord::DELETED_TEXT ) !== 0
				&& ( $deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0;
		} else {
			return in_array( ChangeTags::TAG_REVERTED, $this->splitTags( $row->ts_tags ), true );
		}
	}

	/**
	 * @param string|null $tsTags Comma-separated tags from a row's ts_tags field
	 * @return string[]
	 */
	private function splitTags( ?string $tsTags ): array {
		return $tsTags !== null && $tsTags !== '' ? explode( ',', $tsTags ) : [];
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

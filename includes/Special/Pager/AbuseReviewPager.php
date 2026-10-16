<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Diff\DifferenceEngine;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewLinkClickHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttributionFormatter;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictPerformerLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewPagerNavigationBuilder;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Navigation\CodexPagerNavigationBuilder;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Pager\IndexPager;
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
use Wikimedia\Rdbms\IResultWrapper;

class AbuseReviewPager extends CodexTablePager {

	private const string TARGET_FIELD = 'target';
	private const string FLAGS_FIELD = 'flags';
	private const string TIMESTAMP_FIELD = 'timestamp';
	private const string DETAILS_FIELD = 'details';
	private const array ROW_DATA_FIELDS = [ self::TARGET_FIELD, self::FLAGS_FIELD, self::TIMESTAMP_FIELD ];

	private const int MAX_DIFF_BYTES = 8192;

	public const array HANDLED_REASON_MESSAGES = [
		AbuseReviewQueueLookup::HANDLED_REASON_SUPPRESSED =>
			'wikimediaantiabuse-special-abuse-review-handled-outside-abuse-review-mw-private-personal-info',
		AbuseReviewQueueLookup::HANDLED_REASON_PAGE_DELETED =>
			'wikimediaantiabuse-special-abuse-review-handled-page-deleted',
		AbuseReviewQueueLookup::HANDLED_REASON_REVISION_DELETED =>
			'wikimediaantiabuse-special-abuse-review-handled-revision-deleted',
		AbuseReviewQueueLookup::HANDLED_REASON_REVERTED =>
			'wikimediaantiabuse-special-abuse-review-handled-reverted',
	];

	/** @var bool Whether a row has been rendered yet; used to make first row expanded by default */
	private bool $rowRendered = false;

	/** @var true Always default to paging in a descending order */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/** @var string[] Formatted edit summaries, keyed by revision ID */
	private array $formattedComments = [];

	/** @var array<int,UserIdentity> The performer for each revision ID */
	private array $verdictPerformers = [];

	/** @var int[] Revision IDs that the next lookup also loads */
	private array $revisionIdsToLookUp = [];

	/** @var array<int,?RevisionRecord> Looked-up revisions by revision ID, null if not found */
	private array $revisions = [];

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly AbuseReviewQueueLookup $queueLookup,
		private readonly RevisionStore $revisionStore,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly AbuseReviewVerdictPerformerLookup $verdictPerformerLookup,
		private readonly AbuseReviewVerdictAttributionFormatter $verdictAttributionFormatter,
		private readonly AbuseReviewQueueFilters $filters,
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
		if ( !$this->rowRendered || $this->filters->revisionIds ) {
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
	 * HTML containing a link to the revision along with whether the revision is current.
	 *
	 * If the viewer does not have access to view the revision, no link is returned.
	 * Otherwise, the visibility classes for deleted/suppressed are added.
	 */
	private function buildTimestamp( Title $title, stdClass $row ): string {
		$timestamp = $this->getLanguage()->userTimeAndDate( $row->timestamp, $this->getUser() );

		if ( !$this->canSeeRowText( $title, $row ) ) {
			$dateLink = htmlspecialchars( $timestamp );
		} else {
			[ $target, $query ] = $this->revisionLinkTarget(
				$title,
				$row,
				AbuseReviewLinkClickHandler::SUBTYPE_TIMESTAMP
			);
			$dateLink = $this->getLinkRenderer()->makeKnownLink( $target, $timestamp, [], $query );
		}

		$visibilityClasses = $this->visibilityClasses( (int)$row->deleted, RevisionRecord::DELETED_TEXT );
		if ( $visibilityClasses ) {
			$dateLink = Html::rawElement( 'span', [ 'class' => $visibilityClasses ], $dateLink );
		}

		return $dateLink . $this->buildCurrentRevisionMark( $row );
	}

	/**
	 * Marks a row whose flagged revision is the latest revision on the page, similar to
	 * Special:Contributions.
	 */
	private function buildCurrentRevisionMark( stdClass $row ): string {
		if ( !$this->isCurrentRevision( $row ) ) {
			return '';
		}

		return Html::element( 'span', [ 'class' => 'mw-uctop' ], $this->msg( 'uctop' )->text() );
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
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__page-line' ],
			Html::rawElement(
				'span',
				[ 'class' => $pageClasses ],
				$this->buildPageLink( $title, $row )
			) . $this->buildPageToolLinks( $title, $row )
		) . Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__author' ],
			$this->buildAuthor( $title, $row )
		);
	}

	private function buildFlags( stdClass $row ): string {
		$heldVerdict = $this->queueLookup->getVerdict( $row, $this->filters->abuseReviewTag );
		$handledOutsideAbuseReviewMessage = $this->getHandledOutsideAbuseReviewMessage( $row );
		$attributionHtml = $this->verdictAttributionFormatter->formatFor(
			$this->getContext(),
			$this->verdictPerformers,
			(int)$row->rev_id,
			$heldVerdict !== null
		);
		// @phan-suppress-next-line SecurityCheck-DoubleEscaped
		$mountPoint = Html::element(
			'span',
			[
				'class' => 'mw-wikimediaantiabuse-abuse-review-verdicts-app',
				'data-verdicts' => json_encode( [
					'tag' => $this->filters->abuseReviewTag,
					'isFalsePositive' => $heldVerdict === 'falsePositive',
					'isNoFurtherAction' => $heldVerdict === 'noFurtherAction',
					'handledOutsideAbuseReviewMessage' => $handledOutsideAbuseReviewMessage,
					'attributionHtml' => $attributionHtml,
				], JSON_THROW_ON_ERROR ),
			]
		);

		return Html::rawElement(
			'span',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__tags' ],
			$this->buildFlagChip( $this->filters->abuseReviewTag )
		) . $mountPoint;
	}

	private function buildFlagChip( string $tag ): string {
		// Generates:
		// * wikimediaantiabuse-special-abuse-review-flag-chip-mw-private-personal-info
		// * wikimediaantiabuse-special-abuse-review-flag-chip-mw-private-vandalism
		// * wikimediaantiabuse-special-abuse-review-flag-chip-mw-private-link-spam
		$label = $this->msg( 'wikimediaantiabuse-special-abuse-review-flag-chip-' . $tag )->text();

		return ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )
			->infoChip()
			->setStatus( 'notice' )
			->setText( $label )
			->getHtml();
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
		if ( !$this->queueLookup->isArchivedRow( $row ) ) {
			return $this->getLinkRenderer()->makeKnownLink( $title, null, [], $query );
		}

		return $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleValueFor( 'Undelete', $title->getPrefixedDBkey() ),
			$title->getPrefixedText(),
			[],
			$query
		);
	}

	private function buildPageToolLinks( Title $title, stdClass $row ): string {
		$links = [ $this->buildDiffLink( $title, $row ) ];
		// No history link is needed for deleted pages, since page title link shows the
		// history on Special:Undelete
		if ( !$this->queueLookup->isArchivedRow( $row ) ) {
			$links[] = $this->getLinkRenderer()->makeKnownLink(
				$title,
				$this->msg( 'hist' )->text(),
				[ 'class' => 'mw-changeslist-history' ],
				array_merge(
					[ 'action' => 'history' ],
					$this->linkClickQuery( AbuseReviewLinkClickHandler::SUBTYPE_PAGE_HISTORY, $row )
				)
			);
		}
		$rollbackLink = $this->buildRollbackLink( $title, $row );
		if ( $rollbackLink !== null ) {
			$links[] = $rollbackLink;
		}
		$undoLink = $this->buildUndoLink( $title, $row );
		if ( $undoLink !== null ) {
			$links[] = $undoLink;
		}
		$changeVisibilityLink = $this->buildChangeVisibilityLink( $title, $row );
		if ( $changeVisibilityLink !== null ) {
			$links[] = $changeVisibilityLink;
		}

		return ' ' . Html::rawElement(
			'span',
			[ 'class' => 'mw-changeslist-links' ],
			implode( '', array_map(
				static fn ( string $link ): string => Html::rawElement( 'span', [], $link ),
				$links
			) )
		);
	}

	private function buildRollbackLink( Title $title, stdClass $row ): ?string {
		if ( $this->filters->abuseReviewTag !== ChangeTagsHandler::VANDALISM_TAG
			|| !$this->isCurrentRevision( $row )
			|| !(int)$row->parent_id
			|| $this->isHandledOutsideAbuseReview( $row )
			|| !$this->getAuthority()->probablyCan( 'edit', $title )
			|| !$this->getAuthority()->probablyCan( 'rollback', $title )
		) {
			return null;
		}

		$revision = $this->revisionStore->getRevisionById( (int)$row->rev_id );
		if ( $revision === null ) {
			return null;
		}

		// Returns an empty string when the author is the only contributor to the page.
		$rollbackLink = Linker::generateRollback( $revision, $this->getContext(), [ 'noBrackets' ] );
		return $rollbackLink !== '' ? $rollbackLink : null;
	}

	private function buildUndoLink( Title $title, stdClass $row ): ?string {
		if ( $this->filters->abuseReviewTag !== ChangeTagsHandler::VANDALISM_TAG
			|| $this->queueLookup->isArchivedRow( $row )
			|| !(int)$row->parent_id
			|| $this->isHandledOutsideAbuseReview( $row )
			|| ( (int)$row->deleted & RevisionRecord::DELETED_TEXT ) !== 0
			|| !$this->getAuthority()->probablyCan( 'edit', $title )
		) {
			return null;
		}

		// Same markup as the undo link of ChangeToolsFactory.
		return Html::rawElement(
			'span',
			[ 'class' => [ 'mw-history-undo', 'mw-change-tools-undo' ] ],
			$this->getLinkRenderer()->makeKnownLink(
				$title,
				$this->msg( 'editundo' )->text(),
				[],
				array_merge(
					[ 'action' => 'edit', 'undoafter' => $row->parent_id, 'undo' => $row->rev_id ],
					$this->linkClickQuery( AbuseReviewLinkClickHandler::SUBTYPE_UNDO, $row )
				)
			)
		);
	}

	private function buildChangeVisibilityLink( Title $title, stdClass $row ): ?string {
		// Special:RevisionDelete cannot hide the text of the current revision.
		if ( $this->filters->abuseReviewTag !== ChangeTagsHandler::PERSONAL_INFO_TAG
			|| $this->isCurrentRevision( $row )
			|| $this->isHandledOutsideAbuseReview( $row )
			|| !$this->getAuthority()->isAllowed( 'suppressrevision' )
		) {
			return null;
		}

		// A revision ID still finds the revision after the page is deleted.
		// The wpHide* parameters select the text and suppress checkboxes on the form.
		return $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleFor( 'Revisiondelete' ),
			$this->msg( 'rev-delundel' )->text(),
			[],
			array_merge(
				[
					'type' => 'revision',
					'target' => $title->getPrefixedDBkey(),
					'ids' => $row->rev_id,
					'wpHidePrimary' => 1,
					'wpHideRestricted' => 1,
				],
				$this->linkClickQuery( AbuseReviewLinkClickHandler::SUBTYPE_CHANGE_VISIBILITY, $row )
			)
		);
	}

	private function buildDiffLink( Title $title, stdClass $row ): string {
		$label = $this->msg( 'diff' )->text();

		if ( !$this->canSeeRowText( $title, $row ) ) {
			// Plain text, as is shown on Special:Contributions when the diff cannot be viewed.
			return htmlspecialchars( $label );
		}

		[ $target, $query ] = $this->diffLinkTarget(
			$title,
			$row,
			AbuseReviewLinkClickHandler::SUBTYPE_DIFF
		);

		return $this->getLinkRenderer()->makeKnownLink(
			$target,
			$label,
			[ 'class' => 'mw-changeslist-diff' ],
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
			AbuseReviewLinkClickHandler::TAG_PARAM => $this->filters->abuseReviewTag,
		];
	}

	/** @return array<string,string> Query parameters addressing an archived revision on Special:Undelete */
	private function buildUndeleteQuery( Title $title, stdClass $row ): array {
		return [ 'target' => $title->getPrefixedText(), 'timestamp' => $row->timestamp ];
	}

	/**
	 * Link for the flagged revision, or to Special:Undelete if the page was deleted.
	 *
	 * @return array{0:Title,1:array<string,string|int>}
	 */
	private function revisionLinkTarget( Title $title, stdClass $row, string $subtype ): array {
		$query = $this->linkClickQuery( $subtype, $row );
		if ( !$this->queueLookup->isArchivedRow( $row ) ) {
			return [ $title, array_merge( [ 'oldid' => $row->rev_id ], $query ) ];
		}

		return [
			SpecialPage::getTitleFor( 'Undelete' ),
			array_merge( $this->buildUndeleteQuery( $title, $row ), $query ),
		];
	}

	/**
	 * Page and query for the diff of a given revision. If the page has been deleted, it goes
	 * to Special:Undelete, otherwise it links to the page
	 *
	 * @return array{0:Title,1:array<string,string|int>}
	 */
	private function diffLinkTarget( Title $title, stdClass $row, string $subtype ): array {
		$query = $this->linkClickQuery( $subtype, $row );
		if ( !$this->queueLookup->isArchivedRow( $row ) ) {
			return [ $title, array_merge( [ 'diff' => 'prev', 'oldid' => $row->rev_id ], $query ) ];
		}

		return [
			SpecialPage::getTitleFor( 'Undelete' ),
			array_merge( $this->buildUndeleteQuery( $title, $row ), [ 'diff' => 'prev' ], $query ),
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
		if ( $visibilityClasses ) {
			$userLink = Html::rawElement( 'span', [ 'class' => $visibilityClasses ], $userLink );
		}

		return $userLink . Linker::userToolLinks( $author->getId(), $author->getName() );
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
		if ( !$this->canSeeRowText( $title, $row ) ) {
			return '';
		}

		$revision = $this->getRevision( (int)$row->rev_id );
		if ( $revision === null ) {
			return '';
		}

		$header = $this->buildChangesHeader();

		$parent = null;
		$parentId = $revision->getParentId();
		if ( $parentId ) {
			$parent = $this->getRevision( $parentId );
			if ( !$this->canSeeText( $parent, $title ) ) {
				return $header . $this->buildWithheldDiffNotice( $title, $row, $parent );
			}
		}

		$diff = $this->buildDiffTable( $title, $revision, $parent );
		if ( strlen( $diff ) > self::MAX_DIFF_BYTES ) {
			return $header . $this->buildOversizeDiffNotice();
		}

		if ( $diff === '' ) {
			return '';
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

	private function buildChangesHeader(): string {
		return Html::rawElement(
			'div',
			[ 'class' => 'mw-wikimediaantiabuse-abuse-review-row__changes-header' ],
			Html::element(
				'strong',
				[],
				$this->msg( 'wikimediaantiabuse-special-abuse-review-changes-made' )->text()
			)
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
	 * Revisions from whichever table holds them: the revisions of a deleted page are in the
	 * archive table, and a partial undeletion can leave one page's history split across both.
	 *
	 * The archive table is only read if the viewer has the deletedtext right.
	 *
	 * @param int[] $revisionIds
	 * @return array<int,?RevisionRecord> Keyed by revision ID
	 */
	private function lookUpRevisions( array $revisionIds ): array {
		$revisions = $this->revisionStore->newRevisionsFromBatch(
			$this->revisionStore->newSelectQueryBuilder( $this->getDatabase() )
				->joinComment()
				->joinPage()
				->where( [ 'rev_id' => $revisionIds ] )
				->caller( __METHOD__ )
				->fetchResultSet(),
			[ 'slots' => true ]
		)->getValue();

		$missingIds = array_values( array_diff( $revisionIds, array_keys( $revisions ) ) );
		if ( $missingIds === [] || !$this->getAuthority()->isAllowed( 'deletedtext' ) ) {
			return $revisions;
		}

		$archivedRevisions = $this->revisionStore->newRevisionsFromBatch(
			$this->revisionStore->newArchiveSelectQueryBuilder( $this->getDatabase() )
				->joinComment()
				->where( [ 'ar_rev_id' => $missingIds ] )
				->caller( __METHOD__ )
				->fetchResultSet(),
			[ 'archive' => true, 'slots' => true ]
		)->getValue();

		return $revisions + $archivedRevisions;
	}

	/** On a cache miss, looks up the given ID and all collected IDs in one batch. */
	private function getRevision( int $revisionId ): ?RevisionRecord {
		if ( !array_key_exists( $revisionId, $this->revisions ) ) {
			$revisionIds = array_unique( [ ...$this->revisionIdsToLookUp, $revisionId ] );
			$this->revisionIdsToLookUp = [];

			$this->revisions += $this->lookUpRevisions( $revisionIds );
			// Cache a miss as null, so that the next call does not look it up again.
			$this->revisions += array_fill_keys( $revisionIds, null );
		}

		return $this->revisions[$revisionId];
	}

	private function canSeeRowText( Title $title, stdClass $row ): bool {
		return RevisionRecord::userCanBitfield(
			(int)$row->deleted,
			RevisionRecord::DELETED_TEXT,
			$this->getAuthority(),
			$title
		);
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
		return $this->queueLookup->fetchRows(
			$this->filters,
			$this->getAuthority(),
			$offset,
			$limit,
			$order,
			$this->mIncludeOffset
		);
	}

	/** Throws, because {@link AbuseReviewQueueLookup::fetchRows} gets the rows from more than one table. */
	public function getQueryInfo(): never {
		throw new LogicException( 'Use AbuseReviewQueueLookup::getQueryInfo to get the query info of a table' );
	}

	/** @inheritDoc */
	protected function doBatchLookups(): void {
		parent::doBatchLookups();

		$lb = $this->linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
		$revisionIds = [];
		$parentIds = [];
		foreach ( $this->mResult as $row ) {
			$lb->add( (int)$row->namespace, $row->title );
			$lb->addUser( new UserIdentityValue( (int)$row->user, $row->user_text ) );
			$revisionIds[] = (int)$row->rev_id;
			$parentIds[] = (int)$row->parent_id;
		}

		$performers = $this->verdictPerformerLookup->lookUpPerformers(
			$revisionIds,
			$this->filters->abuseReviewTag,
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
		// A page creation has no parent, so its parent ID is 0.
		$this->revisionIdsToLookUp = [ ...$revisionIds, ...array_filter( $parentIds ) ];
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
	 * Whether the row's revision is the one the page currently shows. A revision in the
	 * archive table is one of a deleted page, which has no current revision.
	 */
	private function isCurrentRevision( stdClass $row ): bool {
		return !$this->queueLookup->isArchivedRow( $row ) && (int)$row->page_latest === (int)$row->rev_id;
	}

	/** Returns the message key for the reason from {@link AbuseReviewQueueLookup::getHandledReason}, or null. */
	private function getHandledOutsideAbuseReviewMessage( stdClass $row ): ?string {
		$handledReason = $this->queueLookup->getHandledReason( $row, $this->filters->abuseReviewTag );

		return $handledReason !== null ? self::HANDLED_REASON_MESSAGES[$handledReason] : null;
	}

	private function isHandledOutsideAbuseReview( stdClass $row ): bool {
		return $this->queueLookup->getHandledReason( $row, $this->filters->abuseReviewTag ) !== null;
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
		return [ 'timestamp' => AbuseReviewQueueLookup::INDEX_COLUMNS ];
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

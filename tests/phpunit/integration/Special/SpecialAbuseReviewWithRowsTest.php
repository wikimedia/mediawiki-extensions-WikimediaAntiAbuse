<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewLinkClickHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewWithRowsTest extends SpecialAbuseReviewTestBase {

	private const string SUPPRESS_LABEL = '(wikimediaantiabuse-special-abuse-review-action-suppress)';
	private const string REVISION_DELETE_LABEL =
		'(wikimediaantiabuse-special-abuse-review-action-revision-delete)';
	private const string REVERT_LABEL = '(wikimediaantiabuse-special-abuse-review-action-revert)';

	private const array VERDICT_CHIPS = [
		'falsePositive' => [
			'class' => 'cdx-info-chip--warning',
			'label' => '(wikimediaantiabuse-special-abuse-review-verdict-chip-false-positive)',
		],
		'noFurtherAction' => [
			'class' => 'cdx-info-chip--success',
			'label' => '(wikimediaantiabuse-special-abuse-review-verdict-chip-no-further-action)',
		],
	];

	private static int $suppressedContentRevId;
	private static int $notTaggedContentRevId;
	private static int $taggedContentRevId;
	private static int $falsePositiveRevId;
	private static int $suppressedFalsePositiveRevId;
	private static int $deletedTaggedContentRevId;
	private static int $noFurtherActionRevId;
	private static int $deletedNoFurtherActionRevId;
	private static int $revertableTaggedContentRevId;
	private static int $revertableTaggedContentParentRevId;

	private static string $firstPageName;
	private static string $deletedNoFurtherActionPageName;

	/** @dataProvider provideViewWhenRevisionsPresent */
	public function testViewWhenRevisionsPresent(
		bool $includeFalsePositiveRevisions,
		bool $includeHandledRevisions,
		bool $descendingOrder,
		callable $extraQueryParamsCallback,
		array $authorityRights,
		callable $expectedRevIdsCallback,
		int $expectedFiltersAppliedCount
	): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
			'WikimediaAntiAbuseEnableVandalismTag' => true,
		] );
		$this->setGroupPermissions( [ 'suppress-test' => array_fill_keys( $authorityRights, true ) ] );
		$testUser = $this->getTestUser( [ 'suppress-test' ] )->getUser();
		$data = [];
		if ( $includeFalsePositiveRevisions ) {
			$data['wpShowFalsePositives'] = '1';
		}
		if ( $includeHandledRevisions ) {
			$data['wpShowHandledRevisions'] = '1';
		}
		if ( !$descendingOrder ) {
			$data['asc'] = '1';
		}
		$data = array_merge( $data, $extraQueryParamsCallback() );

		$expectedRevisionIdFilter = array_values( array_filter( array_map( 'intval', $data['revision'] ?? [] ) ) );
		$expectedPageFilter = array_values( array_filter(
			$data['page'] ?? [],
			$this->getServiceContainer()->getTitleFactory()->newFromText( ... )
		) );

		// The flags being shown would be the one in 'tab', defaulting to personal info if the
		// no tab is selected or the tab is not known
		$validTabs = [ 'mw-private-personal-info', 'mw-private-vandalism' ];
		$validatedTab = in_array( $data['tab'] ?? '', $validTabs, true ) ? $data['tab'] : null;
		$expectedFlag = $validatedTab ?? 'mw-private-personal-info';

		$context = RequestContext::getMain();
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		if ( ( $data['tab'] ?? '' ) === '' ) {
			$expectedTabForInstrumentation = 'mw-private-personal-info';
		} else {
			$expectedTabForInstrumentation = in_array( $data['tab'] ?? '', $validTabs, true ) ? $data['tab'] : '';
		}
		$client->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				$context,
				'page_load',
				[
					'is_paging_results' => array_key_exists( 'offset', $data ) || ( $data['dir'] ?? '' ) === 'prev',
					'pager_limit' => $data['limit'] ?? 50,
					'applied_filters' => [
						'show_false_positives' => $includeFalsePositiveRevisions,
						'show_handled_revisions' => $includeHandledRevisions,
						'username' => [],
						'revision' => $expectedRevisionIdFilter,
						'page' => $expectedPageFilter,
						'tab' => $expectedTabForInstrumentation,
					]
				]
			);
		$this->setService( 'WikimediaAntiAbuseAbuseReviewInstrumentationClient', $client );

		$context->setRequest( new FauxRequest( $data ) );
		$context->setUser( $testUser );
		$context->setLanguage( 'qqx' );
		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$expectedActiveFiltersArray = [
			'showFalsePositives' => $includeFalsePositiveRevisions,
			'showHandledRevisions' => $includeHandledRevisions,
			'username' => [],
			'page' => $expectedPageFilter,
			'revision' => $expectedRevisionIdFilter,
			'tab' => $validatedTab ?? '',
		];
		$this->assertArrayEquals(
			$expectedActiveFiltersArray,
			$context->getOutput()->getJsConfigVars()['wgWikimediaAntiAbuseActiveFilters'],
			false,
			true
		);

		$specialPageSummaryHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-specialpage-summary' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-summary)',
			$specialPageSummaryHtml
		);

		$this->verifyFilterButtonPresent( $html, $expectedFiltersAppliedCount );

		$expectedRevIds = $expectedRevIdsCallback();
		$tablePagerHtml = $this->commonVerifyTablePager( $html, count( $expectedRevIds ) !== 0 );

		// The tabs should only be shown if the user has the ability to see at least two tabs
		$shouldDisplayTabs = in_array( 'rollback', $authorityRights, true ) &&
			array_intersect( [ 'viewsuppressed', 'suppressrevision' ], $authorityRights );

		if ( ( $data['tab'] ?? '' ) === '' ) {
			$expectedSelectedTab = 'mw-private-personal-info';
		} else {
			$expectedSelectedTab = in_array( $data['tab'] ?? '', $validTabs, true ) ? $data['tab'] : null;
		}
		$this->assertTabElement( DOMUtils::parseHTML( $html ), $shouldDisplayTabs, $expectedSelectedTab );

		$tableRows = DOMCompat::querySelectorAll(
			DOMUtils::parseHTML( $tablePagerHtml ), self::ROW_SELECTOR
		);
		$this->assertSameSize( $expectedRevIds, $tableRows );

		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$archivedRevisionLookup = $this->getServiceContainer()->getArchivedRevisionLookup();
		$qqxLanguage = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );
		foreach ( $tableRows as $tableRowIndex => $tableRow ) {
			$actualRevId = (int)DOMCompat::getAttribute( $tableRow, 'data-rev-id' );
			$this->assertContains(
				$actualRevId,
				$expectedRevIds,
				'The revision was not expected to be in the table'
			);
			$this->assertSame(
				array_search( $actualRevId, $expectedRevIds, true ),
				$tableRowIndex,
				'The order of the rows was not as expected'
			);

			$isArchivedRevision = in_array(
				$actualRevId,
				[ static::$deletedTaggedContentRevId, static::$deletedNoFurtherActionRevId ],
				true
			);

			$actualRevision = $isArchivedRevision ?
				$archivedRevisionLookup->getArchivedRevisionRecord( null, $actualRevId ) :
				$revisionStore->getRevisionById( $actualRevId );

			$timestampCellNode = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--timestamp'
			);
			$timestampCellHtml = DOMCompat::getInnerHTML( $timestampCellNode );
			$this->assertStringContainsString(
				$qqxLanguage->userTimeAndDate( $actualRevision->getTimestamp(), $testUser ),
				$timestampCellHtml
			);

			// Link to diff should only exist if the user can see the revision text
			$timestampLink = DOMCompat::querySelector( $timestampCellNode, 'a' );
			$href = DOMCompat::getAttribute( $timestampLink, 'href' );
			$expectedQueryParamsForTimestampLink = array_merge( $data, [
				'title' => 'Special:AbuseReview',
				'revision' => $actualRevId,
				'ar_revid' => $actualRevId,
				'ar_subtype' => 'timestamp',
			] );
			unset( $expectedQueryParamsForTimestampLink['referrer'] );
			$this->assertArrayEquals(
				$expectedQueryParamsForTimestampLink,
				wfCgiToArray( parse_url( $href )['query'] ),
				false,
				true,
				'The timestamp link query parameters were not as expected'
			);

			$detailsCellNode = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.mw-wikimediaantiabuse-abuse-review-row__details'
			);
			$detailsCellHtml = DOMCompat::getOuterHTML( $detailsCellNode );

			$isOpenRow = $tableRowIndex === 0 || count( $expectedRevisionIdFilter ) !== 0;
			$this->assertSame(
				$isOpenRow,
				DOMCompat::getAttribute( $detailsCellNode, 'open' ) !== null,
				'Only first row should be open by default, or all rows should be open if revision filter set'
			);

			// The row header names the edited page and offers the show/hide toggle.
			$pageCellNode = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.mw-wikimediaantiabuse-abuse-review-row__page'
			);
			$pageCellHtml = DOMCompat::getOuterHTML( $pageCellNode );
			$pageTitle = Title::newFromPageIdentity( $actualRevision->getPage() );
			$this->assertStringContainsString( $pageTitle->getPrefixedText(), $pageCellHtml );
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-show-details)',
				$detailsCellHtml
			);
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-hide-details)',
				$detailsCellHtml
			);

			// A deleted page can no longer be linked to, so its title points at the page's
			// deleted revisions on Special:Undelete instead.
			$pageLinkHref = DOMCompat::getAttribute(
				$this->assertSelectorMatchesOneElementInNode( $pageCellNode, 'a' ),
				'href'
			);
			if ( $isArchivedRevision ) {
				$this->assertStringContainsString(
					'Special:Undelete/' . $pageTitle->getPrefixedDBkey(),
					$pageLinkHref,
					'the title opens the page\'s deleted revisions'
				);
				$this->assertStringNotContainsString( 'diff=prev', $pageLinkHref );
			} else {
				$this->assertSame(
					$pageTitle->getLocalURL( [
						AbuseReviewLinkClickHandler::SUBTYPE_PARAM => 'page_title',
						AbuseReviewLinkClickHandler::REVISION_PARAM => $actualRevId,
					] ),
					$pageLinkHref,
					'the title links to the page itself'
				);
			}

			// Link to the full diff should only exist if the user can see the revision text
			if ( $actualRevision->userCan( RevisionRecord::DELETED_TEXT, $testUser ) ) {
				$this->assertStringContainsString(
					'(wikimediaantiabuse-special-abuse-review-open-full-diff)',
					$detailsCellHtml
				);
				$fullDiffHref = DOMCompat::getAttribute(
					$this->assertSelectorMatchesOneElementInNode(
						$tableRow,
						'.mw-wikimediaantiabuse-abuse-review-row__full-diff'
					),
					'href'
				);
				if ( $isArchivedRevision ) {
					// An archived revision has left the revision table, so an oldid= link to it
					// would be dead.
					$undeleteQuery = 'target=' . urlencode( $pageTitle->getPrefixedText() ) .
						'&timestamp=' . $actualRevision->getTimestamp();
					$this->assertStringContainsString( 'Special:Undelete', $fullDiffHref );
					$this->assertStringContainsString( $undeleteQuery . '&diff=prev', $fullDiffHref );
					$this->assertStringNotContainsString( 'oldid=', $fullDiffHref );
				} else {
					$this->assertStringContainsString( 'diff=prev', $fullDiffHref );
					$this->assertStringContainsString( 'oldid=' . $actualRevId, $fullDiffHref );
				}
				$this->assertStringContainsString(
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM . '=full_diff',
					$fullDiffHref,
					'the full diff link names the click it stands for'
				);
			} else {
				$this->assertStringNotContainsString( 'oldid=' . $actualRevId, $detailsCellHtml );
				$this->assertStringNotContainsString(
					'(wikimediaantiabuse-special-abuse-review-open-full-diff)',
					$detailsCellHtml
				);
			}

			// The row title carries the visibility state the timestamp used to.
			if ( $actualRevision->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
				$this->assertStringContainsString( 'history-deleted', $pageCellHtml );
				$this->assertSame(
					$actualRevision->isDeleted( RevisionRecord::DELETED_RESTRICTED ),
					str_contains( $pageCellHtml, 'mw-history-suppressed' ),
					'suppressed revisions are doubly struck through'
				);
			} else {
				$this->assertStringNotContainsString( 'history-deleted', $pageCellHtml );
			}

			$authorCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.mw-wikimediaantiabuse-abuse-review-row__author',
				true
			);
			if ( $actualRevision->userCan( RevisionRecord::DELETED_USER, $testUser ) ) {
				$this->assertStringContainsString(
					$actualRevision->getUser( RevisionRecord::RAW )->getName(),
					$authorCellHtml
				);
			} else {
				$this->assertStringNotContainsString(
					$actualRevision->getUser( RevisionRecord::RAW )->getName(),
					$authorCellHtml
				);
				$this->assertStringContainsString( '(rev-deleted-user)', $authorCellHtml );
			}

			// The author is marked as deleted even for viewers permitted to see the name.
			if ( $actualRevision->isDeleted( RevisionRecord::DELETED_USER ) ) {
				$this->assertStringContainsString( 'history-deleted', $authorCellHtml );
				$this->assertSame(
					$actualRevision->isDeleted( RevisionRecord::DELETED_RESTRICTED ),
					str_contains( $authorCellHtml, 'mw-history-suppressed' ),
					'suppressed revisions are doubly struck through'
				);
			} else {
				$this->assertStringNotContainsString( 'history-deleted', $authorCellHtml );
			}

			$tagsCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.mw-wikimediaantiabuse-abuse-review-row__tags',
				true
			);
			$this->assertStringContainsString(
				"(tag-$expectedFlag)",
				$tagsCellHtml
			);
			$this->assertStringNotContainsString(
				"(tag-$expectedFlag-false-positive)",
				$tagsCellHtml,
				'the flag description is not replaced by the verdict'
			);
			$this->assertStringNotContainsString(
				"(tag-$expectedFlag-no-further-action)",
				$tagsCellHtml,
				'The "no further action" tag description should never be present in the page'
			);

			$isFalsePositiveRow = in_array(
				$actualRevId,
				[ static::$falsePositiveRevId, static::$suppressedFalsePositiveRevId ],
				true
			);
			$isNoFurtherActionRow = in_array(
				$actualRevId,
				[ static::$noFurtherActionRevId, static::$deletedNoFurtherActionRevId ],
				true
			);

			$verdicts = $this->getVerdictsPayload( $tableRow );
			$this->assertSame(
				$expectedFlag,
				$verdicts['tag'],
				'The tag the mark and unmark actions operate on should be as expected'
			);

			// A suppressed revision has been handled, which is what stops it being marked.
			$isSuppressedRow = in_array(
				$actualRevId,
				[ static::$suppressedContentRevId, static::$suppressedFalsePositiveRevId ],
				true
			);
			$this->assertSame(
				$isSuppressedRow,
				$verdicts['isSuppressed'],
				'a suppressed revision is reported as already handled'
			);
			$this->assertSame(
				$isFalsePositiveRow,
				$verdicts['isFalsePositive'],
				'the row reports whether its flag has already been called a false positive'
			);
			$this->assertSame(
				$isNoFurtherActionRow,
				$verdicts['isNoFurtherAction'],
				'the row reports whether it has already been marked as needing no further action'
			);

			$heldVerdict = null;
			if ( $isFalsePositiveRow ) {
				$heldVerdict = 'falsePositive';
			} elseif ( $isNoFurtherActionRow ) {
				$heldVerdict = 'noFurtherAction';
			}
			if ( $heldVerdict !== null ) {
				$this->assertVerdictChip( $tableRow, $heldVerdict );
			} else {
				$rowRefuses = $isSuppressedRow || !$isOpenRow;
				$note = $isSuppressedRow
					? '(wikimediaantiabuse-special-abuse-review-already-suppressed-note)'
					: '(wikimediaantiabuse-special-abuse-review-closed-row-note)';
				$this->assertVerdictButtons(
					$tableRow,
					[
						[
							'disabled' => $rowRefuses,
							'title' => $rowRefuses
								? $note
								: '(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)',
						],
						[
							'disabled' => $rowRefuses,
							'title' => $rowRefuses
								? $note
								: '(wikimediaantiabuse-special-abuse-review-action-mark-false-positive)',
						],
					]
				);
			}

			$actionLinks = $this->getActionLinks( $tableRow );

			// Special:RevisionDelete resolves a type=revision id against the live revision
			// table, so an archived row is not offered the link at all.
			$expectsRevisionDelete = !$isArchivedRevision
				&& in_array( 'deleterevision', $authorityRights, true );
			$this->assertSame(
				$expectsRevisionDelete,
				isset( $actionLinks[self::REVISION_DELETE_LABEL] ),
				'revision deletion offered only on a live revision to a user who may delete revisions'
			);
			if ( $expectsRevisionDelete ) {
				$this->assertStringContainsString(
					'ids=' . $actualRevId,
					$actionLinks[self::REVISION_DELETE_LABEL]
				);
				$this->assertStringContainsString(
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM . '=' .
						AbuseReviewLinkClickHandler::SUBTYPE_REVISION_DELETE,
					$actionLinks[self::REVISION_DELETE_LABEL],
					'the revision deletion link names the click it stands for'
				);
			}

			// Reverting is offered only where core would accept the undo: a live revision on a
			// live page, with a parent whose text it will still show. Of the fixtures only the
			// revertable one qualifies; the rest are archived, parentless or text-deleted.
			$isRevertableRow = $actualRevId === static::$revertableTaggedContentRevId;
			$this->assertSame(
				$isRevertableRow,
				isset( $actionLinks[self::REVERT_LABEL] ),
				'revert is offered only where the undo can succeed'
			);
			if ( $isRevertableRow ) {
				$this->assertStringContainsString(
					'action=edit&undoafter=' . static::$revertableTaggedContentParentRevId .
						'&undo=' . static::$revertableTaggedContentRevId,
					$actionLinks[self::REVERT_LABEL]
				);
				$this->assertStringContainsString(
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM . '=' .
						AbuseReviewLinkClickHandler::SUBTYPE_REVERT,
					$actionLinks[self::REVERT_LABEL],
					'the revert link names the click it stands for'
				);
			}

			// The history offers its visibility checkboxes to a holder of deleterevision, so
			// suppressrevision alone would reach a page with nothing to tick.
			$expectsSuppress = !$isArchivedRevision
				&& in_array( 'deleterevision', $authorityRights, true )
				&& in_array( 'suppressrevision', $authorityRights, true );
			$this->assertSame(
				$expectsSuppress,
				isset( $actionLinks[self::SUPPRESS_LABEL] ),
				'suppression offered only where the history will let the reviewer act'
			);
			if ( $expectsSuppress ) {
				$this->assertStringContainsString(
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM . '=' .
						AbuseReviewLinkClickHandler::SUBTYPE_SUPPRESS,
					$actionLinks[self::SUPPRESS_LABEL],
					'the suppression link names the click it stands for'
				);
			}

			$this->assertSame(
				array_values( array_filter( [
					$expectsSuppress ? self::SUPPRESS_LABEL : null,
					$expectsRevisionDelete ? self::REVISION_DELETE_LABEL : null,
					$isRevertableRow ? self::REVERT_LABEL : null,
				] ) ),
				array_keys( $actionLinks ),
				'every action the viewer is offered is a link, in that order, and nothing else is'
			);
		}

		$notTaggedContentRevIdElement = DOMCompat::querySelector(
			DOMUtils::parseHTML( $tablePagerHtml ),
			'tr[data-rev-id=' . static::$notTaggedContentRevId . ']'
		);
		$this->assertNull(
			$notTaggedContentRevIdElement,
			'The edit with no abuse review tag should never be shown'
		);
	}

	public static function provideViewWhenRevisionsPresent(): array {
		$allRights = [
			'viewsuppressed', 'deleterevision', 'suppressrevision', 'deletedhistory', 'deletedtext', 'rollback',
		];
		return [
			'False positives and handled revisions excluded' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$taggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 0,
			],
			'False positives included, handled revisions excluded' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$falsePositiveRevId,
					static::$taggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 1,
			],
			'False positives included, handled revisions excluded in reverse order' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => false,
				'descendingOrder' => false,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$falsePositiveRevId,
					static::$deletedTaggedContentRevId,
					static::$revertableTaggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 1,
			],
			'False positives excluded, handled revisions included' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
					static::$noFurtherActionRevId,
					static::$deletedNoFurtherActionRevId,
				],
				'expectedFiltersAppliedCount' => 1,
			],
			'False positives and handled revisions included' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$suppressedFalsePositiveRevId,
					static::$falsePositiveRevId,
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
					static::$noFurtherActionRevId,
					static::$deletedNoFurtherActionRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'False positives and handled revisions included with limit of 2' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'limit' => 2 ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'False positives and handled revisions included with limit of 2 with offset' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'limit' => 2,
					'offset' => '20260101010103|' . static::$falsePositiveRevId,
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'False positives and handled revisions included with offset and prev direction' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'limit' => 2,
					'dir' => 'prev',
					'offset' => '20260101010103|' . static::$taggedContentRevId,
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$suppressedFalsePositiveRevId,
					static::$falsePositiveRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'Oldest page via the last pagination link' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'limit' => 2,
					'dir' => 'prev',
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$noFurtherActionRevId,
					static::$deletedNoFurtherActionRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'False positives and handled revisions included but user lacks access to deleted history' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => [ 'viewsuppressed' ],
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$suppressedFalsePositiveRevId,
					static::$falsePositiveRevId,
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
					static::$noFurtherActionRevId,
				],
				'expectedFiltersAppliedCount' => 2,
			],
			'Filters for specific revision' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'revision' => [ static::$taggedContentRevId ] ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$taggedContentRevId ],
				'expectedFiltersAppliedCount' => 3,
			],
			'Filters for specific archived revision' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'revision' => [ static::$deletedTaggedContentRevId ] ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$deletedTaggedContentRevId ],
				'expectedFiltersAppliedCount' => 3,
			],
			'Ignores invalid revision filter' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'revision' => [ 'invalid' ] ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$taggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 0,
			],
			'Filters for specific revision, ignoring an invalid one' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'revision' => [ 'invalid', static::$taggedContentRevId ],
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$taggedContentRevId ],
				'expectedFiltersAppliedCount' => 3,
			],
			'Filters for multiple revisions' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'revision' => [ static::$falsePositiveRevId, static::$taggedContentRevId ],
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$falsePositiveRevId,
					static::$taggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 4,
			],
			'Filters for specific revision with invalid referrer' => [
				'includeFalsePositiveRevisions' => true,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'revision' => [ static::$taggedContentRevId ],
					'referrer' => 'invalid',
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$taggedContentRevId ],
				'expectedFiltersAppliedCount' => 3,
			],
			'Filters to specific title, ignoring invalid page titles' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'page' => [ ':', static::$firstPageName ],
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$suppressedContentRevId ],
				'expectedFiltersAppliedCount' => 2,
			],
			'Filters for title which is deleted' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [
					'page' => [ static::$deletedNoFurtherActionPageName ],
				],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$deletedNoFurtherActionRevId ],
				'expectedFiltersAppliedCount' => 2,
			],
			'Vandalism tab is selected' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'tab' => 'mw-private-vandalism' ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$revertableTaggedContentRevId ],
				'expectedFiltersAppliedCount' => 0,
			],
			'Personal info tab is selected' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'tab' => 'mw-private-personal-info' ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$revertableTaggedContentRevId,
					static::$deletedTaggedContentRevId,
					static::$taggedContentRevId,
				],
				'expectedFiltersAppliedCount' => 0,
			],
			'Selected tab is unknown' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [ 'tab' => 'mw-private-unknown' ],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [],
				'expectedFiltersAppliedCount' => 0,
			],
		];
	}

	/**
	 * Both sides of a diff are derived from both revisions, so a parent whose text the viewer
	 * may not see withholds the whole preview, as it does in core.
	 */
	public function testDiffPreviewWithheldWhenParentTextIsNotVisible(): void {
		$this->setGroupPermissions( [
			'suppress-partial' => [ 'viewsuppressed' => true ],
			'suppress-full' => [
				'viewsuppressed' => true,
				'deletedtext' => true,
				'deletedhistory' => true,
			],
		] );
		$request = new FauxRequest( [ 'wpShowFalsePositives' => '1' ] );

		// The parent of the false positive revision is revision-deleted, so only the
		// viewer holding deletedtext may see what it said.
		[ $htmlForViewerWithAccess ] = $this->executeSpecialPage(
			'', $request, null, $this->getTestUser( [ 'suppress-full' ] )->getUser()
		);
		$row = $this->getRowForRevision(
			DOMUtils::parseHTML( $htmlForViewerWithAccess ),
			static::$falsePositiveRevId
		);
		$this->assertStringContainsString(
			'Tagged',
			$this->assertSelectorMatchesOneElementInNode(
				$row,
				'.mw-wikimediaantiabuse-abuse-review-row__diff del',
				true
			),
			'a viewer who can see the parent gets the words the edit removed'
		);

		[ $htmlForViewerWithoutAccess ] = $this->executeSpecialPage(
			'', $request, null, $this->getTestUser( [ 'suppress-partial' ] )->getUser()
		);
		$row = $this->getRowForRevision(
			DOMUtils::parseHTML( $htmlForViewerWithoutAccess ),
			static::$falsePositiveRevId
		);
		$rowHtml = DOMCompat::getOuterHTML( $row );
		$this->assertStringNotContainsString(
			'Tagged',
			$rowHtml,
			'a viewer who cannot see the parent gets none of its text'
		);
		$this->assertStringNotContainsString(
			'False positive tagged',
			$rowHtml,
			'nor the revision\'s own text, which would give the rest of the parent away'
		);
		$this->assertNull(
			DOMCompat::querySelector( $row, '.mw-wikimediaantiabuse-abuse-review-row__diff' ),
			'no diff is rendered at all'
		);
		$this->assertStringContainsString(
			'(rev-deleted-no-diff)',
			$this->assertSelectorMatchesOneElementInNode(
				$row,
				'.mw-wikimediaantiabuse-abuse-review-row__withheld-diff',
				true
			),
			'MediaWiki core\'s own wording explains the refusal, the parent being deleted not suppressed'
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-open-full-diff)',
			$rowHtml,
			'and the link to the full diff stays, core refusing it there in the same terms'
		);
	}

	public function testDoesNotShowEchoNotificationBannerWhenNoReferrer(): void {
		$context = RequestContext::getMain();
		$context->setRequest( new FauxRequest( [
			'revision' => [ static::$taggedContentRevId ],
		] ) );
		$context->setUser( $this->getTestUser( [ 'suppress' ] )->getUser() );
		$context->setLanguage( 'qqx' );

		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$this->assertStringNotContainsString(
			'mw-wikimediaantiabuse-abuse-review-echo-notification-banner',
			$html,
			'The echo notification banner should not be shown if referrer is not echo_notification'
		);
	}

	public function testShowsEchoNotificationBannerWhenReferrerIsEchoNotification(): void {
		$context = RequestContext::getMain();
		$context->setRequest( new FauxRequest( [
			'referrer' => 'echo_notification',
			'revision' => [ static::$taggedContentRevId ],
		] ) );
		$context->setUser( $this->getTestUser( [ 'suppress' ] )->getUser() );
		$context->setLanguage( 'qqx' );
		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$echoBanner = $this->assertSelectorMatchesOneElementInNode(
			DOMUtils::parseHTML( $html ),
			'.mw-wikimediaantiabuse-abuse-review-echo-notification-banner'
		);

		$echoBannerContent = $this->assertSelectorMatchesOneElementInNode(
			$echoBanner,
			'.cdx-message__content'
		);

		$expectedLinkParameter = $this->getServiceContainer()->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleValueFor( 'AbuseReview' ),
			'(wikimediaantiabuse-special-abuse-review-echo-notification-banner-link)',
			[],
			[ 'tab' => 'mw-private-personal-info' ]
		);
		// Parameter 2 is 2 because of the deleted tagged content revision and the revertable tagged content revision
		$this->assertSame(
			'(wikimediaantiabuse-special-abuse-review-echo-notification-banner: 1, 2, ' . $expectedLinkParameter . ')',
			trim( DOMCompat::getInnerHTML( $echoBannerContent ) ),
			'The echo notification banner should have the expected label'
		);
	}

	/** @dataProvider provideDoesNotShowTabsWhenOnlyOneTagEnabled */
	public function testDoesNotShowTabsWhenOnlyOneTagEnabled(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled
	): void {
		$this->setGroupPermissions( 'suppress', 'rollback', true );
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
			'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
		] );

		$context = RequestContext::getMain();
		$context->setUser( $this->getTestUser( [ 'suppress' ] )->getUser() );
		$context->setLanguage( 'qqx' );
		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$this->assertTabElement( DOMUtils::parseHTML( $html ), false, null );
	}

	public static function provideDoesNotShowTabsWhenOnlyOneTagEnabled(): array {
		return [
			'Only personal info tag enabled' => [
				'personalInfoEnabled' => true,
				'vandalismEnabled' => false,
			],
			'Only vandalism tag enabled' => [
				'personalInfoEnabled' => false,
				'vandalismEnabled' => true,
			],
		];
	}

	/**
	 * Validates the tab element in the given HTML node.
	 */
	private function assertTabElement(
		Element|Document $htmlAsNode,
		bool $shouldDisplayTabs,
		?string $expectedSelectedTab
	): void {
		if ( $shouldDisplayTabs ) {
			$tabsElement = $this->assertSelectorMatchesOneElementInNode(
				$htmlAsNode,
				'.cdx-tabs.mw-wikimediaantiabuse-abuse-review-tabs'
			);

			if ( $expectedSelectedTab !== null ) {
				$selectedTab = $this->assertSelectorMatchesOneElementInNode(
					$tabsElement,
					'.cdx-tabs__list__item.mw-wikimediaantiabuse-abuse-review-tab-' . $expectedSelectedTab
				);
				$this->assertSame(
					'true',
					$selectedTab->getAttribute( 'aria-selected' ),
					'The selected tab should have been selected'
				);
			} else {
				$this->assertNull(
					DOMCompat::querySelector(
						$tabsElement,
						'.cdx-tabs__list__item[aria-selected="true"]'
					),
					'No tab should be selected when an unknown tab is selected'
				);
			}

			// The number of rows is the number of rows created in ::addDBDataOnce that remain in the "needs review"
			// state.
			$expectedTabs = [
				'mw-private-personal-info' => '3',
				'mw-private-vandalism' => '1',
			];
			$actualTabs = DOMCompat::querySelectorAll( $tabsElement, '.cdx-tabs__list__item' );
			foreach ( $actualTabs as $actualTab ) {
				$expectedTab = array_key_first( $expectedTabs );
				$expectedCount = array_shift( $expectedTabs );

				$this->assertSame(
					'cdx-tabs__list__item mw-wikimediaantiabuse-abuse-review-tab-' . $expectedTab,
					$actualTab->getAttribute( 'class' ),
					'Tab should have the expected classes'
				);

				$infoChipTextElement = $this->assertSelectorMatchesOneElementInNode(
					$actualTab,
					'.mw-wikimediaantiabuse-abuse-review-tabs__count .cdx-info-chip__text'
				);

				$this->assertSame(
					$expectedCount,
					DOMCompat::getInnerHTML( $infoChipTextElement ),
					'Info chip text should have the expected row count'
				);
			}
		} else {
			$this->assertNull(
				DOMCompat::querySelector(
					$htmlAsNode,
					'.cdx-tabs.mw-wikimediaantiabuse-abuse-review-tabs'
				),
				'Tabs should not be displayed when only one flag is enabled'
			);
		}
	}

	/**
	 * Asserts the verdict chip in the provided row is as expected
	 */
	private function assertVerdictChip( Element $row, string $heldVerdict ): void {
		$verdicts = $this->assertSelectorMatchesOneElementInNode(
			$row, '.mw-wikimediaantiabuse-abuse-review-verdicts'
		);
		$this->assertSame(
			$heldVerdict,
			DOMCompat::getAttribute( $verdicts, 'data-verdict-held' ),
			'the row names the verdict it holds, which the queue steps over'
		);
		$chip = $this->assertSelectorMatchesOneElementInNode( $verdicts, '.cdx-info-chip' );
		$this->assertTrue(
			DOMCompat::getClassList( $chip )->contains( self::VERDICT_CHIPS[$heldVerdict]['class'] ),
			'the chip carries the status its verdict stands for'
		);
		$this->assertSame(
			self::VERDICT_CHIPS[$heldVerdict]['label'],
			DOMCompat::getInnerHTML(
				$this->assertSelectorMatchesOneElementInNode( $chip, '.cdx-info-chip__text' )
			),
			'the chip names the verdict the row holds'
		);
		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( $verdicts, 'button' ),
			'a row that holds a verdict offers no button to record one'
		);
	}

	/**
	 * @param Element $row
	 * @param array[] $expected One [ 'disabled' => bool, 'title' => string ] per button
	 */
	private function assertVerdictButtons( Element $row, array $expected ): void {
		$verdicts = $this->assertSelectorMatchesOneElementInNode(
			$row, '.mw-wikimediaantiabuse-abuse-review-verdicts'
		);
		$this->assertNull(
			DOMCompat::getAttribute( $verdicts, 'data-verdict-held' ),
			'a row that holds no verdict names none'
		);
		$buttons = DOMCompat::querySelectorAll( $verdicts, 'button' );
		$this->assertSameSize( $expected, $buttons, 'one button per verdict' );

		foreach ( $expected as $index => $state ) {
			$button = $buttons[$index];
			$this->assertNull(
				DOMCompat::getAttribute( $button, 'aria-pressed' ),
				"button $index is not announced as a toggle, the chip carrying the state instead"
			);
			$this->assertSame(
				$state['disabled'],
				DOMCompat::getAttribute( $button, 'disabled' ) !== null,
				"button $index is out of reach only when the row's state blocks it"
			);
			$this->assertNotNull(
				DOMCompat::getAttribute( $button, 'aria-label' ),
				"button $index is named, carrying only an icon"
			);
			$this->assertSame(
				$state['title'],
				DOMCompat::getAttribute( $button, 'title' ),
				"button $index explains itself on hover, a verdict it holds before the note"
			);
		}
	}

	/** @return array<string,string> The href of each rendered revision action, by its label */
	private function getActionLinks( Document|Element $node ): array {
		$links = DOMCompat::querySelectorAll(
			$node,
			'.mw-wikimediaantiabuse-abuse-review-actions a'
		);

		$hrefs = [];
		foreach ( $links as $link ) {
			$hrefs[DOMCompat::getInnerHTML( $link )] = DOMCompat::getAttribute( $link, 'href' );
		}
		return $hrefs;
	}

	public function addDBDataOnce(): void {
		// Get enough revisions to test each state of the filters, and one that should never show up in the results.
		// The two no-further-action revisions get the oldest timestamps, so they sort last.
		// This one sits on a page that is deleted below, which exercises the archive table.
		ConvertibleTimestamp::setFakeTime( '20260101010059' );
		$deletedNoFurtherActionPage = $this->getNonexistingTestPage();
		$deletedNoFurtherActionEditStatus = $this->editPage(
			$deletedNoFurtherActionPage,
			'Deleted no further action content'
		);
		$this->assertStatusGood( $deletedNoFurtherActionEditStatus );
		static::$deletedNoFurtherActionRevId = $deletedNoFurtherActionEditStatus->getNewRevision()->getId();
		static::$deletedNoFurtherActionPageName = $deletedNoFurtherActionPage->getTitle()->getPrefixedText();

		ConvertibleTimestamp::setFakeTime( '20260101010100' );
		$noFurtherActionEditStatus = $this->editPage(
			$this->getNonexistingTestPage(),
			'No further action content'
		);
		$this->assertStatusGood( $noFurtherActionEditStatus );
		static::$noFurtherActionRevId = $noFurtherActionEditStatus->getNewRevision()->getId();

		ConvertibleTimestamp::setFakeTime( '20260101010101' );
		$firstPage = $this->getNonexistingTestPage();
		$suppressedContentEditStatus = $this->editPage( $firstPage, 'Suppressed and tagged content' );
		$this->assertStatusGood( $suppressedContentEditStatus );
		static::$suppressedContentRevId = $suppressedContentEditStatus->getNewRevision()->getId();

		ConvertibleTimestamp::setFakeTime( '20260101010102' );
		$notTaggedContentEditStatus = $this->editPage( $firstPage, 'Not tagged content' );
		$this->assertStatusGood( $notTaggedContentEditStatus );
		static::$notTaggedContentRevId = $notTaggedContentEditStatus->getNewRevision()->getId();

		static::$firstPageName = $firstPage->getTitle()->getPrefixedText();

		ConvertibleTimestamp::setFakeTime( '20260101010103' );
		$secondPage = $this->getNonexistingTestPage();
		$taggedContentEditStatus = $this->editPage( $secondPage, 'Tagged content' );
		$this->assertStatusGood( $taggedContentEditStatus );
		static::$taggedContentRevId = $taggedContentEditStatus->getNewRevision()->getId();

		// Intentionally use the same timestamp as the previous revision to test
		// handling when multiple revisions have the same timestamp.
		ConvertibleTimestamp::setFakeTime( '20260101010103' );
		$falsePositiveEditStatus = $this->editPage( $secondPage, 'False positive tagged content' );
		$this->assertStatusGood( $falsePositiveEditStatus );
		static::$falsePositiveRevId = $falsePositiveEditStatus->getNewRevision()->getId();

		// A revision that was marked a false positive and then suppressed.
		ConvertibleTimestamp::setFakeTime( '20260101010105' );
		$thirdPage = $this->getNonexistingTestPage();
		$suppressedFalsePositiveEditStatus = $this->editPage( $thirdPage, 'Suppressed false positive content' );
		$this->assertStatusGood( $suppressedFalsePositiveEditStatus );
		static::$suppressedFalsePositiveRevId = $suppressedFalsePositiveEditStatus->getNewRevision()->getId();
		$this->assertStatusGood( $this->editPage( $thirdPage, 'Trailing content' ) );

		ConvertibleTimestamp::setFakeTime( '20260101010106' );
		$fourthPage = $this->getNonexistingTestPage();
		$deletedTaggedContentEditStatus = $this->editPage( $fourthPage, 'Deleted tagged content' );
		$this->assertStatusGood( $deletedTaggedContentEditStatus );
		static::$deletedTaggedContentRevId = $deletedTaggedContentEditStatus->getNewRevision()->getId();

		// A tagged revision whose page and parent both stay live, so the undo can succeed.
		ConvertibleTimestamp::setFakeTime( '20260101010107' );
		$fifthPage = $this->getNonexistingTestPage();
		$revertableParentEditStatus = $this->editPage( $fifthPage, 'Content to revert to' );
		$this->assertStatusGood( $revertableParentEditStatus );
		static::$revertableTaggedContentParentRevId = $revertableParentEditStatus->getNewRevision()->getId();
		$revertableEditStatus = $this->editPage( $fifthPage, 'Revertable tagged content' );
		$this->assertStatusGood( $revertableEditStatus );
		static::$revertableTaggedContentRevId = $revertableEditStatus->getNewRevision()->getId();

		ConvertibleTimestamp::setFakeTime( false );

		$changeTagsStore = $this->getServiceContainer()->getChangeTagsStore();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ],
			null,
			static::$taggedContentRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ],
			null,
			static::$suppressedContentRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info-false-positive' ],
			null,
			static::$falsePositiveRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info-false-positive' ],
			null,
			static::$suppressedFalsePositiveRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ],
			null,
			static::$deletedTaggedContentRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info', 'mw-private-personal-info-no-further-action' ],
			null,
			static::$noFurtherActionRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info', 'mw-private-personal-info-no-further-action' ],
			null,
			static::$deletedNoFurtherActionRevId
		);
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info', 'mw-private-vandalism' ],
			null,
			static::$revertableTaggedContentRevId
		);

		$this->revisionDelete(
			static::$suppressedContentRevId,
			[ RevisionRecord::DELETED_RESTRICTED | RevisionRecord::DELETED_TEXT => 1 ]
		);
		$this->revisionDelete(
			static::$suppressedFalsePositiveRevId,
			[ RevisionRecord::DELETED_RESTRICTED | RevisionRecord::DELETED_TEXT => 1 ]
		);
		$this->revisionDelete(
			static::$taggedContentRevId,
			[ RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_USER => 1 ]
		);

		$this->deletePage( $fourthPage );
		$this->deletePage( $deletedNoFurtherActionPage );
	}
}

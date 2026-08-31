<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
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

	/** @dataProvider provideViewWhenRevisionsPresent */
	public function testViewWhenRevisionsPresent(
		bool $includeFalsePositiveRevisions,
		bool $includeHandledRevisions,
		bool $descendingOrder,
		callable $extraQueryParamsCallback,
		array $authorityRights,
		callable $expectedRevIdsCallback,
	): void {
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

		$context = RequestContext::getMain();
		$context->setRequest( new FauxRequest( $data ) );
		$context->setUser( $testUser );
		$context->setLanguage( 'qqx' );
		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$expectedActiveFiltersArray = [
			'showFalsePositives' => $includeFalsePositiveRevisions,
			'showHandledRevisions' => $includeHandledRevisions,
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

		$this->verifyFilterButtonPresent( $html, count( array_filter( $expectedActiveFiltersArray ) ) );

		$tablePagerHtml = $this->commonVerifyTablePager( $html, true );

		$expectedRevIds = $expectedRevIdsCallback();
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
			if ( $actualRevision->userCan( RevisionRecord::DELETED_TEXT, $testUser ) ) {
				$href = DOMCompat::getAttribute( $timestampLink, 'href' );
				if ( $isArchivedRevision ) {
					$this->assertStringContainsString( 'Special:Undelete', $href );
					$this->assertStringContainsString( 'timestamp=' . $actualRevision->getTimestamp(), $href );
					$this->assertStringContainsString( 'diff=prev', $href );
				} else {
					$this->assertStringContainsString( 'oldid=' . $actualRevId, $href );
				}
			} else {
				$this->assertNull( $timestampLink );
			}

			if ( $actualRevision->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
				$this->assertStringContainsString( 'history-deleted', $timestampCellHtml );
				$this->assertSame(
					$actualRevision->isDeleted( RevisionRecord::DELETED_RESTRICTED ),
					str_contains( $timestampCellHtml, 'mw-history-suppressed' ),
					'suppressed revisions are doubly struck through'
				);
			} else {
				$this->assertStringNotContainsString( 'history-deleted', $timestampCellHtml );
			}

			$detailsCellNode = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.mw-wikimediaantiabuse-abuse-review-row__details'
			);
			$detailsCellHtml = DOMCompat::getOuterHTML( $detailsCellNode );

			$isOpenRow = $tableRowIndex === 0;
			$this->assertSame(
				$isOpenRow,
				DOMCompat::getAttribute( $detailsCellNode, 'open' ) !== null,
				'only the first row arrives open'
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
					$pageTitle->getLocalURL(),
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
				'(tag-mw-private-personal-info)',
				$tagsCellHtml
			);
			$this->assertStringNotContainsString(
				'(tag-mw-private-personal-info-false-positive)',
				$tagsCellHtml,
				'the flag description is not replaced by the verdict'
			);
			$this->assertStringNotContainsString(
				'(tag-mw-private-personal-info-no-further-action)',
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
				'mw-private-personal-info',
				$verdicts['tag'],
				'the tag the mark and unmark actions operate on'
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

			$suppressedBlocksMark = $isSuppressedRow && !$isFalsePositiveRow && !$isNoFurtherActionRow;
			$rowRefuses = $suppressedBlocksMark || !$isOpenRow;
			$note = $suppressedBlocksMark
				? '(wikimediaantiabuse-special-abuse-review-already-suppressed-note)'
				: '(wikimediaantiabuse-special-abuse-review-closed-row-note)';
			$this->assertVerdictButtons(
				$tableRow,
				[
					[
						'pressed' => $isNoFurtherActionRow,
						'disabled' => $rowRefuses || $isFalsePositiveRow,
						'title' => $rowRefuses
							? $note
							: self::getExpectedVerdictLabel( 'no-further-action', $isNoFurtherActionRow ),
					],
					[
						'pressed' => $isFalsePositiveRow,
						'disabled' => $rowRefuses || $isNoFurtherActionRow,
						'title' => $rowRefuses
							? $note
							: self::getExpectedVerdictLabel( 'false-positive', $isFalsePositiveRow ),
					],
				]
			);

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
			'viewsuppressed', 'deleterevision', 'suppressrevision', 'deletedhistory', 'deletedtext',
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

	private static function getExpectedVerdictLabel( string $verdict, bool $held ): string {
		return '(wikimediaantiabuse-special-abuse-review-action-'
			. ( $held ? 'unmark-' : 'mark-' ) . $verdict . ')';
	}

	/**
	 * @param Element $row
	 * @param array[] $expected One [ 'pressed' => bool, 'disabled' => bool, 'title' => string ]
	 *   per button
	 */
	private function assertVerdictButtons( Element $row, array $expected ): void {
		$buttons = DOMCompat::querySelectorAll(
			$row, '.mw-wikimediaantiabuse-abuse-review-verdicts button'
		);
		$this->assertSameSize( $expected, $buttons, 'one button per verdict' );

		foreach ( $expected as $index => $state ) {
			$button = $buttons[$index];
			$this->assertSame(
				$state['pressed'] ? 'true' : 'false',
				DOMCompat::getAttribute( $button, 'aria-pressed' ),
				"button $index reads as pressed only when the row holds that verdict"
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

	private function getVerdictsPayload( Document|Element $node ): array {
		$mountPoint = $this->assertSelectorMatchesOneElementInNode(
			$node,
			'.mw-wikimediaantiabuse-abuse-review-verdicts-app'
		);

		$payload = json_decode( DOMCompat::getAttribute( $mountPoint, 'data-verdicts' ), true );
		$this->assertIsArray( $payload, 'the mount point carries a decodable payload' );
		return $payload;
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
			[ 'mw-private-personal-info' ],
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

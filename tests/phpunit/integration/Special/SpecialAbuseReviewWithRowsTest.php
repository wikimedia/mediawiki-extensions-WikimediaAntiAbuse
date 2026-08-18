<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewWithRowsTest extends SpecialAbuseReviewTestBase {

	private static int $suppressedContentRevId;
	private static int $notTaggedContentRevId;
	private static int $taggedContentRevId;
	private static int $falsePositiveRevId;
	private static int $suppressedFalsePositiveRevId;
	private static int $deletedTaggedContentRevId;
	private static int $noFurtherActionRevId;
	private static int $deletedNoFurtherActionRevId;

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
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( $data ), null, $testUser );

		$specialPageSummaryHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-specialpage-summary' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-summary)',
			$specialPageSummaryHtml
		);

		$this->verifyFilterForm( $html );

		$tablePagerHtml = $this->commonVerifyTablePager( $html );

		$expectedRevIds = $expectedRevIdsCallback();
		$tableRows = DOMCompat::querySelectorAll( DOMUtils::parseHTML( $tablePagerHtml ), 'tbody tr' );
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

			$authorCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--user_text',
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

			$tagsCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--ts_tags',
				true
			);
			if ( in_array(
				$actualRevId,
				[
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
					static::$noFurtherActionRevId,
					static::$deletedNoFurtherActionRevId,
				],
				true
			) ) {
				$this->assertStringContainsString(
					'(tag-mw-private-personal-info)',
					$tagsCellHtml
				);
			} elseif ( in_array(
				$actualRevId,
				[ static::$falsePositiveRevId, static::$suppressedFalsePositiveRevId ],
				true
			) ) {
				$this->assertStringContainsString(
					'(tag-mw-private-personal-info-false-positive)',
					$tagsCellHtml
				);
			}

			// Both tag-description variants are rendered so the visible one can switch client-side
			// when the row is marked or unmarked.
			$this->assertStringContainsString(
				'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive',
				$tagsCellHtml
			);
			$this->assertStringContainsString(
				'mw-wikimediaantiabuse-abuse-review-tag--false-positive',
				$tagsCellHtml
			);

			// The tag variant not matching the row's current state starts hidden.
			$isFalsePositiveRow = in_array(
				$actualRevId,
				[ static::$falsePositiveRevId, static::$suppressedFalsePositiveRevId ],
				true
			);
			$notFpTagClass = DOMCompat::getAttribute( DOMCompat::querySelector(
				$tableRow, '.mw-wikimediaantiabuse-abuse-review-tag--not-false-positive'
			), 'class' );
			$fpTagClass = DOMCompat::getAttribute( DOMCompat::querySelector(
				$tableRow, '.mw-wikimediaantiabuse-abuse-review-tag--false-positive'
			), 'class' );
			$this->assertSame(
				$isFalsePositiveRow,
				str_contains( $notFpTagClass, 'mw-wikimediaantiabuse-hidden' )
			);
			$this->assertSame(
				!$isFalsePositiveRow,
				str_contains( $fpTagClass, 'mw-wikimediaantiabuse-hidden' )
			);

			// The no-further-action tag is rendered on every row; it starts hidden unless
			// the row is marked as needing no further action.
			$isNoFurtherActionRow = in_array(
				$actualRevId,
				[ static::$noFurtherActionRevId, static::$deletedNoFurtherActionRevId ],
				true
			);
			$noFurtherActionTagClass = DOMCompat::getAttribute( DOMCompat::querySelector(
				$tableRow, '.mw-wikimediaantiabuse-abuse-review-tag--no-further-action'
			), 'class' );
			$this->assertSame(
				!$isNoFurtherActionRow,
				str_contains( $noFurtherActionTagClass, 'mw-wikimediaantiabuse-hidden' )
			);

			$actionsCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--actions',
				true
			);
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-action-mark-false-positive)',
				$actionsCellHtml
			);
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-action-unmark-false-positive)',
				$actionsCellHtml
			);
			// The controls the click binding and show/hide CSS depend on.
			$markButton = DOMCompat::querySelector( $tableRow, '.mw-wikimediaantiabuse-abuse-review-mark-button' );
			$unmarkButton = DOMCompat::querySelector( $tableRow, '.mw-wikimediaantiabuse-abuse-review-unmark-button' );
			$this->assertNotNull( $markButton, 'mark button is present' );
			$this->assertNotNull( $unmarkButton, 'unmark button is present' );
			$this->assertSame(
				(string)$actualRevId,
				DOMCompat::getAttribute( $markButton, 'data-rev-id' )
			);
			$this->assertSame(
				'mw-private-personal-info',
				DOMCompat::getAttribute( $markButton, 'data-abuse-review-tag' )
			);

			// A suppressed revision has been handled: its mark button is disabled and an
			// explanatory note is shown. Unmarking stays enabled regardless.
			$isSuppressedRow = in_array(
				$actualRevId,
				[ static::$suppressedContentRevId, static::$suppressedFalsePositiveRevId ],
				true
			);
			$this->assertSame(
				$isSuppressedRow,
				DOMCompat::getAttribute( $markButton, 'disabled' ) !== null,
				'mark button is disabled only for suppressed revisions'
			);
			$this->assertNull(
				DOMCompat::getAttribute( $unmarkButton, 'disabled' ),
				'unmark button is never disabled'
			);
			if ( $isSuppressedRow ) {
				$this->assertStringContainsString(
					'(wikimediaantiabuse-special-abuse-review-already-suppressed-note)',
					$actionsCellHtml
				);

				// The note is always visible on a suppressed row, whether or not it is a false
				// positive, so handled rows can always be recognised.
				$noteClass = DOMCompat::getAttribute( DOMCompat::querySelector(
					$tableRow, '.mw-wikimediaantiabuse-abuse-review-suppressed-note'
				), 'class' );
				$this->assertStringNotContainsString( 'mw-wikimediaantiabuse-hidden', $noteClass );
			} else {
				$this->assertStringNotContainsString(
					'(wikimediaantiabuse-special-abuse-review-already-suppressed-note)',
					$actionsCellHtml
				);
			}

			// The no-further-action buttons follow the same pattern: the pair is always
			// rendered, the one not matching the row's state starts hidden, and marking
			// is disabled on suppressed rows.
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)',
				$actionsCellHtml
			);
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action)',
				$actionsCellHtml
			);
			$markNoFurtherActionButton = DOMCompat::querySelector(
				$tableRow, '.mw-wikimediaantiabuse-abuse-review-mark-no-further-action-button'
			);
			$unmarkNoFurtherActionButton = DOMCompat::querySelector(
				$tableRow, '.mw-wikimediaantiabuse-abuse-review-unmark-no-further-action-button'
			);
			$this->assertNotNull( $markNoFurtherActionButton, 'mark no-further-action button is present' );
			$this->assertNotNull( $unmarkNoFurtherActionButton, 'unmark no-further-action button is present' );
			$this->assertSame(
				(string)$actualRevId,
				DOMCompat::getAttribute( $markNoFurtherActionButton, 'data-rev-id' )
			);
			$this->assertSame(
				'mw-private-personal-info',
				DOMCompat::getAttribute( $markNoFurtherActionButton, 'data-abuse-review-tag' )
			);
			// A row holds one verdict, so neither mark button is offered once one is set.
			$hasVerdict = $isFalsePositiveRow || $isNoFurtherActionRow;
			$this->assertSame(
				$hasVerdict,
				str_contains(
					DOMCompat::getAttribute( $markNoFurtherActionButton, 'class' ),
					'mw-wikimediaantiabuse-hidden'
				),
				'mark no-further-action button is hidden on any row that already has a verdict'
			);
			$this->assertSame(
				$hasVerdict,
				str_contains(
					DOMCompat::getAttribute( $markButton, 'class' ),
					'mw-wikimediaantiabuse-hidden'
				),
				'mark false-positive button is hidden on any row that already has a verdict'
			);
			$this->assertSame(
				!$isNoFurtherActionRow,
				str_contains(
					DOMCompat::getAttribute( $unmarkNoFurtherActionButton, 'class' ),
					'mw-wikimediaantiabuse-hidden'
				),
				'unmark no-further-action button is shown only on marked rows'
			);
			$this->assertSame(
				$isSuppressedRow,
				DOMCompat::getAttribute( $markNoFurtherActionButton, 'disabled' ) !== null,
				'mark no-further-action button is disabled only for suppressed revisions'
			);
			$this->assertNull(
				DOMCompat::getAttribute( $unmarkNoFurtherActionButton, 'disabled' ),
				'unmark no-further-action button is never disabled'
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
		$allRights = [ 'viewsuppressed', 'suppressrevision', 'deletedhistory', 'deletedtext' ];
		return [
			'False positives and handled revisions excluded' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => false,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
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
				],
			],
			'False positives excluded, handled revisions included' => [
				'includeFalsePositiveRevisions' => false,
				'includeHandledRevisions' => true,
				'descendingOrder' => true,
				'extraQueryParamsCallback' => static fn () => [],
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
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
					static::$deletedTaggedContentRevId,
					static::$suppressedFalsePositiveRevId,
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
					static::$suppressedFalsePositiveRevId,
					static::$falsePositiveRevId,
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
					static::$noFurtherActionRevId,
				],
			],
		];
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

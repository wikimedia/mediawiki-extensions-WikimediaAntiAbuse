<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

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

	/** @dataProvider provideViewWhenRevisionsPresent */
	public function testViewWhenRevisionsPresent(
		bool $includeFalsePositiveRevisions,
		bool $includeSuppressedRevisions,
		array $authorityRights,
		callable $expectedRevIdsCallback
	): void {
		$this->setGroupPermissions( [ 'suppress-test' => array_fill_keys( $authorityRights, true ) ] );
		$testUser = $this->getTestUser( [ 'suppress-test' ] )->getUser();
		$data = [];
		if ( $includeFalsePositiveRevisions ) {
			$data['wpShowFalsePositives'] = '1';
		}
		if ( $includeSuppressedRevisions ) {
			$data['wpShowHandledRevisions'] = '1';
		}
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
		$qqxLanguage = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );
		foreach ( $tableRows as $tableRow ) {
			$actualRevId = (int)DOMCompat::getAttribute( $tableRow, 'data-rev-id' );
			$this->assertContains( $actualRevId, $expectedRevIds );

			$actualRevision = $revisionStore->getRevisionById( $actualRevId );

			$timestampCellHtml = $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--rev_timestamp',
				true
			);
			$this->assertStringContainsString(
				$qqxLanguage->userTimeAndDate( $actualRevision->getTimestamp(), $testUser ),
				$timestampCellHtml
			);

			// Link to diff should only exist if the user can see the revision text
			if ( $actualRevision->userCan( RevisionRecord::DELETED_TEXT, $testUser ) ) {
				$this->assertStringContainsString( 'oldid=' . $actualRevId, $timestampCellHtml );
			} else {
				$this->assertStringNotContainsString( 'oldid=' . $actualRevId, $timestampCellHtml );
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
				'.cdx-table-pager__col--rev_user_text',
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
			if ( in_array( $actualRevId, [ static::$taggedContentRevId, static::$suppressedContentRevId ], true ) ) {
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
			'False positives and suppressed revisions excluded' => [
				'includeFalsePositiveRevisions' => false,
				'includeSuppressedRevisions' => false,
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [ static::$taggedContentRevId ],
			],
			'False positives included, suppressed revisions excluded' => [
				'includeFalsePositiveRevisions' => true,
				'includeSuppressedRevisions' => false,
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$falsePositiveRevId,
				],
			],
			'False positives excluded, suppressed revisions included' => [
				'includeFalsePositiveRevisions' => false,
				'includeSuppressedRevisions' => true,
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$suppressedContentRevId,
				],
			],
			'False positives and suppressed revisions included' => [
				'includeFalsePositiveRevisions' => true,
				'includeSuppressedRevisions' => true,
				'authorityRights' => $allRights,
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$falsePositiveRevId,
					static::$suppressedContentRevId,
					static::$suppressedFalsePositiveRevId,
				],
			],
			'False positives and suppressed revisions included but user lacks access to deleted history' => [
				'includeFalsePositiveRevisions' => true,
				'includeSuppressedRevisions' => true,
				'authorityRights' => [ 'viewsuppressed' ],
				'expectedRevIdsCallback' => static fn () => [
					static::$taggedContentRevId,
					static::$falsePositiveRevId,
					static::$suppressedContentRevId,
					static::$suppressedFalsePositiveRevId,
				],
			],
		];
	}

	public function addDBDataOnce(): void {
		// Get enough revisions to test each state of the filters, and one that should never show up in the results
		$firstPage = $this->getNonexistingTestPage();
		$suppressedContentEditStatus = $this->editPage( $firstPage, 'Suppressed and tagged content' );
		$this->assertStatusGood( $suppressedContentEditStatus );
		static::$suppressedContentRevId = $suppressedContentEditStatus->getNewRevision()->getId();

		$notTaggedContentEditStatus = $this->editPage( $firstPage, 'Not tagged content' );
		$this->assertStatusGood( $notTaggedContentEditStatus );
		static::$notTaggedContentRevId = $notTaggedContentEditStatus->getNewRevision()->getId();

		$secondPage = $this->getNonexistingTestPage();
		$taggedContentEditStatus = $this->editPage( $secondPage, 'Tagged content' );
		$this->assertStatusGood( $taggedContentEditStatus );
		static::$taggedContentRevId = $taggedContentEditStatus->getNewRevision()->getId();

		$falsePositiveEditStatus = $this->editPage( $secondPage, 'False positive tagged content' );
		$this->assertStatusGood( $falsePositiveEditStatus );
		static::$falsePositiveRevId = $falsePositiveEditStatus->getNewRevision()->getId();

		// A revision that was marked a false positive and then suppressed.
		$thirdPage = $this->getNonexistingTestPage();
		$suppressedFalsePositiveEditStatus = $this->editPage( $thirdPage, 'Suppressed false positive content' );
		$this->assertStatusGood( $suppressedFalsePositiveEditStatus );
		static::$suppressedFalsePositiveRevId = $suppressedFalsePositiveEditStatus->getNewRevision()->getId();
		$this->assertStatusGood( $this->editPage( $thirdPage, 'Trailing content' ) );

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
	}
}

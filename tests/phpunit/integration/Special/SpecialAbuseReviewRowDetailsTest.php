<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Diff\DifferenceEngine;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * Rows whose details need revisions of their own. Tables written to by ::addDBDataOnce are not
 * cleared between tests, so these fixtures cannot share a class with the row sets that assert
 * on how many rows the pager returned.
 *
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewRowDetailsTest extends SpecialAbuseReviewTestBase {

	private static int $deletedSummaryRevId;
	private static int $longDiffRevId;
	private static int $pageCreationRevId;
	private static int $oversizeRevId;
	private static int $longPageSmallEditRevId;
	private static int $deletedPageRevId;
	private static int $suppressedTextRevId;
	private static int $badContentRevId;

	/**
	 * A summary the viewer may see, but which has been deleted, is marked as core marks it,
	 * so a reviewer can tell it has already been handled.
	 */
	public function testDeletedEditSummaryIsMarkedForAViewerWhoMaySeeIt(): void {
		$this->setGroupPermissions( [ 'reviewer' => [
			'viewsuppressed' => true,
			'deletedtext' => true,
			'deletedhistory' => true,
		] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$deletedSummaryRevId );

		$this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-row__summary .history-deleted'
		);
		$this->assertStringContainsString(
			'A summary worth hiding',
			DOMCompat::getOuterHTML( $row ),
			'the deleted summary is struck through and still shown, this viewer being allowed to see it'
		);
	}

	/**
	 * @dataProvider provideDiffPreviews
	 * @param callable $revisionId Deferred: ::addDBDataOnce has not run when providers do
	 */
	public function testDiffPreview(
		callable $revisionId,
		string $selector,
		string $expected,
		array $requestParams
	): void {
		$this->setGroupPermissions( [ 'reviewer' => [ 'viewsuppressed' => true ] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( $requestParams ), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);

		$this->assertStringContainsString(
			$expected,
			$this->assertSelectorMatchesOneElementInNode(
				$this->getRowForRevision( DOMUtils::parseHTML( $html ), $revisionId() ),
				$selector,
				true
			)
		);
	}

	public static function provideDiffPreviews(): array {
		$diff = '.mw-wikimediaantiabuse-abuse-review-row__diff table.diff';

		return [
			'the word an edit replaced' => [
				'revisionId' => static fn (): int => static::$longDiffRevId,
				'selector' => $diff . ' del',
				'expected' => 'First',
				'requestParams' => [],
			],
			'every added line, the preview no longer stopping at ten' => [
				'revisionId' => static fn (): int => static::$longDiffRevId,
				'selector' => $diff,
				'expected' => '>Added line 15</ins>',
				'requestParams' => [],
			],
			'a page creation, diffed against empty content' => [
				'revisionId' => static fn (): int => static::$pageCreationRevId,
				'selector' => $diff,
				'expected' => '>A brand new page</ins>',
				'requestParams' => [],
			],
			'one changed line on a long page' => [
				'revisionId' => static fn (): int => static::$longPageSmallEditRevId,
				'selector' => $diff,
				'expected' => '>A new line.</ins>',
				'requestParams' => [],
			],
			// Suppressing the text handles the row, so it appears only with handled rows shown.
			'suppressed text, for a viewer who may see it' => [
				'revisionId' => static fn (): int => static::$suppressedTextRevId,
				'selector' => '.mw-wikimediaantiabuse-abuse-review-row__diff.history-deleted.mw-history-suppressed',
				'expected' => '>SuppressedSecret</ins>',
				'requestParams' => [ 'wpShowHandledRevisions' => '1' ],
			],
		];
	}

	/**
	 * The preview shows both sides of a change on one line.
	 */
	public function testDiffPreviewIsInline(): void {
		// Core renders a diff inline only with wikidiff2 1.14.0 or later, and only if
		// $wgDiffEngine lets it. Otherwise the preview falls back to core's table diff,
		// which marks the same words elsewhere.
		if ( !in_array( 'inline', ( new DifferenceEngine() )->getSupportedFormats(), true ) ) {
			$this->markTestSkipped( 'Need a diff engine that can render a diff inline' );
		}

		$this->setGroupPermissions( [ 'reviewer' => [ 'viewsuppressed' => true ] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);

		$this->assertStringContainsString(
			'>First</del>',
			$this->assertSelectorMatchesOneElementInNode(
				$this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$longDiffRevId ),
				'.mw-wikimediaantiabuse-abuse-review-row__diff table.diff.diff-type-inline'
					. ' .mw-diff-inline-changed',
				true
			)
		);
	}

	/**
	 * A deleted page's text is core's to gate on deletedtext, and an archived revision's own
	 * bitfield is clear, so the preview must not read the right off the bitfield alone.
	 */
	public function testDeletedPageTextIsWithheldWithoutDeletedtext(): void {
		$this->setGroupPermissions( [ 'reviewer' => [
			'viewsuppressed' => true,
			'deletedhistory' => true,
		] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$rowHtml = DOMCompat::getOuterHTML(
			$this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$deletedPageRevId )
		);

		$this->assertStringNotContainsString( 'DeletedPageSecret', $rowHtml );
		$this->assertStringNotContainsString( 'ArchivedParentSecret', $rowHtml );
	}

	public function testDeletedPageTextIsShownWithDeletedtext(): void {
		$this->setGroupPermissions( [ 'reviewer' => [
			'viewsuppressed' => true,
			'deletedhistory' => true,
			'deletedtext' => true,
		] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$rowHtml = DOMCompat::getOuterHTML(
			$this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$deletedPageRevId )
		);

		$this->assertStringContainsString( 'DeletedPageSecret', $rowHtml );
	}

	public function testOversizeEditIsNotDiffed(): void {
		$this->setGroupPermissions( [ 'reviewer' => [ 'viewsuppressed' => true ] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$oversizeRevId );

		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-diff-too-large)',
			$this->assertSelectorMatchesOneElementInNode(
				$row, '.mw-wikimediaantiabuse-abuse-review-row__oversize-diff', true
			),
			'the row says why it shows no diff'
		);
		$this->assertNull(
			DOMCompat::querySelector( $row, '.mw-wikimediaantiabuse-abuse-review-row__diff' ),
			'and renders no diff at all'
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-open-full-diff)',
			DOMCompat::getOuterHTML( $row ),
			'the link out staying, that being all the reviewer has left'
		);
	}

	public function testRevisionWithMissingContentIsNotDiffed(): void {
		$this->setGroupPermissions( [ 'reviewer' => [ 'viewsuppressed' => true ] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$badContentRevId );

		$this->assertNull(
			DOMCompat::querySelector( $row, '.mw-wikimediaantiabuse-abuse-review-row__diff' ),
			'a revision whose content is gone shows no diff'
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-open-full-diff)',
			DOMCompat::getOuterHTML( $row ),
			'the link out staying, that being all the reviewer has left'
		);
	}

	public function addDBDataOnce(): void {
		$changeTagsStore = $this->getServiceContainer()->getChangeTagsStore();

		$summaryPage = $this->getNonexistingTestPage();
		$this->assertStatusGood( $this->editPage( $summaryPage, 'First line' ) );
		$deletedSummaryEdit = $this->editPage( $summaryPage, 'Second line', 'A summary worth hiding' );
		$this->assertStatusGood( $deletedSummaryEdit );
		static::$deletedSummaryRevId = $deletedSummaryEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$deletedSummaryRevId
		);
		$this->revisionDelete(
			static::$deletedSummaryRevId,
			[ RevisionRecord::DELETED_COMMENT => 1 ]
		);

		$longDiffPage = $this->getNonexistingTestPage();
		$this->assertStatusGood( $this->editPage( $longDiffPage, 'First line' ) );
		$longDiffEdit = $this->editPage( $longDiffPage, implode( "\n", array_map(
			static fn ( int $i ): string => "Added line $i",
			range( 1, 15 )
		) ) );
		$this->assertStatusGood( $longDiffEdit );
		static::$longDiffRevId = $longDiffEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$longDiffRevId
		);

		// A long page, kept inside the size the test wiki will render, with one line added.
		$longPageBody = str_repeat( "An unchanged line.\n", 620 );
		$longPage = $this->getNonexistingTestPage();
		$this->assertStatusGood( $this->editPage( $longPage, $longPageBody ) );
		$longPageEdit = $this->editPage( $longPage, $longPageBody . "A new line.\n" );
		$this->assertStatusGood( $longPageEdit );
		static::$longPageSmallEditRevId = $longPageEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$longPageSmallEditRevId
		);

		// A page creation, every line of which is added, making a diff too long to show.
		$oversizeEdit = $this->editPage(
			$this->getNonexistingTestPage(), str_repeat( "A newly added line.\n", 250 )
		);
		$this->assertStatusGood( $oversizeEdit );
		static::$oversizeRevId = $oversizeEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$oversizeRevId
		);

		$pageCreationEdit = $this->editPage( $this->getNonexistingTestPage(), 'A brand new page' );
		$this->assertStatusGood( $pageCreationEdit );
		static::$pageCreationRevId = $pageCreationEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$pageCreationRevId
		);

		$suppressedTextPage = $this->getNonexistingTestPage();
		$this->assertStatusGood( $this->editPage( $suppressedTextPage, 'Common line' ) );
		$suppressedTextEdit = $this->editPage( $suppressedTextPage, "Common line\nSuppressedSecret" );
		$this->assertStatusGood( $suppressedTextEdit );
		static::$suppressedTextRevId = $suppressedTextEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$suppressedTextRevId
		);
		// Core refuses to hide a page's current revision, so leave one after it.
		$this->assertStatusGood( $this->editPage( $suppressedTextPage, 'Trailing content' ) );
		$this->revisionDelete( static::$suppressedTextRevId, [
			RevisionRecord::DELETED_TEXT => 1,
			RevisionRecord::DELETED_RESTRICTED => 1,
		] );

		$deletedPage = $this->getNonexistingTestPage();
		$this->assertStatusGood(
			$this->editPage( $deletedPage, "Common line\nArchivedParentSecret" )
		);
		$deletedPageEdit = $this->editPage( $deletedPage, "Common line\nDeletedPageSecret" );
		$this->assertStatusGood( $deletedPageEdit );
		static::$deletedPageRevId = $deletedPageEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$deletedPageRevId
		);
		$this->deletePage( $deletedPage );

		$badContentEdit = $this->editPage( $this->getNonexistingTestPage(), 'Content that is gone' );
		$this->assertStatusGood( $badContentEdit );
		$badContentSlot = $badContentEdit->getNewRevision()->getSlot( SlotRecord::MAIN );
		static::$badContentRevId = $badContentEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$badContentRevId
		);
		// This is how findBadBlobs.php marks a blob that is missing or corrupt.
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'content' )
			->set( [ 'content_address' => 'bad:' . urlencode( $badContentSlot->getAddress() ) ] )
			->where( [ 'content_id' => $badContentSlot->getContentId() ] )
			->caller( __METHOD__ )->execute();
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\RevisionRecord;
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
	private static int $truncatedDiffRevId;
	private static int $deletedPageRevId;

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
	 * The preview is capped, and says how many lines it left out.
	 */
	public function testDiffPreviewIsTruncated(): void {
		$this->setGroupPermissions( [ 'reviewer' => [ 'viewsuppressed' => true ] ] );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest(), null, $this->getTestUser( [ 'reviewer' ] )->getUser()
		);
		$rowHtml = DOMCompat::getOuterHTML(
			$this->getRowForRevision( DOMUtils::parseHTML( $html ), static::$truncatedDiffRevId )
		);

		$this->assertStringContainsString( 'Added line 10', $rowHtml, 'the preview runs to the cap' );
		$this->assertStringNotContainsString( 'Added line 11', $rowHtml, 'and stops there' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-diff-truncated: 5)',
			$rowHtml,
			'saying how many lines it left out rather than dropping them silently'
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

		$truncatedDiffPage = $this->getNonexistingTestPage();
		$this->assertStatusGood( $this->editPage( $truncatedDiffPage, 'First line' ) );
		$truncatedDiffEdit = $this->editPage( $truncatedDiffPage, implode( "\n", array_map(
			static fn ( int $i ): string => "Added line $i",
			range( 1, 15 )
		) ) );
		$this->assertStatusGood( $truncatedDiffEdit );
		static::$truncatedDiffRevId = $truncatedDiffEdit->getNewRevision()->getId();
		$changeTagsStore->addTags(
			[ 'mw-private-personal-info' ], null, static::$truncatedDiffRevId
		);

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
	}
}

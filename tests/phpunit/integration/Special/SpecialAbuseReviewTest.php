<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Exception\ErrorPageError;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewTest extends SpecialAbuseReviewTestBase {

	public function testViewWhenCannotSeeAnyAbuseTag(): void {
		$this->expectException( ErrorPageError::class );
		$this->expectExceptionMessage( 'You do not have any of the permissions needed to view this page' );
		$this->executeSpecialPage();
	}

	public function testViewWhenNoRevisionsPresent(): void {
		$testUser = $this->getTestUser( [ 'suppress' ] )->getUser();
		[ $html ] = $this->executeSpecialPage( '', null, null, $testUser );

		$specialPageSummaryHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-specialpage-summary' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-summary)',
			$specialPageSummaryHtml
		);

		$this->verifyFilterButtonPresent( $html, 0 );

		$tablePagerHtml = $this->commonVerifyTablePager( $html, false );
		$tablePagerEmptyContentHtml = $this->assertSelectorMatchesOneElement(
			$tablePagerHtml,
			'.cdx-table__table__empty-state-content'
		);
		foreach ( [ 'title', 'description', 'hint' ] as $part ) {
			$this->assertStringContainsString(
				"(wikimediaantiabuse-special-abuse-review-empty-$part)",
				$tablePagerEmptyContentHtml
			);
		}
		$this->assertSelectorMatchesOneElement(
			$tablePagerHtml,
			'.mw-wikimediaantiabuse-abuse-review-empty-mark'
		);

		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( DOMUtils::parseHTML( $html ), '.cdx-table-pager' ),
			'an empty queue has nothing to page through'
		);
	}

	/**
	 * An edit that changed no lines still gets its link out to the full diff, the preview
	 * having nothing to show.
	 */
	public function testViewWhenRevisionHasNoContentChange(): void {
		$editStatus = $this->editPage( $this->getNonexistingTestPage(), '' );
		$this->assertStatusGood( $editStatus );
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ 'mw-private-personal-info' ],
			null,
			$editStatus->getNewRevision()->getId()
		);

		[ $html ] = $this->executeSpecialPage(
			'', null, null, $this->getTestUser( [ 'suppress' ] )->getUser()
		);
		$row = $this->assertSelectorMatchesOneElementInNode(
			DOMUtils::parseHTML( $html ),
			'.mw-wikimediaantiabuse-abuse-review-row'
		);

		$this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-row__full-diff'
		);
		$this->assertNull(
			DOMCompat::querySelector( $row, '.mw-wikimediaantiabuse-abuse-review-row__diff' ),
			'a preview with no lines either side is left out rather than drawn empty'
		);
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

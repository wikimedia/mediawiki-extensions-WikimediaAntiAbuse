<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Exception\ErrorPageError;

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

		$this->verifyFilterForm( $html );

		$tablePagerHtml = $this->commonVerifyTablePager( $html );
		$tablePagerEmptyContentHtml = $this->assertSelectorMatchesOneElement(
			$tablePagerHtml,
			'.cdx-table__table__empty-state'
		);
		$this->assertStringContainsString( '(table_pager_empty)', $tablePagerEmptyContentHtml );
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @group Database
 */
class SpecialAbuseReviewTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	public function testViewWhenCannotSeeAnyAbuseTag(): void {
		$this->expectException( ErrorPageError::class );
		$this->expectExceptionMessage( 'You do not have any of the permissions needed to view this page' );
		$this->executeSpecialPage();
	}

	public function testViewWhenUserCanSeeAbuseTags(): void {
		$testUser = $this->getTestUser( [ 'suppress' ] )->getUser();
		[ $html ] = $this->executeSpecialPage( '', null, null, $testUser );

		$specialPageSummaryHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-specialpage-summary' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-summary)',
			$specialPageSummaryHtml
		);

		$filterFormHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-htmlform' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-show-false-positives)',
			$filterFormHtml
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-show-handled-revisions)',
			$filterFormHtml
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-filter-submit)',
			$filterFormHtml
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-filter-legend)',
			$filterFormHtml
		);
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

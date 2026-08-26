<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Navigation;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewPagerNavigationBuilder;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewPagerNavigationBuilder
 */
class AbuseReviewPagerNavigationBuilderTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'qqx' );
	}

	public function testGetFilterButtonWithNoFiltersApplied(): void {
		$navBuilder = $this->initializeNavBuilder( 0 );
		$actualFiltersButtonHtml = $navBuilder->getFilterButton();

		$filterButtonHtml = $this->assertSelectorMatchesOneElement(
			$actualFiltersButtonHtml,
			'.mw-wikimediaantiabuse-abuse-review-filter-button'
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-filter-open-button)',
			$filterButtonHtml,
			'Button label was not as expected'
		);
		$this->assertStringNotContainsString(
			'mw-wikimediaantiabuse-abuse-review-filter-button-filters-applied-chip',
			$filterButtonHtml,
			'The info chip indicating how many filters were applied should not be present'
		);
	}

	public function testGetFilterButtonWithFiltersApplied(): void {
		$navBuilder = $this->initializeNavBuilder( 2 );
		$actualFiltersButton = DOMUtils::parseHTML( $navBuilder->getFilterButton() );

		$filterButton = $this->assertSelectorMatchesOneElementInNode(
			$actualFiltersButton,
			'.mw-wikimediaantiabuse-abuse-review-filter-button'
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-filter-open-button)',
			DOMCompat::getInnerHTML( $filterButton ),
			'Button label was not as expected'
		);
		$this->assertSelectorMatchesOneElementInNode(
			$actualFiltersButton,
			'.mw-wikimediaantiabuse-abuse-review-filter-button-filters-applied-chip'
		);
	}

	public function testGetLimitForm(): void {
		$navBuilder = $this->initializeNavBuilder( 0 );
		$actualLimitFormHtml = $navBuilder->getLimitForm();

		$this->assertSelectorMatchesOneElement(
			$actualLimitFormHtml,
			'.mw-wikimediaantiabuse-abuse-review-filter-button',
		);
	}

	private function initializeNavBuilder(
		int $numberOfFiltersApplied
	): AbuseReviewPagerNavigationBuilder {
		return new AbuseReviewPagerNavigationBuilder(
			RequestContext::getMain(),
			[],
			$numberOfFiltersApplied
		);
	}
}

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

	public function testGetHiddenFields(): void {
		$navBuilder = $this->initializeNavBuilder(
			0,
			[
				'sort' => 'timestamp',
				'asc' => '1',
				'desc' => '0',
				'limit' => '15',
				'arrayfield' => [ 'value1', 'value2' ],
			]
		);
		$actualHiddenFieldsHtml = $navBuilder->getHiddenFields( [ 'limit' ] );
		$actualHiddenFields = DOMUtils::parseHTML( $actualHiddenFieldsHtml );

		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="sort"][value="timestamp"]'
		);
		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="asc"][value="1"]'
		);
		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="desc"][value="0"]'
		);
		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="arrayfield[]"][value="value1"]'
		);
		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="arrayfield[]"][value="value2"]'
		);
		$this->assertNull( DOMCompat::querySelector( $actualHiddenFields, 'input[name="limit"]' ) );
	}

	public function testGetHiddenFieldsWithNestedArrayValue(): void {
		$navBuilder = $this->initializeNavBuilder(
			0,
			[ 'nested' => [ 'a' => [ 'b' => '1' ] ], 'sort' => 'timestamp' ]
		);
		$actualHiddenFields = DOMUtils::parseHTML( $navBuilder->getHiddenFields() );

		$this->assertNull( DOMCompat::querySelector( $actualHiddenFields, 'input[name="nested[]"]' ) );
		$this->assertSelectorMatchesOneElementInNode(
			$actualHiddenFields,
			'input[type="hidden"][name="sort"]'
		);
	}

	private function initializeNavBuilder(
		int $numberOfFiltersApplied,
		array $queryValues = []
	): AbuseReviewPagerNavigationBuilder {
		return new AbuseReviewPagerNavigationBuilder(
			RequestContext::getMain(),
			$queryValues,
			$numberOfFiltersApplied
		);
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Navigation;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewTabsBuilder;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewTabsBuilder
 */
class AbuseReviewTabsBuilderTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	/** @dataProvider provideGetHtmlWhenMultipleFlagsAreProvided */
	public function testGetHtmlWhenMultipleFlagsAreProvided(
		array $currentPageQueryParams,
		array $expectedQueryParamsForTabLink
	): void {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );
		$context->setTitle( SpecialPage::getTitleFor( 'AbuseReview' ) );
		$context->setRequest( new FauxRequest( $currentPageQueryParams ) );

		$tabs = [
			'mw-private-test' => 0,
			'mw-private-personal-info' => 10,
			'mw-private-vandalism' => 15,
		];
		$tabsBuilder = new AbuseReviewTabsBuilder(
			$context,
			$tabs,
			'mw-private-personal-info',
			10
		);

		$tabsList = $this->assertSelectorMatchesOneElementInNode(
			DOMUtils::parseHTML( $tabsBuilder->getHtml() ),
			'.cdx-tabs.mw-wikimediaantiabuse-abuse-review-tabs .cdx-tabs__header nav.cdx-tabs__list'
		);

		$this->assertSame(
			'(wikimediaantiabuse-special-abuse-review-tabs-label)',
			$tabsList->getAttribute( 'aria-label' ),
			'Aria label should be as expected'
		);
		$this->assertSame(
			'tablist',
			$tabsList->getAttribute( 'role' ),
			'Tablist should have the expected role'
		);

		$actualTabs = DOMCompat::querySelectorAll( $tabsList, '.cdx-tabs__list__item' );
		$this->assertCount( 3, $actualTabs, 'Three tabs should be present' );

		$expectedTabs = [
			'mw-private-test' => [ 'count' => 0, 'countForDisplay' => '0' ],
			'mw-private-personal-info' => [ 'count' => 10, 'countForDisplay' => '10' ],
			'mw-private-vandalism' => [
				'count' => 15,
				'countForDisplay' => '(wikimediaantiabuse-special-abuse-review-tab-count-capped: 10)'
			],
		];
		foreach ( $actualTabs as $actualTab ) {
			$expectedTab = array_key_first( $expectedTabs );
			[ 'count' => $expectedCount, 'countForDisplay' => $expectedCountForDisplay ] = array_shift( $expectedTabs );

			$this->assertSame(
				'tab',
				$actualTab->getAttribute( 'role' ),
				'Tab should have the expected role'
			);
			$this->assertSame(
				'cdx-tabs__list__item mw-wikimediaantiabuse-abuse-review-tab-' . $expectedTab,
				$actualTab->getAttribute( 'class' ),
				'Tab should have the expected classes'
			);
			if ( $expectedTab === 'mw-private-personal-info' ) {
				$this->assertSame(
					'true',
					$actualTab->getAttribute( 'aria-selected' ),
					'Tab should be labelled as selected'
				);
			} else {
				$this->assertSame(
					'false',
					$actualTab->getAttribute( 'aria-selected' ),
					'Tab should be labelled as not selected'
				);
			}

			$actualHref = $actualTab->getAttribute( 'href' );
			$this->assertArrayEquals(
				array_merge( [
					'title' => 'Special:AbuseReview',
					'tab' => $expectedTab,
				], $expectedQueryParamsForTabLink ),
				wfCgiToArray( parse_url( $actualHref )['query'] ),
				false,
				true
			);

			// Use a regex to assert on the message key, as the displayed count has HTML we assert on further below
			$this->assertMatchesRegularExpression(
				'/' . preg_quote( '(wikimediaantiabuse-special-abuse-review-tab-' . $expectedTab, '/' ) .
					':[\s\S]*' . preg_quote( $expectedCountForDisplay, '/' ) . '[\s\S]*, ' . $expectedCount . '\)/',
				$actualTab->textContent,
				'Tab should have the expected label'
			);

			$infoChipElement = $this->assertSelectorMatchesOneElementInNode(
				$actualTab,
				'.mw-wikimediaantiabuse-abuse-review-tabs__count'
			);

			$infoChipText = $this->assertSelectorMatchesOneElementInNode(
				$infoChipElement,
				'.cdx-info-chip__text'
			);
			$this->assertSame(
				$expectedCountForDisplay,
				DOMCompat::getInnerHTML( $infoChipText ),
				'Info chip text should be match the expected text'
			);
		}
	}

	public static function provideGetHtmlWhenMultipleFlagsAreProvided(): array {
		return [
			'Title has no query parameters set' => [
				'currentPageQueryParams' => [],
				'expectedQueryParamsForTabLink' => [],
			],
			'Title has limit, username, and dir query parameters set' => [
				'currentPageQueryParams' => [ 'limit' => '50', 'username' => 'Test', 'dir' => 'prev' ],
				'expectedQueryParamsForTabLink' => [ 'limit' => '50' ],
			],
		];
	}
}

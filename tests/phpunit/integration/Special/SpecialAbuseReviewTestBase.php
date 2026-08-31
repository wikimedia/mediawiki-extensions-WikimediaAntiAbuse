<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Tests\Specials\SpecialPageTestBase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * Base class for tests that test {@link SpecialAbuseReview}
 */
abstract class SpecialAbuseReviewTestBase extends SpecialPageTestBase {

	/** Each row holds core's diff table, which a plain `tbody tr` would also match. */
	protected const string ROW_SELECTOR = 'tbody tr.mw-wikimediaantiabuse-abuse-review-row';

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	/**
	 * Verifies that the filter button is present in the form
	 */
	protected function verifyFilterButtonPresent( string $html, int $numberOfFiltersApplied ): void {
		$filterButtons = DOMCompat::querySelectorAll(
			DOMUtils::parseHTML( $html ),
			'.mw-wikimediaantiabuse-abuse-review-filter-button'
		);
		$this->assertGreaterThan(
			0,
			count( $filterButtons ),
			'The filter button should be present in the form'
		);
		foreach ( $filterButtons as $filterButton ) {
			$filterButtonHtml = DOMCompat::getInnerHTML( $filterButton );
			$this->assertStringContainsString(
				'(wikimediaantiabuse-special-abuse-review-filter-open-button)',
				$filterButtonHtml,
				'The filter button should have the correct label'
			);
			$this->assertSelectorMatchesOneElementInNode(
				$filterButton,
				'.mw-wikimediaantiabuse-abuse-review-icon-filter'
			);
			if ( $numberOfFiltersApplied === 0 ) {
				$this->assertStringNotContainsString(
					'mw-wikimediaantiabuse-abuse-review-filter-button-filters-applied-chip',
					$filterButtonHtml
				);
			} else {
				$chip = $this->assertSelectorMatchesOneElementInNode(
					$filterButton,
					'.mw-wikimediaantiabuse-abuse-review-filter-button-filters-applied-chip'
				);
				$chipText = $this->assertSelectorMatchesOneElementInNode(
					$chip,
					'.cdx-info-chip__text'
				);
				$this->assertSame(
					(string)$numberOfFiltersApplied,
					DOMCompat::getInnerHTML( $chipText ),
					'The chip should show the number of filters applied'
				);
			}
		}
	}

	/**
	 * Verifies the structure of the table pager for assertions that are common to all tests
	 */
	protected function commonVerifyTablePager( string $html, bool $shouldHaveRows ): string {
		$htmlAsNode = DOMUtils::parseHTML( $html );
		$tablePager = $this->assertSelectorMatchesOneElementInNode(
			$htmlAsNode,
			'.cdx-table__table.mw-wikimediaantiabuse-abuse-review-table'
		);
		if ( $shouldHaveRows ) {
			$this->assertTrue(
				DOMCompat::getClassList( $tablePager )->contains(
					'mw-wikimediaantiabuse-abuse-review-table-with-navigation-bar'
				),
				'The table should have the navigation bar class when it has rows'
			);
		}

		$tablePagerCaption = $this->assertSelectorMatchesOneElementInNode( $tablePager, 'caption', true );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-caption)',
			$tablePagerCaption
		);

		$headings = DOMCompat::querySelectorAll( $tablePager, 'thead th' );
		$this->assertCount( 4, $headings );
		foreach ( [
			'(wikimediaantiabuse-special-abuse-review-heading-revision)',
			'(wikimediaantiabuse-special-abuse-review-heading-flags)',
			'(wikimediaantiabuse-special-abuse-review-heading-timestamp)',
		] as $index => $expectedHeading ) {
			$this->assertStringContainsString(
				$expectedHeading,
				DOMCompat::getInnerHTML( $headings[$index] )
			);
		}
		$sortButton = $this->assertSelectorMatchesOneElementInNode(
			$tablePager, '.cdx-table__table__sort-button', true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-timestamp)',
			$sortButton,
			'only the revision timestamp column should be sortable'
		);

		return DOMCompat::getOuterHTML( $tablePager );
	}

	/**
	 * Returns the row the pager rendered for one revision, to assert on the parts of it a test cares
	 * about.
	 */
	protected function getRowForRevision( Document|Element $node, int $revId ): Element {
		foreach ( DOMCompat::querySelectorAll( $node, self::ROW_SELECTOR ) as $tableRow ) {
			if ( (int)DOMCompat::getAttribute( $tableRow, 'data-rev-id' ) === $revId ) {
				return $tableRow;
			}
		}
		$this->fail( "No row was rendered for revision $revId" );
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

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

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	/**
	 * Verifies the filter form at the top of the special page has the right structure
	 */
	protected function verifyFilterForm( string $html ): void {
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

	/**
	 * Verifies the structure of the table pager for assertions that are common to all tests
	 */
	protected function commonVerifyTablePager( string $html ): string {
		$htmlAsNode = DOMUtils::parseHTML( $html );
		$tablePager = $this->assertSelectorMatchesOneElementInNode( $htmlAsNode, '.cdx-table__table' );

		$tablePagerCaption = $this->assertSelectorMatchesOneElementInNode( $tablePager, 'caption', true );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-caption)',
			$tablePagerCaption
		);

		// Three labelled columns and the unlabelled one holding the show/hide toggle.
		$headings = DOMCompat::querySelectorAll( $tablePager, 'thead th' );
		$this->assertCount( 4, $headings );
		// The timestamp heading sits inside the sort control, asserted below.
		$this->assertSame(
			[
				'(wikimediaantiabuse-special-abuse-review-heading-revision)',
				'(wikimediaantiabuse-special-abuse-review-heading-flags)',
			],
			array_map(
				static fn ( $heading ) => trim( DOMCompat::getInnerHTML( $heading ) ),
				array_slice( iterator_to_array( $headings ), 0, 2 )
			),
			'the columns are labelled in the order the design shows them'
		);
		$sortButton = $this->assertSelectorMatchesOneElementInNode(
			$tablePager, '.cdx-table__table__sort-button', true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-timestamp)',
			$sortButton,
			'only the timestamp column should be sortable'
		);

		return DOMCompat::getOuterHTML( $tablePager );
	}

	/**
	 * Returns the row the pager rendered for one revision, to assert on the parts of it a test cares
	 * about.
	 */
	protected function getRowForRevision( Document|Element $node, int $revId ): Element {
		foreach ( DOMCompat::querySelectorAll( $node, 'tbody tr' ) as $tableRow ) {
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

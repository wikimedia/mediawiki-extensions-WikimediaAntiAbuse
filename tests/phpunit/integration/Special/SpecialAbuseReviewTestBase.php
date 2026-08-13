<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Tests\Specials\SpecialPageTestBase;
use Wikimedia\Parsoid\Core\DOMCompat;
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
			'(cdx-table-sort-caption: (wikimediaantiabuse-special-abuse-review-caption))',
			$tablePagerCaption
		);

		$timestampColumnHeaderHtml = $this->assertSelectorMatchesOneElementInNode(
			$tablePager,
			'th.cdx-table-pager__col--timestamp',
			true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-revision)',
			$timestampColumnHeaderHtml
		);

		$authorColumnHeaderHtml = $this->assertSelectorMatchesOneElementInNode(
			$tablePager,
			'th.cdx-table-pager__col--user_text',
			true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-author)',
			$authorColumnHeaderHtml
		);

		$tagsColumnHeaderHtml = $this->assertSelectorMatchesOneElementInNode(
			$tablePager,
			'th.cdx-table-pager__col--ts_tags',
			true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-tags)',
			$tagsColumnHeaderHtml
		);

		$actionsColumnHeaderHtml = $this->assertSelectorMatchesOneElementInNode(
			$tablePager,
			'th.cdx-table-pager__col--actions',
			true
		);
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-heading-actions)',
			$actionsColumnHeaderHtml
		);

		return DOMCompat::getOuterHTML( $tablePager );
	}

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

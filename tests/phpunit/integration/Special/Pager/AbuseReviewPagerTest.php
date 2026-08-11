<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class AbuseReviewPagerTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private function getObjectUnderTest( array $tagsFilter ): AbuseReviewPager {
		return new AbuseReviewPager(
			RequestContext::getMain(),
			$this->getServiceContainer()->getLinkRenderer(),
			$this->getServiceContainer()->getChangeTagsStore(),
			$this->getServiceContainer()->getChangeTagsFormatter(),
			$this->getServiceContainer()->getRevisionStore(),
			$this->getServiceContainer()->getUserEditTracker(),
			$this->getServiceContainer()->getLinkBatchFactory(),
			$tagsFilter,
			false
		);
	}

	public function testFormatValueWhenNameUnknown(): void {
		$objectUnderTest = $this->getObjectUnderTest( [ 'mw-private-test' ] );
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->formatValue( 'unknown', 'some value' );
	}

	public function testGetQueryInfoWhenTagsFilterEmpty(): void {
		$objectUnderTest = $this->getObjectUnderTest( [] );
		$actualQueryInfo = $objectUnderTest->getQueryInfo();
		$this->assertContains( '1=0', $actualQueryInfo['conds'] );
	}

	public function testFormatValueWhenRevisionHasNoReviewTags(): void {
		RequestContext::getMain()->setTitle( Title::makeTitle( NS_SPECIAL, 'Special:AbuseReview' ) );

		$editStatus = $this->editPage( $this->getNonexistingTestPage(), 'Test' );
		$this->assertStatusGood( $editStatus );
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ 'mw-reverted' ], null, $editStatus->getNewRevision()->getId()
		);

		$objectUnderTest = $this->getObjectUnderTest( [ 'mw-reverted' ] );
		$actualPagerHtml = $objectUnderTest->getBody();

		$tableRows = DOMCompat::querySelectorAll( DOMUtils::parseHTML( $actualPagerHtml ), 'tbody tr' );
		$this->assertCount( 1, $tableRows );
		$tableRow = $tableRows[0];

		$tagsCellHtml = $this->assertSelectorMatchesOneElementInNode(
			$tableRow,
			'.cdx-table-pager__col--ts_tags',
			true
		);
		$this->assertStringNotContainsString(
			'mw-reverted',
			$tagsCellHtml,
			'Only abuse review tags should be displayed'
		);

		$this->assertStringNotContainsString(
			'mw-wikimediaantiabuse-abuse-review-tag',
			$actualPagerHtml,
			'No action buttons should be present if the revision has no abuse review tags'
		);
	}
}

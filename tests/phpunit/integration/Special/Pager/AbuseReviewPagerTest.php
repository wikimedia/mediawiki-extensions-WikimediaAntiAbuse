<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use LogicException;
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
			$this->getServiceContainer()->getArchivedRevisionLookup(),
			$this->getServiceContainer()->getLinkBatchFactory(),
			$this->getServiceContainer()->getRowCommentFormatter(),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseRevisionSnippetGenerator' ),
			$tagsFilter,
			false
		);
	}

	public function testFormatValueWhenNameUnknown(): void {
		$objectUnderTest = $this->getObjectUnderTest( [ 'mw-private-test' ] );
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->formatValue( 'unknown', 'some value' );
	}

	/** @dataProvider provideGetQueryInfoWhenInvalidTableProvided */
	public function testGetQueryInfoWhenInvalidTableProvided( ?string $table ): void {
		$this->expectException( LogicException::class );
		$this->getObjectUnderTest( [] )->getQueryInfo( $table );
	}

	public static function provideGetQueryInfoWhenInvalidTableProvided(): array {
		return [
			'No table provided' => [ 'table' => null ],
			'Unhandled table provided' => [ 'table' => 'logging' ],
		];
	}

	/** @dataProvider provideTablesForEmptyTagsFilter */
	public function testGetQueryInfoWhenTagsFilterEmpty( string $table ): void {
		$objectUnderTest = $this->getObjectUnderTest( [] );
		$actualQueryInfo = $objectUnderTest->getQueryInfo( $table );
		$this->assertContains( '1=0', $actualQueryInfo['conds'] );
	}

	public static function provideTablesForEmptyTagsFilter(): array {
		return [
			'revision table' => [ 'revision' ],
			'archive table' => [ 'archive' ],
		];
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

		$flagsCellHtml = $this->assertSelectorMatchesOneElementInNode(
			$tableRow,
			'.cdx-table-pager__col--flags',
			true
		);
		$this->assertStringNotContainsString(
			'mw-reverted',
			$flagsCellHtml,
			'Only abuse review tags should be displayed'
		);

		$this->assertSame(
			'',
			trim( DOMCompat::getInnerHTML( $this->assertSelectorMatchesOneElementInNode(
				$tableRow,
				'.cdx-table-pager__col--flags'
			) ) ),
			'A revision with no abuse review tag has no flag to show and nothing to judge'
		);
		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( $tableRow, '.mw-wikimediaantiabuse-abuse-review-verdicts button' ),
			'A revision with no abuse review tag has no verdict buttons'
		);
	}
}

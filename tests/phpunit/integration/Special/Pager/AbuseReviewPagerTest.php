<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class AbuseReviewPagerTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private function getObjectUnderTest( string $abuseReviewTag ): AbuseReviewPager {
		return new AbuseReviewPager(
			RequestContext::getMain(),
			$this->getServiceContainer()->getLinkRenderer(),
			$this->getServiceContainer()->getChangeTagsStore(),
			$this->getServiceContainer()->getRevisionStore(),
			$this->getServiceContainer()->getArchivedRevisionLookup(),
			$this->getServiceContainer()->getLinkBatchFactory(),
			$this->getServiceContainer()->getRowCommentFormatter(),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictPerformerLookup' ),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttributionFormatter' ),
			$abuseReviewTag,
			false,
			false,
			[],
			[],
			[],
			0,
			0
		);
	}

	public function testFormatValueWhenNameUnknown(): void {
		$objectUnderTest = $this->getObjectUnderTest( 'mw-private-test' );
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->formatValue( 'unknown', 'some value' );
	}

	/** @dataProvider provideGetQueryInfoWhenInvalidTableProvided */
	public function testGetQueryInfoWhenInvalidTableProvided( ?string $table ): void {
		$this->expectException( LogicException::class );
		$this->getObjectUnderTest( '' )->getQueryInfo( $table );
	}

	public static function provideGetQueryInfoWhenInvalidTableProvided(): array {
		return [
			'No table provided' => [ 'table' => null ],
			'Unhandled table provided' => [ 'table' => 'logging' ],
		];
	}

	/** @dataProvider provideTablesForEmptyTagsFilter */
	public function testGetQueryInfoWhenTagsFilterEmpty( string $table ): void {
		$objectUnderTest = $this->getObjectUnderTest( '' );
		$actualQueryInfo = $objectUnderTest->getQueryInfo( $table );
		$this->assertContains( '1=0', $actualQueryInfo['conds'] );
	}

	public static function provideTablesForEmptyTagsFilter(): array {
		return [
			'revision table' => [ 'revision' ],
			'archive table' => [ 'archive' ],
		];
	}
}

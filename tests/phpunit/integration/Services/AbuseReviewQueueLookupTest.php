<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use LogicException;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup
 * @group Database
 */
class AbuseReviewQueueLookupTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	private AbuseReviewQueueLookup $queueLookup;

	protected function setUp(): void {
		parent::setUp();

		$this->queueLookup = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewQueueLookup' );
	}

	public function testGetQueryInfoWhenUnknownTable(): void {
		$this->expectException( LogicException::class );
		$this->queueLookup->getQueryInfo(
			'logging',
			new AbuseReviewQueueFilters( '' ),
			$this->mockAnonNullAuthority()
		);
	}

	/** @dataProvider provideTablesForEmptyTagsFilter */
	public function testGetQueryInfoWhenTagsFilterEmpty( string $table ): void {
		$actualQueryInfo = $this->queueLookup->getQueryInfo(
			$table,
			new AbuseReviewQueueFilters( '' ),
			$this->mockAnonNullAuthority()
		);
		$this->assertContains( '1=0', $actualQueryInfo['conds'] );
	}

	public static function provideTablesForEmptyTagsFilter(): array {
		return [
			'revision table' => [ 'table' => 'revision' ],
			'archive table' => [ 'table' => 'archive' ],
		];
	}
}

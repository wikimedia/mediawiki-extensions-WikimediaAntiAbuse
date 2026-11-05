<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountBlockLookup;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountBlockLookup
 */
class AbuseReviewAccountBlockLookupTest extends MediaWikiUnitTestCase {

	public function testGetBlockedUserIdsWithoutUsers(): void {
		$accountBlockLookup = new AbuseReviewAccountBlockLookup(
			$this->createNoOpMock( DatabaseBlockStore::class ),
			$this->createNoOpMock( CentralIdLookup::class ),
			null
		);

		$this->assertSame(
			[],
			$accountBlockLookup->getBlockedUserIds( [] ),
			'An empty list of users has no blocked user, and no block is read'
		);
	}

	public function testGetBlockedUserIdsWithoutGlobalBlocking(): void {
		$blockStore = $this->createMock( DatabaseBlockStore::class );
		$blockStore->method( 'newListFromConds' )->willReturn( [] );
		$accountBlockLookup = new AbuseReviewAccountBlockLookup(
			$blockStore,
			$this->createNoOpMock( CentralIdLookup::class ),
			null
		);

		$this->assertSame(
			[],
			$accountBlockLookup->getBlockedUserIds( [ new UserIdentityValue( 1, 'TestUser' ) ] ),
			'Without GlobalBlocking, the lookup reads only the local blocks and no central ID'
		);
	}
}

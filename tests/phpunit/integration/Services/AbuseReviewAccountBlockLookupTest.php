<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountBlockLookup
 * @group Database
 */
class AbuseReviewAccountBlockLookupTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideLocalBlocks */
	public function testGetBlockedUserIdsWithALocalBlock( array $blockOptions, bool $isBlocked ): void {
		$user = $this->getMutableTestUser()->getUser();
		$unblockedUser = $this->getMutableTestUser()->getUser();
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams(
			[ 'targetUser' => $user, 'by' => $this->getTestSysop()->getUser() ] + $blockOptions
		);

		$accountBlockLookup = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewAccountBlockLookup' );
		$expectedUserIds = $isBlocked ? [ $user->getId() ] : [];
		$this->assertSame(
			$expectedUserIds,
			$accountBlockLookup->getBlockedUserIds( [ $user, $unblockedUser ] ),
			'The lookup returns the IDs of the users who have an active sitewide block, and no other ID'
		);
	}

	public static function provideLocalBlocks(): array {
		return [
			'Sitewide block' => [ 'blockOptions' => [], 'isBlocked' => true ],
			'Partial block' => [ 'blockOptions' => [ 'sitewide' => false ], 'isBlocked' => false ],
		];
	}

	public function testGetBlockedUserIdsWithAGlobalBlock(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalBlocking' );
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
		$user = $this->getMutableTestUser()->getUser();
		$services = $this->getServiceContainer();
		$blockStatus = $services->get( 'GlobalBlocking.GlobalBlockManager' )->block(
			$user->getName(),
			'Test global block',
			'infinite',
			$this->getTestSysop()->getAuthority()
		);
		$this->assertStatusGood( $blockStatus );

		$accountBlockLookup = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountBlockLookup' );
		$this->assertSame(
			[ $user->getId() ],
			$accountBlockLookup->getBlockedUserIds( [ $user ] ),
			'The lookup returns the ID of a globally blocked user'
		);
	}

	/** @dataProvider provideLocalBlockExpiries */
	public function testIsIndefinitelyBlockedWithALocalBlock(
		string $expiry,
		bool $sitewide,
		bool $isIndefinitelyBlocked
	): void {
		$user = $this->getMutableTestUser()->getUser();
		$unblockedUser = $this->getMutableTestUser()->getUser();
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $user,
			'by' => $this->getTestSysop()->getUser(),
			'expiry' => $expiry,
			'sitewide' => $sitewide,
		] );

		$accountBlockLookup = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewAccountBlockLookup' );
		$this->assertSame(
			$isIndefinitelyBlocked,
			$accountBlockLookup->isIndefinitelyBlocked( $user ),
			'Only an active indefinite sitewide block makes the user indefinitely blocked'
		);
		$this->assertFalse(
			$accountBlockLookup->isIndefinitelyBlocked( $unblockedUser ),
			'A user without a block is not indefinitely blocked when another user has a block'
		);
	}

	public static function provideLocalBlockExpiries(): array {
		return [
			'Indefinite sitewide block' => [
				'expiry' => 'infinity',
				'sitewide' => true,
				'isIndefinitelyBlocked' => true,
			],
			'Temporary sitewide block' => [
				'expiry' => '20990101000000',
				'sitewide' => true,
				'isIndefinitelyBlocked' => false,
			],
			'Indefinite partial block' => [
				'expiry' => 'infinity',
				'sitewide' => false,
				'isIndefinitelyBlocked' => false,
			],
		];
	}

	public function testIsIndefinitelyBlockedWithAGlobalBlock(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalBlocking' );
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );
		$user = $this->getMutableTestUser()->getUser();
		$services = $this->getServiceContainer();
		$blockStatus = $services->get( 'GlobalBlocking.GlobalBlockManager' )->block(
			$user->getName(),
			'Test global block',
			'infinite',
			$this->getTestSysop()->getAuthority()
		);
		$this->assertStatusGood( $blockStatus );

		$accountBlockLookup = $services->get( 'WikimediaAntiAbuseAbuseReviewAccountBlockLookup' );
		$this->assertTrue(
			$accountBlockLookup->isIndefinitelyBlocked( $user ),
			'An indefinite global block makes the user indefinitely blocked'
		);
	}
}

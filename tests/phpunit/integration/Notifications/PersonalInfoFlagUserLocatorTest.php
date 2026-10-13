<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Notifications;

use GlobalPreferences\Storage;
use MediaWiki\MainConfigNames;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagUserLocator
 * @group Database
 */
class PersonalInfoFlagUserLocatorTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );

		parent::setUp();

		// Tag access (ListRestrictedTags) is only registered when the tag feature is enabled.
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
		$this->setGroupPermissions( 'suppress', 'viewsuppressed', true );
		$this->setGroupPermissions( 'suppress-only', 'suppressrevision', true );
	}

	public function testSubscriptionDefaultsToOff(): void {
		$userOptionsLookup = $this->getServiceContainer()->getUserOptionsLookup();

		$this->assertFalse(
			(bool)$userOptionsLookup->getDefaultOption( 'echo-subscriptions-web-personal-info' ),
			'web subscription must default to off for locate() to find opted-in users'
		);
		$this->assertFalse(
			(bool)$userOptionsLookup->getDefaultOption( 'echo-subscriptions-email-personal-info' ),
			'email subscription must default to off for locate() to find opted-in users'
		);
		$this->assertFalse(
			(bool)$userOptionsLookup->getDefaultOption( 'echo-subscriptions-push-personal-info' ),
			'push subscription must default to off for locate() to find opted-in users'
		);
	}

	/** @dataProvider provideEligibility */
	public function testLocate(
		bool $optedIn,
		array $groups,
		bool $expectEligible,
		string $subscriptionType = 'web'
	): void {
		$user = $this->getTestUser( $groups )->getUser();
		if ( $optedIn ) {
			$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
			$userOptionsManager->setOption( $user, "echo-subscriptions-$subscriptionType-personal-info", '1' );
			$userOptionsManager->saveOptions( $user );
		}

		$result = $this->getServiceContainer()
			->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
			->locate();

		if ( $expectEligible ) {
			$this->assertArrayHasKey( $user->getId(), $result );
			$this->assertContainsOnlyInstancesOf( User::class, $result );
		} else {
			$this->assertArrayNotHasKey( $user->getId(), $result );
		}
	}

	public static function provideEligibility(): array {
		return [
			'opted in and holds viewsuppressed is eligible' => [
				'optedIn' => true,
				'groups' => [ 'suppress' ],
				'expectEligible' => true,
			],
			'opted in and holds only suppressrevision is eligible' => [
				'optedIn' => true,
				'groups' => [ 'suppress-only' ],
				'expectEligible' => true,
			],
			'opted in via push only and holds viewsuppressed is eligible' => [
				'optedIn' => true,
				'groups' => [ 'suppress' ],
				'expectEligible' => true,
				'subscriptionType' => 'push',
			],
			'opted in without tag access is excluded' => [
				'optedIn' => true,
				'groups' => [],
				'expectEligible' => false,
			],
			'holds viewsuppressed but not opted in is excluded' => [
				'optedIn' => false,
				'groups' => [ 'suppress' ],
				'expectEligible' => false,
			],
			'neither opted in nor tag access is excluded' => [
				'optedIn' => false,
				'groups' => [],
				'expectEligible' => false,
			],
		];
	}

	public function testLocateReturnsOnlyEligibleUsers(): void {
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();

		$eligible = $this->getTestUser( [ 'suppress' ] )->getUser();
		$userOptionsManager->setOption( $eligible, 'echo-subscriptions-web-personal-info', '1' );
		$userOptionsManager->saveOptions( $eligible );

		// Opted in but without tag access, so it must be filtered out of the audience.
		$ineligible = $this->getTestUser()->getUser();
		$userOptionsManager->setOption( $ineligible, 'echo-subscriptions-web-personal-info', '1' );
		$userOptionsManager->saveOptions( $ineligible );

		$result = $this->getServiceContainer()
			->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
			->locate();

		$this->assertSame( [ $eligible->getId() ], array_keys( $result ) );
	}

	/** @dataProvider provideGlobalEligibility */
	public function testLocateWithGlobalOptIn(
		array $groups,
		bool $localOptOut,
		bool $expectEligible
	): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );
		// Use local ids as central ids, so that the test does not depend on CentralAuth.
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );

		$user = $this->getTestUser( $groups )->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption(
			$user,
			'echo-subscriptions-web-personal-info',
			'1',
			UserOptionsManager::GLOBAL_CREATE
		);
		$userOptionsManager->saveOptions( $user );
		$this->assertTrue(
			$userOptionsManager->isOptionGlobal( $user, 'echo-subscriptions-web-personal-info' ),
			'opt-in must be stored as a global preference'
		);

		if ( $localOptOut ) {
			$userOptionsManager->setOption(
				$user,
				'echo-subscriptions-web-personal-info',
				'0',
				UserOptionsManager::GLOBAL_OVERRIDE
			);
			$userOptionsManager->saveOptions( $user );
		}

		$result = $this->getServiceContainer()
			->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
			->locate();

		if ( $expectEligible ) {
			$this->assertArrayHasKey( $user->getId(), $result );
		} else {
			$this->assertArrayNotHasKey( $user->getId(), $result );
		}
	}

	public static function provideGlobalEligibility(): array {
		return [
			'global opt-in and holds viewsuppressed is eligible' => [
				'groups' => [ 'suppress' ],
				'localOptOut' => false,
				'expectEligible' => true,
			],
			'global opt-in without tag access is excluded' => [
				'groups' => [],
				'localOptOut' => false,
				'expectEligible' => false,
			],
			'global opt-in disabled by a local exception is excluded' => [
				'groups' => [ 'suppress' ],
				'localOptOut' => true,
				'expectEligible' => false,
			],
		];
	}

	public function testLocateIgnoresGlobalOptInWithoutLocalAccount(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'GlobalPreferences' );
		$this->overrideConfigValue( MainConfigNames::CentralIdLookupProvider, 'local' );

		// No user on this wiki has this central id.
		( new Storage( 12345678 ) )->replaceAndDelete( [ 'echo-subscriptions-web-personal-info' => '1' ], [] );

		$result = $this->getServiceContainer()
			->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
			->locate();

		$this->assertSame( [], $result );
	}
}

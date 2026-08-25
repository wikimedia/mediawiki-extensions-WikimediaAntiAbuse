<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator;
use MediaWikiIntegrationTestCase;

/**
 * @coversNothing
 * @group Database
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	/** @dataProvider provideService */
	public function testService( string $name ): void {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService(): iterable {
		$wiring = require __DIR__ . '/../../../includes/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}

	/** @dataProvider providePersonalInfoFlagNotificationsEnabled */
	public function testFlagNotificationModeratorFollowsTheFeatureFlag(
		bool $personalInfoFlagNotificationsEnabled,
		string $expectedClass
	): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->overrideConfigValue(
			'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications',
			$personalInfoFlagNotificationsEnabled
		);

		$this->assertInstanceOf(
			$expectedClass,
			$this->getServiceContainer()->get( 'WikimediaAntiAbusePersonalInfoFlagNotificationModerator' )
		);
	}

	public static function providePersonalInfoFlagNotificationsEnabled(): array {
		return [
			'Personal-info flag notifications enabled' => [
				'personalInfoFlagNotificationsEnabled' => true,
				'expectedClass' => EchoPersonalInfoFlagNotificationModerator::class,
			],
			'Personal-info flag notifications disabled' => [
				'personalInfoFlagNotificationsEnabled' => false,
				'expectedClass' => NullPersonalInfoFlagNotificationModerator::class,
			],
		];
	}
}

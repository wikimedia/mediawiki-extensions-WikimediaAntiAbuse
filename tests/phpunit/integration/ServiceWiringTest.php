<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationDeleter;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationDeleter;
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
	public function testFlagNotificationDeleterFollowsTheFeatureFlag(
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
			$this->getServiceContainer()->get( 'WikimediaAntiAbusePersonalInfoFlagNotificationDeleter' )
		);
	}

	public static function providePersonalInfoFlagNotificationsEnabled(): array {
		return [
			'Personal-info flag notifications enabled' => [
				'personalInfoFlagNotificationsEnabled' => true,
				'expectedClass' => EchoPersonalInfoFlagNotificationDeleter::class,
			],
			'Personal-info flag notifications disabled' => [
				'personalInfoFlagNotificationsEnabled' => false,
				'expectedClass' => NullPersonalInfoFlagNotificationDeleter::class,
			],
		];
	}
}

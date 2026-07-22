<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoHooksHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagPresentationModel;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoHooksHandler
 */
class EchoHooksHandlerTest extends MediaWikiUnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( !class_exists( Event::class ) ) {
			$this->markTestSkipped( 'Echo is not loaded' );
		}
	}

	public function testOnBeforeCreateEchoEventRegistersNothingWhenDisabled(): void {
		$notifications = [];
		$notificationCategories = [];
		$icons = [];

		$this->newHandler( false )
			->onBeforeCreateEchoEvent( $notifications, $notificationCategories, $icons );

		$this->assertSame( [], $notifications );
		$this->assertSame( [], $notificationCategories );
		$this->assertSame( [], $icons );
	}

	public function testOnBeforeCreateEchoEventRegistersNotificationWhenEnabled(): void {
		$notifications = [];
		$notificationCategories = [];
		$icons = [];

		$this->newHandler( true )
			->onBeforeCreateEchoEvent( $notifications, $notificationCategories, $icons );

		$this->assertArrayHasKey( 'personal-info', $notificationCategories );
		$this->assertArrayNotHasKey(
			'usergroups',
			$notificationCategories['personal-info'],
			'The category must not be restricted by local user groups'
		);
		$this->assertArrayEquals(
			[ 'priority' => 2, 'tooltip' => 'echo-pref-tooltip-personal-info' ],
			$notificationCategories['personal-info'],
			false,
			true
		);

		$this->assertArrayHasKey( PersonalInfoFlagNotifier::EVENT_TYPE, $notifications );
		$notification = $notifications[PersonalInfoFlagNotifier::EVENT_TYPE];
		$this->assertSame( 'personal-info', $notification['category'] );
		$this->assertSame( 'alert', $notification['section'] );
		$this->assertSame( PersonalInfoFlagPresentationModel::class, $notification['presentation-model'] );
		$this->assertArrayNotHasKey(
			AttributeManager::ATTR_LOCATORS,
			$notification,
			'The notifier passes recipients explicitly, so no Echo locator should be registered'
		);
		$this->assertArrayEquals( [ 'web' => true, 'expandable' => true ], $notification['bundle'], false, true );
	}

	/** @dataProvider provideBundleRules */
	public function testOnEchoGetBundleRules(
		string $eventType,
		int $getTypeCalls,
		string $initialBundleString,
		string $expectedBundleString
	): void {
		$event = $this->createMock( Event::class );
		$event->expects( $this->exactly( $getTypeCalls ) )
			->method( 'getType' )
			->willReturn( $eventType );

		$bundleString = $initialBundleString;
		$this->newHandler( true )->onEchoGetBundleRules( $event, $bundleString );

		$this->assertSame( $expectedBundleString, $bundleString );
	}

	public static function provideBundleRules(): array {
		return [
			'our type sets the bundle string' => [
				'eventType' => PersonalInfoFlagNotifier::EVENT_TYPE,
				'getTypeCalls' => 2,
				'initialBundleString' => '',
				'expectedBundleString' => PersonalInfoFlagNotifier::EVENT_TYPE,
			],
			'other type is left untouched' => [
				'eventType' => 'some-other-event',
				'getTypeCalls' => 1,
				'initialBundleString' => 'preexisting-bundle',
				'expectedBundleString' => 'preexisting-bundle',
			],
		];
	}

	private function newHandler( bool $flagEnabled ): EchoHooksHandler {
		return new EchoHooksHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => $flagEnabled ] )
		);
	}
}

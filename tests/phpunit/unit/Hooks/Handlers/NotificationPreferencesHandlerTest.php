<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\NotificationPreferencesHandler;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\NotificationPreferencesHandler
 */
class NotificationPreferencesHandlerTest extends MediaWikiUnitTestCase {

	private User $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->createMock( User::class );
	}

	/** @dataProvider providePersonalInfoRowRemoval */
	public function testPersonalInfoRowRemoval( bool $flagEnabled, bool $canViewTag, bool $expectRemoved ): void {
		$preferences = $this->newPreferences();

		$this->newHandler( $flagEnabled, $canViewTag )->onGetPreferences( $this->user, $preferences );

		$checkmatrix = $preferences['echo-subscriptions'];
		if ( $expectRemoved ) {
			$this->assertNotContains( 'personal-info', $checkmatrix['rows'] );
			$this->assertArrayNotHasKey( 'Personal info label', $checkmatrix['rows'] );
			$this->assertArrayNotHasKey( 'Personal info label', $checkmatrix['tooltips'] );
			$this->assertSame( [ 'echo-subscriptions-web-system' ], $checkmatrix['force-options-off'] );
			$this->assertSame( [], $checkmatrix['force-options-on'] );
		} else {
			$this->assertSame( $this->newPreferences()['echo-subscriptions'], $checkmatrix );
		}
	}

	public static function providePersonalInfoRowRemoval(): array {
		return [
			'enabled and can view the tag keeps the row' => [
				'flagEnabled' => true,
				'canViewTag' => true,
				'expectRemoved' => false,
			],
			'enabled without tag access removes the row' => [
				'flagEnabled' => true,
				'canViewTag' => false,
				'expectRemoved' => true,
			],
			'disabled removes the row despite tag access' => [
				'flagEnabled' => false,
				'canViewTag' => true,
				'expectRemoved' => true,
			],
			'disabled without tag access removes the row' => [
				'flagEnabled' => false,
				'canViewTag' => false,
				'expectRemoved' => true,
			],
		];
	}

	/** @dataProvider provideNoOp */
	public function testNoOpLeavesPreferencesUntouched( array $preferences ): void {
		$original = $preferences;

		$this->newHandler( false, false )->onGetPreferences( $this->user, $preferences );

		$this->assertSame( $original, $preferences );
	}

	public static function provideNoOp(): array {
		return [
			'echo-subscriptions checkmatrix absent' => [
				'preferences' => [ 'some-other-preference' => [ 'type' => 'toggle' ] ],
			],
			'checkmatrix rows key absent' => [
				'preferences' => [ 'echo-subscriptions' => [ 'tooltips' => [ 'System label' => 'system-tip' ] ] ],
			],
			'personal-info row not present' => [
				'preferences' => [ 'echo-subscriptions' => [ 'rows' => [ 'System label' => 'system' ] ] ],
			],
		];
	}

	private function newHandler(
		bool $flagEnabled,
		bool $canViewTag,
		bool $echoIsLoaded = false
	): NotificationPreferencesHandler {
		$changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$changeTagsStore->expects( $flagEnabled ? $this->once() : $this->never() )
			->method( 'canViewTag' )
			->with( ChangeTagsHandler::PERSONAL_INFO_TAG, $this->user )
			->willReturn( $canViewTag );

		$extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$extensionRegistry->method( 'isLoaded' )
			->with( 'Echo' )
			->willReturn( $echoIsLoaded );

		return new NotificationPreferencesHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => $flagEnabled ] ),
			$changeTagsStore,
			new NullLogger(),
			$extensionRegistry
		);
	}

	private function newPreferences(): array {
		return [
			'echo-subscriptions' => [
				'rows' => [
					'System label' => 'system',
					'Personal info label' => 'personal-info',
				],
				'tooltips' => [
					'System label' => 'system-tip',
					'Personal info label' => 'personal-info-tip',
				],
				'force-options-off' => [
					'echo-subscriptions-web-personal-info',
					'echo-subscriptions-web-system',
				],
				'force-options-on' => [
					'echo-subscriptions-email-personal-info',
				],
			],
		];
	}
}

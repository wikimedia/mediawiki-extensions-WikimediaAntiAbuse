<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\NotificationPreferencesHandler;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
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

	/**
	 * The row-kept path adds a help link via wfMessage()->parse(), which needs the service
	 * container, so it is covered by the integration test instead.
	 *
	 * @dataProvider providePersonalInfoRowRemoval
	 */
	public function testPersonalInfoRowRemoval( bool $flagEnabled, bool $canViewTag ): void {
		$preferences = $this->newPreferences();

		$this->newHandler( $flagEnabled, $canViewTag )->onGetPreferences( $this->user, $preferences );

		$checkmatrix = $preferences['echo-subscriptions'];
		$this->assertNotContains( 'personal-info', $checkmatrix['rows'] );
		$this->assertArrayNotHasKey( 'Personal info label', $checkmatrix['rows'] );
		$this->assertArrayNotHasKey( 'Personal info label', $checkmatrix['tooltips'] );
		$this->assertSame( [ 'echo-subscriptions-web-system' ], $checkmatrix['force-options-off'] );
		$this->assertSame( [], $checkmatrix['force-options-on'] );
	}

	public static function providePersonalInfoRowRemoval(): array {
		return [
			'enabled without tag access removes the row' => [
				'flagEnabled' => true,
				'canViewTag' => false,
			],
			'disabled removes the row despite tag access' => [
				'flagEnabled' => false,
				'canViewTag' => true,
			],
			'disabled without tag access removes the row' => [
				'flagEnabled' => false,
				'canViewTag' => false,
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

	public function testWarnsWhenEchoLoadedButRowsMissing(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'warning' )
			->with( $this->stringContains( 'registered before Echo' ) );

		$preferences = [ 'echo-subscriptions' => [ 'tooltips' => [ 'System label' => 'system-tip' ] ] ];
		$this->newHandler( false, false, true, $logger )
			->onGetPreferences( $this->user, $preferences );
	}

	/** @dataProvider provideTooltipHelpLinkNoOp */
	public function testTooltipHelpLinkNoOp( array $preferences ): void {
		$original = $preferences;

		$this->newHandler( true, true )->onGetPreferences( $this->user, $preferences );

		$this->assertSame( $original, $preferences );
	}

	public static function provideTooltipHelpLinkNoOp(): array {
		return [
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
		bool $echoIsLoaded = false,
		?LoggerInterface $logger = null
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
			$logger ?? new NullLogger(),
			$extensionRegistry,
			$this->createMock( MessageLocalizer::class )
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

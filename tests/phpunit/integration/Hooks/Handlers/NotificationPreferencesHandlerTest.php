<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\NotificationPreferencesHandler
 * @group Database
 */
class NotificationPreferencesHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );

		parent::setUp();
	}

	private function getEchoSubscriptionRows( User $user ): array {
		$context = RequestContext::getMain();
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'Preferences' ) );
		$context->setUser( $user );

		$descriptor = $this->getServiceContainer()->getPreferencesFactory()
			->getFormDescriptor( $user, $context );

		return $descriptor['echo-subscriptions']['rows'] ?? [];
	}

	/** @dataProvider provideRowVisibility */
	public function testRowVisibilityFollowsTagAccess( array $groups, bool $expectRow ): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
			'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => true,
			// BeforeCreateEchoEvent contributions do not survive the PHPUnit bootstrap, so
			// register the category directly to exercise the row add/remove path for real.
			'EchoNotificationCategories' => array_merge(
				$this->getServiceContainer()->getMainConfig()->get( 'EchoNotificationCategories' ),
				[ 'personal-info' => [ 'priority' => 2 ] ]
			),
		] );
		$this->setGroupPermissions( 'tag-viewer', 'viewsuppressed', true );
		$this->setGroupPermissions( 'tag-suppressor', 'suppressrevision', true );

		$user = $this->getTestUser( $groups )->getUser();

		if ( $expectRow ) {
			$this->assertContains( 'personal-info', $this->getEchoSubscriptionRows( $user ) );
		} else {
			$this->assertNotContains( 'personal-info', $this->getEchoSubscriptionRows( $user ) );
		}
	}

	public static function provideRowVisibility(): array {
		return [
			'viewsuppressed keeps the row' => [
				'groups' => [ 'tag-viewer' ],
				'expectRow' => true,
			],
			'suppressrevision alone keeps the row' => [
				'groups' => [ 'tag-suppressor' ],
				'expectRow' => true,
			],
			'no tag access removes the row' => [
				'groups' => [],
				'expectRow' => false,
			],
		];
	}

	public function testEchoCheckmatrixShapeMatchesHandlerAssumptions(): void {
		$user = $this->getTestUser()->getUser();
		$context = RequestContext::getMain();
		$context->setTitle( Title::makeTitle( NS_SPECIAL, 'Preferences' ) );
		$context->setUser( $user );

		$descriptor = $this->getServiceContainer()->getPreferencesFactory()
			->getFormDescriptor( $user, $context );

		$this->assertArrayHasKey(
			'echo-subscriptions',
			$descriptor,
			'Echo must expose an echo-subscriptions preference field'
		);
		$checkmatrix = $descriptor['echo-subscriptions'];

		// The exact keys NotificationPreferencesHandler mutates. If Echo renames or drops any of
		// these, the handler can no longer remove the row and this assertion fails.
		$this->assertArrayHasKey( 'rows', $checkmatrix, 'checkmatrix must expose rows' );
		$this->assertArrayHasKey( 'tooltips', $checkmatrix, 'checkmatrix must expose tooltips' );
		$this->assertArrayHasKey( 'force-options-off', $checkmatrix, 'checkmatrix must expose force-options-off' );
		$this->assertArrayHasKey( 'force-options-on', $checkmatrix, 'checkmatrix must expose force-options-on' );

		// The handler looks the category up by value (array_search over rows), so rows must map
		// display label => category name (strings), not to a nested/object shape.
		$this->assertNotSame( [], $checkmatrix['rows'], 'checkmatrix rows must not be empty' );
		$this->assertContainsOnly( 'string', $checkmatrix['rows'], null, 'rows must map label => category name' );
	}
}

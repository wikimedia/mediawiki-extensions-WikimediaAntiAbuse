<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\UserRightsNotificationHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\UserRightsNotificationHandler
 * @group Database
 */
class UserRightsNotificationHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );

		parent::setUp();

		$this->overrideConfigValues( [
			'EchoUseJobQueue' => false,
			'EchoNotifications' => [
				PersonalInfoFlagNotifier::EVENT_TYPE => [ 'category' => 'system' ],
			],
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
		] );
		$this->clearHook( 'PageSaveComplete' );
	}

	public function testMarksNotificationReadWhenRecipientLosesTagAccess(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );
		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ), 'precondition: recipient has an unread flag' );

		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		$this->newHandler( true )->onUserGroupsChanged( $user, [], [ 'suppress' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame(
			0,
			$this->unreadPersonalInfoCount( $user ),
			'the flag notification is marked read once the recipient can no longer view the tag'
		);
	}

	public function testLeavesNotificationWhenRecipientRetainsTagAccess(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );

		// Some other group changed, but the user still holds suppress (and thus tag access).
		$this->newHandler( true )->onUserGroupsChanged( $user, [], [ 'some-other-group' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testNoOpWhenNoGroupsRemoved(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		// Only additions: nothing can have removed the recipient's tag access.
		$this->newHandler( true )->onUserGroupsChanged( $user, [ 'sysop' ], [], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testNoOpWhenFeatureDisabled(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		$this->newHandler( false )->onUserGroupsChanged( $user, [], [ 'suppress' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testNoOpWhenEchoNotLoaded(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		$this->newHandler( true, false )->onUserGroupsChanged( $user, [], [ 'suppress' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testNoOpForForeignWikiUser(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->notifyUser( $user );
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		// A group change carrying a foreign-wiki identity must be ignored by this local handler.
		$foreignUser = new UserIdentityValue( $user->getId(), $user->getName(), 'foreignwiki' );
		$this->newHandler( true )->onUserGroupsChanged( $foreignUser, [], [ 'suppress' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 1, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testNoOpWhenRecipientHasNoUnreadNotifications(): void {
		// The recipient loses tag access but has no unread flag to clear.
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$this->getServiceContainer()->getUserGroupManager()->removeUserFromGroup( $user, 'suppress' );

		$this->newHandler( true )->onUserGroupsChanged( $user, [], [ 'suppress' ], false, false, [], [] );
		DeferredUpdates::doUpdates();

		$this->assertSame( 0, $this->unreadPersonalInfoCount( $user ) );
	}

	public function testFactory(): void {
		$services = $this->getServiceContainer();
		$handler = UserRightsNotificationHandler::factory(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => true ] ),
			$services->getChangeTagsStore(),
			$services->getUserFactory(),
			$services->getConnectionProvider(),
			ExtensionRegistry::getInstance()
		);
		$this->assertInstanceOf( UserRightsNotificationHandler::class, $handler );
	}

	private function newHandler( bool $flagEnabled, bool $echoIsLoaded = true ): UserRightsNotificationHandler {
		$services = $this->getServiceContainer();
		return new UserRightsNotificationHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => $flagEnabled ] ),
			$services->getChangeTagsStore(),
			$services->getUserFactory(),
			$services->getConnectionProvider(),
			$echoIsLoaded
		);
	}

	private function notifyUser( User $user ): void {
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, 'echo-subscriptions-web-' . PersonalInfoFlagNotifier::CATEGORY, '1' );
		$userOptionsManager->saveOptions( $user );

		$status = $this->editPage( 'WikimediaAntiAbuse user-rights notifier page', 'test content' );
		$this->assertStatusGood( $status );

		$services = $this->getServiceContainer();
		$notifier = new PersonalInfoFlagNotifier(
			true,
			$services->getNotificationService(),
			$services->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
		);
		$notifier->notify( $status->getNewRevision() );
	}

	private function unreadPersonalInfoCount( User $user ): int {
		return (int)$this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'echo_notification' )
			->join( 'echo_event', null, 'notification_event = event_id' )
			->where( [
				'notification_user' => $user->getId(),
				'event_type' => PersonalInfoFlagNotifier::EVENT_TYPE,
				'notification_read_timestamp' => null,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Notifications;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoHooksHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Page\WikiPage;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagPresentationModel
 * @group Database
 */
class PersonalInfoFlagPresentationModelTest extends MediaWikiIntegrationTestCase {

	private User $user;
	private WikiPage $page;

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );

		parent::setUp();

		$notifications = [];
		$categories = [];
		$icons = [];
		( new EchoHooksHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => true ] )
		) )->onBeforeCreateEchoEvent( $notifications, $categories, $icons );

		$this->overrideConfigValues( [
			'EchoUseJobQueue' => false,
			'EchoNotifications' => $notifications,
			'EchoNotificationCategories' => array_merge(
				$this->getServiceContainer()->getMainConfig()->get( 'EchoNotificationCategories' ),
				$categories
			),
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
		] );
		$this->setGroupPermissions( 'suppress', 'viewsuppressed', true );
		$this->clearHook( 'PageSaveComplete' );

		// A real oversighter is also an admin, so they can view a normally revision-deleted edit.
		$this->user = $this->getTestUser( [ 'suppress', 'sysop' ] )->getUser();
		$this->page = $this->getExistingTestPage( 'PIFlagTitle' );
	}

	public function testGetPrimaryLink(): void {
		$link = $this->newModel( $this->createEvent( 1001 ) )->getPrimaryLink();

		$this->assertStringContainsString( 'Special:AbuseReview', $link['url'] );
		$this->assertSame( '(notification-link-text-personal-info-flagged)', $link['label'] );
	}

	public function testGetHeaderMessageForSingleNotification(): void {
		$header = $this->newModel( $this->createEvent( 1001 ) )->getHeaderMessage()->text();

		$this->assertStringContainsString( 'notification-header-personal-info-flagged', $header );
		$this->assertStringContainsString( 'PIFlagTitle', $header );
		$this->assertStringNotContainsString( 'bundle', $header );
	}

	public function testGetHeaderMessageForBundledNotification(): void {
		$event = $this->createEvent( 1001 );
		$event->setBundledEvents( [ $this->createEvent( 1002 ) ] );

		$header = $this->newModel( $event )->getHeaderMessage()->text();

		$this->assertStringContainsString( 'notification-bundle-header-personal-info-flagged', $header );
	}

	/** @dataProvider provideCanRender */
	public function testCanRender( string $revisionState, bool $expected ): void {
		$event = $this->createEvent( $this->revisionIdForState( $revisionState ) );

		$this->assertSame( $expected, $this->newModel( $event )->canRender() );
	}

	public static function provideCanRender(): array {
		return [
			'visible revision can be rendered' => [
				'revisionState' => 'visible',
				'expected' => true,
			],
			'revision-deleted revision is still rendered until it is suppressed' => [
				'revisionState' => 'hidden',
				'expected' => true,
			],
			'missing revision cannot be rendered' => [
				'revisionState' => 'missing',
				'expected' => false,
			],
		];
	}

	public function testHiddenRevisionIsHiddenFromOversighterWhoCannotViewIt(): void {
		// Oversighter without the admin right cannot view a normally revision-deleted edit.
		$oversightOnly = $this->getTestUser( [ 'suppress' ] )->getUser();
		$event = $this->createEvent( $this->revisionIdForState( 'hidden' ) );

		$this->assertTrue( $this->newModel( $event )->canRender() );
		$this->assertFalse( $this->newModelForUser( $event, $oversightOnly )->canRender() );
	}

	public function testCanRenderRequiresTagViewingRight(): void {
		$event = $this->createEvent( $this->revisionIdForState( 'visible' ) );
		$ineligible = $this->getTestUser()->getUser();

		$this->assertTrue( $this->newModel( $event )->canRender() );
		$this->assertFalse( $this->newModelForUser( $event, $ineligible )->canRender() );
	}

	public function testGetIconType(): void {
		$this->assertSame( 'alert', $this->newModel( $this->createEvent( 1001 ) )->getIconType() );
	}

	public function testCannotRenderWithoutRevisionId(): void {
		$event = Event::create( [
			'type' => PersonalInfoFlagNotifier::EVENT_TYPE,
			'title' => $this->page->getTitle(),
		] );

		$this->assertFalse( $this->newModel( $event )->canRender() );
	}

	private function revisionIdForState( string $state ): int {
		if ( $state === 'missing' ) {
			return 999999999;
		}

		$revisionId = $this->editPage( 'PIFlagRevisionPage', 'first revision' )
			->getNewRevision()
			->getId();
		if ( $state === 'hidden' ) {
			// Revision-delete refuses the current revision, so add a newer one before hiding this one.
			$this->editPage( 'PIFlagRevisionPage', 'second revision' );
			$this->revisionDelete( $revisionId );
		}

		return $revisionId;
	}

	private function createEvent( int $revisionId ): Event {
		return Event::create( [
			'type' => PersonalInfoFlagNotifier::EVENT_TYPE,
			'title' => $this->page->getTitle(),
			'extra' => [ 'revisionId' => $revisionId ],
		] );
	}

	private function newModel( Event $event ): EchoEventPresentationModel {
		return $this->newModelForUser( $event, $this->user );
	}

	private function newModelForUser( Event $event, User $user ): EchoEventPresentationModel {
		$language = $this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' );
		return EchoEventPresentationModel::factory( $event, $language, $user );
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Notifications;

use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier
 * @group Database
 */
class PersonalInfoFlagNotifierTest extends MediaWikiIntegrationTestCase {

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

	/** @dataProvider provideNotify */
	public function testNotify( bool $flagEnabled, bool $hasEligibleRecipient, int $expectedEventCount ): void {
		if ( $hasEligibleRecipient ) {
			$this->createEligibleOptedInUser();
		}
		$status = $this->editPage( 'WikimediaAntiAbuse notifier test page', 'test content' );
		$this->assertStatusGood( $status );
		$revision = $status->getNewRevision();

		$this->newNotifier( $flagEnabled )->notify( $revision );

		$events = $this->personalInfoEventsForPage( $revision->getPageId() );
		$this->assertCount( $expectedEventCount, $events );
		foreach ( $events as $event ) {
			$this->assertEquals( $revision->getId(), $event->getExtraParam( 'revisionId' ) );
		}
	}

	public static function provideNotify(): array {
		return [
			'creates an event when enabled with an eligible recipient' => [
				'flagEnabled' => true,
				'hasEligibleRecipient' => true,
				'expectedEventCount' => 1,
			],
			'creates no event when enabled without an eligible recipient' => [
				'flagEnabled' => true,
				'hasEligibleRecipient' => false,
				'expectedEventCount' => 0,
			],
			'creates no event when disabled' => [
				'flagEnabled' => false,
				'hasEligibleRecipient' => true,
				'expectedEventCount' => 0,
			],
		];
	}

	public function testNotifyDoesNotCreateEventForSuppressedRevision(): void {
		// Seed a recipient so the suppression guard, not an empty audience, holds the count at zero.
		$this->createEligibleOptedInUser();
		$status = $this->editPage( 'WikimediaAntiAbuse notifier suppressed page', 'first revision' );
		$this->assertStatusGood( $status );
		$revisionId = $status->getNewRevision()->getId();
		// Revision-delete refuses the current revision, so add a newer one before hiding this one.
		$this->editPage( 'WikimediaAntiAbuse notifier suppressed page', 'second revision' );
		$this->revisionDelete( $revisionId, [
			RevisionRecord::DELETED_TEXT => 1,
			RevisionRecord::DELETED_RESTRICTED => 1,
		] );

		$revision = $this->getServiceContainer()->getRevisionLookup()->getRevisionById( $revisionId );

		$this->newNotifier( true )->notify( $revision );

		$this->assertCount( 0, $this->personalInfoEventsForPage( $revision->getPageId() ) );
	}

	public function testNotifyCreatesEventForRevisionDeletedButNotSuppressedRevision(): void {
		$this->createEligibleOptedInUser();
		$status = $this->editPage( 'WikimediaAntiAbuse notifier revdel page', 'first revision' );
		$this->assertStatusGood( $status );
		$revisionId = $status->getNewRevision()->getId();
		// Revision-delete refuses the current revision, so add a newer one before hiding this one.
		$this->editPage( 'WikimediaAntiAbuse notifier revdel page', 'second revision' );
		// Plain revision-deletion (text hidden from the public) without suppression: still actionable.
		$this->revisionDelete( $revisionId );

		$revision = $this->getServiceContainer()->getRevisionLookup()->getRevisionById( $revisionId );

		$this->newNotifier( true )->notify( $revision );

		$this->assertCount( 1, $this->personalInfoEventsForPage( $revision->getPageId() ) );
	}

	public function testNotifyCreatesEventForSuppressedMetadataButVisibleText(): void {
		$this->createEligibleOptedInUser();
		$status = $this->editPage( 'WikimediaAntiAbuse notifier restricted metadata page', 'first revision' );
		$this->assertStatusGood( $status );
		$revisionId = $status->getNewRevision()->getId();
		// Revision-delete refuses the current revision, so add a newer one before hiding this one.
		$this->editPage( 'WikimediaAntiAbuse notifier restricted metadata page', 'second revision' );
		// Username suppressed from admins but the edit text is still visible, so the edit still needs action.
		$this->revisionDelete( $revisionId, [
			RevisionRecord::DELETED_USER => 1,
			RevisionRecord::DELETED_RESTRICTED => 1,
		] );

		$revision = $this->getServiceContainer()->getRevisionLookup()->getRevisionById( $revisionId );

		$this->newNotifier( true )->notify( $revision );

		$this->assertCount( 1, $this->personalInfoEventsForPage( $revision->getPageId() ) );
	}

	private function newNotifier( bool $flagEnabled ): PersonalInfoFlagNotifier {
		$services = $this->getServiceContainer();
		return new PersonalInfoFlagNotifier(
			$flagEnabled,
			$services->getNotificationService(),
			$services->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
		);
	}

	private function createEligibleOptedInUser(): void {
		$user = $this->getTestUser( [ 'suppress' ] )->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, 'echo-subscriptions-web-personal-info', '1' );
		$userOptionsManager->saveOptions( $user );
	}

	/** @return Event[] */
	private function personalInfoEventsForPage( int $pageId ): array {
		return array_filter(
			( new EventMapper() )->fetchByPage( $pageId ),
			static fn ( Event $event ): bool => $event->getType() === PersonalInfoFlagNotifier::EVENT_TYPE
		);
	}
}

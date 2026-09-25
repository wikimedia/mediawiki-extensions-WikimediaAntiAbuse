<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Notifications;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationDeleter;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Page\WikiPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationDeleter
 * @group Database
 */
class EchoPersonalInfoFlagNotificationDeleterTest extends MediaWikiIntegrationTestCase {

	private const string OTHER_EVENT_TYPE = 'wikimedia-anti-abuse-test-other';
	private const string PAGE_NAME = 'WikimediaAntiAbuse notification deleter test page';

	private ?bool $originalAlwaysInsert = null;
	private WikiPage $page;

	protected function setUp(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );

		parent::setUp();

		$this->overrideConfigValues( [
			'EchoUseJobQueue' => false,
			'EchoNotifications' => [
				PersonalInfoFlagNotifier::EVENT_TYPE => [ 'category' => 'system' ],
				self::OTHER_EVENT_TYPE => [ 'category' => 'system' ],
			],
		] );
		$this->clearHook( 'PageSaveComplete' );

		$this->originalAlwaysInsert = Event::$alwaysInsert;
		Event::$alwaysInsert = true;

		$this->page = $this->getExistingTestPage( self::PAGE_NAME );
	}

	protected function tearDown(): void {
		// setUp() can skip before this is set, and tearDown() still runs.
		if ( $this->originalAlwaysInsert !== null ) {
			Event::$alwaysInsert = $this->originalAlwaysInsert;
		}

		parent::tearDown();
	}

	public function testDeletesOnlyTheFlagEventsForTheGivenRevisions(): void {
		$firstRevisionId = 1001;
		$secondRevisionId = 2002;
		$untouchedRevisionId = 3003;
		$firstEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $firstRevisionId );
		$secondEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $secondRevisionId );
		$otherRevisionEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $untouchedRevisionId );
		$otherTypeEventId = $this->createEvent( self::OTHER_EVENT_TYPE, $firstRevisionId );

		$this->newDeleter()->deleteForRevisions(
			$this->page->getId(),
			[ $firstRevisionId, $secondRevisionId ]
		);
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $firstEventId, true, 'The event for the first given revision is deleted' );
		$this->assertEventDeleted( $secondEventId, true, 'The event for the second given revision is deleted' );
		$this->assertEventDeleted( $otherRevisionEventId, false, 'An event for another revision is untouched' );
		$this->assertEventDeleted( $otherTypeEventId, false, 'An event of another type is untouched' );
	}

	/** @dataProvider provideNothingToDelete */
	public function testNoOpWhenThereIsNothingToDelete( bool $pageIsKnown, array $revisionIds ): void {
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, 1001 );

		$this->newDeleter()
			->deleteForRevisions( $pageIsKnown ? $this->page->getId() : 0, $revisionIds );
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $eventId, false );
	}

	public static function provideNothingToDelete(): array {
		return [
			'no revisions given' => [ 'pageIsKnown' => true, 'revisionIds' => [] ],
			'no page id given' => [ 'pageIsKnown' => false, 'revisionIds' => [ 1001 ] ],
		];
	}

	private function newDeleter(): EchoPersonalInfoFlagNotificationDeleter {
		return new EchoPersonalInfoFlagNotificationDeleter(
			$this->getServiceContainer()->get( 'EchoEventMapper' ),
			$this->getServiceContainer()->get( 'EchoEventController' )
		);
	}

	private function createEvent( string $type, int $revisionId ): int {
		return Event::create( [
			'type' => $type,
			'title' => $this->page->getTitle(),
			'extra' => [ 'revisionId' => $revisionId ],
		] )->getId();
	}

	private function assertEventDeleted( int $eventId, bool $expectedDeleted, string $message = '' ): void {
		$this->assertSame(
			$expectedDeleted,
			!$this->getDb()->newSelectQueryBuilder()
				->select( 'event_id' )
				->from( 'echo_event' )
				->where( [ 'event_id' => $eventId ] )
				->caller( __METHOD__ )
				->fetchField(),
			$message
		);
	}
}

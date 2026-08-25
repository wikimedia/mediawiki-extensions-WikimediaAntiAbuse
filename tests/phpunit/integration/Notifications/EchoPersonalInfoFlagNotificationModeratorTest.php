<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Notifications;

use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Page\WikiPage;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationModerator
 * @group Database
 */
class EchoPersonalInfoFlagNotificationModeratorTest extends MediaWikiIntegrationTestCase {

	private const string OTHER_EVENT_TYPE = 'wikimedia-anti-abuse-test-other';
	private const string PAGE_NAME = 'WikimediaAntiAbuse notification moderator test page';

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

	public function testHidesOnlyTheFlagEventsForTheGivenRevisions(): void {
		$firstRevisionId = 1001;
		$secondRevisionId = 2002;
		$untouchedRevisionId = 3003;
		$firstEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $firstRevisionId );
		$secondEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $secondRevisionId );
		$otherRevisionEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $untouchedRevisionId );
		$otherTypeEventId = $this->createEvent( self::OTHER_EVENT_TYPE, $firstRevisionId );

		$this->newModerator()->hideForRevisions(
			$this->page->getId(),
			[ $firstRevisionId, $secondRevisionId ]
		);
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $firstEventId, true, 'The event for the first given revision is hidden' );
		$this->assertEventDeleted( $secondEventId, true, 'The event for the second given revision is hidden' );
		$this->assertEventDeleted( $otherRevisionEventId, false, 'An event for another revision is untouched' );
		$this->assertEventDeleted( $otherTypeEventId, false, 'An event of another type is untouched' );
	}

	/** @dataProvider provideNothingToHide */
	public function testNoOpWhenThereIsNothingToHide( bool $pageIsKnown, array $revisionIds ): void {
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, 1001 );

		$this->newModerator()
			->hideForRevisions( $pageIsKnown ? $this->page->getId() : 0, $revisionIds );
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $eventId, false );
	}

	public static function provideNothingToHide(): array {
		return [
			'no revisions given' => [ 'pageIsKnown' => true, 'revisionIds' => [] ],
			'no page id given' => [ 'pageIsKnown' => false, 'revisionIds' => [ 1001 ] ],
		];
	}

	public function testRestoreShowsTheEventAgain(): void {
		$revision = $this->page->getRevisionRecord();
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $revision->getId() );
		( new EventMapper() )->toggleDeleted( [ $eventId ], true );

		$this->newModerator()->restoreForRevision( $revision );
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $eventId, false, 'The notification comes back for a live revision' );
	}

	public function testRestoreLeavesASuppressedRevisionHidden(): void {
		$revisionId = $this->page->getRevisionRecord()->getId();
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $revisionId );
		( new EventMapper() )->toggleDeleted( [ $eventId ], true );

		$this->getDb()->newUpdateQueryBuilder()
			->update( 'revision' )
			->set( [ 'rev_deleted' => PersonalInfoFlagNotifier::SUPPRESSED_BITS ] )
			->where( [ 'rev_id' => $revisionId ] )
			->caller( __METHOD__ )->execute();
		$revision = $this->getServiceContainer()->getRevisionLookup()->getRevisionById( $revisionId );

		$this->newModerator()->restoreForRevision( $revision );
		$this->runDeferredUpdates();

		$this->assertEventDeleted( $eventId, true, 'A suppressed revision keeps its notification hidden' );
	}

	public function testRestoreLeavesAnArchivedRevisionHidden(): void {
		$revisionId = $this->page->getRevisionRecord()->getId();
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $revisionId );
		( new EventMapper() )->toggleDeleted( [ $eventId ], true );

		$this->deletePage( self::PAGE_NAME );
		$revision = $this->getServiceContainer()->getArchivedRevisionLookup()
			->getArchivedRevisionRecord( null, $revisionId );

		$this->newModerator()->restoreForRevision( $revision );
		$this->runDeferredUpdates();

		$this->assertEventDeleted(
			$eventId,
			true,
			'Echo owns the notifications of a deleted page, so the restore must not touch them'
		);
	}

	private function newModerator(): EchoPersonalInfoFlagNotificationModerator {
		return new EchoPersonalInfoFlagNotificationModerator(
			$this->getServiceContainer()->get( 'EchoEventMapper' )
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
			( new EventMapper() )->fetchById( $eventId, true )->isDeleted(),
			$message
		);
	}
}

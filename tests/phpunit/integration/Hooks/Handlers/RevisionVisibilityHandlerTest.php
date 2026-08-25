<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Hooks\Handlers;

use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\RevisionVisibilityHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\RevisionVisibilityHandler
 * @group Database
 */
class RevisionVisibilityHandlerTest extends MediaWikiIntegrationTestCase {

	private const OTHER_EVENT_TYPE = 'wikimedia-anti-abuse-test-other';

	private ?bool $originalAlwaysInsert = null;
	private RevisionVisibilityHandler $handler;
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

		$this->handler = new RevisionVisibilityHandler(
			new PersonalInfoFlagNotificationModerator( true )
		);
		$this->page = $this->getExistingTestPage( 'WikimediaAntiAbuse visibility test page' );
	}

	protected function tearDown(): void {
		// setUp() can skip before this is set, and tearDown() still runs.
		if ( $this->originalAlwaysInsert !== null ) {
			Event::$alwaysInsert = $this->originalAlwaysInsert;
		}

		parent::tearDown();
	}

	/** @dataProvider provideVisibilityTransition */
	public function testModeratesOnVisibilityTransition(
		bool $initiallyDeleted,
		int $oldBits,
		int $newBits,
		bool $expectedDeleted
	): void {
		$revisionId = 1001;
		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $revisionId );
		if ( $initiallyDeleted ) {
			( new EventMapper() )->toggleDeleted( [ $eventId ], true );
		}

		$this->handler->onArticleRevisionVisibilitySet(
			$this->page->getTitle(),
			[ $revisionId ],
			[ $revisionId => [ 'oldBits' => $oldBits, 'newBits' => $newBits ] ]
		);

		$this->assertEventDeleted( $eventId, $expectedDeleted );
	}

	public static function provideVisibilityTransition(): array {
		return [
			'suppressing a revision moderates the event' => [
				'initiallyDeleted' => false,
				'oldBits' => 0,
				'newBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
				'expectedDeleted' => true,
			],
			'unsuppressing a revision leaves the event moderated' => [
				'initiallyDeleted' => true,
				'oldBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
				'newBits' => 0,
				'expectedDeleted' => true,
			],
			'plain revision-deletion leaves the event untouched' => [
				'initiallyDeleted' => false,
				'oldBits' => 0,
				'newBits' => RevisionRecord::DELETED_TEXT,
				'expectedDeleted' => false,
			],
			'restricting metadata without hiding text leaves the event untouched' => [
				'initiallyDeleted' => false,
				'oldBits' => 0,
				'newBits' => RevisionRecord::DELETED_USER | RevisionRecord::DELETED_RESTRICTED,
				'expectedDeleted' => false,
			],
		];
	}

	public function testLeavesUnrelatedEventsUntouched(): void {
		$targetRevisionId = 1001;
		$otherRevisionId = 2002;
		$targetEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $targetRevisionId );
		$otherRevisionEventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $otherRevisionId );
		$otherTypeEventId = $this->createEvent( self::OTHER_EVENT_TYPE, $targetRevisionId );

		$this->handler->onArticleRevisionVisibilitySet(
			$this->page->getTitle(),
			[ $targetRevisionId ],
			[ $targetRevisionId => [
				'oldBits' => 0,
				'newBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
			] ]
		);

		$this->assertEventDeleted( $targetEventId, true, 'The event for the suppressed revision must be moderated' );
		$this->assertEventDeleted( $otherRevisionEventId, false, 'An event for a different revision is untouched' );
		$this->assertEventDeleted( $otherTypeEventId, false, 'An event of a different type is untouched' );
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

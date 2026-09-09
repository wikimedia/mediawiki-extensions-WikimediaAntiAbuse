<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\EventSubscribers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\EventSubscribers\PageHistoryVisibilityEventIngress;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\EventSubscribers\PageHistoryVisibilityEventIngress
 * @group Database
 */
class PageHistoryVisibilityEventIngressTest extends MediaWikiIntegrationTestCase {

	private const OTHER_EVENT_TYPE = 'wikimedia-anti-abuse-test-other';

	private ?bool $originalAlwaysInsert = null;
	private PageHistoryVisibilityEventIngress $listener;
	private IAbuseReviewInstrumentationClient $instrumentationClient;
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

		$this->instrumentationClient = $this->createMock( IAbuseReviewInstrumentationClient::class );

		$this->listener = new PageHistoryVisibilityEventIngress(
			new EchoPersonalInfoFlagNotificationModerator(
				$this->getServiceContainer()->get( 'EchoEventMapper' )
			),
			$this->getServiceContainer()->getConnectionProvider(),
			$this->instrumentationClient,
			$this->getServiceContainer()->getNamespaceInfo(),
			$this->getServiceContainer()->getTitleFactory()
		);
		$this->page = $this->getExistingTestPage( 'Template talk:WikimediaAntiAbuse visibility test page' );
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
		$latestRevisionId = 1002;
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ ChangeTagsHandler::PERSONAL_INFO_TAG ],
			null,
			$revisionId
		);

		$eventId = $this->createEvent( PersonalInfoFlagNotifier::EVENT_TYPE, $revisionId );
		if ( $initiallyDeleted ) {
			( new EventMapper() )->toggleDeleted( [ $eventId ], true );
		}

		$expectsInstrumentationEvent = !$initiallyDeleted && $expectedDeleted;
		$this->instrumentationClient->expects( $expectsInstrumentationEvent ? $this->once() : $this->never() )
			->method( 'submitInteraction' )
			->with(
				RequestContext::getMain(),
				'revision_content_suppressed',
				[
					'action_subtype' => 'personal-info-tagged',
					'identifier' => $revisionId,
					'identifier_type' => 'revision',
					'page' => $this->getExpectedPageInstrumentationData( $latestRevisionId ),
					'reason' => 'test reason',
				]
			);

		$this->listener->handlePageHistoryVisibilityChangedEvent( $this->createVisibilityChangedEvent(
			$latestRevisionId,
			[ $revisionId => [ 'oldBits' => $oldBits, 'newBits' => $newBits ] ],
			$oldBits,
			$newBits,
			'test reason'
		) );

		$this->assertEventDeleted( $eventId, $expectedDeleted );
	}

	private function getExpectedPageInstrumentationData( int $expectedLatestRevisionId ): array {
		return [
			'id' => $this->page->getTitle()->getArticleID(),
			'title' => $this->page->getTitle()->getDBkey(),
			'namespace_id' => $this->page->getTitle()->getNamespace(),
			'namespace_name' => $this->getServiceContainer()->getNamespaceInfo()->getCanonicalName(
				$this->page->getTitle()->getNamespace()
			),
			'revision_id' => $expectedLatestRevisionId,
			'content_language' => $this->page->getTitle()->getPageLanguage()->getCode(),
			'is_redirect' => $this->page->getTitle()->isRedirect(),
		];
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

		$this->listener->handlePageHistoryVisibilityChangedEvent( $this->createVisibilityChangedEvent(
			$otherRevisionId,
			[ $targetRevisionId => [
				'oldBits' => 0,
				'newBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
			] ],
			RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
			0,
			'test reason'
		) );

		$this->assertEventDeleted( $targetEventId, true, 'The event for the suppressed revision must be moderated' );
		$this->assertEventDeleted( $otherRevisionEventId, false, 'An event for a different revision is untouched' );
		$this->assertEventDeleted( $otherTypeEventId, false, 'An event of a different type is untouched' );
	}

	public function testInteractionEventWhenSomeRevisionsSuppressed(): void {
		$unsuppressedRevisionId = 1001;
		$suppressedRevisionIdWithoutTag = 1002;
		$suppressedRevisionIdWithTag = 1003;
		$latestRevisionId = 1004;

		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ ChangeTagsHandler::PERSONAL_INFO_TAG ],
			null,
			$suppressedRevisionIdWithTag
		);

		$expectedInstrumentationData = [
			$suppressedRevisionIdWithoutTag => [
				'action_subtype' => 'personal-info-not-tagged',
				'identifier' => $suppressedRevisionIdWithoutTag,
				'identifier_type' => 'revision',
				'page' => $this->getExpectedPageInstrumentationData( $latestRevisionId ),
				'reason' => 'test reason abc',
			],
			$suppressedRevisionIdWithTag => [
				'action_subtype' => 'personal-info-tagged',
				'identifier' => $suppressedRevisionIdWithTag,
				'identifier_type' => 'revision',
				'page' => $this->getExpectedPageInstrumentationData( $latestRevisionId ),
				'reason' => 'test reason abc',
			],
		];
		$this->instrumentationClient->expects( $this->exactly( count( $expectedInstrumentationData ) ) )
			->method( 'submitInteraction' )
			->with(
				RequestContext::getMain(),
				'revision_content_suppressed',
				$this->anything()
			)
			->willReturnCallback( function ( $context, $action, $interactionData ) use (
				$expectedInstrumentationData
			) {
				$this->assertArrayHasKey( 'identifier', $interactionData );
				$this->assertContains(
					$interactionData['identifier'],
					array_keys( $expectedInstrumentationData ),
					'Revision was not expected to be instrumented as suppressed'
				);
				$this->assertSame(
					$expectedInstrumentationData[$interactionData['identifier']],
					$interactionData,
					'Interaction data was not as expected'
				);
			} );

		$this->listener->handlePageHistoryVisibilityChangedEvent( $this->createVisibilityChangedEvent(
			$latestRevisionId,
			[
				$unsuppressedRevisionId => [
					'oldBits' => 0,
					'newBits' => RevisionRecord::DELETED_TEXT,
				],
				$suppressedRevisionIdWithoutTag => [
					'oldBits' => 0,
					'newBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
				],
				$suppressedRevisionIdWithTag => [
					'oldBits' => 0,
					'newBits' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
				],
			],
			RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
			0,
			'test reason abc'
		) );
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

	private function createVisibilityChangedEvent(
		int $latestRevisionId,
		array $visibilityMap,
		int $bitsSet,
		int $bitsUnset,
		string $reason
	): PageHistoryVisibilityChangedEvent {
		return new PageHistoryVisibilityChangedEvent(
			$this->page,
			$this->getTestUser()->getUserIdentity(),
			$latestRevisionId,
			$bitsSet,
			$bitsUnset,
			$visibilityMap,
			$reason,
			[],
			[],
			false
		);
	}
}

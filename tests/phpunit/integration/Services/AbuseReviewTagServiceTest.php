<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttribution;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService
 * @group Database
 */
class AbuseReviewTagServiceTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	private const string PERSONAL_INFO_TAG = 'mw-private-personal-info';
	private const string PERSONAL_INFO_FALSE_POSITIVE_TAG = 'mw-private-personal-info-false-positive';
	private const string PERSONAL_INFO_NO_FURTHER_ACTION_TAG = 'mw-private-personal-info-no-further-action';
	private const string PAGE_NAME = 'Abuse review verdict test page';
	private const string VERDICT_TIME = '20260901133152';

	private ?bool $originalAlwaysInsert = null;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	protected function tearDown(): void {
		if ( $this->originalAlwaysInsert !== null ) {
			Event::$alwaysInsert = $this->originalAlwaysInsert;
		}

		parent::tearDown();
	}

	private function enableFlagNotifications(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' => true,
			'EchoUseJobQueue' => false,
			'EchoNotifications' => [
				PersonalInfoFlagNotifier::EVENT_TYPE => [ 'category' => 'system' ],
			],
		] );
		$this->originalAlwaysInsert = Event::$alwaysInsert;
		Event::$alwaysInsert = true;
	}

	private function createFlagEvent( int $revId ): int {
		return Event::create( [
			'type' => PersonalInfoFlagNotifier::EVENT_TYPE,
			'title' => Title::newFromText( self::PAGE_NAME ),
			'extra' => [ 'revisionId' => $revId ],
		] )->getId();
	}

	private function getService(): AbuseReviewTagService {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' );
	}

	private function getAttribution(): AbuseReviewVerdictAttribution {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttribution' );
	}

	private function reviewer(): Authority {
		return $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] );
	}

	private function realReviewer(): User {
		return $this->getMutableTestUser( [ 'suppress' ] )->getUser();
	}

	private function createRevisionId( string $page = self::PAGE_NAME ): int {
		return $this->editPage( $page, 'test content' )
			->getNewRevision()
			->getId();
	}

	private function applyTag( int $revId, string $tag ): void {
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ $tag ], null, $revId
		);
	}

	private function getTags( int $revId ): array {
		return $this->getServiceContainer()->getChangeTagsStore()->getTags( $this->getDb(), null, $revId );
	}

	/** @return string|null The ct_params the given tag carries on the revision */
	private function getTagParams( int $revId, string $tag ): ?string {
		$tagsWithData = $this->getServiceContainer()->getChangeTagsStore()
			->getTagsWithData( $this->getDb(), null, $revId );
		$this->assertArrayHasKey( $tag, $tagsWithData, "The revision must carry the $tag tag" );

		return $tagsWithData[$tag];
	}

	private function reviewerActorId( User $reviewer ): int {
		$actorId = $this->getServiceContainer()->getActorStore()->findActorId( $reviewer, $this->getDb() );
		$this->assertNotNull( $actorId, 'The reviewer account already has an actor ID, as in production' );

		return $actorId;
	}

	public function testMarkReturnsNotFoundWhenFeatureDisabled(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', false );

		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-disabled', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsBadRequestForUnknownTag(): void {
		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, 'mw-not-a-reviewable-tag' );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-unknown-tag', $status );
		$this->assertSame( 400, $status->getValue() );
	}

	public function testMarkReturnsServiceUnavailableWhenReadOnly(): void {
		$this->getServiceContainer()->getReadOnlyMode()->setReason( 'Maintenance in progress' );

		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'readonlytext', $status );
		$this->assertSame( 503, $status->getValue() );
	}

	/** @dataProvider provideUnprivilegedAuthorities */
	public function testMarkReturnsErrorWhenLackingSuppressionRights( bool $isRegistered, int $expected ): void {
		$authority = $isRegistered ? $this->mockRegisteredNullAuthority() : $this->mockAnonNullAuthority();

		$status = $this->getService()->markFalsePositive( $authority, 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-permission-denied', $status );
		$this->assertSame( $expected, $status->getValue() );
	}

	public static function provideUnprivilegedAuthorities(): array {
		return [
			'registered user without suppression rights' => [ 'isRegistered' => true, 'expected' => 403 ],
			'anonymous user' => [ 'isRegistered' => false, 'expected' => 401 ],
		];
	}

	public function testMarkReturnsErrorWhenUserCannotSeeDeletedRevision(): void {
		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] );
		$revId = $this->createRevisionId();
		$this->deletePage( self::PAGE_NAME );

		$status = $this->getService()->markFalsePositive( $authority, $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'rest-nonexistent-revision', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsForbiddenWhenSitewideBlocked(): void {
		$authority = $this->mockUserAuthorityWithBlock(
			new UserIdentityValue( 9999, 'Blocked reviewer' ),
			new DatabaseBlock( [ 'sitewide' => true ] ),
			[ 'viewsuppressed' ]
		);

		$status = $this->getService()->markFalsePositive( $authority, 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-blocked', $status );
		$this->assertSame( 403, $status->getValue() );
	}

	public function testMarkReturnsForbiddenWhenPartiallyBlockedFromPage(): void {
		$revId = $this->createRevisionId();
		$block = $this->createMock( DatabaseBlock::class );
		$block->method( 'isSitewide' )->willReturn( false );
		$block->method( 'appliesToTitle' )->willReturn( true );
		$authority = $this->mockUserAuthorityWithBlock(
			new UserIdentityValue( 9999, 'Partially blocked reviewer' ),
			$block,
			[ 'viewsuppressed' ]
		);

		$status = $this->getService()->markFalsePositive( $authority, $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-blocked', $status );
		$this->assertSame( 403, $status->getValue() );
	}

	public function testMarkReturnsNotFoundForMissingRevision(): void {
		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'rest-nonexistent-revision', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsUnprocessableWhenNotFlagged(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-not-flagged', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	public function testUnmarkReturnsNotFoundWhenFeatureDisabled(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', false );

		$status = $this->getService()->unmarkFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-disabled', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testUnmarkReturnsUnprocessableWhenNotMarked(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-not-marked', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	/** @dataProvider providePageIsDeleted */
	public function testMarkAndUnmarkSwapTags( bool $pageIsDeleted ): void {
		$reviewer = $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed', 'deletedhistory' ] );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->assertSame( [ self::PERSONAL_INFO_TAG ], $this->getTags( $revId ) );

		if ( $pageIsDeleted ) {
			$this->deletePage( self::PAGE_NAME );
		}

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking must remove the personal-info tag and add the false-positive tag'
		);

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking must restore the personal-info tag and remove the false-positive tag'
		);
	}

	public static function providePageIsDeleted(): array {
		return [
			'Page is not deleted' => [ 'pageIsDeleted' => false ],
			'Page is deleted' => [ 'pageIsDeleted' => true ],
		];
	}

	/** @dataProvider provideSuppressionRights */
	public function testMarkAcceptsEitherSuppressionRight( string $right ): void {
		$reviewer = $this->mockRegisteredAuthorityWithPermissions( [ $right ] );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame( [ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ], $this->getTags( $revId ) );
	}

	public static function provideSuppressionRights(): array {
		return [
			'viewsuppressed' => [ 'right' => 'viewsuppressed' ],
			'suppressrevision' => [ 'right' => 'suppressrevision' ],
		];
	}

	public function testMarkIsIdempotentWhenAlreadyFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking an already false-positive revision leaves it unchanged'
		);
	}

	public function testUnmarkIsIdempotentWhenNotFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking a revision that is not a false positive leaves it unchanged'
		);
	}

	/** @dataProvider providePageIsDeleted */
	public function testMarkAndUnmarkNoFurtherActionKeepsFlaggingTag( bool $pageIsDeleted ): void {
		$reviewer = $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed', 'deletedhistory' ] );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		if ( $pageIsDeleted ) {
			$this->deletePage( self::PAGE_NAME );
		}

		$this->assertStatusGood(
			$this->getService()->markNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertArrayEquals(
			[ self::PERSONAL_INFO_TAG, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG ],
			$this->getTags( $revId ),
			false,
			false,
			'Marking must add the no-further-action tag and keep the flagging tag'
		);

		$this->assertStatusGood(
			$this->getService()->unmarkNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking must remove only the no-further-action tag'
		);
	}

	public function testMarkFalsePositiveHidesTheFlagNotification(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$eventId = $this->createFlagEvent( $revId );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'Marking a false positive must hide the flag notification'
		);

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertFalse(
			$this->isEventHidden( $eventId ),
			'Unmarking puts the revision back in the queue, so the notification comes back'
		);
	}

	public function testMarkFalsePositiveHidesTheNotificationWhenAlreadyMarked(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );
		$eventId = $this->createFlagEvent( $revId );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'Marking an already-marked revision still hides a notification which slipped through'
		);
	}

	public function testUnmarkFalsePositiveLeavesASuppressedRevisionHidden(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );
		$eventId = $this->createFlagEvent( $revId );
		( new EventMapper() )->toggleDeleted( [ $eventId ], true );
		$this->suppressRevision( $revId );

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'A suppressed revision needs no action, so its notification stays hidden'
		);
	}

	public function testMarkNoFurtherActionHidesTheFlagNotification(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$eventId = $this->createFlagEvent( $revId );

		$this->assertStatusGood(
			$this->getService()->markNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'Marking no further action must hide the flag notification'
		);

		$this->assertStatusGood(
			$this->getService()->unmarkNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertFalse(
			$this->isEventHidden( $eventId ),
			'Unmarking puts the revision back in the queue, so the notification comes back'
		);
	}

	public function testUnmarkNoFurtherActionLeavesASuppressedRevisionHidden(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG );
		$eventId = $this->createFlagEvent( $revId );
		( new EventMapper() )->toggleDeleted( [ $eventId ], true );
		$this->suppressRevision( $revId );

		$this->assertStatusGood(
			$this->getService()->unmarkNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();
		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'A suppressed revision needs no action, so its notification stays hidden'
		);
	}

	public function testMarkNoFurtherActionHidesTheNotificationWhenAlreadyMarked(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG );
		$eventId = $this->createFlagEvent( $revId );

		$this->assertStatusGood(
			$this->getService()->markNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();

		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'Marking an already-marked revision still hides a notification which slipped through'
		);
	}

	public function testUnmarkNoFurtherActionLeavesAnArchivedRevisionHidden(): void {
		$this->enableFlagNotifications();

		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG );
		$eventId = $this->createFlagEvent( $revId );
		$this->deletePage( self::PAGE_NAME );
		$this->runDeferredUpdates();

		$reviewer = $this->mockRegisteredAuthorityWithPermissions(
			[ 'viewsuppressed', 'deletedhistory' ]
		);
		$this->assertStatusGood(
			$this->getService()->unmarkNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->runDeferredUpdates();

		$this->assertTrue(
			$this->isEventHidden( $eventId ),
			'Echo hid the notification when the page went, so the unmark must leave it alone'
		);
	}

	private function suppressRevision( int $revId ): void {
		$suppressed = RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED;
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'revision' )
			->set( [ 'rev_deleted' => $suppressed ] )
			->where( [ 'rev_id' => $revId ] )
			->caller( __METHOD__ )->execute();
	}

	private function isEventHidden( int $eventId ): bool {
		return ( new EventMapper() )->fetchById( $eventId, true )->isDeleted();
	}

	/**
	 * A revision holds one verdict at a time: "false positive" says the flag was wrong,
	 * "no further action" says the flag was right, so a revision cannot hold both.
	 *
	 * @dataProvider provideConflictingVerdicts
	 */
	public function testMarkingRejectsARevisionThatAlreadyHasTheOtherVerdict(
		string $existingTag,
		string $method
	): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, $existingTag );

		$status = $this->getService()->$method( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-conflicting-verdict', $status );
		$this->assertSame( 422, $status->getValue() );
		$this->assertArrayEquals(
			[ self::PERSONAL_INFO_TAG, $existingTag ],
			$this->getTags( $revId ),
			false,
			false,
			'The rejected call must leave the tags untouched'
		);
	}

	public static function provideConflictingVerdicts(): array {
		return [
			'no further action on a false positive' => [
				'existingTag' => self::PERSONAL_INFO_FALSE_POSITIVE_TAG,
				'method' => 'markNoFurtherAction',
			],
			'false positive on a no further action' => [
				'existingTag' => self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG,
				'method' => 'markFalsePositive',
			],
		];
	}

	public function testMarkNoFurtherActionIsIdempotentWhenAlreadyMarked(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG );

		$this->assertStatusGood(
			$this->getService()->markNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertArrayEquals(
			[ self::PERSONAL_INFO_TAG, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG ],
			$this->getTags( $revId ),
			false,
			false,
			'Marking an already marked revision leaves it unchanged'
		);
	}

	public function testMarkNoFurtherActionReturnsUnprocessableWhenNotFlagged(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->markNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-not-flagged', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	public function testUnmarkNoFurtherActionIsIdempotentWhenNotMarked(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood(
			$this->getService()->unmarkNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking a revision that is not marked leaves it unchanged'
		);
	}

	public function testUnmarkNoFurtherActionReturnsUnprocessableWhenNotFlagged(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->unmarkNoFurtherAction( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-not-flagged', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	/** @dataProvider provideNoFurtherActionMethods */
	public function testNoFurtherActionReturnsNotFoundWhenFeatureDisabled( string $method ): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', false );

		$status = $this->getService()->$method( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-review-disabled', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public static function provideNoFurtherActionMethods(): array {
		return [
			'markNoFurtherAction' => [ 'method' => 'markNoFurtherAction' ],
			'unmarkNoFurtherAction' => [ 'method' => 'unmarkNoFurtherAction' ],
		];
	}

	public function testMarkOnRevisionWithBothTagsResolvesToFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking a revision that carries both tags leaves only the false-positive tag'
		);
	}

	public function testUnmarkOnRevisionWithBothTagsResolvesToFlagged(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking a revision that carries both tags leaves only the flagged tag'
		);
	}

	public function testMarkingFalsePositiveRecordsTheReviewer(): void {
		$reviewer = $this->realReviewer();
		$actorId = $this->reviewerActorId( $reviewer );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		ConvertibleTimestamp::setFakeTime( self::VERDICT_TIME );
		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);

		$this->assertSame(
			$this->getAttribution()->encode( $actorId, self::VERDICT_TIME ),
			$this->getTagParams( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG ),
			'The false positive verdict must name the reviewer who recorded it, and when'
		);
	}

	public function testMarkingNoFurtherActionRecordsTheReviewer(): void {
		$reviewer = $this->realReviewer();
		$actorId = $this->reviewerActorId( $reviewer );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		ConvertibleTimestamp::setFakeTime( self::VERDICT_TIME );
		$this->assertStatusGood(
			$this->getService()->markNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);

		$this->assertSame(
			$this->getAttribution()->encode( $actorId, self::VERDICT_TIME ),
			$this->getTagParams( $revId, self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG ),
			'The no further action verdict must name the reviewer who recorded it, and when'
		);
	}

	public function testFlagRestoredFromFalsePositiveCarriesNoAttribution(): void {
		$reviewer = $this->realReviewer();
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$service = $this->getService();
		$this->assertStatusGood( $service->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG ) );
		$this->assertStatusGood( $service->unmarkFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG ) );

		$this->assertNull(
			$this->getTagParams( $revId, self::PERSONAL_INFO_TAG ),
			'Attribution belongs to a verdict, not to the flag that unmarking restores'
		);
	}

	public function testFlagRestoredFromNoFurtherActionCarriesNoAttribution(): void {
		$reviewer = $this->realReviewer();
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$service = $this->getService();
		$this->assertStatusGood( $service->markNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG ) );
		$this->assertStatusGood( $service->unmarkNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG ) );

		$this->assertNull(
			$this->getTagParams( $revId, self::PERSONAL_INFO_TAG ),
			'Attribution belongs to a verdict, not to the flag that unmarking restores'
		);
	}

	public function testReFlaggedVerdictKeepsTheFirstAttribution(): void {
		$firstReviewer = $this->realReviewer();
		$secondReviewer = $this->realReviewer();
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$service = $this->getService();
		$this->assertStatusGood( $service->markFalsePositive( $firstReviewer, $revId, self::PERSONAL_INFO_TAG ) );
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood( $service->markFalsePositive( $secondReviewer, $revId, self::PERSONAL_INFO_TAG ) );

		$actorStore = $this->getServiceContainer()->getActorStore();
		$this->assertSame(
			$actorStore->findActorId( $firstReviewer, $this->getDb() ),
			$this->getAttribution()->decodeActorId(
				$this->getTagParams( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG )
			),
			'The reviewer who first judged the revision keeps the attribution'
		);
		$this->assertNotContains(
			self::PERSONAL_INFO_TAG,
			$this->getServiceContainer()->getChangeTagsStore()->getTags( $this->getDb(), null, $revId ),
			'The flag a later model run re-added is dropped when the verdict is reapplied'
		);
	}

	public function testTwoReviewersAreRecordedDistinctly(): void {
		$firstReviewer = $this->realReviewer();
		$secondReviewer = $this->realReviewer();
		$firstRevId = $this->createRevisionId();
		$secondRevId = $this->createRevisionId( 'Second false positive test page' );
		$this->applyTag( $firstRevId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $secondRevId, self::PERSONAL_INFO_TAG );

		$service = $this->getService();
		$this->assertStatusGood(
			$service->markFalsePositive( $firstReviewer, $firstRevId, self::PERSONAL_INFO_TAG )
		);
		$this->assertStatusGood(
			$service->markFalsePositive( $secondReviewer, $secondRevId, self::PERSONAL_INFO_TAG )
		);

		$actorStore = $this->getServiceContainer()->getActorStore();
		$firstActorId = $actorStore->findActorId( $firstReviewer, $this->getDb() );
		$secondActorId = $actorStore->findActorId( $secondReviewer, $this->getDb() );
		$this->assertNotNull( $firstActorId, 'The first reviewer account already has an actor ID' );
		$this->assertNotSame( $firstActorId, $secondActorId, 'The reviewers must be two different actors' );

		$this->assertSame(
			$firstActorId,
			$this->getAttribution()->decodeActorId(
				$this->getTagParams( $firstRevId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG )
			),
			'The first revision names the reviewer who judged it'
		);
		$this->assertSame(
			$secondActorId,
			$this->getAttribution()->decodeActorId(
				$this->getTagParams( $secondRevId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG )
			),
			'The second revision names the other reviewer, not the first'
		);
	}
}

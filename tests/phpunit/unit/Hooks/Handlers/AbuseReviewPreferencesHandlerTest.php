<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewPreferencesHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewPreferencesHandler
 */
class AbuseReviewPreferencesHandlerTest extends MediaWikiUnitTestCase {

	private const array EXPECTED_PREFERENCE = [
		'type' => 'toggle',
		'label-message' => 'wikimediaantiabuse-pref-abusereview-live-updates',
		'section' => 'rc/abusereview',
	];

	private AbuseReviewEnabledTagsProvider $enabledTagsProvider;
	private ChangeTagsStore $changeTagsStore;
	private User $user;
	private AbuseReviewPreferencesHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->enabledTagsProvider = $this->createMock( AbuseReviewEnabledTagsProvider::class );
		$this->changeTagsStore = $this->createMock( ChangeTagsStore::class );
		$this->user = $this->createMock( User::class );
		$this->handler = new AbuseReviewPreferencesHandler( $this->enabledTagsProvider, $this->changeTagsStore );
	}

	/** @dataProvider provideUsersWhoCanSeeAQueue */
	public function testShowsCheckboxToUserWhoCanSeeAQueue(
		array $enabledTags,
		array $canViewSequence,
		array $expectedTagsChecked
	): void {
		$this->enabledTagsProvider->expects( $this->once() )
			->method( 'getEnabledReviewableTags' )
			->willReturn( $enabledTags );

		$checkedTags = [];
		$this->changeTagsStore->expects( $this->exactly( count( $expectedTagsChecked ) ) )
			->method( 'canViewTag' )
			->willReturnCallback( function ( string $tag, $performer ) use ( &$checkedTags, $canViewSequence ): bool {
				$this->assertSame( $this->user, $performer, 'the rights checked are those of the viewing user' );
				$canView = $canViewSequence[count( $checkedTags )];
				$checkedTags[] = $tag;

				return $canView;
			} );

		$preferences = [];
		$this->handler->onGetPreferences( $this->user, $preferences );

		$this->assertSame(
			$expectedTagsChecked,
			$checkedTags,
			'queues are checked in order until one the user can see is found'
		);
		$this->assertSame(
			[ AbuseReviewPreferencesHandler::LIVE_UPDATES_PREFERENCE => self::EXPECTED_PREFERENCE ],
			$preferences
		);
	}

	public static function provideUsersWhoCanSeeAQueue(): array {
		return [
			'can see the Personal information queue' => [
				'enabledTags' => [ 'mw-private-personal-info', 'mw-private-vandalism' ],
				'canViewSequence' => [ true ],
				'expectedTagsChecked' => [ 'mw-private-personal-info' ],
			],
			'can see only the Vandalism queue' => [
				'enabledTags' => [ 'mw-private-personal-info', 'mw-private-vandalism' ],
				'canViewSequence' => [ false, true ],
				'expectedTagsChecked' => [ 'mw-private-personal-info', 'mw-private-vandalism' ],
			],
		];
	}

	public function testHidesCheckboxFromUserWhoCanSeeNoQueue(): void {
		$enabledTags = [ 'mw-private-personal-info', 'mw-private-vandalism' ];
		$this->enabledTagsProvider->expects( $this->once() )
			->method( 'getEnabledReviewableTags' )
			->willReturn( $enabledTags );

		$checkedTags = [];
		$this->changeTagsStore->expects( $this->exactly( count( $enabledTags ) ) )
			->method( 'canViewTag' )
			->willReturnCallback( function ( string $tag, $performer ) use ( &$checkedTags ): bool {
				$this->assertSame( $this->user, $performer, 'the rights checked are those of the viewing user' );
				$checkedTags[] = $tag;

				return false;
			} );

		$preferences = [];
		$this->handler->onGetPreferences( $this->user, $preferences );

		$this->assertSame(
			$enabledTags,
			$checkedTags,
			'every switched-on queue is checked when the user can see none'
		);
		$this->assertSame( [], $preferences, 'no checkbox for a user who can see no queue' );
	}

	public function testHidesCheckboxWhenNoQueueIsSwitchedOn(): void {
		$this->enabledTagsProvider->expects( $this->once() )
			->method( 'getEnabledReviewableTags' )
			->willReturn( [] );
		$this->changeTagsStore->expects( $this->never() )
			->method( 'canViewTag' );

		$preferences = [];
		$this->handler->onGetPreferences( $this->user, $preferences );

		$this->assertSame( [], $preferences, 'no checkbox when no queue is switched on' );
	}
}

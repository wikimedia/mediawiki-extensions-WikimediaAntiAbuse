<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Request\FauxRequest;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewTest extends SpecialAbuseReviewTestBase {

	private const string PERSONAL_INFO_TAG = 'mw-private-personal-info';

	/** @dataProvider provideQueueViewers */
	public function testUserCanExecute(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		array $rights,
		bool $expected
	): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
			'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
		] );
		$this->setGroupPermissions( [ 'abuse-review-viewer' => array_fill_keys( $rights, true ) ] );

		$this->assertSame(
			$expected,
			$this->newSpecialPage()->userCanExecute(
				$this->getTestUser( [ 'abuse-review-viewer' ] )->getUser()
			)
		);
	}

	public static function provideQueueViewers(): array {
		return [
			'holds a right over the enabled personal information tag' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'viewsuppressed' ],
				'expected' => true,
			],
			'holds the other right over the enabled personal information tag' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'suppressrevision' ],
				'expected' => true,
			],
			'holds a right over the enabled vandalism tag' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'rights' => [ 'rollback' ],
				'expected' => true,
			],
			'holds a right over the disabled tag only' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'rollback' ],
				'expected' => false,
			],
			'holds every right while no tag is enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'rights' => [ 'viewsuppressed', 'suppressrevision', 'rollback' ],
				'expected' => false,
			],
			'holds none of the rights' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'rights' => [],
				'expected' => false,
			],
		];
	}

	public function testViewWhenCannotSeeAnyAbuseTag(): void {
		$this->expectException( ErrorPageError::class );
		$this->expectExceptionMessage( 'You do not have any of the permissions needed to view this page' );
		$this->executeSpecialPage();
	}

	public function testViewWhenNoRevisionsPresent(): void {
		$testUser = $this->getTestUser( [ 'suppress' ] )->getUser();
		[ $html ] = $this->executeSpecialPage( '', null, null, $testUser );

		$specialPageSummaryHtml = $this->assertSelectorMatchesOneElement( $html, '.mw-specialpage-summary' );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-summary)',
			$specialPageSummaryHtml
		);

		$this->verifyFilterButtonPresent( $html, 0 );

		$tablePagerHtml = $this->commonVerifyTablePager( $html, false );
		$tablePagerEmptyContentHtml = $this->assertSelectorMatchesOneElement(
			$tablePagerHtml,
			'.cdx-table__table__empty-state-content'
		);
		foreach ( [ 'title', 'description', 'hint' ] as $part ) {
			$this->assertStringContainsString(
				"(wikimediaantiabuse-special-abuse-review-empty-$part)",
				$tablePagerEmptyContentHtml
			);
		}
		$this->assertSelectorMatchesOneElement(
			$tablePagerHtml,
			'.mw-wikimediaantiabuse-abuse-review-empty-mark'
		);

		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( DOMUtils::parseHTML( $html ), '.cdx-table-pager' ),
			'an empty queue has nothing to page through'
		);
	}

	/**
	 * An edit that changed no lines still gets its link out to the full diff, the preview
	 * having nothing to show.
	 */
	public function testViewWhenRevisionHasNoContentChange(): void {
		$editStatus = $this->editPage( $this->getNonexistingTestPage(), '' );
		$this->assertStatusGood( $editStatus );
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ 'mw-private-personal-info' ],
			null,
			$editStatus->getNewRevision()->getId()
		);

		[ $html ] = $this->executeSpecialPage(
			'', null, null, $this->getTestUser( [ 'suppress' ] )->getUser()
		);
		$row = $this->assertSelectorMatchesOneElementInNode(
			DOMUtils::parseHTML( $html ),
			'.mw-wikimediaantiabuse-abuse-review-row'
		);

		$this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-row__full-diff'
		);
		$this->assertNull(
			DOMCompat::querySelector( $row, '.mw-wikimediaantiabuse-abuse-review-row__diff' ),
			'a preview with no lines either side is left out rather than drawn empty'
		);
	}

	public function testViewWhenUsernameFilterSet(): void {
		$firstTestUser = $this->getTestUser()->getUser();
		$firstEditStatus = $this->editPage(
			$this->getNonexistingTestPage(),
			'test content',
			performer: $firstTestUser
		);
		$this->assertStatusGood( $firstEditStatus );
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ 'mw-private-personal-info' ],
			null,
			$firstEditStatus->getNewRevision()->getId()
		);

		$secondTestUser = $this->getTestSysop()->getUser();
		$secondEditStatus = $this->editPage(
			$this->getNonexistingTestPage(),
			'test content',
			performer: $secondTestUser
		);
		$this->assertStatusGood( $secondEditStatus );
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ 'mw-private-personal-info' ],
			null,
			$secondEditStatus->getNewRevision()->getId()
		);

		$context = RequestContext::getMain();
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$client->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				$context,
				'page_load',
				[
					'is_paging_results' => false,
					'pager_limit' => 123,
					'applied_filters' => [
						'show_false_positives' => false,
						'show_handled_revisions' => false,
						'username' => [ $firstTestUser->getName() ],
						'revision' => [],
						'page' => [],
						'tab' => 'mw-private-personal-info',
					],
				]
			);
		$this->setService( 'WikimediaAntiAbuseAbuseReviewInstrumentationClient', $client );

		$context->setRequest( new FauxRequest( [
			'username' => [ $firstTestUser->getName() ],
			'limit' => 123,
		] ) );
		$context->setUser( $this->getTestUser( [ 'suppress' ] )->getUser() );
		$context->setLanguage( 'qqx' );
		[ $html ] = $this->executeSpecialPage( '', null, null, null, false, $context );

		$this->assertArrayEquals(
			[
				'showFalsePositives' => false,
				'showHandledRevisions' => false,
				'username' => [ $firstTestUser->getName() ],
				'page' => [],
				'revision' => [],
				'tab' => '',
			],
			$context->getOutput()->getJsConfigVars()['wgWikimediaAntiAbuseActiveFilters'],
			false,
			true
		);

		$this->verifyFilterButtonPresent( $html, 1 );

		$htmlAsNode = DOMUtils::parseHTML( $html );
		$reviewRows = DOMCompat::querySelectorAll( $htmlAsNode, self::ROW_SELECTOR );
		$this->assertCount(
			1,
			$reviewRows,
			'Username filter should have filtered out the revision performed by the second user'
		);
		$this->assertSame(
			$firstEditStatus->getNewRevision()->getId(),
			(int)DOMCompat::getAttribute( $reviewRows[0], 'data-rev-id' ),
			'The visible row should be one performed by the first user'
		);
	}

	/** @dataProvider provideViewWhenReferrerSet */
	public function testViewWhenReferrerSet( string $referrerSetInRequest, ?string $expectedReferrer ): void {
		$context = RequestContext::getMain();
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$expectedInteractionData = [
			'is_paging_results' => false,
			'pager_limit' => 1,
			'applied_filters' => [
				'show_false_positives' => false,
				'show_handled_revisions' => false,
				'username' => [],
				'revision' => [],
				'page' => [],
				'tab' => 'mw-private-personal-info',
			]
		];
		if ( $expectedReferrer !== null ) {
			$expectedInteractionData['referrer'] = $expectedReferrer;
		}
		$client->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				$context,
				'page_load',
				$expectedInteractionData
			);
		$this->setService( 'WikimediaAntiAbuseAbuseReviewInstrumentationClient', $client );

		$context->setRequest( new FauxRequest( [
			'limit' => 1,
			'referrer' => $referrerSetInRequest,
		] ) );
		$context->setUser( $this->getTestUser( [ 'suppress' ] )->getUser() );
		$context->setLanguage( 'qqx' );
		$this->executeSpecialPage( '', null, null, null, false, $context );
	}

	public static function provideViewWhenReferrerSet(): array {
		return [
			'Referrer set in request as echo_notification' => [
				'referrerSetInRequest' => 'echo_notification',
				'expectedReferrer' => 'echo_notification',
			],
			'Referrer set in request as unrecognised referrer' => [
				'referrerSetInRequest' => 'unrecognised_referrer',
				'expectedReferrer' => null,
			],
			'Referrer not set in request' => [
				'referrerSetInRequest' => '',
				'expectedReferrer' => null,
			],
		];
	}

	public function testVerdictNamesTheReviewerWhoRecordedIt(): void {
		$reviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createFlaggedRevisionId();
		$this->assertStatusGood(
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' )
				->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( [ 'wpShowFalsePositives' => '1' ] ), null, $reviewer
		);
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), $revId );

		$byline = $this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-verdict-performer'
		);
		$bylineHtml = DOMCompat::getInnerHTML( $byline );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-verdict-attribution: ' . $reviewer->getName(),
			$bylineHtml,
			'the byline carries the reviewer\'s name for the message to resolve GENDER from'
		);
		$this->assertStringContainsString(
			$reviewer->getName(),
			DOMCompat::getInnerHTML(
				$this->assertSelectorMatchesOneElementInNode( $byline, 'a.mw-userlink' )
			),
			'the reviewer is linked beside the verdict they recorded'
		);
	}

	public function testReturnToReviewNamesWhoSentTheRevisionBack(): void {
		$reviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createFlaggedRevisionId();
		$tagService = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' );
		$this->assertStatusGood(
			$tagService->markNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertStatusGood(
			$tagService->unmarkNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);

		[ $html ] = $this->executeSpecialPage( '', new FauxRequest(), null, $reviewer );
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), $revId );

		$bylineHtml = DOMCompat::getInnerHTML( $this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-verdict-performer'
		) );
		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-verdict-returned-attribution: '
				. $reviewer->getName(),
			$bylineHtml,
			'a revision back in the queue is bylined to whoever sent it back'
		);
	}

	public function testExecuteForAVerdictRecordedBeforeAttributionExisted(): void {
		$revId = $this->createFlaggedRevisionId(
			[ self::PERSONAL_INFO_TAG, ChangeTagsHandler::PERSONAL_INFO_NO_FURTHER_ACTION_TAG ]
		);

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'wpShowHandledRevisions' => '1' ] ),
			null,
			$this->getTestUser( [ 'suppress' ] )->getUser()
		);
		$row = $this->getRowForRevision( DOMUtils::parseHTML( $html ), $revId );

		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( $row, '.mw-wikimediaantiabuse-abuse-review-verdict-performer' ),
			'a verdict recorded before attribution existed renders no byline at all'
		);
	}

	public function testEachRowNamesTheReviewerWhoJudgedIt(): void {
		$firstReviewer = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$secondReviewer = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$tagService = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' );
		$falsePositiveRevId = $this->createFlaggedRevisionId();
		$noFurtherActionRevId = $this->createFlaggedRevisionId();

		$this->assertStatusGood( $tagService->markFalsePositive(
			$firstReviewer, $falsePositiveRevId, self::PERSONAL_INFO_TAG
		) );
		$this->assertStatusGood( $tagService->markNoFurtherAction(
			$secondReviewer, $noFurtherActionRevId, self::PERSONAL_INFO_TAG
		) );

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'wpShowFalsePositives' => '1', 'wpShowHandledRevisions' => '1' ] ),
			null,
			$this->getTestUser( [ 'suppress' ] )->getUser()
		);
		$document = DOMUtils::parseHTML( $html );

		$falsePositiveByline = $this->getBylineHtml(
			$this->getRowForRevision( $document, $falsePositiveRevId )
		);
		$this->assertStringContainsString(
			$firstReviewer->getName(),
			$falsePositiveByline,
			'each row names the reviewer who judged that revision'
		);
		$this->assertStringNotContainsString(
			$secondReviewer->getName(),
			$falsePositiveByline,
			'no row names a reviewer who judged another revision'
		);
		$this->assertStringContainsString(
			$secondReviewer->getName(),
			$this->getBylineHtml(
				$this->getRowForRevision( $document, $noFurtherActionRevId )
			),
			'a row holding the other verdict names the reviewer who recorded it'
		);
	}

	public function testVerdictAttributionOutranksAnEarlierReturnToReview(): void {
		// Keep both tags in use: dropping a tag's last use deletes its definition
		$this->createFlaggedRevisionId(
			[ self::PERSONAL_INFO_TAG, ChangeTagsHandler::PERSONAL_INFO_NO_FURTHER_ACTION_TAG ]
		);
		$revId = $this->createFlaggedRevisionId();
		$returningReviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$judgingReviewer = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$tagService = $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' );
		$this->assertStatusGood(
			$tagService->markNoFurtherAction( $returningReviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertStatusGood(
			$tagService->unmarkNoFurtherAction( $returningReviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertStatusGood(
			$tagService->markNoFurtherAction( $judgingReviewer, $revId, self::PERSONAL_INFO_TAG )
		);

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'wpShowHandledRevisions' => '1' ] ),
			null,
			$this->getTestUser( [ 'suppress' ] )->getUser()
		);
		$byline = $this->getBylineHtml(
			$this->getRowForRevision( DOMUtils::parseHTML( $html ), $revId )
		);

		$this->assertStringContainsString(
			'(wikimediaantiabuse-special-abuse-review-verdict-attribution: '
				. $judgingReviewer->getName(),
			$byline,
			'the row names the reviewer whose verdict it holds'
		);
		$this->assertStringNotContainsString(
			'wikimediaantiabuse-special-abuse-review-verdict-returned-attribution',
			$byline,
			'a row holding a verdict is not bylined as one returned to the queue'
		);
		$this->assertStringNotContainsString(
			$returningReviewer->getName(),
			$byline,
			'the reviewer who sent the earlier verdict back is no longer named'
		);
	}

	private function getBylineHtml( Element $row ): string {
		return DOMCompat::getInnerHTML( $this->assertSelectorMatchesOneElementInNode(
			$row,
			'.mw-wikimediaantiabuse-abuse-review-verdict-performer'
		) );
	}

}

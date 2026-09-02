<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Request\FauxRequest;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class SpecialAbuseReviewTest extends SpecialAbuseReviewTestBase {

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
					]
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

	/** @inheritDoc */
	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'AbuseReview' );
	}
}

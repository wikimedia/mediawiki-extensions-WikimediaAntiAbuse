<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttributionFormatter;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttributionFormatter
 * @group Database
 */
class AbuseReviewVerdictAttributionFormatterTest extends MediaWikiIntegrationTestCase {

	use HtmlAssertionHelperTrait;

	private const int REVISION_ID = 123;
	private const string RECORDED_VERDICT_MESSAGE =
		'wikimediaantiabuse-special-abuse-review-verdict-attribution';
	private const string RETURNED_TO_REVIEW_MESSAGE =
		'wikimediaantiabuse-special-abuse-review-verdict-returned-attribution';

	private RequestContext $context;
	private User $performer;
	private AbuseReviewVerdictAttributionFormatter $formatter;

	protected function setUp(): void {
		parent::setUp();

		$this->context = new RequestContext();
		$this->context->setLanguage( 'qqx' );
		$this->performer = $this->getTestUser()->getUser();
		$this->formatter = $this->getServiceContainer()
			->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttributionFormatter' );
	}

	/** @dataProvider provideVerdictHeld */
	public function testFormatForNamesTheReviewerTheFlagRecords(
		bool $verdictHeld,
		string $expectedMessageKey
	): void {
		$byline = DOMUtils::parseHTML( $this->formatter->formatFor(
			$this->context,
			[ self::REVISION_ID => $this->performer ],
			self::REVISION_ID,
			$verdictHeld
		) );

		$performerName = $this->performer->getName();
		$this->assertSame(
			"($expectedMessageKey: $performerName, $performerName)",
			DOMCompat::getBody( $byline )->textContent,
			'the byline is built from the message matching how the flag was left, and is passed the '
				. 'reviewer\'s name for GENDER, then the link naming them'
		);
		$this->assertStringContainsString(
			$performerName,
			DOMCompat::getInnerHTML(
				$this->assertSelectorMatchesOneElementInNode( $byline, 'a.mw-userlink' )
			),
			'the byline links to the reviewer it names'
		);
	}

	public static function provideVerdictHeld(): array {
		return [
			'verdict held' => [
				'verdictHeld' => true,
				'expectedMessageKey' => self::RECORDED_VERDICT_MESSAGE,
			],
			'returned to review' => [
				'verdictHeld' => false,
				'expectedMessageKey' => self::RETURNED_TO_REVIEW_MESSAGE,
			],
		];
	}

	public function testFormatForOmitsTheUserInfoCard(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		$viewer = $this->getTestUser()->getUser();
		$this->getServiceContainer()->getUserOptionsManager()
			->setOption( $viewer, 'checkuser-userinfocard-enable', 1 );
		$this->context->setUser( $viewer );

		$byline = DOMUtils::parseHTML( $this->formatter->formatFor(
			$this->context,
			[ self::REVISION_ID => $this->performer ],
			self::REVISION_ID,
			true
		) );

		$this->assertCount(
			0,
			DOMCompat::querySelectorAll( $byline, '.ext-checkuser-userinfocard-button-wrapper' ),
			'the byline links the reviewer plainly, with no user info card, even for a viewer who '
				. 'turned the card on'
		);
		$this->assertSelectorMatchesOneElementInNode( $byline, 'a.mw-userlink' );
	}

	public function testFormatForNamesNobodyWhereTheFlagRecordsNoReviewer(): void {
		$this->assertNull(
			$this->formatter->formatFor( $this->context, [], self::REVISION_ID, true ),
			'a revision the flag names no reviewer for is attributed to nobody'
		);
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictPerformerLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\AbuseReviewRevisionTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictPerformerLookup
 * @group Database
 */
class AbuseReviewVerdictPerformerLookupTest extends MediaWikiIntegrationTestCase {

	use AbuseReviewRevisionTestTrait;
	use MockAuthorityTrait;

	private const string PERSONAL_INFO_TAG = 'mw-private-personal-info';
	private const string FALSE_POSITIVE_TAG = 'mw-private-personal-info-false-positive';
	private const string NO_FURTHER_ACTION_TAG = 'mw-private-personal-info-no-further-action';
	private const string VANDALISM_TAG = 'mw-private-vandalism';
	private const string VANDALISM_NO_FURTHER_ACTION_TAG = 'mw-private-vandalism-no-further-action';
	private const string RECORDED_AT = '20260101010203';

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	private function getObjectUnderTest(): AbuseReviewVerdictPerformerLookup {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictPerformerLookup' );
	}

	private function createJudgedRevisionId( Authority $reviewer ): int {
		ConvertibleTimestamp::setFakeTime( self::RECORDED_AT );
		$revId = $this->createFlaggedRevisionId();
		$this->assertStatusGood(
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' )
				->markNoFurtherAction( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		ConvertibleTimestamp::setFakeTime( false );

		return $revId;
	}

	private function recordVerdict( int $revId, string $verdictTag, UserIdentity $reviewer ): void {
		$services = $this->getServiceContainer();
		$params = $services->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttribution' )->encode(
			$services->getActorNormalization()->acquireActorId( $reviewer, $this->getDb() ),
			self::RECORDED_AT
		);

		$services->getChangeTagsStore()->addTags( [ $verdictTag ], null, $revId, params: $params );
	}

	private function hideUser( UserIdentity $user ): void {
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $user,
			'hideName' => true,
			'expiry' => 'infinity',
			'by' => $this->getTestSysop()->getUser(),
		] );
	}

	public function testLookUpPerformersWithAnEmptyRevisionList(): void {
		// The lookup returns early unless some reviewable tag already carries an ID
		$this->createFlaggedRevisionId();

		$this->assertSame(
			[],
			$this->getObjectUnderTest()->lookUpPerformers(
				[],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] )
			),
			'An empty list matches nothing, rather than building a query that matches everything'
		);
	}

	public function testLookUpPerformersForAViewerWhoMaySeeTheVerdictTag(): void {
		$reviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createJudgedRevisionId( $reviewer );

		$this->assertEquals(
			[ $revId => $this->getExpectedPerformer( $reviewer ) ],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] )
			),
			'A viewer who may see the verdict tag is given the reviewer who recorded it'
		);
	}

	public function testLookUpPerformersForARevisionSentBackForReview(): void {
		$reviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createJudgedRevisionId( $reviewer );
		$sender = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$this->assertStatusGood(
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewTagService' )
				->unmarkNoFurtherAction( $sender, $revId, self::PERSONAL_INFO_TAG ),
			'Sending the revision back for review succeeds'
		);

		$this->assertEquals(
			[ $revId => $this->getExpectedPerformer( $sender ) ],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] )
			),
			'The flag tag names whoever returned the revision to the queue, not the verdict it lost'
		);
	}

	public function testLookUpPerformersForAViewerWhoMayNotSeeTheVerdictTag(): void {
		$reviewer = $this->getTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createJudgedRevisionId( $reviewer );

		$this->assertSame(
			[],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'abusereview-vandalism-alpha-tester' ] )
			),
			'A verdict tag restricted to a right the viewer lacks names nobody'
		);
	}

	public function testLookUpPerformersForAVerdictRecordedBeforeAttributionExisted(): void {
		$revId = $this->createFlaggedRevisionId(
			[ self::PERSONAL_INFO_TAG, self::NO_FURTHER_ACTION_TAG ]
		);

		$this->assertSame(
			[],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] )
			),
			'A verdict tag carrying no recorded attribution names nobody'
		);
	}

	public function testLookUpPerformersForAHiddenReviewer(): void {
		$reviewer = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createJudgedRevisionId( $reviewer );
		$this->hideUser( $reviewer );

		$this->assertSame(
			[],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] )
			),
			'A hidden reviewer is named to nobody who may not see hidden users'
		);
	}

	public function testLookUpPerformersForAViewerWhoMaySeeHiddenUsers(): void {
		$reviewer = $this->getMutableTestUser( [ 'suppress' ] )->getUser();
		$revId = $this->createJudgedRevisionId( $reviewer );
		$this->hideUser( $reviewer );

		$this->assertEquals(
			[ $revId => $this->getExpectedPerformer( $reviewer ) ],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::PERSONAL_INFO_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed', 'hideuser' ] )
			),
			'A viewer who may see hidden users is given the hidden reviewer'
		);
	}

	public function testLookUpPerformersForEachContentPolicySeparately(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableVandalismTag', true );
		$personalInfoReviewer = $this->getTestUser()->getUser();
		$vandalismReviewer = $this->getMutableTestUser()->getUser();
		$revId = $this->createFlaggedRevisionId(
			[ self::PERSONAL_INFO_TAG, self::VANDALISM_TAG ]
		);
		$this->recordVerdict( $revId, self::FALSE_POSITIVE_TAG, $personalInfoReviewer );
		$this->recordVerdict( $revId, self::VANDALISM_NO_FURTHER_ACTION_TAG, $vandalismReviewer );

		$authority = $this->mockRegisteredAuthorityWithPermissions( [
			'viewsuppressed',
			'abusereview-vandalism-alpha-tester',
		] );

		$this->assertEquals(
			[ $revId => $this->getExpectedPerformer( $personalInfoReviewer ) ],
			$this->getObjectUnderTest()->lookUpPerformers( [ $revId ], self::PERSONAL_INFO_TAG, $authority ),
			'The personal information flag reports the reviewer who judged it'
		);
		$this->assertEquals(
			[ $revId => $this->getExpectedPerformer( $vandalismReviewer ) ],
			$this->getObjectUnderTest()->lookUpPerformers( [ $revId ], self::VANDALISM_TAG, $authority ),
			'The vandalism flag reports its own reviewer, not the one the other flag names'
		);
	}

	public function testLookUpPerformersForAContentPolicyTheWikiHasSwitchedOff(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableVandalismTag', false );
		$reviewer = $this->getTestUser()->getUser();
		$revId = $this->createFlaggedRevisionId( [ self::VANDALISM_TAG ] );
		$this->recordVerdict( $revId, self::VANDALISM_NO_FURTHER_ACTION_TAG, $reviewer );

		$this->assertSame(
			[],
			$this->getObjectUnderTest()->lookUpPerformers(
				[ $revId ],
				self::VANDALISM_TAG,
				$this->mockRegisteredAuthorityWithPermissions( [
					'viewsuppressed',
					'abusereview-vandalism-alpha-tester',
				] )
			),
			'A verdict on a content policy the wiki has switched off stays hidden from a viewer '
				. 'holding every right its tag would be restricted to'
		);
	}

	private function getExpectedPerformer( UserIdentity $reviewer ): UserIdentityValue {
		return new UserIdentityValue( $reviewer->getId(), $reviewer->getName() );
	}
}

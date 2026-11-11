<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Jobs;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountBlockLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob
 */
class CloseBlockedAccountCaseJobTest extends MediaWikiUnitTestCase {

	private const int NOW = 1767225600;
	private const int RELEASE_TIME = self::NOW + 3600;
	private const int USER_ID = 42;
	private const string USER_NAME = 'Blocked user';

	/** @dataProvider provideNewSpec */
	public function testNewSpec(
		bool $delayedJobsEnabled,
		array $expectedParams,
		int|null $expectedReleaseTimestamp
	): void {
		ConvertibleTimestamp::setFakeTime( self::NOW );

		$spec = CloseBlockedAccountCaseJob::newSpec( self::USER_ID, $delayedJobsEnabled );

		$params = $spec->getParams();
		// JobSpecification adds the ID of the current request.
		unset( $params['requestId'] );
		$this->assertSame(
			$expectedParams,
			$params,
			'The job gets the user ID, and a release time only if delayed jobs are enabled'
		);
		// getReleaseTimestamp() returns the UNIX time as a string.
		$this->assertEquals(
			$expectedReleaseTimestamp,
			$spec->getReleaseTimestamp(),
			'The job queue releases the job at the release time'
		);
	}

	public static function provideNewSpec(): array {
		return [
			'Delayed jobs enabled' => [
				'delayedJobsEnabled' => true,
				'expectedParams' => [ 'userId' => self::USER_ID, 'jobReleaseTimestamp' => self::RELEASE_TIME ],
				'expectedReleaseTimestamp' => self::RELEASE_TIME,
			],
			'Delayed jobs disabled' => [
				'delayedJobsEnabled' => false,
				'expectedParams' => [ 'userId' => self::USER_ID ],
				'expectedReleaseTimestamp' => null,
			],
		];
	}

	/** @dataProvider provideNothingToClose */
	public function testRunDoesNotCloseACase(
		bool $queueEnabled,
		UserIdentity|null $user,
		AbuseReviewCase|null $case,
		int $expectedUserLookups,
		int $expectedCaseLookups
	): void {
		$config = new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => $queueEnabled ] );
		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->expects( $this->exactly( $expectedUserLookups ) )
			->method( 'getUserIdentityByUserId' )
			->with( self::USER_ID )
			->willReturn( $user );
		$caseLookup = $this->createMock( AbuseReviewAccountCaseLookup::class );
		$caseLookup->expects( $this->exactly( $expectedCaseLookups ) )
			->method( 'getCase' )
			->with( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS )
			->willReturn( $case );
		$caseManager = $this->createNoOpMock( AbuseReviewAccountCaseManager::class );
		$blockLookup = $this->createNoOpMock( AbuseReviewAccountBlockLookup::class );
		$job = new CloseBlockedAccountCaseJob(
			[ 'userId' => self::USER_ID ],
			$config,
			$userIdentityLookup,
			$caseLookup,
			$caseManager,
			$blockLookup,
			new NullLogger()
		);

		$this->assertTrue( $job->run(), 'The job reports success, so that the job queue does not run it again' );
	}

	public static function provideNothingToClose(): array {
		$user = new UserIdentityValue( self::USER_ID, self::USER_NAME );

		return [
			'Queue disabled' => [
				'queueEnabled' => false,
				'user' => null,
				'case' => null,
				'expectedUserLookups' => 0,
				'expectedCaseLookups' => 0,
			],
			'Unknown user' => [
				'queueEnabled' => true,
				'user' => null,
				'case' => null,
				'expectedUserLookups' => 1,
				'expectedCaseLookups' => 0,
			],
			'No case' => [
				'queueEnabled' => true,
				'user' => $user,
				'case' => null,
				'expectedUserLookups' => 1,
				'expectedCaseLookups' => 1,
			],
			'Handled case' => [
				'queueEnabled' => true,
				'user' => $user,
				'case' => new AbuseReviewCase( 1, 2, AbuseReviewCaseState::HANDLED, '20260101000000' ),
				'expectedUserLookups' => 1,
				'expectedCaseLookups' => 1,
			],
		];
	}
}

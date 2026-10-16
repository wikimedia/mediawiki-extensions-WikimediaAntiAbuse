<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Rest\Handler;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictRequestLogger;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\SpecialAbuseReview;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkReviewVerdictHandler
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler
 */
class MarkReviewVerdictHandlerTest extends MediaWikiUnitTestCase {

	use HandlerTestTrait;

	private const string TAG = 'mw-private-personal-info';

	private function newRequest( string $verdict, string $referrer = '', array $headers = [] ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'pathParams' => [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ],
			'headers' => [ 'Content-Type' => 'application/json' ] + $headers,
			'bodyContents' => json_encode( [ 'referrer' => $referrer ] )
		] );
	}

	private function pathParams( string $verdict ): array {
		return [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ];
	}

	/** @dataProvider provideVerdicts */
	public function testRunMarksRevisionAndReturnsJson(
		string $verdict,
		string $serviceMethod,
		string $responseField,
		string $referrer,
		bool $changed
	): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( $serviceMethod )
			->with( $authority, 123, self::TAG )
			->willReturn( StatusValue::newGood( $changed ) );

		$expectedInstrumentationData = [
			'action_subtype' => 'mark',
			'identifier' => 123,
			'identifier_type' => 'revision',
			'abuse_review_tag' => self::TAG,
		];
		if ( in_array( $referrer, SpecialAbuseReview::VALID_REFERRERS, true ) ) {
			$expectedInstrumentationData['referrer'] = $referrer;
		}
		$instrumentationClient = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$instrumentationClient->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				RequestContext::getMain(),
				strtr( $verdict, [ '-' => '_' ] ),
				$expectedInstrumentationData
			);

		$data = $this->executeHandlerAndGetBodyData(
			new MarkReviewVerdictHandler(
				$service,
				$instrumentationClient,
				$this->createStub( AbuseReviewVerdictRequestLogger::class )
			),
			$this->newRequest( $verdict, $referrer ),
			[],
			[],
			[],
			[],
			$authority
		);

		$this->assertSame(
			[ 'revision' => 123, 'tag' => self::TAG, $responseField => true, 'changed' => $changed ],
			$data
		);
	}

	public static function provideVerdicts(): array {
		return [
			'false positive' => [
				'verdict' => ReviewVerdictHandler::FALSE_POSITIVE,
				'serviceMethod' => 'markFalsePositive',
				'responseField' => 'falsePositive',
				'referrer' => '',
				'changed' => true,
			],
			'no further action already recorded' => [
				'verdict' => ReviewVerdictHandler::NO_FURTHER_ACTION,
				'serviceMethod' => 'markNoFurtherAction',
				'responseField' => 'noFurtherAction',
				'referrer' => 'invalid_referrer',
				'changed' => false,
			],
			'false positive with valid referrer' => [
				'verdict' => ReviewVerdictHandler::FALSE_POSITIVE,
				'serviceMethod' => 'markFalsePositive',
				'responseField' => 'falsePositive',
				'referrer' => 'echo_notification',
				'changed' => true,
			],
		];
	}

	public function testRunThrowsBadTokenOnCsrfUnsafeSessionWithoutValidToken(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->never() )
			->method( 'markFalsePositive' );

		$this->expectExceptionObject( new LocalizedHttpException( new MessageValue( 'rest-badtoken' ), 403 ) );
		$this->executeHandler(
			new MarkReviewVerdictHandler(
				$service,
				$this->createNoOpMock( IAbuseReviewInstrumentationClient::class ),
				$this->createNoOpMock( AbuseReviewVerdictRequestLogger::class )
			),
			$this->newRequest( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			[],
			$this->pathParams( ReviewVerdictHandler::FALSE_POSITIVE ),
			[ 'token' => 'invalid' ],
			$authority,
			$this->getSession( false )
		);
	}

	public function testRunThrowsHttpExceptionWhenServiceReturnsFatal(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( 'markFalsePositive' )
			->willReturn(
				StatusValue::newFatal( 'wikimediaantiabuse-api-review-blocked' )->setResult( false, 403 )
			);

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'wikimediaantiabuse-api-review-blocked' ), 403 )
		);
		$this->executeHandler(
			new MarkReviewVerdictHandler(
				$service,
				$this->createNoOpMock( IAbuseReviewInstrumentationClient::class ),
				$this->createStub( AbuseReviewVerdictRequestLogger::class )
			),
			$this->newRequest( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			[],
			$this->pathParams( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			$authority
		);
	}

	/** @dataProvider provideHandledRequests */
	public function testRunLogsHandledRequest( StatusValue $status, array $headers, string $expectedUserAgent ): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( 'markFalsePositive' )
			->willReturn( $status );

		$verdictRequestLogger = $this->createMock( AbuseReviewVerdictRequestLogger::class );
		$verdictRequestLogger->expects( $this->once() )
			->method( 'logHandledRequest' )
			->with(
				$this->identicalTo( $authority ),
				123,
				self::TAG,
				ReviewVerdictHandler::FALSE_POSITIVE,
				'mark',
				$this->identicalTo( $status ),
				$expectedUserAgent
			);

		if ( !$status->isGood() ) {
			$this->expectExceptionObject(
				new LocalizedHttpException( new MessageValue( 'wikimediaantiabuse-api-review-blocked' ), 403 )
			);
		}
		$this->executeHandler(
			new MarkReviewVerdictHandler(
				$service,
				$this->createStub( IAbuseReviewInstrumentationClient::class ),
				$verdictRequestLogger
			),
			$this->newRequest( ReviewVerdictHandler::FALSE_POSITIVE, headers: $headers ),
			[],
			[],
			$this->pathParams( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			$authority
		);
	}

	public static function provideHandledRequests(): array {
		return [
			'successful mark with an API user agent' => [
				'status' => StatusValue::newGood(),
				'headers' => [ 'User-Agent' => 'Mozilla/5.0', 'Api-User-Agent' => 'AbuseReviewTool/1.0' ],
				'expectedUserAgent' => 'AbuseReviewTool/1.0',
			],
			'successful mark without an API user agent' => [
				'status' => StatusValue::newGood(),
				'headers' => [ 'User-Agent' => 'Mozilla/5.0' ],
				'expectedUserAgent' => 'Mozilla/5.0',
			],
			'failed mark' => [
				'status' => StatusValue::newFatal( 'wikimediaantiabuse-api-review-blocked' )->setResult( false, 403 ),
				'headers' => [ 'User-Agent' => 'Mozilla/5.0' ],
				'expectedUserAgent' => 'Mozilla/5.0',
			],
		];
	}
}

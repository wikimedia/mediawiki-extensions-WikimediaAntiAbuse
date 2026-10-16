<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Rest\Handler;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\UnmarkReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictRequestLogger;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\UnmarkReviewVerdictHandler
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler
 */
class UnmarkReviewVerdictHandlerTest extends MediaWikiUnitTestCase {

	use HandlerTestTrait;

	private const string TAG = 'mw-private-personal-info';

	private function newRequest( string $verdict ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'pathParams' => [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ],
			'headers' => [ 'Content-Type' => 'application/json', 'User-Agent' => 'Mozilla/5.0' ],
		] );
	}

	private function pathParams( string $verdict ): array {
		return [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ];
	}

	/** @dataProvider provideVerdicts */
	public function testRunUnmarksRevisionAndReturnsJson(
		string $verdict,
		string $serviceMethod,
		string $responseField,
		bool $changed
	): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( $serviceMethod )
			->with( $authority, 123, self::TAG )
			->willReturn( StatusValue::newGood( $changed ) );

		$instrumentationClient = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$instrumentationClient->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				RequestContext::getMain(),
				strtr( $verdict, [ '-' => '_' ] ),
				[
					'action_subtype' => 'unmark',
					'identifier' => 123,
					'identifier_type' => 'revision',
					'abuse_review_tag' => self::TAG,
				]
			);

		$data = $this->executeHandlerAndGetBodyData(
			new UnmarkReviewVerdictHandler(
				$service,
				$instrumentationClient,
				$this->createStub( AbuseReviewVerdictRequestLogger::class )
			),
			$this->newRequest( $verdict ),
			[],
			[],
			$this->pathParams( $verdict ),
			[],
			$authority
		);

		$this->assertSame(
			[ 'revision' => 123, 'tag' => self::TAG, $responseField => false, 'changed' => $changed ],
			$data
		);
	}

	public static function provideVerdicts(): array {
		return [
			'false positive' => [
				'verdict' => ReviewVerdictHandler::FALSE_POSITIVE,
				'serviceMethod' => 'unmarkFalsePositive',
				'responseField' => 'falsePositive',
				'changed' => true,
			],
			'no further action already removed' => [
				'verdict' => ReviewVerdictHandler::NO_FURTHER_ACTION,
				'serviceMethod' => 'unmarkNoFurtherAction',
				'responseField' => 'noFurtherAction',
				'changed' => false,
			],
		];
	}

	public function testRunThrowsBadTokenOnCsrfUnsafeSessionWithoutValidToken(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->never() )
			->method( 'unmarkFalsePositive' );

		$this->expectExceptionObject( new LocalizedHttpException( new MessageValue( 'rest-badtoken' ), 403 ) );
		$this->executeHandler(
			new UnmarkReviewVerdictHandler(
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
			->method( 'unmarkFalsePositive' )
			->willReturn(
				StatusValue::newFatal( 'wikimediaantiabuse-api-review-blocked' )->setResult( false, 403 )
			);

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'wikimediaantiabuse-api-review-blocked' ), 403 )
		);
		$this->executeHandler(
			new UnmarkReviewVerdictHandler(
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

	public function testRunLogsHandledRequest(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$status = StatusValue::newGood();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( 'unmarkFalsePositive' )
			->willReturn( $status );

		$verdictRequestLogger = $this->createMock( AbuseReviewVerdictRequestLogger::class );
		$verdictRequestLogger->expects( $this->once() )
			->method( 'logHandledRequest' )
			->with(
				$this->identicalTo( $authority ),
				123,
				self::TAG,
				ReviewVerdictHandler::FALSE_POSITIVE,
				'unmark',
				$this->identicalTo( $status ),
				'Mozilla/5.0'
			);

		$this->executeHandler(
			new UnmarkReviewVerdictHandler(
				$service,
				$this->createStub( IAbuseReviewInstrumentationClient::class ),
				$verdictRequestLogger
			),
			$this->newRequest( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			[],
			$this->pathParams( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			$authority
		);
	}
}

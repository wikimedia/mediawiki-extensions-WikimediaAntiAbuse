<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Rest\Handler;

use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
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

	private function newRequest( string $verdict ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'pathParams' => [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ],
			'headers' => [ 'Content-Type' => 'application/json' ],
		] );
	}

	private function pathParams( string $verdict ): array {
		return [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ];
	}

	/** @dataProvider provideVerdicts */
	public function testRunMarksRevisionAndReturnsJson(
		string $verdict,
		string $serviceMethod,
		string $responseField
	): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( $serviceMethod )
			->with( $authority, 123, self::TAG )
			->willReturn( StatusValue::newGood() );

		$data = $this->executeHandlerAndGetBodyData(
			new MarkReviewVerdictHandler( $service ),
			$this->newRequest( $verdict ),
			[],
			[],
			$this->pathParams( $verdict ),
			[],
			$authority
		);

		$this->assertSame(
			[ 'revision' => 123, 'tag' => self::TAG, $responseField => true ],
			$data
		);
	}

	public static function provideVerdicts(): array {
		return [
			'false positive' => [
				'verdict' => ReviewVerdictHandler::FALSE_POSITIVE,
				'serviceMethod' => 'markFalsePositive',
				'responseField' => 'falsePositive',
			],
			'no further action' => [
				'verdict' => ReviewVerdictHandler::NO_FURTHER_ACTION,
				'serviceMethod' => 'markNoFurtherAction',
				'responseField' => 'noFurtherAction',
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
			new MarkReviewVerdictHandler( $service ),
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
			new MarkReviewVerdictHandler( $service ),
			$this->newRequest( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			[],
			$this->pathParams( ReviewVerdictHandler::FALSE_POSITIVE ),
			[],
			$authority
		);
	}
}

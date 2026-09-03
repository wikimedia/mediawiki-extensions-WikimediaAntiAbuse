<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Rest\Handler;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
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

	private function newRequest( string $verdict, string $referrer = '' ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'pathParams' => [ 'revision' => 123, 'tag' => self::TAG, 'verdict' => $verdict ],
			'headers' => [ 'Content-Type' => 'application/json' ],
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
		string $referrer
	): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( AbuseReviewTagService::class );
		$service->expects( $this->once() )
			->method( $serviceMethod )
			->with( $authority, 123, self::TAG )
			->willReturn( StatusValue::newGood() );

		$expectedInstrumentationData = [
			'action_subtype' => 'mark',
			'identifier' => 123,
			'identifier_type' => 'revision',
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
			new MarkReviewVerdictHandler( $service, $instrumentationClient ),
			$this->newRequest( $verdict, $referrer ),
			[],
			[],
			[],
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
				'referrer' => '',
			],
			'no further action' => [
				'verdict' => ReviewVerdictHandler::NO_FURTHER_ACTION,
				'serviceMethod' => 'markNoFurtherAction',
				'responseField' => 'noFurtherAction',
				'referrer' => 'invalid_referrer',
			],
			'false positive with valid referrer' => [
				'verdict' => ReviewVerdictHandler::FALSE_POSITIVE,
				'serviceMethod' => 'markFalsePositive',
				'responseField' => 'falsePositive',
				'referrer' => 'echo_notification',
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
				$this->createNoOpMock( IAbuseReviewInstrumentationClient::class )
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
				$this->createNoOpMock( IAbuseReviewInstrumentationClient::class )
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

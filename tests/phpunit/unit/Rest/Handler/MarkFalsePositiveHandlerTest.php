<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Rest\Handler;

use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkFalsePositiveHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FalsePositiveTagService;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\MarkFalsePositiveHandler
 */
class MarkFalsePositiveHandlerTest extends MediaWikiUnitTestCase {

	use HandlerTestTrait;

	private const string TAG = 'mw-private-personal-info';

	private function newRequest(): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'pathParams' => [ 'revision' => 123, 'tag' => self::TAG ],
			'headers' => [ 'Content-Type' => 'application/json' ],
		] );
	}

	public function testRunMarksRevisionAndReturnsJson(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( FalsePositiveTagService::class );
		$service->expects( $this->once() )
			->method( 'markFalsePositive' )
			->with( $authority, 123, self::TAG )
			->willReturn( StatusValue::newGood() );

		$data = $this->executeHandlerAndGetBodyData(
			new MarkFalsePositiveHandler( $service ),
			$this->newRequest(),
			[],
			[],
			[ 'revision' => 123, 'tag' => self::TAG ],
			[],
			$authority
		);

		$this->assertSame( [ 'revision' => 123, 'tag' => self::TAG, 'falsePositive' => true ], $data );
	}

	public function testRunThrowsBadTokenOnCsrfUnsafeSessionWithoutValidToken(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( FalsePositiveTagService::class );
		$service->expects( $this->never() )
			->method( 'markFalsePositive' );

		$this->expectExceptionObject( new LocalizedHttpException( new MessageValue( 'rest-badtoken' ), 403 ) );
		$this->executeHandler(
			new MarkFalsePositiveHandler( $service ),
			$this->newRequest(),
			[],
			[],
			[ 'revision' => 123, 'tag' => self::TAG ],
			[ 'token' => 'invalid' ],
			$authority,
			$this->getSession( false )
		);
	}

	public function testRunThrowsHttpExceptionWhenServiceReturnsFatal(): void {
		$authority = $this->mockRegisteredUltimateAuthority();
		$service = $this->createMock( FalsePositiveTagService::class );
		$service->expects( $this->once() )
			->method( 'markFalsePositive' )
			->willReturn(
				StatusValue::newFatal( 'wikimediaantiabuse-api-falsepositive-blocked' )->setResult( false, 403 )
			);

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( 'wikimediaantiabuse-api-falsepositive-blocked' ), 403 )
		);
		$this->executeHandler(
			new MarkFalsePositiveHandler( $service ),
			$this->newRequest(),
			[],
			[],
			[ 'revision' => 123, 'tag' => self::TAG ],
			[],
			$authority
		);
	}
}

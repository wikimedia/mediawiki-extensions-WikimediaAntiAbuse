<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\RawMessage;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Stats\Metrics\TimingMetric;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator
 */
class ContentPolicyEvaluatorTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue(
			'WikimediaAntiAbuseCoPEModelConfig',
			[
				'url' => 'http://localhost:2345',
				'host' => 'host',
				'timeout' => 123,
			]
		);
	}

	private function getObjectUnderTest(): ContentPolicyEvaluator {
		$contentPolicyEvaluator = $this->getServiceContainer()->get( 'WikimediaAntiAbuseContentPolicyEvaluator' );
		TestingAccessWrapper::newFromObject( $contentPolicyEvaluator )->requestReattemptDelayMicroseconds = 0;
		return $contentPolicyEvaluator;
	}

	/** @dataProvider provideEvaluateCoPEModelForInvalidResponse */
	public function testEvaluateCoPEModelForInvalidResponse( string $responseContent ): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseDeveloperMode', false );

		// Define a mock MWHttpRequest that will be returned by a mock HttpRequestFactory,
		// that simulates an invalid response from the CoPE model
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$status = new StatusValue();
		$status->setOK( true );
		$mwHttpRequest->method( 'execute' )
			->willReturn( $status );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( $responseContent );

		$actualHeaders = [];
		$mwHttpRequest->method( 'setHeader' )
			->willReturnCallback( static function ( $name, $value ) use ( &$actualHeaders ) {
				$actualHeaders[$name] = $value;
			} );

		// Mock HttpRequestFactory directly so that we can check the URL and options are as expected.
		// Other tests do not check this as it should be fine to check this once.
		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->method( 'create' )
			->willReturnCallback( function ( $url, $options ) use ( $mwHttpRequest ) {
				$this->assertSame( 'http://localhost:2345', $url );
				$postArgs = '{"content":"test content","policy":"content policy"}';
				$this->assertArrayEquals(
					[
						'postData' => $postArgs,
						'method' => 'POST',
						'userAgent' => 'MediaWiki-WikimediaAntiAbuse/1.0 ' .
							'(https://www.mediawiki.org/wiki/Product_Safety_and_Integrity)',
						'sslVerifyCert' => true,
						'sslVerifyHost' => true,
						'timeout' => 123,
					],
					$options,
					false,
					true
				);
				return $mwHttpRequest;
			} );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		// Create a mock LoggerInterface that expects a error to be logged about the invalid response
		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->willReturnCallback( function ( $msg, $context ) use ( $mwHttpRequest ) {
				$this->assertSame(
					'Got unexpected data from CoPE model while checking content policy {contentPolicyName}',
					$msg
				);
				$this->assertArrayEquals(
					[ 'contentPolicyName' => 'test-content-policy', 'response' => $mwHttpRequest->getContent() ],
					$context,
					false,
					true
				);
			} );
		$this->setLogger( 'WikimediaAntiAbuse', $mockLogger );

		$this->assertNull(
			$this->getObjectUnderTest()->evaluateCoPEModel(
				'content policy',
				'test-content-policy',
				'test content'
			),
			'Should return null when CoPE model returns malformed data'
		);

		$this->assertArrayEquals(
			[ 'Host' => 'host', 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
			$actualHeaders,
			false,
			true
		);

		// Timing is not observed when evaluator returns null (service unavailable/malformed data)
		$this->assertTimingNotObserved();
	}

	public static function provideEvaluateCoPEModelForInvalidResponse(): array {
		return [
			'Non-array response' => [ 'responseContent' => 'foo' ],
			'Empty array response' => [ 'responseContent' => FormatJson::encode( [] ) ],
			'Response missing violation key' => [ 'responseContent' => FormatJson::encode( [ 'foo' => 'bar' ] ) ],
		];
	}

	public function testEvaluateCoPEModelWhenRequestReturnsWithFatalStatus(): void {
		$this->overrideConfigValue(
			'WikimediaAntiAbuseCoPEModelConfig',
			[
				'url' => 'http://localhost:2345',
				'host' => 'host',
				'timeout' => '',
			]
		);
		$this->overrideConfigValue( 'WikimediaAntiAbuseDeveloperMode', true );

		// Mock the CoPE model returns a fatal response
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( StatusValue::newFatal( new RawMessage( 'Message for error' ) ) );
		$this->installMockHttp( $mwHttpRequest );

		// Mock HttpRequestFactory directly so that we can check the timeout isn't specified when empty
		// and verifies ssl certificates when not in developer mode
		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->method( 'create' )
			->willReturnCallback( function ( $url, $options ) use ( $mwHttpRequest ) {
				$this->assertSame( 'http://localhost:2345', $url );
				$postArgs = '{"content":"test content","policy":"content policy"}';
				$this->assertArrayEquals(
					[
						'postData' => $postArgs,
						'method' => 'POST',
						'userAgent' => 'MediaWiki-WikimediaAntiAbuse/1.0 ' .
							'(https://www.mediawiki.org/wiki/Product_Safety_and_Integrity)',
						'sslVerifyCert' => false,
						'sslVerifyHost' => false,
					],
					$options,
					false,
					true
				);
				return $mwHttpRequest;
			} );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'error' )
			->with( $this->stringContains( 'Message for error' ) );
		$this->setLogger( 'WikimediaAntiAbuse', $mockLogger );

		$this->assertNull(
			$this->getObjectUnderTest()->evaluateCoPEModel(
				'content policy',
				'test-content-policy',
				'test content'
			),
			'Should return null when CoPE model request returns 500 error'
		);
		// Timing is not observed when evaluator returns null (service unavailable)
		$this->assertTimingNotObserved();
	}

	/** @dataProvider provideEvaluateCoPEModelWhenRequestSucceeds */
	public function testEvaluateCoPEModelWhenRequestSucceeds(
		int $violationResponse,
		bool $expectedIsViolation
	): void {
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( StatusValue::newGood() );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( json_encode( [
				'violation' => $violationResponse,
				'p_violation' => 0.5,
				'p_safe' => 0.5,
			] ) );
		$this->installMockHttp( $mwHttpRequest );

		$this->setLogger( 'WikimediaAntiAbuse', $this->createNoOpMock( LoggerInterface::class ) );

		$modelResponse = $this->getObjectUnderTest()->evaluateCoPEModel(
			'content policy',
			'test-content-policy',
			'test content'
		);
		$this->assertSame(
			$expectedIsViolation,
			$modelResponse->isViolation(),
			'Should return the expected violation value when CoPE model request succeeds'
		);
		$this->assertSame(
			0.5,
			$modelResponse->getSafeProbability(),
			'Should return the expected safe probability when CoPE model request succeeds'
		);
		$this->assertSame(
			0.5,
			$modelResponse->getViolationProbability(),
			'Should return the expected violation probability when CoPE model request succeeds'
		);
		$this->assertTimingObserved();
	}

	public static function provideEvaluateCoPEModelWhenRequestSucceeds(): array {
		return [
			'Violation response is 0 (no violation)' => [ 'violationResponse' => 0, 'expectedIsViolation' => false ],
			'Violation response is 1 (violation)' => [ 'violationResponse' => 1, 'expectedIsViolation' => true ],
		];
	}

	public function testEvaluateCoPEModelEventuallySucceeds(): void {
		$failStatus = StatusValue::newFatal( new RawMessage( 'Connection error' ) );
		$successStatus = StatusValue::newGood();

		$failRequest = $this->createMock( MWHttpRequest::class );
		$failRequest->method( 'execute' )->willReturn( $failStatus );

		$successRequest = $this->createMock( MWHttpRequest::class );
		$successRequest->method( 'execute' )->willReturn( $successStatus );
		$successRequest->method( 'getContent' )->willReturn( json_encode( [ 'violation' => 1 ] ) );

		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->expects( $this->exactly( 2 ) )
			->method( 'create' )
			->willReturnOnConsecutiveCalls( $failRequest, $successRequest );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		$mockLogger = $this->createMock( LoggerInterface::class );
		$mockLogger->expects( $this->once() )
			->method( 'warning' )
			->with( $this->stringContains( 'retrying' ) );
		$mockLogger->expects( $this->never() )->method( 'error' );
		$this->setLogger( 'WikimediaAntiAbuse', $mockLogger );

		$modelResponse = $this->getObjectUnderTest()->evaluateCoPEModel(
			'content policy',
			'test-content-policy',
			'test content'
		);
		$this->assertTrue(
			$modelResponse->isViolation(),
			'Should return match when CoPE model request succeeds even after first failure'
		);
		$this->assertTimingObserved();
	}

	public function testEvaluateCoPEModelRetryExhaustedForHttpTimeout(): void {
		$failStatus = StatusValue::newFatal( 'http-timed-out' );
		$failRequest = $this->createMock( MWHttpRequest::class );
		$failRequest->method( 'execute' )->willReturn( $failStatus );

		$mockHttpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$mockHttpRequestFactory->expects( $this->exactly( 2 ) )
			->method( 'create' )
			->willReturn( $failRequest );
		$this->setService( 'HttpRequestFactory', $mockHttpRequestFactory );

		$mockLogger = $this->createNoOpMock( LoggerInterface::class, [ 'warning' ] );

		$expectedWarnings = [
			'CoPE model request failed {contentPolicyName}, retrying (attempt {attempt}/{maxAttempts})',
			'Timeout connecting to CoPE model for content policy {contentPolicyName}'
		];
		$mockLogger->expects( $this->exactly( 2 ) )
			->method( 'warning' )
			->with( $this->callback( static function ( string $message ) use ( &$expectedWarnings ): bool {
				$expected = array_shift( $expectedWarnings );
				return $expected !== null && $message === $expected;
			} ) );

		$this->setLogger( 'WikimediaAntiAbuse', $mockLogger );

		$this->assertNull(
			$this->getObjectUnderTest()->evaluateCoPEModel(
				'content policy',
				'test-content-policy',
				'test content'
			),
			'Should return null when CoPE model request fails after all retries'
		);
		$this->assertTimingNotObserved();
	}

	private function assertTimingObserved(): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaAntiAbuse' )
			->getTiming( 'cope_model_evaluation_seconds' );

		$this->assertInstanceOf( TimingMetric::class, $metric );
		$this->assertSame( 1, $metric->getSampleCount() );
	}

	private function assertTimingNotObserved(): void {
		$metric = $this->getServiceContainer()
			->getStatsFactory()
			->withComponent( 'WikimediaAntiAbuse' )
			->getTiming( 'cope_model_evaluation_seconds' );

		$this->assertInstanceOf( TimingMetric::class, $metric );
		$this->assertSame( 0, $metric->getSampleCount() );
	}
}

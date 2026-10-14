<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictRequestLogger;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictRequestLogger
 */
class AbuseReviewVerdictRequestLoggerTest extends MediaWikiUnitTestCase {

	use MockAuthorityTrait;

	private LoggerInterface&MockObject $logger;
	private AbuseReviewVerdictRequestLogger $verdictRequestLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock( LoggerInterface::class );
		$this->verdictRequestLogger = new AbuseReviewVerdictRequestLogger( $this->logger );
	}

	/** @dataProvider provideHandledRequests */
	public function testLogHandledRequestLogsContext(
		StatusValue $status,
		string $userAgent,
		array $expectedOutcome
	): void {
		$performer = $this->mockRegisteredUltimateAuthority();
		$this->logger->expects( $this->once() )
			->method( 'info' )
			->with(
				'Handled an AbuseReview verdict request',
				$this->identicalTo( [
					'revisionId' => 123,
					'tag' => 'mw-private-personal-info',
					'verdict' => 'false-positive',
					'action' => 'mark',
					'performer' => $performer->getUser()->getName(),
				] + $expectedOutcome )
			);

		$this->verdictRequestLogger->logHandledRequest(
			$performer,
			123,
			'mw-private-personal-info',
			'false-positive',
			'mark',
			$status,
			$userAgent
		);
	}

	public static function provideHandledRequests(): array {
		return [
			'good status with a user agent' => [
				'status' => StatusValue::newGood(),
				'userAgent' => 'AbuseReviewTool/1.0',
				'expectedOutcome' => [ 'success' => true, 'userAgent' => 'AbuseReviewTool/1.0' ],
			],
			'not-good status without a user agent' => [
				'status' => StatusValue::newFatal( 'first-error-key' )->fatal( 'second-error-key' ),
				'userAgent' => '',
				'expectedOutcome' => [ 'success' => false, 'error' => 'first-error-key' ],
			],
		];
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttribution;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttribution
 */
class AbuseReviewVerdictAttributionTest extends MediaWikiUnitTestCase {

	private AbuseReviewVerdictAttribution $attribution;

	protected function setUp(): void {
		parent::setUp();

		$this->attribution = new AbuseReviewVerdictAttribution( new NullLogger() );
	}

	/** @dataProvider provideParams */
	public function testDecodeActorId( ?string $params, ?int $expected ): void {
		$this->assertSame( $expected, $this->attribution->decodeActorId( $params ) );
	}

	public static function provideParams(): array {
		return [
			'a verdict recorded before attribution existed' => [ 'params' => null, 'expected' => null ],
			'an actor ID another writer wrote as a string' => [ 'params' => '{"actor":"12"}', 'expected' => 12 ],
			'an actor field that is not a number' => [ 'params' => '{"actor":"someone"}', 'expected' => null ],
			'an actor ID' => [ 'params' => '{"actor":12}', 'expected' => 12 ],
		];
	}

	public function testEncode(): void {
		$this->assertSame(
			'{"actor":12,"recordedAt":"20260901133152"}',
			$this->attribution->encode( 12, '20260901133152' ),
			'The stored format is the one every recorded verdict already carries'
		);
	}

	public function testDecodeActorIdLogsMalformedParams(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'warning' );

		$this->assertNull(
			( new AbuseReviewVerdictAttribution( $logger ) )->decodeActorId( 'actor=12' ),
			'A verdict whose ct_params cannot be parsed names nobody'
		);
	}
}

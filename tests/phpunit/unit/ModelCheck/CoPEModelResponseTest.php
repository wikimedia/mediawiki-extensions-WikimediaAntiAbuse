<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\ModelCheck;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse
 */
class CoPEModelResponseTest extends MediaWikiUnitTestCase {

	public function testGetData(): void {
		$data = [ 'test-key' => 'test-value' ];

		$this->assertSame( $data, ( new CoPEModelResponse( $data ) )->getData() );
	}

	public function testTypedAccessorsForCopeViolationResponse(): void {
		$response = new CoPEModelResponse(
			[ 'violation' => 1, 'p_violation' => 1.0, 'p_safe' => 1.522997974471263e-8 ]
		);

		$this->assertTrue( $response->isViolation() );
		$this->assertSame( 1.0, $response->getViolationProbability() );
		$this->assertSame( 1.522997974471263e-8, $response->getSafeProbability() );
	}

	public function testTypedAccessorsForCopeNonViolationResponse(): void {
		$response = new CoPEModelResponse( [ 'violation' => 0, 'p_violation' => 0.03, 'p_safe' => 0.97 ] );

		$this->assertFalse( $response->isViolation() );
		$this->assertSame( 0.03, $response->getViolationProbability() );
		$this->assertSame( 0.97, $response->getSafeProbability() );
	}

	public function testTypedAccessorsForUnknownResponseShape(): void {
		$response = new CoPEModelResponse(
			[ 'verdict' => 'PASS', 'violation' => [ 'nested' ], 'p_violation' => 'high' ]
		);

		$this->assertFalse( $response->isViolation() );
		$this->assertNull( $response->getViolationProbability() );
		$this->assertNull( $response->getSafeProbability() );
	}
}

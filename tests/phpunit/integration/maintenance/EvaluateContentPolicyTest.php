<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Maintenance;

use MediaWiki\Extension\WikimediaAntiAbuse\Maintenance\EvaluateContentPolicy;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Maintenance\EvaluateContentPolicy
 */
class EvaluateContentPolicyTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return EvaluateContentPolicy::class;
	}

	public function testExecuteWhenContentPolicyFileIsEmpty(): void {
		$this->maintenance->setOption( 'content-policy', $this->getNewTempFile() );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Unable to read the content policy text file/' );
		$this->maintenance->execute();
	}

	public function testExecuteWhenEvaluationFails(): void {
		$mockContentPolicyEvaluator = $this->createMock( ContentPolicyEvaluator::class );
		$mockContentPolicyEvaluator->method( 'evaluateCoPEModel' )
			->with( 'Test content policy', 'maintenance-script-custom-content-policy', 'Test content' )
			->willReturn( null );
		$this->setService( 'WikimediaAntiAbuseContentPolicyEvaluator', $mockContentPolicyEvaluator );

		$contentPolicyFile = $this->getNewTempFile();
		file_put_contents( $contentPolicyFile, 'Test content policy' );
		$this->maintenance->setOption( 'content-policy', $contentPolicyFile );
		$this->maintenance->setOption( 'content', 'Test content' );
		$this->expectCallToFatalError();
		$this->expectOutputRegex( '/Call to CoPE model failed/' );
		$this->maintenance->execute();
	}

	/** @dataProvider provideEvaluateCoPEModelForValidResponse */
	public function testExecuteWhenEvaluationSucceeds(
		int $isViolation,
		float|null $violationProbability,
		float|null $safeProbability,
		string $expectedOutputRegex
	): void {
		$mockContentPolicyEvaluator = $this->createMock( ContentPolicyEvaluator::class );
		$mockContentPolicyEvaluator->method( 'evaluateCoPEModel' )
			->with( 'Test content policy', 'maintenance-script-custom-content-policy', 'Test content' )
			->willReturn( new CoPEModelResponse( [
				'violation' => $isViolation,
				'p_violation' => $violationProbability,
				'p_safe' => $safeProbability,
			] ) );
		$this->setService( 'WikimediaAntiAbuseContentPolicyEvaluator', $mockContentPolicyEvaluator );

		$contentPolicyFile = $this->getNewTempFile();
		file_put_contents( $contentPolicyFile, 'Test content policy' );
		$this->maintenance->setOption( 'content-policy', $contentPolicyFile );
		$this->maintenance->setOption( 'content', 'Test content' );
		$this->expectOutputRegex( $expectedOutputRegex );
		$this->maintenance->execute();
	}

	public static function provideEvaluateCoPEModelForValidResponse(): array {
		return [
			'Content matches policy' => [
				'isViolation' => 1,
				'violationProbability' => 0.9,
				'safeProbability' => 0.00001,
				'expectedOutputRegex' => '/Content matches the policy. Violation probability: 0.9. ' .
					'Safe probability: 1.0E-5./',
			],
			'Content does not match policy' => [
				'isViolation' => 0,
				'violationProbability' => 0.00001,
				'safeProbability' => 0.9,
				'expectedOutputRegex' => '/Content does not match the policy/',
			],
			'Content matches policy but no violation probability listed' => [
				'isViolation' => 1,
				'violationProbability' => null,
				'safeProbability' => 0.9,
				'expectedOutputRegex' => '/Content matches the policy/',
			],
			'Content matches policy but no safe probability listed' => [
				'isViolation' => 1,
				'violationProbability' => 0.9,
				'safeProbability' => null,
				'expectedOutputRegex' => '/Content matches the policy/',
			],
			'Content does not match policy with no probabilities listed' => [
				'isViolation' => 0,
				'violationProbability' => null,
				'safeProbability' => null,
				'expectedOutputRegex' => '/Content does not match the policy/',
			],
		];
	}
}

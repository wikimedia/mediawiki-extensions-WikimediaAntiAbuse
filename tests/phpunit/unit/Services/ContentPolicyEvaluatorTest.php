<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Tests\Unit\MockServiceDependenciesTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator
 */
class ContentPolicyEvaluatorTest extends MediaWikiUnitTestCase {
	use MockServiceDependenciesTrait;

	/** @dataProvider provideCoPEModelWhenConfigNotSet */
	public function testCoPEModelWhenConfigNotSet( array $copeModelConfig ): void {
		/** @var ContentPolicyEvaluator $contentPolicyEvaluator */
		$contentPolicyEvaluator = $this->newServiceInstance(
			ContentPolicyEvaluator::class,
			[
				'options' => new ServiceOptions(
					ContentPolicyEvaluator::CONSTRUCTOR_OPTIONS,
					[
						'WikimediaAntiAbuseCoPEModelConfig' => $copeModelConfig,
						'WikimediaAntiAbuseDeveloperMode' => false,
					]
				),
			]
		);

		$this->expectException( ConfigException::class );
		$contentPolicyEvaluator->evaluateCoPEModel( 'content policy', 'test-policy', 'content' );
	}

	public static function provideCoPEModelWhenConfigNotSet(): array {
		return [
			'Config is an empty array' => [
				'copeModelConfig' => [],
			],
			'Config has empty URL and missing host' => [
				'copeModelConfig' => [ 'url' => '' ],
			],
			'Config has empty URL and host' => [
				'copeModelConfig' => [ 'url' => '', 'host' => '' ],
			],
			'Config has URL but empty host' => [
				'copeModelConfig' => [ 'url' => 'http://example.com', 'host' => '' ],
			],
		];
	}
}

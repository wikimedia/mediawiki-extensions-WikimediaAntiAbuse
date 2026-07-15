<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit;

use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use ReflectionParameter;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	public static function provideHookRunners(): array {
		return [
			HookRunner::class => [ HookRunner::class ],
		];
	}

	protected function getMockedParamValue( ReflectionParameter $param ) {
		if ( (string)$param->getType() === ModelToRun::class ) {
			// Readonly DTO, construct it instead of mocking.
			return new ModelToRun( 'model', 'policy' );
		}

		return parent::getMockedParamValue( $param );
	}
}

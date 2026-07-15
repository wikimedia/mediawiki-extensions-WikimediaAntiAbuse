<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\ModelCheck;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun
 */
class ModelToRunTest extends MediaWikiUnitTestCase {

	public function testGetters(): void {
		$modelToRun = new ModelToRun( 'test-model', 'Test policy text' );

		$this->assertSame( 'test-model', $modelToRun->getModelName() );
		$this->assertSame( 'Test policy text', $modelToRun->getPolicyText() );
	}
}

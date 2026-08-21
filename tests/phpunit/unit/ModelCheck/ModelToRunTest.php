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
		$modelToRun = new ModelToRun( 'test-content-policy-name', 'Test policy text', 'test content' );

		$this->assertSame( 'test-content-policy-name', $modelToRun->getContentPolicyName() );
		$this->assertSame( 'Test policy text', $modelToRun->getPolicyText() );
		$this->assertSame( 'test content', $modelToRun->getContent() );
	}

	public function testGetModelNameStillReturnsTheContentPolicyName(): void {
		$modelToRun = new ModelToRun( 'test-content-policy-name', 'Test policy text', 'test content' );

		$this->assertSame( 'test-content-policy-name', $modelToRun->getModelName() );
	}
}

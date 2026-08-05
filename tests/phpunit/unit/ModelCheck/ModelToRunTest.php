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
		$modelToRun = new ModelToRun(
			'test-content-policy-name', 'Test policy text', 'test content', 'test-model-name', '1.2'
		);

		$this->assertSame( 'test-model-name', $modelToRun->getModelName() );
		$this->assertSame( 'Test policy text', $modelToRun->getPolicyText() );
		$this->assertSame( 'test content', $modelToRun->getContent() );
		$this->assertSame( 'test-content-policy-name', $modelToRun->getContentPolicyName() );
		$this->assertSame( '1.2', $modelToRun->getPolicyVersion() );
	}

	public function testPolicyVersionDefaultsToNull(): void {
		$modelToRun = new ModelToRun(
			'test-content-policy-name', 'Test policy text', 'test content', 'test-model-name'
		);

		$this->assertNull( $modelToRun->getPolicyVersion() );
	}
}

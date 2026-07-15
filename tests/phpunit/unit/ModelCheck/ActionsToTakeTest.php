<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\ModelCheck;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake
 */
class ActionsToTakeTest extends MediaWikiUnitTestCase {

	private ActionsToTake $actionsToTake;

	protected function setUp(): void {
		parent::setUp();

		$this->actionsToTake = new ActionsToTake();
	}

	/** @dataProvider provideAddTags */
	public function testAddTagsAccumulatesAndDeduplicates( array $tagCalls, array $expected ): void {
		foreach ( $tagCalls as $tags ) {
			$this->actionsToTake->addTags( $tags );
		}

		$this->assertSame( $expected, $this->actionsToTake->getTagsToAdd() );
	}

	public static function provideAddTags(): array {
		return [
			'adds nothing' => [
				'tagCalls' => [],
				'expected' => [],
			],
			'single call adds one tag' => [
				'tagCalls' => [ [ 'test-tag-one' ] ],
				'expected' => [ 'test-tag-one' ],
			],
			'accumulates across multiple calls' => [
				'tagCalls' => [ [ 'test-tag-one' ], [ 'test-tag-two' ] ],
				'expected' => [ 'test-tag-one', 'test-tag-two' ],
			],
			'de-duplicates within a single call' => [
				'tagCalls' => [ [ 'test-tag-one', 'test-tag-one' ] ],
				'expected' => [ 'test-tag-one' ],
			],
			'de-duplicates across calls' => [
				'tagCalls' => [ [ 'test-tag-one' ], [ 'test-tag-one', 'test-tag-two' ] ],
				'expected' => [ 'test-tag-one', 'test-tag-two' ],
			],
		];
	}
}

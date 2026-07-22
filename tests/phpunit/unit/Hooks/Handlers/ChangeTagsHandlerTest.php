<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler
 */
class ChangeTagsHandlerTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideChangeTagRegistration */
	public function testChangeTagRegistration(
		bool $tagEnabled,
		array $expectedDefinedTags,
		array $expectedRestrictedTags
	): void {
		$changeTagsHandler = new ChangeTagsHandler(
			new HashConfig( [ 'WikimediaAntiAbuseEnablePersonalInfoTag' => $tagEnabled ] )
		);

		$definedTags = [];
		$changeTagsHandler->onListDefinedTags( $definedTags );
		$this->assertSame( $expectedDefinedTags, $definedTags );

		$activeTags = [];
		$changeTagsHandler->onChangeTagsListActive( $activeTags );
		$this->assertSame( $expectedDefinedTags, $activeTags );

		$restrictedTags = [];
		$changeTagsHandler->onListRestrictedTags( $restrictedTags );
		$this->assertArrayEquals(
			$expectedRestrictedTags,
			$restrictedTags,
			false,
			true
		);
	}

	public static function provideChangeTagRegistration(): array {
		return [
			'Tag not enabled' => [
				'tagEnabled' => false,
				'expectedDefinedTags' => [],
				'expectedRestrictedTags' => [],
			],
			'Tag enabled' => [
				'tagEnabled' => true,
				'expectedDefinedTags' => [ 'mw-private-personal-info', 'mw-private-personal-info-false-positive' ],
				'expectedRestrictedTags' => [
					'mw-private-personal-info' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-personal-info-false-positive' => [ 'viewsuppressed', 'suppressrevision' ],
				],
			],
		];
	}
}

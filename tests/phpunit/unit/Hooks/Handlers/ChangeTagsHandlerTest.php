<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use LogicException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler
 */
class ChangeTagsHandlerTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideChangeTagRegistration */
	public function testChangeTagRegistration(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		array $expectedDefinedTags,
		array $expectedRestrictedTags
	): void {
		$changeTagsHandler = new ChangeTagsHandler(
			new HashConfig( [
				'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
				'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
			] )
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
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'expectedDefinedTags' => [],
				'expectedRestrictedTags' => [],
			],
			'Personal info tag enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'expectedDefinedTags' => [
					'mw-private-personal-info',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
				],
				'expectedRestrictedTags' => [
					'mw-private-personal-info' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-personal-info-false-positive' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-personal-info-no-further-action' => [ 'viewsuppressed', 'suppressrevision' ],
				],
			],
			'Personal info and vandalism tags enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'expectedDefinedTags' => [
					'mw-private-personal-info',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
					'mw-private-vandalism',
					'mw-private-vandalism-false-positive',
					'mw-private-vandalism-no-further-action',
				],
				'expectedRestrictedTags' => [
					'mw-private-personal-info' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-personal-info-false-positive' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-personal-info-no-further-action' => [ 'viewsuppressed', 'suppressrevision' ],
					'mw-private-vandalism' => [ 'rollback' ],
					'mw-private-vandalism-false-positive' => [ 'rollback' ],
					'mw-private-vandalism-no-further-action' => [ 'rollback' ],
				],
			],
		];
	}

	public function testIsTagEnabledWhenUnknownTagProvided(): void {
		$changeTagsHandler = new ChangeTagsHandler( new HashConfig() );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Unknown tag: mw-private-unknown' );
		TestingAccessWrapper::newFromObject( $changeTagsHandler )->isTagEnabled( 'mw-private-unknown' );
	}
}

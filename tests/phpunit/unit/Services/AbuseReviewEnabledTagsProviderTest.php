<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider
 */
class AbuseReviewEnabledTagsProviderTest extends MediaWikiUnitTestCase {

	private function getObjectUnderTest(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled
	): AbuseReviewEnabledTagsProvider {
		return new AbuseReviewEnabledTagsProvider(
			new ServiceOptions(
				AbuseReviewEnabledTagsProvider::CONSTRUCTOR_OPTIONS,
				[
					'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
					'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
				]
			)
		);
	}

	/** @dataProvider provideEnabledReviewableTags */
	public function testGetEnabledReviewableTags(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		array $expectedTags
	): void {
		$provider = $this->getObjectUnderTest( $personalInfoTagEnabled, $vandalismTagEnabled );
		$this->assertSame( $expectedTags, $provider->getEnabledReviewableTags() );
	}

	public static function provideEnabledReviewableTags(): array {
		return [
			'No tags enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'expectedTags' => [],
			],
			'Personal info tag enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'expectedTags' => [ 'mw-private-personal-info' ],
			],
			'Vandalism tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'expectedTags' => [ 'mw-private-vandalism' ],
			],
			'Both tags enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'expectedTags' => [ 'mw-private-personal-info', 'mw-private-vandalism' ],
			],
		];
	}

	/** @dataProvider provideGetAllEnabledTags */
	public function testGetAllEnabledTags(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		array $expectedTags
	): void {
		$provider = $this->getObjectUnderTest( $personalInfoTagEnabled, $vandalismTagEnabled );
		$this->assertSame( $expectedTags, $provider->getAllEnabledTags() );
	}

	public static function provideGetAllEnabledTags(): array {
		return [
			'No tags enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'expectedTags' => [],
			],
			'Personal info tag enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'expectedTags' => [
					'mw-private-personal-info',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
				],
			],
			'Vandalism tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'expectedTags' => [
					'mw-private-vandalism',
					'mw-private-vandalism-false-positive',
					'mw-private-vandalism-no-further-action',
				],
			],
			'Both tags enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'expectedTags' => [
					'mw-private-personal-info',
					'mw-private-vandalism',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
					'mw-private-vandalism-false-positive',
					'mw-private-vandalism-no-further-action',
				],
			],
		];
	}
}

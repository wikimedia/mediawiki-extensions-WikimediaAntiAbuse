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
		bool $vandalismTagEnabled,
		bool $linkSpamTagEnabled
	): AbuseReviewEnabledTagsProvider {
		return new AbuseReviewEnabledTagsProvider(
			new ServiceOptions(
				AbuseReviewEnabledTagsProvider::CONSTRUCTOR_OPTIONS,
				[
					'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
					'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
					'WikimediaAntiAbuseEnableLinkSpamTag' => $linkSpamTagEnabled,
				]
			)
		);
	}

	/** @dataProvider provideEnabledReviewableTags */
	public function testGetEnabledReviewableTags(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		bool $linkSpamTagEnabled,
		array $expectedTags
	): void {
		$provider = $this->getObjectUnderTest(
			$personalInfoTagEnabled,
			$vandalismTagEnabled,
			$linkSpamTagEnabled
		);
		$this->assertSame( $expectedTags, $provider->getEnabledReviewableTags() );
	}

	public static function provideEnabledReviewableTags(): array {
		return [
			'No tags enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [],
			],
			'Personal info tag enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [ 'mw-private-personal-info' ],
			],
			'Vandalism tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [ 'mw-private-vandalism' ],
			],
			'Link spam tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => true,
				'expectedTags' => [ 'mw-private-link-spam' ],
			],
			'All tags enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'linkSpamTagEnabled' => true,
				'expectedTags' => [
					'mw-private-personal-info',
					'mw-private-vandalism',
					'mw-private-link-spam',
				],
			],
		];
	}

	/** @dataProvider provideGetAllEnabledTags */
	public function testGetAllEnabledTags(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		bool $linkSpamTagEnabled,
		array $expectedTags
	): void {
		$provider = $this->getObjectUnderTest(
			$personalInfoTagEnabled,
			$vandalismTagEnabled,
			$linkSpamTagEnabled
		);
		$this->assertSame( $expectedTags, $provider->getAllEnabledTags() );
	}

	public static function provideGetAllEnabledTags(): array {
		return [
			'No tags enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [],
			],
			'Personal info tag enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [
					'mw-private-personal-info',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
				],
			],
			'Vandalism tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'linkSpamTagEnabled' => false,
				'expectedTags' => [
					'mw-private-vandalism',
					'mw-private-vandalism-false-positive',
					'mw-private-vandalism-no-further-action',
				],
			],
			'Link spam tag enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'linkSpamTagEnabled' => true,
				'expectedTags' => [
					'mw-private-link-spam',
					'mw-private-link-spam-false-positive',
					'mw-private-link-spam-no-further-action',
				],
			],
			'All tags enabled' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'linkSpamTagEnabled' => true,
				'expectedTags' => [
					'mw-private-personal-info',
					'mw-private-vandalism',
					'mw-private-link-spam',
					'mw-private-personal-info-false-positive',
					'mw-private-personal-info-no-further-action',
					'mw-private-vandalism-false-positive',
					'mw-private-vandalism-no-further-action',
					'mw-private-link-spam-false-positive',
					'mw-private-link-spam-no-further-action',
				],
			],
		];
	}
}

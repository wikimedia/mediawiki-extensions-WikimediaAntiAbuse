<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewPermissionManager;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewPermissionManager
 */
class AbuseReviewPermissionManagerTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	/**
	 * @dataProvider provideCanViewQueue
	 */
	public function testCanViewQueue(
		bool $personalInfoTagEnabled,
		bool $vandalismTagEnabled,
		array $rights,
		bool $expected
	): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
			'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
		] );

		$permissionManager = new AbuseReviewPermissionManager(
			$this->getServiceContainer()->getChangeTagsStore()
		);

		$this->assertSame(
			$expected,
			$permissionManager->canViewQueue( $this->mockRegisteredAuthorityWithPermissions( $rights ) )
		);
	}

	public static function provideCanViewQueue(): array {
		return [
			'holds a right over the enabled personal information tag' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'viewsuppressed' ],
				'expected' => true,
			],
			'holds the other right over the enabled personal information tag' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'suppressrevision' ],
				'expected' => true,
			],
			'holds a right over the enabled vandalism tag' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => true,
				'rights' => [ 'rollback' ],
				'expected' => true,
			],
			// A tag declares its view rights only while it is enabled, so the right over a
			// disabled tag names nothing the queue would show.
			'holds a right over the disabled tag only' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => false,
				'rights' => [ 'rollback' ],
				'expected' => false,
			],
			'holds every right while no tag is enabled' => [
				'personalInfoTagEnabled' => false,
				'vandalismTagEnabled' => false,
				'rights' => [ 'viewsuppressed', 'suppressrevision', 'rollback' ],
				'expected' => false,
			],
			'holds none of the rights' => [
				'personalInfoTagEnabled' => true,
				'vandalismTagEnabled' => true,
				'rights' => [],
				'expected' => false,
			],
		];
	}
}

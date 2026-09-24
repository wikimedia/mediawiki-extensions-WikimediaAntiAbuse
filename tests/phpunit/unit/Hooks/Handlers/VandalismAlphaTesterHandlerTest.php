<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\VandalismAlphaTesterHandler;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\VandalismAlphaTesterHandler
 */
class VandalismAlphaTesterHandlerTest extends MediaWikiUnitTestCase {

	/** @dataProvider provideOnGetUserRights */
	public function testOnGetUserRights(
		array $rights,
		string $userName,
		bool $vandalismTagEnabled,
		bool $personalInfoTagEnabled,
		array $alphaTesters,
		array $expectedRights
	): void {
		$config = new HashConfig( [
			'WikimediaAntiAbuseEnableVandalismTag' => $vandalismTagEnabled,
			'WikimediaAntiAbuseEnablePersonalInfoTag' => $personalInfoTagEnabled,
			'WikimediaAntiAbuseVandalismTagAlphaTesters' => $alphaTesters,
		] );

		$user = $this->createMock( User::class );
		$user->method( 'getName' )
			->willReturn( $userName );

		$handler = new VandalismAlphaTesterHandler( $config );
		$handler->onUserGetRights( $user, $rights );

		$this->assertEquals( $expectedRights, $rights );
	}

	public static function provideOnGetUserRights(): array {
		return [
			'User has no rights and not in alpha tester config' => [
				'rights' => [],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [],
				'expectedRights' => [],
			],
			'User has no rights but is in alpha tester config' => [
				'rights' => [],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [ 'TestUser' ],
				'expectedRights' => [ 'abusereview-vandalism-alpha-tester' ],
			],
			'User has viewsuppressed right' => [
				'rights' => [ 'viewsuppressed' ],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [],
				'expectedRights' => [ 'viewsuppressed', 'abusereview-vandalism-alpha-tester' ],
			],
			'User has suppressrevision right' => [
				'rights' => [ 'suppressrevision' ],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [],
				'expectedRights' => [ 'suppressrevision', 'abusereview-vandalism-alpha-tester' ],
			],
			'Vandalism tag disabled' => [
				'rights' => [],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => false,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [ 'TestUser' ],
				'expectedRights' => [],
			],
			'Personal info tag is disabled when user not in alpha testers' => [
				'rights' => [ 'viewsuppressed' ],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => false,
				'alphaTesters' => [],
				'expectedRights' => [ 'viewsuppressed' ],
			],
			'CheckUser when personal info tag is disabled' => [
				'rights' => [ 'checkuser' ],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => true,
				'personalInfoTagEnabled' => false,
				'alphaTesters' => [],
				'expectedRights' => [ 'checkuser', 'abusereview-vandalism-alpha-tester' ],
			],
			'CheckUser when vandalism tag is disabled' => [
				'rights' => [ 'checkuser' ],
				'userName' => 'TestUser',
				'vandalismTagEnabled' => false,
				'personalInfoTagEnabled' => true,
				'alphaTesters' => [],
				'expectedRights' => [ 'checkuser' ],
			],
		];
	}
}

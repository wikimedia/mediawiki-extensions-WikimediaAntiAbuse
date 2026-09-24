<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Permissions\Hook\UserGetRightsHook;

class VandalismAlphaTesterHandler implements UserGetRightsHook {

	public function __construct(
		private readonly Config $config,
	) {
	}

	/** @inheritDoc */
	public function onUserGetRights( $user, &$rights ): void {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableVandalismTag' ) ) {
			return;
		}

		// We cannot use ChangeTagsStore::canViewTag because the $user does not have their rights set up yet
		$userCanSeePersonalInfoTag = array_intersect( [ 'viewsuppressed', 'suppressrevision' ], $rights )
			 && $this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' );

		// Any user who can see the personal info tag are included in the alpha test, along with
		// any user who is explictly listed in the alpha testers config and CheckUsers.
		if (
			$userCanSeePersonalInfoTag ||
			in_array( $user->getName(), $this->config->get( 'WikimediaAntiAbuseVandalismTagAlphaTesters' ), true ) ||
			in_array( 'checkuser', $rights, true )
		) {
			$rights[] = 'abusereview-vandalism-alpha-tester';
		}
	}
}

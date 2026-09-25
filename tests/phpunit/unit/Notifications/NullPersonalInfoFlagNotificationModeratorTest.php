<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator
 */
class NullPersonalInfoFlagNotificationModeratorTest extends MediaWikiUnitTestCase {

	public function testTouchesNothing(): void {
		$moderator = new NullPersonalInfoFlagNotificationModerator();

		$moderator->deleteForRevisions( 123, [ 456 ] );

		$this->addToAssertionCount( 1 );
	}
}

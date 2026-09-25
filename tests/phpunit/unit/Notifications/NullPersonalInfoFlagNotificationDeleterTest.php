<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationDeleter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationDeleter
 */
class NullPersonalInfoFlagNotificationDeleterTest extends MediaWikiUnitTestCase {

	public function testTouchesNothing(): void {
		$deleter = new NullPersonalInfoFlagNotificationDeleter();

		$deleter->deleteForRevisions( 123, [ 456 ] );

		$this->addToAssertionCount( 1 );
	}
}

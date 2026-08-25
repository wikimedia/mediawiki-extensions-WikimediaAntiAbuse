<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator
 */
class NullPersonalInfoFlagNotificationModeratorTest extends MediaWikiUnitTestCase {

	public function testTouchesNothing(): void {
		$moderator = new NullPersonalInfoFlagNotificationModerator();

		$moderator->hideForRevisions( 123, [ 456 ] );
		$moderator->restoreForRevision( $this->createNoOpMock( RevisionRecord::class ) );

		$this->addToAssertionCount( 1 );
	}
}

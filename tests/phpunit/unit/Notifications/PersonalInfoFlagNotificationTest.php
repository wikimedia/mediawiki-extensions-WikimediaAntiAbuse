<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotification;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Page\PageIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotification
 */
class PersonalInfoFlagNotificationTest extends MediaWikiUnitTestCase {

	public function testExposesTypeTitleAndRevisionId(): void {
		$title = new PageIdentityValue( 7, NS_MAIN, 'Flagged_page', PageIdentityValue::LOCAL );

		$notification = new PersonalInfoFlagNotification( $title, 123 );

		$this->assertSame( PersonalInfoFlagNotifier::EVENT_TYPE, $notification->getType() );
		$this->assertSame( $title, $notification->getTitle() );
		$this->assertSame( [ 'revisionId' => 123 ], $notification->getProperties() );
	}
}

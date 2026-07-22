<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Notification\Notification;
use MediaWiki\Notification\TitleAware;
use MediaWiki\Page\PageIdentity;

class PersonalInfoFlagNotification extends Notification implements TitleAware {

	public function __construct( private readonly PageIdentity $title, int $revisionId ) {
		parent::__construct( PersonalInfoFlagNotifier::EVENT_TYPE, [ 'revisionId' => $revisionId ] );
	}

	/** @inheritDoc */
	public function getTitle(): PageIdentity {
		return $this->title;
	}
}

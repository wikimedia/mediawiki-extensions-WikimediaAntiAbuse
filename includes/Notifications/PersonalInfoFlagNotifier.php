<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Revision\RevisionRecord;

class PersonalInfoFlagNotifier {

	public const string EVENT_TYPE = 'personal-info-flagged';
	public const string CATEGORY = 'personal-info';

	public function __construct(
		private readonly bool $notificationsEnabled,
		private readonly NotificationService $notificationService,
		private readonly PersonalInfoFlagUserLocator $userLocator,
	) {
	}

	public function notify( RevisionRecord $revision ): void {
		if ( !$this->notificationsEnabled ) {
			return;
		}

		// Edits which have their text suppressed don't need further action, and so the notification is skipped.
		// We still notify if the edit text is only normally revision-deleted, as the edit likely needs suppression.
		if ( $revision->isDeleted( RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED ) ) {
			return;
		}

		$recipients = $this->userLocator->locate();
		if ( !$recipients ) {
			return;
		}

		$this->notificationService->notify(
			new PersonalInfoFlagNotification( $revision->getPage(), $revision->getId() ),
			new RecipientSet( $recipients )
		);
	}
}

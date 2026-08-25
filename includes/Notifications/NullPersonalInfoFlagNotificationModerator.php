<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Revision\RevisionRecord;

/** Used when Echo is absent, or when the flag notifications are switched off. */
class NullPersonalInfoFlagNotificationModerator implements IPersonalInfoFlagNotificationModerator {

	/** @inheritDoc */
	public function hideForRevisions( int $pageId, array $revisionIds ): void {
	}

	/** @inheritDoc */
	public function restoreForRevision( RevisionRecord $revision ): void {
	}
}

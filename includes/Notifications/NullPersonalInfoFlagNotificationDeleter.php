<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

/** Used when Echo is absent, or when the flag notifications are switched off. */
class NullPersonalInfoFlagNotificationDeleter implements IPersonalInfoFlagNotificationDeleter {

	/** @inheritDoc */
	public function deleteForRevisions( int $pageId, array $revisionIds ): void {
	}
}

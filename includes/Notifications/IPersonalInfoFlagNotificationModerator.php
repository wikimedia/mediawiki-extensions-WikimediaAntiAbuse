<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

/**
 * Removes the personal-info flag notification when the revision no longer needs a reviewer.
 */
interface IPersonalInfoFlagNotificationModerator {

	/**
	 * Deletes the notification for each of the given revisions of one page, for all recipients.
	 * Intended for use after a revision has been handled by a verdict or suppression.
	 *
	 * @param int $pageId
	 * @param int[] $revisionIds
	 */
	public function deleteForRevisions( int $pageId, array $revisionIds ): void;
}

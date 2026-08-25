<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Revision\RevisionRecord;

/**
 * Shows or hides the personal-info flag notification, to follow what the revision still needs.
 */
interface IPersonalInfoFlagNotificationModerator {

	/**
	 * Hides the notification for each of the given revisions of one page.
	 *
	 * @param int $pageId
	 * @param int[] $revisionIds
	 */
	public function hideForRevisions( int $pageId, array $revisionIds ): void;

	/**
	 * Shows the notification for one revision again. Takes no action for a revision which no
	 * longer needs a reviewer, such as a suppressed or a deleted one.
	 */
	public function restoreForRevision( RevisionRecord $revision ): void;
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\IPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\RevisionDelete\Hook\ArticleRevisionVisibilitySetHook;

class RevisionVisibilityHandler implements ArticleRevisionVisibilitySetHook {

	public function __construct(
		private readonly IPersonalInfoFlagNotificationModerator $notificationModerator
	) {
	}

	/**
	 * A suppressed revision no longer needs a reviewer, so hide its notification. A plain
	 * revision-deletion is deliberately ignored: the edit still needs suppression, so the
	 * notification stays. This matches the suppression guard in PersonalInfoFlagNotifier.
	 * To lift a suppression does not bring the notification back, because that is a rare and
	 * deliberate act which follows human review.
	 *
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ): void {
		$newlySuppressedRevisionIds = [];
		foreach ( $visibilityChangeMap as $revisionId => $visibilityChange ) {
			$wasSuppressed = $this->isSuppressed( (int)$visibilityChange['oldBits'] );
			$isSuppressed = $this->isSuppressed( (int)$visibilityChange['newBits'] );
			if ( !$wasSuppressed && $isSuppressed ) {
				$newlySuppressedRevisionIds[] = (int)$revisionId;
			}
		}

		if ( !$newlySuppressedRevisionIds ) {
			return;
		}

		$this->notificationModerator->hideForRevisions( $title->getId(), $newlySuppressedRevisionIds );
	}

	private function isSuppressed( int $bits ): bool {
		return ( $bits & PersonalInfoFlagNotifier::SUPPRESSED_BITS ) === PersonalInfoFlagNotifier::SUPPRESSED_BITS;
	}
}

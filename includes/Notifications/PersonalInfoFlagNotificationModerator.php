<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Controller\ModerationController;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Revision\RevisionArchiveRecord;
use MediaWiki\Revision\RevisionRecord;

/**
 * Shows or hides the personal-info flag notification, to follow what the revision still needs.
 * Read state survives, so a restored notification is unread only for users who never read it.
 */
class PersonalInfoFlagNotificationModerator {

	public function __construct( private readonly bool $notificationsEnabled ) {
	}

	public function hideForRevisions( int $pageId, array $revisionIds ): void {
		$this->moderate( $pageId, $revisionIds, true );
	}

	public function restoreForRevision( RevisionRecord $revision ): void {
		// Echo moderates a deleted page's events itself, on both delete and undelete.
		if ( $revision instanceof RevisionArchiveRecord ) {
			return;
		}

		// Suppressed needs no action, matching the guard in PersonalInfoFlagNotifier.
		if ( $revision->isDeleted( PersonalInfoFlagNotifier::SUPPRESSED_BITS ) ) {
			return;
		}

		$this->moderate( $revision->getPageId(), [ $revision->getId() ], false );
	}

	private function moderate( int $pageId, array $revisionIds, bool $hide ): void {
		if ( !$this->notificationsEnabled || !$pageId || !$revisionIds ) {
			return;
		}

		// Defer, so the tag or visibility change commits before the counts are recomputed.
		DeferredUpdates::addCallableUpdate(
			static function () use ( $pageId, $revisionIds, $hide ): void {
				$eventIds = [];
				foreach ( ( new EventMapper() )->fetchByPage( $pageId ) as $event ) {
					if ( $event->getType() === PersonalInfoFlagNotifier::EVENT_TYPE
						&& in_array( $event->getExtraParam( 'revisionId' ), $revisionIds, true )
					) {
						$eventIds[] = $event->getId();
					}
				}

				ModerationController::moderate( $eventIds, $hide );
			}
		);
	}
}

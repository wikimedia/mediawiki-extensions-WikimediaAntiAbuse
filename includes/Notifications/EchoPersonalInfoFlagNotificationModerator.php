<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Controller\ModerationController;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Revision\RevisionArchiveRecord;
use MediaWiki\Revision\RevisionRecord;

/**
 * Moderates the notification through Echo. Read state survives moderation, so a restored
 * notification is unread only for users who never read it.
 */
class EchoPersonalInfoFlagNotificationModerator implements IPersonalInfoFlagNotificationModerator {

	public function __construct( private readonly EventMapper $eventMapper ) {
	}

	/** @inheritDoc */
	public function hideForRevisions( int $pageId, array $revisionIds ): void {
		$this->moderate( $pageId, $revisionIds, true );
	}

	/** @inheritDoc */
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
		if ( !$pageId || !$revisionIds ) {
			return;
		}

		$eventMapper = $this->eventMapper;

		// Defer, so the tag or visibility change commits before the counts are recomputed.
		DeferredUpdates::addCallableUpdate(
			static function () use ( $eventMapper, $pageId, $revisionIds, $hide ): void {
				$eventIds = [];
				foreach ( $eventMapper->fetchByPage( $pageId ) as $event ) {
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

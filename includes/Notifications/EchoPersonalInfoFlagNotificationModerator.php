<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Controller\EventController;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;

/**
 * Deletes the notification through Echo when a revision is handled either by verdict or suppression, so
 * notifications only appear when a user needs to take action.
 */
class EchoPersonalInfoFlagNotificationModerator implements IPersonalInfoFlagNotificationModerator {

	public function __construct(
		private readonly EventMapper $eventMapper,
		private readonly EventController $eventController,
	) {
	}

	/** @inheritDoc */
	public function deleteForRevisions( int $pageId, array $revisionIds ): void {
		if ( !$pageId || !$revisionIds ) {
			return;
		}

		// Defer, so the tag or visibility change commits before the events are deleted.
		DeferredUpdates::addCallableUpdate( function () use ( $pageId, $revisionIds ): void {
			$eventIds = [];
			foreach ( $this->eventMapper->fetchByPage( $pageId, PersonalInfoFlagNotifier::EVENT_TYPE ) as $event ) {
				if ( in_array( $event->getExtraParam( 'revisionId' ), $revisionIds, true ) ) {
					$eventIds[] = $event->getId();
				}
			}

			$this->eventController->delete( $eventIds );
		} );
	}
}

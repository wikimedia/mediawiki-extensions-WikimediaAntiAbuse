<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Controller\ModerationController;
use MediaWiki\Extension\Notifications\Mapper\EventMapper;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\RevisionDelete\Hook\ArticleRevisionVisibilitySetHook;

class RevisionVisibilityHandler implements ArticleRevisionVisibilitySetHook {

	public function __construct( private readonly Config $config, private readonly bool $echoIsLoaded ) {
	}

	public static function factory( Config $config, ExtensionRegistry $extensionRegistry ): self {
		return new self( $config, $extensionRegistry->isLoaded( 'Echo' ) );
	}

	/**
	 * Suppressing a flagged revision makes its notification irrelevant — hide it. A plain
	 * revision-deletion is deliberately ignored: the edit still needs suppressing, so the
	 * notification stays, matching PersonalInfoFlagNotifier's suppression guard. Lifting a
	 * suppression is a rare, deliberate act that follows human review, so the notification is
	 * not brought back.
	 *
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ): void {
		if ( !$this->echoIsLoaded || !$this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' ) ) {
			return;
		}

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

		$pageId = $title->getId();
		DeferredUpdates::addCallableUpdate(
			static function () use ( $pageId, $newlySuppressedRevisionIds ): void {
				$events = ( new EventMapper() )->fetchByPage( $pageId );
				ModerationController::moderate(
					self::ourEventIdsForRevisions( $events, $newlySuppressedRevisionIds ),
					true
				);
			}
		);
	}

	private function isSuppressed( int $bits ): bool {
		$suppressed = RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED;
		return ( $bits & $suppressed ) === $suppressed;
	}

	/**
	 * @param Event[] $events
	 * @param int[] $revisionIds
	 * @return int[]
	 */
	private static function ourEventIdsForRevisions( array $events, array $revisionIds ): array {
		$eventIds = [];
		foreach ( $events as $event ) {
			if ( $event->getType() === PersonalInfoFlagNotifier::EVENT_TYPE
				&& in_array( $event->getExtraParam( 'revisionId' ), $revisionIds, true )
			) {
				$eventIds[] = $event->getId();
			}
		}

		return $eventIds;
	}
}

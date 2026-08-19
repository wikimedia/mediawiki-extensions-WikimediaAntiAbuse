<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\DbDomains;
use MediaWiki\Extension\Notifications\NotifUser;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;

class UserRightsNotificationHandler implements UserGroupsChangedHook {

	public function __construct(
		private readonly Config $config,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly UserFactory $userFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly bool $echoIsLoaded,
	) {
	}

	public static function factory(
		Config $config,
		ChangeTagsStore $changeTagsStore,
		UserFactory $userFactory,
		IConnectionProvider $connectionProvider,
		ExtensionRegistry $extensionRegistry
	): self {
		return new self(
			$config,
			$changeTagsStore,
			$userFactory,
			$connectionProvider,
			$extensionRegistry->isLoaded( 'Echo' )
		);
	}

	/**
	 * Echo counts unread personal-info notifications for every recipient regardless of rights, but
	 * PersonalInfoFlagPresentationModel::canRender() hides them once the recipient may no longer view
	 * the tag. That leaves the alert badge counting a notification the flyout won't show. When a group
	 * removal drops a recipient's tag-view right, mark their unread personal-info notifications read so
	 * the count matches what they can see. Rights granted outside local groups (e.g. CentralAuth global
	 * groups) do not fire this hook, so those recipients still rely on canRender() alone.
	 *
	 * @inheritDoc
	 */
	public function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ): void {
		if ( !$this->echoIsLoaded
			|| !$this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' )
			|| !$removed
		) {
			return;
		}

		// This handler only clears notifications on the local wiki, keyed to the local user. A group
		// change carrying a foreign-wiki UserIdentity does not concern the local tag-view rights, and
		// passing a foreign identity to the local user services below would emit a cross-wiki warning.
		if ( $user->getWikiId() !== UserIdentity::LOCAL ) {
			return;
		}

		$user = $this->userFactory->newFromUserIdentity( $user );
		if ( $this->changeTagsStore->canViewTag( ChangeTagsHandler::PERSONAL_INFO_TAG, $user ) ) {
			return;
		}

		$userId = $user->getId();
		$fname = __METHOD__;
		DeferredUpdates::addCallableUpdate( function () use ( $user, $userId, $fname ): void {
			$eventIds = $this->connectionProvider
				->getReplicaDatabase( DbDomains::VIRTUAL_DOMAIN )
				->newSelectQueryBuilder()
				->select( 'event_id' )
				->from( 'echo_notification' )
				->join( 'echo_event', null, 'notification_event = event_id' )
				->where( [
					'notification_user' => $userId,
					'event_type' => PersonalInfoFlagNotifier::EVENT_TYPE,
					'notification_read_timestamp' => null,
				] )
				->caller( $fname )
				->fetchFieldValues();
			if ( !$eventIds ) {
				return;
			}

			NotifUser::newFromUser( $user )->markRead( array_map( 'intval', $eventIds ) );
		} );
	}
}

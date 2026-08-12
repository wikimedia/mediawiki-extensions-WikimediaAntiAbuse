<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

class PersonalInfoFlagUserLocator {

	private const int MAX_RECIPIENTS = 1000;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly UserFactory $userFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Locate users who opted in to the notification and may view the personal-info tag. Tag access
	 * is rights-based, so grants via CentralAuth global groups count.
	 *
	 * @return User[] Keyed by user id.
	 */
	public function locate(): array {
		$optedInUserIds = $this->connectionProvider
			->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( 'up_user' )
			->distinct()
			->from( 'user_properties' )
			->where( [
				'up_property' => [
					'echo-subscriptions-web-' . PersonalInfoFlagNotifier::CATEGORY,
					'echo-subscriptions-email-' . PersonalInfoFlagNotifier::CATEGORY,
					'echo-subscriptions-push-' . PersonalInfoFlagNotifier::CATEGORY,
				],
				'up_value' => '1',
			] )
			->limit( self::MAX_RECIPIENTS )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$optedInUserIds ) {
			return [];
		}
		if ( count( $optedInUserIds ) === self::MAX_RECIPIENTS ) {
			$this->logger->warning(
				'Hit the {limit}-recipient cap; some opted-in users may not be notified.',
				[ 'limit' => self::MAX_RECIPIENTS ]
			);
		}

		$users = $this->userIdentityLookup
			->newSelectQueryBuilder()
			->whereUserIds( array_map( 'intval', $optedInUserIds ) )
			->caller( __METHOD__ )
			->fetchUserIdentities();

		$eligibleUsers = [];
		foreach ( $users as $userIdentity ) {
			$user = $this->userFactory->newFromUserIdentity( $userIdentity );
			if ( $this->changeTagsStore->canViewTag( ChangeTagsHandler::PERSONAL_INFO_TAG, $user ) ) {
				$eligibleUsers[$user->getId()] = $user;
			}
		}

		return $eligibleUsers;
	}
}

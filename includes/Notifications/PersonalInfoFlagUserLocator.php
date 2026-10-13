<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use GlobalPreferences\Services\GlobalPreferencesConnectionProvider;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

class PersonalInfoFlagUserLocator {

	private const int MAX_RECIPIENTS = 1000;

	private const array SUBSCRIPTION_OPTIONS = [
		'echo-subscriptions-web-' . PersonalInfoFlagNotifier::CATEGORY,
		'echo-subscriptions-email-' . PersonalInfoFlagNotifier::CATEGORY,
		'echo-subscriptions-push-' . PersonalInfoFlagNotifier::CATEGORY,
	];

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly UserFactory $userFactory,
		private readonly LoggerInterface $logger,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly ?GlobalPreferencesConnectionProvider $globalPreferencesConnectionProvider,
	) {
	}

	/**
	 * Locate users who opted in to the notification and may view the personal-info tag. Tag access
	 * is rights-based, so grants via CentralAuth global groups count. A global opt-in counts unless
	 * the user disables it with a local exception.
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
				'up_property' => self::SUBSCRIPTION_OPTIONS,
				'up_value' => '1',
			] )
			->limit( self::MAX_RECIPIENTS )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$this->warnIfCapReached( $optedInUserIds, 'user_properties' );

		$optedInUserIds = array_merge( $optedInUserIds, $this->getGloballyOptedInUserIds() );
		if ( !$optedInUserIds ) {
			return [];
		}

		$users = $this->userIdentityLookup
			->newSelectQueryBuilder()
			->whereUserIds( array_map( 'intval', $optedInUserIds ) )
			->caller( __METHOD__ )
			->fetchUserIdentities();

		$eligibleUsers = [];
		foreach ( $users as $userIdentity ) {
			if ( !$this->isOptedIn( $userIdentity ) ) {
				continue;
			}
			$user = $this->userFactory->newFromUserIdentity( $userIdentity );
			if ( $this->changeTagsStore->canViewTag( ChangeTagsHandler::PERSONAL_INFO_TAG, $user ) ) {
				$eligibleUsers[$user->getId()] = $user;
			}
		}

		return $eligibleUsers;
	}

	/**
	 * @return int[] Ids of local users with a global opt-in row.
	 */
	private function getGloballyOptedInUserIds(): array {
		if ( !$this->globalPreferencesConnectionProvider ) {
			return [];
		}

		$centralIds = $this->globalPreferencesConnectionProvider
			->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( 'gp_user' )
			->distinct()
			->from( 'global_preferences' )
			->where( [
				'gp_property' => self::SUBSCRIPTION_OPTIONS,
				'gp_value' => '1',
			] )
			->limit( self::MAX_RECIPIENTS )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$centralIds ) {
			return [];
		}
		$this->warnIfCapReached( $centralIds, 'global_preferences' );

		$userNames = $this->centralIdLookup->namesFromCentralIds(
			array_map( 'intval', $centralIds ),
			CentralIdLookup::AUDIENCE_RAW
		);
		if ( !$userNames ) {
			return [];
		}

		// Users without a local account cannot get a notification on this wiki, so skip them.
		$users = $this->userIdentityLookup
			->newSelectQueryBuilder()
			->whereUserNames( $userNames )
			->registered()
			->caller( __METHOD__ )
			->fetchUserIdentities();

		return array_map(
			static fn ( UserIdentity $user ): int => $user->getId(),
			iterator_to_array( $users, false )
		);
	}

	/**
	 * The stored rows only find candidates. A global value overrides the local value unless the
	 * user sets a local exception, so get the effective value for each user. Do not use
	 * getOptionBatchForUserNames(), because it ignores a local exception without a local value.
	 */
	private function isOptedIn( UserIdentity $userIdentity ): bool {
		foreach ( self::SUBSCRIPTION_OPTIONS as $option ) {
			if ( $this->userOptionsLookup->getBoolOption( $userIdentity, $option ) ) {
				return true;
			}
		}
		return false;
	}

	private function warnIfCapReached( array $ids, string $table ): void {
		if ( count( $ids ) === self::MAX_RECIPIENTS ) {
			$this->logger->warning(
				'Hit the {limit}-recipient cap in {table}; some opted-in users may not get ' .
				'the personal-info flag notification.',
				[ 'limit' => self::MAX_RECIPIENTS, 'table' => $table ]
			);
		}
	}
}

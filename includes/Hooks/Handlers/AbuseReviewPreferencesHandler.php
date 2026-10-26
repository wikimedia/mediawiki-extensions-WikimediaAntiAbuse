<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Preferences\Hook\GetPreferencesHook;

class AbuseReviewPreferencesHandler implements GetPreferencesHook {

	public const string LIVE_UPDATES_PREFERENCE = 'wikimediaantiabuse-abusereview-live-updates';

	public function __construct(
		private readonly AbuseReviewEnabledTagsProvider $enabledTagsProvider,
		private readonly ChangeTagsStore $changeTagsStore,
	) {
	}

	/**
	 * Add the live updates checkbox for users who can see a queue that is switched on.
	 *
	 * @inheritDoc
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		foreach ( $this->enabledTagsProvider->getEnabledReviewableTags() as $tag ) {
			if ( $this->changeTagsStore->canViewTag( $tag, $user ) ) {
				$preferences[self::LIVE_UPDATES_PREFERENCE] = [
					'type' => 'toggle',
					'label-message' => 'wikimediaantiabuse-pref-abusereview-live-updates',
					'section' => 'rc/abusereview',
				];

				return;
			}
		}
	}
}

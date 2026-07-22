<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Registration\ExtensionRegistry;
use Psr\Log\LoggerInterface;

class NotificationPreferencesHandler implements GetPreferencesHook {

	public function __construct(
		private readonly Config $config,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly LoggerInterface $logger,
		private readonly ExtensionRegistry $extensionRegistry,
	) {
	}

	/**
	 * Hide the personal-info opt-in row from the preferences form for users who cannot view the
	 * tag (or when the feature is disabled). This only shapes the rendered form for the current
	 * request; it does not delete any stored user option.
	 *
	 * @inheritDoc
	 */
	public function onGetPreferences( $user, &$preferences ): void {
		if ( $this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' )
			&& $this->changeTagsStore->canViewTag( ChangeTagsHandler::PERSONAL_INFO_TAG, $user )
		) {
			return;
		}

		// Echo may be absent or not yet have built its preferences; skipping is safe — hiding is cosmetic.
		if ( !isset( $preferences['echo-subscriptions']['rows'] ) ) {
			// With Echo absent the row genuinely does not exist. With Echo present it means Echo's
			// GetPreferences handler has not run yet, i.e. this extension registered before Echo.
			if ( $this->extensionRegistry->isLoaded( 'Echo' ) ) {
				$this->logger->warning(
					'echo-subscriptions preference missing though Echo is loaded; ' .
					'is WikimediaAntiAbuse registered before Echo?'
				);
			}
			return;
		}

		$categoryRowLabel = array_search(
			PersonalInfoFlagNotifier::CATEGORY,
			$preferences['echo-subscriptions']['rows'],
			true
		);
		if ( $categoryRowLabel === false ) {
			return;
		}

		unset(
			$preferences['echo-subscriptions']['rows'][$categoryRowLabel],
			$preferences['echo-subscriptions']['tooltips'][$categoryRowLabel]
		);

		$categorySuffix = '-' . PersonalInfoFlagNotifier::CATEGORY;
		foreach ( [ 'force-options-off', 'force-options-on' ] as $forcedOptionsKey ) {
			if ( isset( $preferences['echo-subscriptions'][$forcedOptionsKey] ) ) {
				$preferences['echo-subscriptions'][$forcedOptionsKey] = array_values( array_filter(
					$preferences['echo-subscriptions'][$forcedOptionsKey],
					static fn ( string $option ): bool => !str_ends_with( $option, $categorySuffix )
				) );
			}
		}
	}
}

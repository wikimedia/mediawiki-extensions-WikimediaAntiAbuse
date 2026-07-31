<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Html\Html;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Registration\ExtensionRegistry;
use Psr\Log\LoggerInterface;

class NotificationPreferencesHandler implements GetPreferencesHook {

	public function __construct(
		private readonly Config $config,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly LoggerInterface $logger,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly MessageLocalizer $messageLocalizer,
	) {
	}

	public static function factory(
		Config $config,
		ChangeTagsStore $changeTagsStore,
		LoggerInterface $logger,
		ExtensionRegistry $extensionRegistry
	): self {
		// The preferences form is rendered in the viewing user's interface language; the main
		// request context is the localizer for it.
		return new self( $config, $changeTagsStore, $logger, $extensionRegistry, RequestContext::getMain() );
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
			// The recipient may view the tag, so they keep the opt-in row. Point its tooltip at a
			// help page so someone discovering the category can learn what the notification is.
			$this->addTooltipHelpLink( $preferences );
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

	/**
	 * Replace the plain-text tooltip Echo set for the personal-info row with an HTML tooltip that
	 * appends a help link. The plain tooltip renders only as a title attribute, so a link needs the
	 * 'tooltips-html' variant, which HTMLCheckMatrix shows as a clickable help popup.
	 */
	private function addTooltipHelpLink( array &$preferences ): void {
		if ( !isset( $preferences['echo-subscriptions']['rows'] ) ) {
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

		$tooltipText = $preferences['echo-subscriptions']['tooltips'][$categoryRowLabel]
			?? $this->messageLocalizer->msg( 'echo-pref-tooltip-' . PersonalInfoFlagNotifier::CATEGORY )->text();
		// CheckMatrixWidget's JS gives a plain 'tooltips' entry precedence over 'tooltips-html' (unlike
		// the PHP), so drop the plain entry to let the HTML tooltip render after client-side infusion.
		unset( $preferences['echo-subscriptions']['tooltips'][$categoryRowLabel] );
		$preferences['echo-subscriptions']['tooltips-html'][$categoryRowLabel] =
			Html::element( 'p', [], $tooltipText )
			. $this->messageLocalizer->msg(
				'echo-pref-tooltip-' . PersonalInfoFlagNotifier::CATEGORY . '-learn-more'
			)->parse();
	}
}

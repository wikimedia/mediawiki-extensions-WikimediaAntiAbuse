<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\Hooks\EchoGetBundleRulesHook;
use MediaWiki\Extension\Notifications\Model\Event;

class EchoHooksHandler implements BeforeCreateEchoEventHook, EchoGetBundleRulesHook {

	public function __construct( private readonly Config $config ) {
	}

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' ) ) {
			return;
		}

		// Do NOT set 'usergroups' here: Echo enforces it via local groups only, silently dropping stewards
		// whose viewsuppressed comes from CentralAuth global groups. Gating lives in the locator and preferences.
		$notificationCategories[PersonalInfoFlagNotifier::CATEGORY] = [
			'priority' => 2,
			'tooltip' => 'echo-pref-tooltip-personal-info',
		];

		$notifications[PersonalInfoFlagNotifier::EVENT_TYPE] = [
			'category' => PersonalInfoFlagNotifier::CATEGORY,
			'section' => AttributeManager::ALERT,
			'presentation-model' => PersonalInfoFlagPresentationModel::class,
			'icon' => 'alert',
			'bundle' => [
				'web' => true,
				'expandable' => true,
			],
		];
	}

	/** @inheritDoc */
	public function onEchoGetBundleRules( Event $event, string &$bundleString ): void {
		if ( $event->getType() === PersonalInfoFlagNotifier::EVENT_TYPE ) {
			$bundleString = $event->getType();
		}
	}
}

<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagUserLocator;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FalsePositiveTagService;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\RevisionSnippetGenerator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// This is fully tested in ServiceWiringTest.php
// @codeCoverageIgnoreStart

/** @phpcs-require-sorted-array */
return [
	'WikimediaAntiAbuseContentPolicyEvaluator' => static fn (
		MediaWikiServices $services
	) => new ContentPolicyEvaluator(
		new ServiceOptions(
			ContentPolicyEvaluator::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig()
		),
		$services->getHttpRequestFactory(),
		$services->getFormatterFactory(),
		$services->getStatsFactory(),
		LoggerFactory::getInstance( 'WikimediaAntiAbuse' )
	),

	'WikimediaAntiAbuseFalsePositiveTagService' => static function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig();
		$enabledReviewableTags = [];
		if ( $config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' ) ) {
			$enabledReviewableTags[] = ChangeTagsHandler::PERSONAL_INFO_TAG;
		}

		return new FalsePositiveTagService(
			$enabledReviewableTags,
			$services->getChangeTagsStore(),
			$services->getConnectionProvider(),
			$services->getRevisionLookup(),
			$services->getReadOnlyMode(),
			$services->get( 'WikimediaAntiAbuseLogger' )
		);
	},

	'WikimediaAntiAbuseHookRunner' => static fn (
		MediaWikiServices $services
	) => new HookRunner( $services->getHookContainer() ),

	'WikimediaAntiAbuseLogger' => static fn () => LoggerFactory::getInstance( 'WikimediaAntiAbuse' ),

	'WikimediaAntiAbusePersonalInfoFlagNotifier' => static fn (
		MediaWikiServices $services
	) => new PersonalInfoFlagNotifier(
		// Notifications require both the feature flag and Echo; fold the Echo-loaded guard in here
		// so the notifier stays free of any Echo dependency when it is disabled.
		$services->getMainConfig()->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' )
			&& ExtensionRegistry::getInstance()->isLoaded( 'Echo' ),
		$services->getNotificationService(),
		$services->getService( 'WikimediaAntiAbusePersonalInfoFlagUserLocator' )
	),

	'WikimediaAntiAbusePersonalInfoFlagUserLocator' => static fn (
		MediaWikiServices $services
	) => new PersonalInfoFlagUserLocator(
		$services->getConnectionProvider(),
		$services->getChangeTagsStore(),
		$services->getUserIdentityLookup(),
		$services->getUserFactory(),
		$services->getService( 'WikimediaAntiAbuseLogger' )
	),

	'WikimediaAntiAbuseRevisionSnippetGenerator' => static fn (
		MediaWikiServices $services
	) => new RevisionSnippetGenerator(
		$services->getRevisionLookup(),
		$services->getTitleFormatter()
	),
];
// @codeCoverageIgnoreEnd

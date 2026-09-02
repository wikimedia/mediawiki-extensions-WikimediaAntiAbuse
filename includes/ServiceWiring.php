<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\EchoPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\NullPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagUserLocator;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttribution;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyScoreEventLogger;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IContentPolicyScoreEventLogger;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\NoOpAbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\NoOpContentPolicyScoreEventLogger;
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
	'WikimediaAntiAbuseAbuseReviewInstrumentationClient' => static function (
		MediaWikiServices $services
	): IAbuseReviewInstrumentationClient {
		// If EventLogging is not installed, return the no-op client so callers can call it safely.
		if ( !$services->has( 'EventLogging.MetricsClientFactory' ) ) {
			return new NoOpAbuseReviewInstrumentationClient();
		}

		return new AbuseReviewInstrumentationClient( $services->getService( 'EventLogging.MetricsClientFactory' ) );
	},

	'WikimediaAntiAbuseAbuseReviewTagService' => static function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig();
		$enabledReviewableTags = [];
		if ( $config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' ) ) {
			$enabledReviewableTags[] = ChangeTagsHandler::PERSONAL_INFO_TAG;
		}

		return new AbuseReviewTagService(
			$enabledReviewableTags,
			$services->getChangeTagsStore(),
			$services->getActorNormalization(),
			$services->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttribution' ),
			$services->getConnectionProvider(),
			$services->getRevisionLookup(),
			$services->getArchivedRevisionLookup(),
			$services->getReadOnlyMode(),
			$services->get( 'WikimediaAntiAbusePersonalInfoFlagNotificationModerator' ),
			$services->get( 'WikimediaAntiAbuseLogger' )
		);
	},

	'WikimediaAntiAbuseAbuseReviewVerdictAttribution' => static fn (
		MediaWikiServices $services
	) => new AbuseReviewVerdictAttribution( $services->get( 'WikimediaAntiAbuseLogger' ) ),

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

	'WikimediaAntiAbuseContentPolicyScoreEventLogger' => static function (
		MediaWikiServices $services
	): IContentPolicyScoreEventLogger {
		// If EventLogging or EventBus is not installed, return the no-op logger so callers can call it safely.
		if ( !$services->has( 'EventLogging.EventSubmitter' ) || !$services->has( 'EventBus.UserEntitySerializer' ) ) {
			return new NoOpContentPolicyScoreEventLogger();
		}

		return new ContentPolicyScoreEventLogger(
			$services->getService( 'EventLogging.EventSubmitter' ),
			$services->getService( 'EventBus.UserEntitySerializer' )
		);
	},

	'WikimediaAntiAbuseHookRunner' => static fn (
		MediaWikiServices $services
	) => new HookRunner( $services->getHookContainer() ),

	'WikimediaAntiAbuseLogger' => static fn () => LoggerFactory::getInstance( 'WikimediaAntiAbuse' ),

	'WikimediaAntiAbusePersonalInfoFlagNotificationModerator' => static function (
		MediaWikiServices $services
	) {
		$enabled = $services->getMainConfig()->get( 'WikimediaAntiAbuseEnablePersonalInfoFlagNotifications' )
			&& $services->getExtensionRegistry()->isLoaded( 'Echo' );

		return $enabled
			? new EchoPersonalInfoFlagNotificationModerator( $services->get( 'EchoEventMapper' ) )
			: new NullPersonalInfoFlagNotificationModerator();
	},

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
		$services->getArchivedRevisionLookup(),
		$services->getTitleFormatter()
	),
];
// @codeCoverageIgnoreEnd

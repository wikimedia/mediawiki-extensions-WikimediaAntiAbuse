<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

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

	'WikimediaAntiAbuseHookRunner' => static fn (
		MediaWikiServices $services
	) => new HookRunner( $services->getHookContainer() ),

	'WikimediaAntiAbuseLogger' => static fn () => LoggerFactory::getInstance( 'WikimediaAntiAbuse' ),
];
// @codeCoverageIgnoreEnd

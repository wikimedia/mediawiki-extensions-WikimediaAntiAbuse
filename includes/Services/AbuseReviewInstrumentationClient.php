<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;

/**
 * Wrapper class for emitting server-side interaction events to the Special:AbuseReview
 * Metrics Platform instrument.
 */
class AbuseReviewInstrumentationClient implements IAbuseReviewInstrumentationClient {

	private const string STREAM = 'mediawiki.wikimedia_antiabuse.abuse_review_interaction';
	private const string SCHEMA = '/analytics/mediawiki/wikimedia_antiabuse/abuse_review_interaction/1.1.0';

	public function __construct(
		private readonly MetricsClientFactory $metricsClientFactory,
	) {
	}

	/** @inheritDoc */
	public function submitInteraction(
		IContextSource $context,
		string $action,
		array $interactionData
	): void {
		$this->metricsClientFactory->newMetricsClient( $context )->submitInteraction(
			self::STREAM,
			self::SCHEMA,
			$action,
			$interactionData
		);
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Context\IContextSource;

/**
 * Emits server-side interaction events to the Special:AbuseReview Metrics Platform instrument.
 */
interface IAbuseReviewInstrumentationClient {

	/**
	 * Emit an interaction event to the Special:AbuseReview Metrics Platform instrument.
	 *
	 * @param IContextSource $context
	 * @param string $action The action name to use for the interaction
	 * @param array $interactionData Interaction data for the event
	 */
	public function submitInteraction( IContextSource $context, string $action, array $interactionData ): void;
}

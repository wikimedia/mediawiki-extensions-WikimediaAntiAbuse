<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Context\IContextSource;

/**
 * An implementation of {@link IAbuseReviewInstrumentationClient} that is a no-op.
 *
 * @codeCoverageIgnore Merely declarative
 */
class NoOpAbuseReviewInstrumentationClient implements IAbuseReviewInstrumentationClient {

	/** @inheritDoc */
	public function submitInteraction(
		IContextSource $context,
		string $action,
		array $interactionData
	): void {
	}
}

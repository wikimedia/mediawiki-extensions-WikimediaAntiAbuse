<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Revision\RevisionRecord;

/**
 * A no-op implementation of {@link IContentPolicyScoreEventLogger} used when EventLogging
 * or EventBus is not installed, so callers can record scores safely.
 *
 * @codeCoverageIgnore Merely declarative
 */
class NoOpContentPolicyScoreEventLogger implements IContentPolicyScoreEventLogger {

	/** @inheritDoc */
	public function record( RevisionRecord $revisionRecord, array $results ): void {
	}
}

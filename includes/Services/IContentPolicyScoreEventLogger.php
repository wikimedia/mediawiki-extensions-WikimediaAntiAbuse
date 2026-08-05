<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ContentPolicyEvaluationResult;
use MediaWiki\Revision\RevisionRecord;

/**
 * Emits one EventLogging event per edit capturing each content policy's CoPE confidence scores.
 */
interface IContentPolicyScoreEventLogger {

	/**
	 * @param RevisionRecord $revisionRecord
	 * @param ContentPolicyEvaluationResult[] $results
	 */
	public function record( RevisionRecord $revisionRecord, array $results ): void;
}

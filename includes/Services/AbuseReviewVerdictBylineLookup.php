<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Permissions\Authority;

/**
 * Builds the byline that names the reviewer who last acted on the review flag of each revision.
 */
class AbuseReviewVerdictBylineLookup {

	public function __construct(
		private readonly AbuseReviewVerdictPerformerLookup $verdictPerformerLookup,
		private readonly AbuseReviewVerdictAttributionFormatter $verdictAttributionFormatter,
		private readonly LinkBatchFactory $linkBatchFactory,
	) {
	}

	/**
	 * @param MessageLocalizer $context
	 * @param array<int,bool> $verdictHeldByRevisionId Whether each revision holds a verdict, keyed by
	 *   revision ID. False means the revision goes back to review.
	 * @param string $tag The review flag tag
	 * @return array<int,string> The HTML byline, keyed by revision ID. A revision has no byline if its
	 *   flag names no reviewer that the authority can see.
	 */
	public function lookUpBylines(
		MessageLocalizer $context,
		array $verdictHeldByRevisionId,
		string $tag,
		Authority $authority
	): array {
		$performers = $this->verdictPerformerLookup->lookUpPerformers(
			array_keys( $verdictHeldByRevisionId ),
			$tag,
			$authority
		);

		$linkBatch = $this->linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
		foreach ( $performers as $performer ) {
			$linkBatch->addUser( $performer );
		}
		$linkBatch->execute();

		$bylines = [];
		foreach ( $performers as $revisionId => $performer ) {
			$bylines[$revisionId] = $this->verdictAttributionFormatter->format(
				$context,
				$performer,
				$verdictHeldByRevisionId[$revisionId]
			);
		}

		return $bylines;
	}
}

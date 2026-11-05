<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

/**
 * Immutable description of the case of one account in one AbuseReview queue.
 */
readonly class AbuseReviewCase {

	/**
	 * @param int $id
	 * @param AbuseReviewCaseState $state
	 * @param string $updatedTimestamp In the TS_MW format
	 */
	public function __construct(
		public int $id,
		public AbuseReviewCaseState $state,
		public string $updatedTimestamp,
	) {
	}
}

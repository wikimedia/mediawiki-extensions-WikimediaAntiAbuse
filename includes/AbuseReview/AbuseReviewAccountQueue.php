<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

/**
 * Lists the AbuseReview queues of accounts. Each value is the name of the queue.
 */
enum AbuseReviewAccountQueue: string {
	case FLAGGED_ACCOUNTS = 'mw-private-flagged-accounts';

	/** Returns the arc_queue_id for this queue. */
	public function getId(): int {
		return match ( $this ) {
			self::FLAGGED_ACCOUNTS => 1,
		};
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

/**
 * Immutable description of the case that starts a page of AbuseReview cases.
 */
readonly class AbuseReviewCaseOffset {

	/**
	 * @param string $updatedTimestamp In TimestampFormat::MW
	 * @param int $id
	 */
	public function __construct(
		public string $updatedTimestamp,
		public int $id,
	) {
	}
}

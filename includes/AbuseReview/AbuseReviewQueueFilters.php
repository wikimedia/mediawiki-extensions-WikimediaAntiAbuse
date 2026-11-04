<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

use MediaWiki\Title\Title;

/**
 * Immutable description of the filters that select the revisions an AbuseReview queue shows.
 * @newable
 */
readonly class AbuseReviewQueueFilters {

	public function __construct(
		public string $abuseReviewTag,
		public bool $includeFalsePositives = false,
		public bool $includeHandledRevisions = false,
		public bool $includeRecentEdits = false,
		/** @var string[] */
		public array $usernames = [],
		/** @var int[] */
		public array $revisionIds = [],
		/** @var Title[] */
		public array $pages = [],
		/** The oldest timestamp to include, in TS_MW format */
		public ?string $oldestTimestamp = null,
		/** The newest timestamp to include, in TS_MW format */
		public ?string $newestTimestamp = null,
	) {
	}
}

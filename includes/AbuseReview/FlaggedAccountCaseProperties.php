<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

/**
 * Holds the names of the properties of the cases in the Flagged accounts queue.
 */
final class FlaggedAccountCaseProperties {

	/** Followed by a change tag, the name of the property that holds the number of flagged edits with that tag. */
	public const string FLAGGED_EDIT_COUNT_PREFIX = 'flagged_edit_count:';

	/** The name of the property that holds the TS_MW time, as an integer, from which the flagged edit counts start. */
	public const string COUNT_START_TIMESTAMP = 'count_start_timestamp';
}

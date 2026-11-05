<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview;

/**
 * Lists the states of an AbuseReview case. Each value is the value of the arc_state column.
 */
enum AbuseReviewCaseState: int {
	case OPEN = 0;
	case HANDLED = 1;
}

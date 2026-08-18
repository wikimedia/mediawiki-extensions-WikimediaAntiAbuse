<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler;

use StatusValue;

/**
 * @internal
 */
class UnmarkReviewVerdictHandler extends ReviewVerdictHandler {

	/** @inheritDoc */
	protected function applyVerdict( string $verdict, int $revision, string $tag ): StatusValue {
		return match ( $verdict ) {
			self::FALSE_POSITIVE
				=> $this->abuseReviewTagService->unmarkFalsePositive( $this->getAuthority(), $revision, $tag ),
			self::NO_FURTHER_ACTION
				=> $this->abuseReviewTagService->unmarkNoFurtherAction( $this->getAuthority(), $revision, $tag ),
		};
	}

	/** @inheritDoc */
	protected function verdictIsSet(): bool {
		return false;
	}
}

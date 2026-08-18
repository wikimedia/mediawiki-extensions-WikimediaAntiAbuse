<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler;

use StatusValue;

/**
 * @internal
 */
class MarkReviewVerdictHandler extends ReviewVerdictHandler {

	/** @inheritDoc */
	protected function applyVerdict( string $verdict, int $revision, string $tag ): StatusValue {
		return match ( $verdict ) {
			self::FALSE_POSITIVE
				=> $this->abuseReviewTagService->markFalsePositive( $this->getAuthority(), $revision, $tag ),
		};
	}

	/** @inheritDoc */
	protected function verdictIsSet(): bool {
		return true;
	}
}

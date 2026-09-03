<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Permissions\Authority;

class AbuseReviewPermissionManager {

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
	) {
	}

	/** A performer can view the queue if they can view at least one of the abuse review tags. */
	public function canViewQueue( Authority $performer ): bool {
		// A tag declares its view rights only while it is enabled, so a disabled tag is viewable by nobody.
		return (bool)$this->changeTagsStore->filterViewableTags(
			array_merge(
				array_keys( ChangeTagsHandler::REVIEWABLE_TAGS ),
				array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'falsePositive' ),
				array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'noFurtherAction' )
			),
			$performer
		);
	}
}

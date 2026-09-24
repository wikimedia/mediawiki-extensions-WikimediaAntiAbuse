<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;

class AbuseReviewEnabledTagsProvider {

	/** @internal Only for use by ServiceWiring.php */
	public const array CONSTRUCTOR_OPTIONS = [
		'WikimediaAntiAbuseEnablePersonalInfoTag',
		'WikimediaAntiAbuseEnableVandalismTag',
	];

	public function __construct(
		private readonly ServiceOptions $options
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Returns all enabled reviewable tags (not including the verdict tags).
	 * Does not consider whether the user can see the tags, so appropriately redact these when
	 * displaying them.
	 *
	 * @return string[]
	 */
	public function getEnabledReviewableTags(): array {
		$enabledReviewableTags = [];
		if ( $this->options->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' ) ) {
			$enabledReviewableTags[] = ChangeTagsHandler::PERSONAL_INFO_TAG;
		}
		if ( $this->options->get( 'WikimediaAntiAbuseEnableVandalismTag' ) ) {
			$enabledReviewableTags[] = ChangeTagsHandler::VANDALISM_TAG;
		}

		return $enabledReviewableTags;
	}

	/**
	 * Returns all enabled abuse review tags, including the associated verdict tags.
	 * Does not consider whether the user can see the tags, so appropriately redact these when
	 * displaying them.
	 *
	 * @return string[]
	 */
	public function getAllEnabledTags(): array {
		$tags = $this->getEnabledReviewableTags();
		foreach ( ChangeTagsHandler::REVIEWABLE_TAGS as $tag => $verdictTags ) {
			if ( !in_array( $tag, $tags, true ) ) {
				continue;
			}
			$tags = array_merge( $tags, array_values( $verdictTags ) );
		}

		return $tags;
	}
}

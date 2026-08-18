<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\ChangeTags\Hook\ListRestrictedTagsHook;
use MediaWiki\Config\Config;

class ChangeTagsHandler implements ListDefinedTagsHook, ListRestrictedTagsHook, ChangeTagsListActiveHook {

	public const string PERSONAL_INFO_TAG = 'mw-private-personal-info';
	public const string PERSONAL_INFO_FALSE_POSITIVE_TAG = 'mw-private-personal-info-false-positive';
	public const string PERSONAL_INFO_NO_FURTHER_ACTION_TAG = 'mw-private-personal-info-no-further-action';

	/**
	 * Maps each abuse review tag to its review-verdict tags. Each verdict tag is
	 * specific to one flag so it inherits that flag's view rights.
	 */
	public const array REVIEWABLE_TAGS = [
		self::PERSONAL_INFO_TAG => [
			'falsePositive' => self::PERSONAL_INFO_FALSE_POSITIVE_TAG,
			'noFurtherAction' => self::PERSONAL_INFO_NO_FURTHER_ACTION_TAG,
		],
	];

	/** Rights that let a user see an abuse review tag. */
	private const array TAG_VIEW_RIGHTS = [ 'viewsuppressed', 'suppressrevision' ];

	public function __construct( private readonly Config $config ) {
	}

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ): void {
		$tags = array_merge( $tags, $this->getEnabledTags() );
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ): void {
		$tags = array_merge( $tags, $this->getEnabledTags() );
	}

	/** @inheritDoc */
	public function onListRestrictedTags( array &$restrictedTags ): void {
		foreach ( $this->getEnabledTags() as $tag ) {
			$restrictedTags[$tag] = self::TAG_VIEW_RIGHTS;
		}
	}

	/**
	 * Every enabled abuse review tag: each flag, then its verdict tags. All three hooks
	 * read this list, so a new tag cannot be defined without also being restricted.
	 *
	 * @return string[]
	 */
	private function getEnabledTags(): array {
		if ( !$this->isPersonalInfoTagEnabled() ) {
			return [];
		}

		$tags = [];
		foreach ( self::REVIEWABLE_TAGS as $baseTag => $verdictTags ) {
			$tags[] = $baseTag;
			$tags = array_merge( $tags, array_values( $verdictTags ) );
		}

		return $tags;
	}

	private function isPersonalInfoTagEnabled(): bool {
		return $this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' );
	}
}

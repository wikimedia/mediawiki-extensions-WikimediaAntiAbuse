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

	/** Maps each abuse review tag to the tag applied when it is marked a false positive. */
	public const array REVIEWABLE_TAGS = [
		self::PERSONAL_INFO_TAG => self::PERSONAL_INFO_FALSE_POSITIVE_TAG,
	];

	public function __construct( private readonly Config $config ) {
	}

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$tags[] = self::PERSONAL_INFO_TAG;
			$tags[] = self::PERSONAL_INFO_FALSE_POSITIVE_TAG;
		}
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$tags[] = self::PERSONAL_INFO_TAG;
			$tags[] = self::PERSONAL_INFO_FALSE_POSITIVE_TAG;
		}
	}

	/** @inheritDoc */
	public function onListRestrictedTags( array &$restrictedTags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$restrictedTags[self::PERSONAL_INFO_TAG] = [ 'viewsuppressed', 'suppressrevision' ];
			$restrictedTags[self::PERSONAL_INFO_FALSE_POSITIVE_TAG] = [ 'viewsuppressed', 'suppressrevision' ];
		}
	}

	private function isPersonalInfoTagEnabled(): bool {
		return $this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' );
	}
}

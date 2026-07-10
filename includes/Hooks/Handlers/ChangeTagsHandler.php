<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\ChangeTags\Hook\ListRestrictedTagsHook;
use MediaWiki\Config\Config;

class ChangeTagsHandler implements ListDefinedTagsHook, ListRestrictedTagsHook, ChangeTagsListActiveHook {

	public function __construct( private readonly Config $config ) {
	}

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$tags[] = 'mw-private-personal-info';
		}
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$tags[] = 'mw-private-personal-info';
		}
	}

	/** @inheritDoc */
	public function onListRestrictedTags( array &$restrictedTags ): void {
		if ( $this->isPersonalInfoTagEnabled() ) {
			$restrictedTags['mw-private-personal-info'] = 'viewsuppressed';
		}
	}

	private function isPersonalInfoTagEnabled(): bool {
		return $this->config->get( 'WikimediaAntiAbuseEnablePersonalInfoTag' );
	}
}

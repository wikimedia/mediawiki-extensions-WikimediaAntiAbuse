<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @codeCoverageIgnore Tested by installing or updating MediaWiki.
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$base = dirname( __DIR__, 3 ) . '/schema';
		$dbType = $updater->getDB()->getType();

		// The file creates both tables, so the update checks only for the first table.
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-wikimediaantiabuse', 'addTable', 'abuse_review_case', "$base/$dbType/tables-generated.sql", true,
		] );
	}
}

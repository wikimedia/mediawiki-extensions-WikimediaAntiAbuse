<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Revision\RevisionRecord;

/**
 * This is a hook handler interface, see docs/Hooks.md.
 * Use the hook name "WikimediaAntiAbuseGetModelsToRun" to register handlers implementing this interface.
 *
 * @ingroup Hooks
 */
interface WikimediaAntiAbuseGetModelsToRunHook {

	/**
	 * Collect the models that should evaluate the given revision.
	 *
	 * Handlers append {@link ModelToRun} instances to $modelsToRun and must never remove or
	 * overwrite entries added by other handlers. The revision's author is available via
	 * {@link RevisionRecord::getUser}.
	 *
	 * The hook can be fired more than once for the same revision (at save time and again during
	 * deferred processing), so handlers must be side-effect-free and idempotent. At save time it
	 * runs on the page-save critical path, so handlers should stay cheap and defer expensive work
	 * such as reading policy texts from disk until the returned objects are consumed.
	 *
	 * @since 1.47
	 * @param RevisionRecord $revisionRecord
	 * @param ModelToRun[] &$modelsToRun
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onWikimediaAntiAbuseGetModelsToRun( RevisionRecord $revisionRecord, array &$modelsToRun ): void;
}

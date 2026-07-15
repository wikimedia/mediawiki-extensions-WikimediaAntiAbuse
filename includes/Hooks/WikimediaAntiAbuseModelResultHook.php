<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\IModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Revision\RevisionRecord;

/**
 * This is a hook handler interface, see docs/Hooks.md.
 * Use the hook name "WikimediaAntiAbuseModelResult" to register handlers implementing this interface.
 *
 * @ingroup Hooks
 */
interface WikimediaAntiAbuseModelResultHook {

	/**
	 * Inspect a model response and register the resulting actions on $actionsToTake.
	 *
	 * The hook may fire more than once for the same revision (for example if the job is retried),
	 * so handlers should register actions idempotently.
	 *
	 * @since 1.47
	 * @codeCoverageIgnore Cannot be annotated as covered.
	 */
	public function onWikimediaAntiAbuseModelResult(
		ModelToRun $modelToRun,
		RevisionRecord $revisionRecord,
		IModelResponse $response,
		ActionsToTake $actionsToTake
	): void;
}

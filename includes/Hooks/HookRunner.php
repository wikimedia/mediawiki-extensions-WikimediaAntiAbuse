<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\IModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Revision\RevisionRecord;

/**
 * Runs the hooks fired by this extension's revision model-check pipeline.
 *
 * @internal
 */
class HookRunner implements WikimediaAntiAbuseGetModelsToRunHook, WikimediaAntiAbuseModelResultHook {

	public function __construct( private readonly HookContainer $hookContainer ) {
	}

	/** @inheritDoc */
	public function onWikimediaAntiAbuseGetModelsToRun( RevisionRecord $revisionRecord, array &$modelsToRun ): void {
		$this->hookContainer->run(
			'WikimediaAntiAbuseGetModelsToRun',
			[ $revisionRecord, &$modelsToRun ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onWikimediaAntiAbuseModelResult(
		ModelToRun $modelToRun,
		RevisionRecord $revisionRecord,
		IModelResponse $response,
		ActionsToTake $actionsToTake
	): void {
		$this->hookContainer->run(
			'WikimediaAntiAbuseModelResult',
			[ $modelToRun, $revisionRecord, $response, $actionsToTake ],
			[ 'abortable' => false ]
		);
	}
}

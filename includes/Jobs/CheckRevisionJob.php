<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Jobs;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\HookRunner;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDBAccessObject;

class CheckRevisionJob extends Job {

	public const string TYPE = 'wikimediaAntiAbuseCheckRevision';

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly RevisionLookup $revisionLookup,
		private readonly HookRunner $hookRunner,
		private readonly ContentPolicyEvaluator $contentPolicyEvaluator,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly PersonalInfoFlagNotifier $personalInfoFlagNotifier,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct( self::TYPE, $params );
	}

	public static function newSpec( int $revisionId ): IJobSpecification {
		return new JobSpecification( self::TYPE, [ 'revisionId' => $revisionId ] );
	}

	/** @inheritDoc */
	public function run(): bool {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableModelChecks' ) ) {
			return true;
		}

		$revisionId = (int)$this->params['revisionId'];
		$revisionRecord = $this->loadRevision( $revisionId );
		if ( !$revisionRecord ) {
			$this->logger->error(
				'Revision {revisionId} was queued for a model check but could not be loaded',
				[ 'revisionId' => $revisionId ]
			);

			return true;
		}

		$modelsToRun = [];
		$this->hookRunner->onWikimediaAntiAbuseGetModelsToRun( $revisionRecord, $modelsToRun );
		if ( !$modelsToRun ) {
			return true;
		}

		$this->logger->info(
			'Running {modelCount} model check(s) for revision {revisionId}',
			[
				'modelCount' => count( $modelsToRun ),
				'revisionId' => $revisionId,
			]
		);

		foreach ( $modelsToRun as $modelToRun ) {
			$this->runModelCheck( $modelToRun, $revisionRecord );
		}

		return true;
	}

	private function loadRevision( int $revisionId ): ?RevisionRecord {
		$revisionRecord = $this->revisionLookup->getRevisionById( $revisionId );
		if ( !$revisionRecord ) {
			$revisionRecord = $this->revisionLookup->getRevisionById( $revisionId, IDBAccessObject::READ_LATEST );
		}

		return $revisionRecord;
	}

	private function runModelCheck( ModelToRun $modelToRun, RevisionRecord $revisionRecord ): void {
		$response = $this->contentPolicyEvaluator->evaluateCoPEModel(
			$modelToRun->getPolicyText(),
			$modelToRun->getModelName(),
			$modelToRun->getContent()
		);
		if ( !$response ) {
			// The evaluator already logs why it could not produce a response, so no need to double log
			return;
		}

		$tagsToAdd = $this->collectActionsToTake( $modelToRun, $revisionRecord, $response )->getTagsToAdd();
		if ( !$tagsToAdd ) {
			return;
		}

		$tagsEffectivelyAdded = $this->applyTags( $modelToRun, $revisionRecord, $tagsToAdd );
		$this->notifyWhenPersonalInfoFlagged( $tagsEffectivelyAdded, $revisionRecord );
	}

	private function collectActionsToTake(
		ModelToRun $modelToRun,
		RevisionRecord $revisionRecord,
		CoPEModelResponse $response
	): ActionsToTake {
		$actionsToTake = new ActionsToTake();
		$this->hookRunner->onWikimediaAntiAbuseModelResult(
			$modelToRun,
			$revisionRecord,
			$response,
			$actionsToTake
		);

		return $actionsToTake;
	}

	/**
	 * @return string[] The tags this call effectively added.
	 */
	private function applyTags( ModelToRun $modelToRun, RevisionRecord $revisionRecord, array $tagsToAdd ): array {
		$recentChangeId = null;
		$revisionId = $revisionRecord->getId();
		$logId = null;
		[ $tagsEffectivelyAdded ] = $this->changeTagsStore->updateTags(
			$tagsToAdd, null, $recentChangeId, $revisionId, $logId
		);
		$this->logger->info(
			'Applied change tags after model check',
			[
				'modelName' => $modelToRun->getModelName(),
				'revisionId' => $revisionRecord->getId(),
				'tags' => $tagsToAdd,
			]
		);

		return $tagsEffectivelyAdded;
	}

	/**
	 * @param string[] $tagsEffectivelyAdded
	 */
	private function notifyWhenPersonalInfoFlagged(
		array $tagsEffectivelyAdded,
		RevisionRecord $revisionRecord
	): void {
		// Notify only for tags this call actually added, so a retry re-running the batch does not re-notify.
		if ( in_array( ChangeTagsHandler::PERSONAL_INFO_TAG, $tagsEffectivelyAdded, true ) ) {
			$this->personalInfoFlagNotifier->notify( $revisionRecord );
		}
	}
}

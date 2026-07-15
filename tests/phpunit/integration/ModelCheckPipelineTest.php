<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration;

use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\IModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\PageSaveCompleteHandler
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob
 * @group Database
 */
class ModelCheckPipelineTest extends MediaWikiIntegrationTestCase {

	public function testEditToTagPipeline(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );

		$this->setTemporaryHook(
			'WikimediaAntiAbuseGetModelsToRun',
			static function ( RevisionRecord $revisionRecord, array &$modelsToRun ): void {
				$modelsToRun[] = new ModelToRun( 'test-model', 'Test policy text', 'test content' );
			}
		);

		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->method( 'evaluateCoPEModel' )
			->with( 'Test policy text', 'test-model', 'test content' )
			->willReturn( new CoPEModelResponse( [ 'test-key' => 'test-value' ] ) );
		$this->setService( 'WikimediaAntiAbuseContentPolicyEvaluator', $evaluator );

		$this->setTemporaryHook(
			'WikimediaAntiAbuseModelResult',
			static function (
				ModelToRun $modelToRun,
				RevisionRecord $revisionRecord,
				IModelResponse $response,
				ActionsToTake $actionsToTake
			): void {
				$actionsToTake->addTags( [ 'test-tag-name' ] );
			}
		);

		$revisionId = $this->editPage( 'WikimediaAntiAbuse pipeline page', 'test content' )
			->getNewRevision()
			->getId();

		$this->runJobs();

		$tags = $this->getServiceContainer()->getChangeTagsStore()->getTags( $this->getDb(), null, $revisionId );
		$this->assertSame( [ 'test-tag-name' ], $tags,
			'The save-hook-job-client-hook pipeline should apply the tag to the saved revision' );
	}
}

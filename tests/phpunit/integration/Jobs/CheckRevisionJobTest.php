<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Jobs;

use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ActionsToTake;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\IModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ModelToRun;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob
 * @group Database
 */
class CheckRevisionJobTest extends MediaWikiIntegrationTestCase {

	public function testAppliesTagsFromModelResult(): void {
		$revisionId = $this->createRevisionId();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
		$this->setModelToRunHook();
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

		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->expects( $this->once() )
			->method( 'evaluateCoPEModel' )
			->with( 'Test policy text', 'test-model', 'test content' )
			->willReturn( new CoPEModelResponse( [ 'test-key' => 'test-value' ] ) );

		$this->assertTrue( $this->newJob( $revisionId, $evaluator )->run() );
		$this->assertRevisionTags( [ 'test-tag-name' ], $revisionId,
			'The tag registered by the model-result hook should be applied to the revision' );
	}

	public function testNullResponseSkipsModelResultHook(): void {
		$revisionId = $this->createRevisionId();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
		$this->setModelToRunHook();

		$modelResultFired = false;
		$this->setTemporaryHook(
			'WikimediaAntiAbuseModelResult',
			static function () use ( &$modelResultFired ): void {
				$modelResultFired = true;
			}
		);

		$evaluator = $this->newEvaluatorReturning( null );

		$this->assertTrue( $this->newJob( $revisionId, $evaluator )->run() );
		$this->assertFalse( $modelResultFired,
			'The model-result hook must not fire when the evaluator returns null' );
		$this->assertRevisionTags( [], $revisionId, 'No tag should be applied when the evaluator returns null' );
	}

	public function testReplicaLagFallbackLoadsFromPrimary(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
		$this->setModelToRunHook();

		$revisionRecord = $this->createMock( RevisionRecord::class );

		$calls = [];
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->exactly( 2 ) )
			->method( 'getRevisionById' )
			->willReturnCallback(
				static function ( int $id, int $flags = IDBAccessObject::READ_NORMAL ) use (
					&$calls,
					$revisionRecord
				): RevisionRecord|null {
					$calls[] = [ $id, $flags ];

					return $flags === IDBAccessObject::READ_LATEST ? $revisionRecord : null;
				}
			);

		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->expects( $this->once() )
			->method( 'evaluateCoPEModel' )
			->with( 'Test policy text', 'test-model', 'test content' )
			->willReturn( null );

		$this->assertTrue( $this->newJob( 123, $evaluator, $revisionLookup )->run() );
		$this->assertSame(
			[ [ 123, IDBAccessObject::READ_NORMAL ], [ 123, IDBAccessObject::READ_LATEST ] ],
			$calls,
			'The job must query the replica first, then fall back to the primary with READ_LATEST'
		);
	}

	public function testModelResultAddingNoTagsWritesNoChangeTag(): void {
		$revisionId = $this->createRevisionId();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
		$this->setModelToRunHook();

		$modelResultFired = false;
		$this->setTemporaryHook(
			'WikimediaAntiAbuseModelResult',
			static function () use ( &$modelResultFired ): void {
				$modelResultFired = true;
			}
		);

		$evaluator = $this->newEvaluatorReturning( new CoPEModelResponse( [ 'test-key' => 'test-value' ] ) );

		$this->assertTrue( $this->newJob( $revisionId, $evaluator )->run() );
		$this->assertTrue( $modelResultFired,
			'The model-result hook should fire when the evaluator returns a response' );
		$this->assertRevisionTags( [], $revisionId,
			'No change_tag row should be written when the model-result hook adds no tags' );
	}

	public function testRunsEachModelIndependently(): void {
		$revisionId = $this->createRevisionId();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
		$this->setTemporaryHook(
			'WikimediaAntiAbuseGetModelsToRun',
			static function ( RevisionRecord $revisionRecord, array &$modelsToRun ): void {
				$modelsToRun[] = new ModelToRun( 'test-model-one', 'Test policy text', 'test content' );
				$modelsToRun[] = new ModelToRun( 'test-model-two', 'Test policy text', 'test content' );
			}
		);

		$modelResultCalls = [];
		$this->setTemporaryHook(
			'WikimediaAntiAbuseModelResult',
			static function (
				ModelToRun $modelToRun,
				RevisionRecord $revisionRecord,
				IModelResponse $response,
				ActionsToTake $actionsToTake
			) use ( &$modelResultCalls ): void {
				$modelResultCalls[] = $modelToRun->getModelName();
				if ( $modelToRun->getModelName() === 'test-model-one' ) {
					$actionsToTake->addTags( [ 'test-tag-name' ] );
				}
			}
		);

		$checkedModels = [];
		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->expects( $this->exactly( 2 ) )
			->method( 'evaluateCoPEModel' )
			->willReturnCallback(
				static function (
					string $contentPolicy,
					string $contentPolicyName
				) use ( &$checkedModels ): CoPEModelResponse {
					$checkedModels[] = $contentPolicyName;

					return new CoPEModelResponse( [ 'test-key' => 'test-value' ] );
				}
			);

		$this->assertTrue( $this->newJob( $revisionId, $evaluator )->run() );
		$this->assertSame( [ 'test-model-one', 'test-model-two' ], $checkedModels,
			'The evaluator must be called once per model, in order' );
		$this->assertSame( [ 'test-model-one', 'test-model-two' ], $modelResultCalls,
			'The model-result hook must fire once per model, in order' );
		$this->assertRevisionTags( [ 'test-tag-name' ], $revisionId,
			'Only the tag registered for test-model-one should be applied' );
	}

	/** @dataProvider provideEarlyExit */
	public function testEarlyExitDoesNotCallEvaluator(
		bool $flagEnabled,
		bool $revisionExists,
		bool $expectGetModelsToRunFired
	): void {
		$revisionId = $revisionExists ? $this->createRevisionId() : 999999999;

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', $flagEnabled );

		$getModelsToRunFired = false;
		$this->setTemporaryHook(
			'WikimediaAntiAbuseGetModelsToRun',
			static function () use ( &$getModelsToRunFired ): void {
				$getModelsToRunFired = true;
			}
		);

		$this->assertTrue( $this->newJob( $revisionId, $this->newNeverCalledEvaluator() )->run() );
		$this->assertSame( $expectGetModelsToRunFired, $getModelsToRunFired,
			'GetModelsToRun hook firing must match the control-flow expectation' );
		$this->assertRevisionTags( [], $revisionId,
			'No change_tag rows should be written when the pipeline stops early' );
	}

	public static function provideEarlyExit(): array {
		return [
			'feature flag disabled' => [
				'flagEnabled' => false,
				'revisionExists' => true,
				'expectGetModelsToRunFired' => false,
			],
			'nonexistent revision id' => [
				'flagEnabled' => true,
				'revisionExists' => false,
				'expectGetModelsToRunFired' => false,
			],
			'hook appends no models' => [
				'flagEnabled' => true,
				'revisionExists' => true,
				'expectGetModelsToRunFired' => true,
			],
		];
	}

	public function testNotFoundRevisionDoesNotCallEvaluator(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );

		$getModelsToRunFired = false;
		$this->setTemporaryHook(
			'WikimediaAntiAbuseGetModelsToRun',
			static function () use ( &$getModelsToRunFired ): void {
				$getModelsToRunFired = true;
			}
		);

		$calls = [];
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->exactly( 2 ) )
			->method( 'getRevisionById' )
			->willReturnCallback(
				static function (
					int $id,
					int $flags = IDBAccessObject::READ_NORMAL
				) use ( &$calls ): ?RevisionRecord {
					$calls[] = [ $id, $flags ];

					return null;
				}
			);

		$this->assertTrue( $this->newJob( 123, $this->newNeverCalledEvaluator(), $revisionLookup )->run() );
		$this->assertFalse( $getModelsToRunFired,
			'The get-models-to-run hook must not fire when the revision cannot be found' );
		$this->assertSame(
			[ [ 123, IDBAccessObject::READ_NORMAL ], [ 123, IDBAccessObject::READ_LATEST ] ],
			$calls,
			'The job must query the replica first and fall back to the primary with READ_LATEST'
		);
		$this->assertRevisionTags( [], 123, 'No change_tag rows should be written when the revision cannot be found' );
	}

	private function createRevisionId(): int {
		return $this->editPage( 'WikimediaAntiAbuse test page', 'test content' )
			->getNewRevision()
			->getId();
	}

	private function setModelToRunHook(): void {
		$this->setTemporaryHook(
			'WikimediaAntiAbuseGetModelsToRun',
			static function ( RevisionRecord $revisionRecord, array &$modelsToRun ): void {
				$modelsToRun[] = new ModelToRun( 'test-model', 'Test policy text', 'test content' );
			}
		);
	}

	private function newNeverCalledEvaluator(): ContentPolicyEvaluator {
		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->expects( $this->never() )
			->method( 'evaluateCoPEModel' );

		return $evaluator;
	}

	private function newEvaluatorReturning( ?CoPEModelResponse $response ): ContentPolicyEvaluator {
		$evaluator = $this->createMock( ContentPolicyEvaluator::class );
		$evaluator->expects( $this->once() )
			->method( 'evaluateCoPEModel' )
			->willReturn( $response );

		return $evaluator;
	}

	private function assertRevisionTags( array $expectedTags, int $revisionId, string $message ): void {
		$this->assertSame(
			$expectedTags,
			$this->getServiceContainer()->getChangeTagsStore()->getTags( $this->getDb(), null, $revisionId ),
			$message
		);
	}

	private function newJob(
		int $revisionId,
		ContentPolicyEvaluator $evaluator,
		?RevisionLookup $revisionLookup = null
	): CheckRevisionJob {
		$services = $this->getServiceContainer();

		return new CheckRevisionJob(
			[ 'revisionId' => $revisionId ],
			$services->getMainConfig(),
			$revisionLookup ?? $services->getRevisionLookup(),
			$services->get( 'WikimediaAntiAbuseHookRunner' ),
			$evaluator,
			$services->getChangeTagsStore(),
			new NullLogger()
		);
	}
}

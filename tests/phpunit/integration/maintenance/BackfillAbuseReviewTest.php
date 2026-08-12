<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Maintenance;

use MediaWiki\Extension\WikimediaAntiAbuse\Maintenance\BackfillAbuseReview;
use MediaWiki\JobQueue\JobQueue;
use MediaWiki\JobQueue\RunnableJob;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Maintenance\BackfillAbuseReview
 * @group Database
 */
class BackfillAbuseReviewTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return BackfillAbuseReview::class;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', true );
	}

	/** @dataProvider provideExecuteForFatalError */
	public function testExecuteForFatalError( array $options, string $expectedOutputRegex ): void {
		foreach ( $options as $name => $value ) {
			$this->maintenance->setOption( $name, $value );
		}
		$this->expectCallToFatalError();
		$this->expectOutputRegex( $expectedOutputRegex );
		$this->maintenance->execute();
	}

	public static function provideExecuteForFatalError(): array {
		return [
			'--start-timestamp is invalid' => [
				'options' => [ 'start-timestamp' => 'invalid' ],
				'expectedOutputRegex' => '/Invalid start timestamp/',
			],
			'--end-timestamp is invalid' => [
				'options' => [ 'start-timestamp' => '20260102030405', 'end-timestamp' => 'invalid' ],
				'expectedOutputRegex' => '/Invalid end timestamp/',
			],
		];
	}

	public function testExecuteWhenModelChecksDisabled(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnableModelChecks', false );
		$this->testExecuteForFatalError(
			[
				'start-timestamp' => '20260102030405',
				'end-timestamp' => '20260102030406',
			],
			'/Model checks must be enabled/'
		);
	}

	public function testExecuteWhenNoRevisionsToProcess(): void {
		$this->maintenance->setOption( 'start-timestamp', '20260102030405' );
		$this->maintenance->setOption( 'end-timestamp', '20260102030406' );
		$this->expectOutputRegex(
			'/Backfilling Special:AbuseReview by evaluating revisions between 20260102030405 and 20260102030406' .
			'[\s\S]*Backfill complete\. Total jobs pushed: 0/'
		);
		$this->maintenance->execute();

		$this->checkRevisionJobsExist( [] );
	}

	/**
	 * Get the {@link JobQueue} that holds {@link CheckRevisionJob} jobs.
	 */
	private function getJobQueue(): JobQueue {
		return $this->getServiceContainer()->getJobQueueGroup()->get( 'wikimediaAntiAbuseCheckRevision' );
	}

	/**
	 * Clear the {@link CheckRevisionJob} jobs from the job queue to remove the jobs added
	 * by making the edits, so we can test the maintenance script adds them.
	 */
	private function clearJobQueue(): void {
		$this->getJobQueue()->delete();
	}

	/**
	 * Checks the the job queue for {@link CheckRevisionJob} jobs only has jobs for the expected revision IDs.
	 */
	private function checkRevisionJobsExist( array $expectedRevisionIds ): void {
		$actualRevisionIds = array_map(
			static fn ( RunnableJob $job ) => $job->getParams()['revisionId'],
			iterator_to_array( $this->getJobQueue()->getAllQueuedJobs() )
		);
		$this->assertArrayEquals( $expectedRevisionIds, $actualRevisionIds );
	}

	public function testExecuteWhenRevisionToProcess(): void {
		ConvertibleTimestamp::setFakeTime( '20260801010101' );
		$firstEditStatus = $this->editPage( 'First test page', 'Test content' );
		$this->assertStatusGood( $firstEditStatus );

		ConvertibleTimestamp::setFakeTime( '20260801010201' );
		$secondEditStatus = $this->editPage( 'Second test page', 'Test content' );
		$this->assertStatusGood( $secondEditStatus );

		ConvertibleTimestamp::setFakeTime( false );
		$this->clearJobQueue();

		$this->maintenance->setOption( 'start-timestamp', '20260801010101' );
		$this->maintenance->setOption( 'end-timestamp', '20260801010140' );
		$this->maintenance->setOption( 'sleep', 0 );
		$this->expectOutputRegex(
			'/Backfilling Special:AbuseReview by evaluating revisions between 20260801010101 and 20260801010140' .
			'[\s\S]*Pushed 1 jobs to the queue. Processed to timestamp: 20260801010101. Total jobs pushed: 1' .
			'[\s\S]*Backfill complete\. Total jobs pushed: 1/'
		);
		$this->maintenance->execute();

		$this->checkRevisionJobsExist( [ $firstEditStatus->getNewRevision()->getId() ] );
	}

	public function testExecuteWhenMultipleBatchesToProcess(): void {
		ConvertibleTimestamp::setFakeTime( '20260801010101' );
		$firstEditStatus = $this->editPage( 'First test page', 'Test content' );
		$this->assertStatusGood( $firstEditStatus );

		ConvertibleTimestamp::setFakeTime( '20260801010201' );
		$secondEditStatus = $this->editPage( 'Second test page', 'Test content' );
		$this->assertStatusGood( $secondEditStatus );

		ConvertibleTimestamp::setFakeTime( '20260801010301' );
		$thirdEditStatus = $this->editPage( 'Third test page', 'Test content' );
		$this->assertStatusGood( $thirdEditStatus );

		ConvertibleTimestamp::setFakeTime( false );
		$this->clearJobQueue();

		$this->maintenance->loadWithArgv( [
			'--batch-size', '2',
			'--start-timestamp', '20260801010101',
			'--end-timestamp', '20260801010401',
			'--sleep', '0',
		] );
		$this->expectOutputRegex(
			'/Backfilling Special:AbuseReview by evaluating revisions between 20260801010101 and 20260801010401' .
			'[\s\S]*Pushed 2 jobs to the queue. Processed to timestamp: 20260801010201. Total jobs pushed: 2' .
			'[\s\S]*Pushed 1 jobs to the queue. Processed to timestamp: 20260801010301. Total jobs pushed: 3' .
			'[\s\S]*Backfill complete\. Total jobs pushed: 3/'
		);
		$this->maintenance->execute();

		$this->checkRevisionJobsExist( [
			$firstEditStatus->getNewRevision()->getId(),
			$secondEditStatus->getNewRevision()->getId(),
			$thirdEditStatus->getNewRevision()->getId(),
		] );
	}
}

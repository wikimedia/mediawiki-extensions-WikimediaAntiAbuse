<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Maintenance;

use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class BackfillAbuseReview extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Backfills Special:AbuseReview by evaluating revisions betwen specified timestamps' );
		$this->addOption(
			'start-timestamp',
			'The start timestamp for the revisions to evaluate in MediaWiki timestamp format (e.g. 20260102030405)',
			true,
			true
		);
		$this->addOption(
			'end-timestamp',
			'The end timestamp for the revisions to evaluate in MediaWiki timestamp format (e.g. 20260102030405)',
			true,
			true
		);
		$this->addOption( 'sleep', 'The number of seconds to sleep between batches (default 1 second)', false, true );
		$this->requireExtension( 'WikimediaAntiAbuse' );
		$this->setBatchSize( 200 );
	}

	public function execute(): void {
		$startTimestamp = ConvertibleTimestamp::convert(
			TimestampFormat::MW,
			$this->getOption( 'start-timestamp', '' )
		);
		if ( !$startTimestamp ) {
			$this->fatalError(
				'Invalid start timestamp, please provide the timestamp in MediaWiki format (e.g. 20260102030405)'
			);
		}

		$endTimestamp = ConvertibleTimestamp::convert(
			TimestampFormat::MW,
			$this->getOption( 'end-timestamp', '' )
		);
		if ( !$endTimestamp ) {
			$this->fatalError(
				'Invalid end timestamp, please provide the timestamp in MediaWiki format (e.g. 20260102030405)'
			);
		}

		if ( !$this->getServiceContainer()->getMainConfig()->get( 'WikimediaAntiAbuseEnableModelChecks' ) ) {
			$this->fatalError( 'Model checks must be enabled to run the backfill script.' );
		}

		$this->output(
			'Backfilling Special:AbuseReview by evaluating revisions between ' . $startTimestamp . ' and ' .
			$endTimestamp . '...' . PHP_EOL
		);

		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$dbr = $this->getReplicaDB();

		$batchStartRevisionId = null;
		$batchStartRevisionTimestamp = $startTimestamp;
		$jobsPushed = 0;
		do {
			if ( $batchStartRevisionId ) {
				$lowerBoundExpr = $dbr->buildComparison(
					'>',
					[
						'rev_timestamp' => $dbr->timestamp( $batchStartRevisionTimestamp ),
						'rev_id' => $batchStartRevisionId,
					]
				);
			} else {
				$lowerBoundExpr = $dbr->expr( 'rev_timestamp', '>=', $dbr->timestamp( $batchStartRevisionTimestamp ) );
			}
			$revisionsToEvaluate = $dbr->newSelectQueryBuilder()
				->select( [ 'rev_id', 'rev_timestamp' ] )
				->from( 'revision' )
				->where( $lowerBoundExpr )
				->andWhere( $dbr->expr( 'rev_timestamp', '<=', $dbr->timestamp( $endTimestamp ) ) )
				->orderBy( [ 'rev_timestamp', 'rev_id' ], SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() ?? 200 )
				->caller( __METHOD__ )
				->fetchResultSet();

			$jobsToPush = [];
			foreach ( $revisionsToEvaluate as $revisionRow ) {
				$jobsToPush[] = CheckRevisionJob::newSpec( (int)$revisionRow->rev_id );
			}
			$jobQueueGroup->push( $jobsToPush );
			$jobsPushed += count( $jobsToPush );

			if ( count( $jobsToPush ) ) {
				sleep( intval( $this->getOption( 'sleep', 1 ) ) );

				$revisionsToEvaluate->seek( $revisionsToEvaluate->count() - 1 );
				$lastRevisionRow = $revisionsToEvaluate->fetchObject();
				$batchStartRevisionTimestamp = $lastRevisionRow->rev_timestamp;
				$batchStartRevisionId = (int)$lastRevisionRow->rev_id;
				$this->output(
					'Pushed ' . count( $jobsToPush ) . " jobs to the queue. Processed to timestamp:" .
					" $batchStartRevisionTimestamp. Total jobs pushed: $jobsPushed" . PHP_EOL
				);
			}
		} while ( count( $revisionsToEvaluate ) !== 0 );

		$this->output( 'Backfill complete. Total jobs pushed: ' . $jobsPushed . PHP_EOL );
	}
}

// @codeCoverageIgnoreStart
$maintClass = BackfillAbuseReview::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd

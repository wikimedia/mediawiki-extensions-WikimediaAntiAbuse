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

		$revisionStore = $this->getServiceContainer()->getRevisionStore();
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$dbr = $this->getReplicaDB();

		$batchStartRevisionId = null;
		$jobsPushed = 0;
		do {
			$revisionIdsQueryBuilder = $revisionStore->newSelectQueryBuilder( $dbr )
				->clearFields()
				->select( 'rev_id' )
				->where( $dbr->expr( 'rev_timestamp', '>=', $dbr->timestamp( $startTimestamp ) ) )
				->andWhere( $dbr->expr( 'rev_timestamp', '<=', $dbr->timestamp( $endTimestamp ) ) )
				->orderBy( 'rev_id', SelectQueryBuilder::SORT_ASC )
				->limit( $this->getBatchSize() ?? 200 );
			if ( $batchStartRevisionId ) {
				$revisionIdsQueryBuilder->andWhere( $dbr->expr( 'rev_id', '>', $batchStartRevisionId ) );
			}
			$revisionIdsToEvaluate = $revisionIdsQueryBuilder
				->caller( __METHOD__ )
				->fetchFieldValues();

			$jobsToPush = [];
			foreach ( $revisionIdsToEvaluate as $revisionId ) {
				$jobsToPush[] = CheckRevisionJob::newSpec( (int)$revisionId );
			}
			$jobQueueGroup->push( $jobsToPush );
			$jobsPushed += count( $jobsToPush );

			if ( count( $jobsToPush ) ) {
				sleep( intval( $this->getOption( 'sleep', 1 ) ) );
				$batchStartRevisionId = (int)end( $revisionIdsToEvaluate );
				$this->output(
					'Pushed ' . count( $jobsToPush ) . " jobs to the queue. Total jobs pushed: $jobsPushed" . PHP_EOL
				);
			}
		} while ( count( $revisionIdsToEvaluate ) !== 0 );

		$this->output( 'Backfill complete. Total jobs pushed: ' . $jobsPushed . PHP_EOL );
	}
}

// @codeCoverageIgnoreStart
$maintClass = BackfillAbuseReview::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd

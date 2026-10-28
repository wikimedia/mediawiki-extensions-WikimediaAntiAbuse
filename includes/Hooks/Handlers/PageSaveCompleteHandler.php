<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Deferred\Hook\LinksUpdateCompleteHook;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\IConnectionProvider;

class PageSaveCompleteHandler implements PageSaveCompleteHook, LinksUpdateCompleteHook {

	/**
	 * @var array<int,true> The IDs of the revisions saved in this request.
	 */
	private array $savedRevisionIds = [];

	public function __construct(
		private readonly Config $config,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableModelChecks' ) ) {
			return;
		}

		if ( $editResult->isNullEdit() ) {
			return;
		}

		$revisionId = $revisionRecord->getId();
		if ( $revisionId === null ) {
			return;
		}

		if ( $this->config->get( 'WikimediaAntiAbuseExternalLinkContentPolicies' ) ) {
			$this->savedRevisionIds[$revisionId] = true;
		}

		$this->queueAfterCommit( CheckRevisionJob::newSpec( $revisionId ) );
	}

	/** @inheritDoc */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ): void {
		$revisionRecord = $linksUpdate->getRevisionRecord();
		if ( !$revisionRecord ) {
			return;
		}

		// Do not score any revisions that were not saved in this request.
		$revisionId = $revisionRecord->getId();
		if ( !isset( $this->savedRevisionIds[$revisionId] ) ) {
			return;
		}

		// Ensure the same revision is not scored twice.
		unset( $this->savedRevisionIds[$revisionId] );

		if ( !$linksUpdate->getAddedExternalLinks() ) {
			return;
		}

		$this->queueAfterCommit( CheckRevisionJob::newExternalLinkSpec( $revisionId ) );
	}

	/**
	 * Queues only after the revision's writes commit, so a rollback cannot leave a job
	 * for a missing revision.
	 */
	private function queueAfterCommit( IJobSpecification $jobSpecification ): void {
		$this->connectionProvider->getPrimaryDatabase()->onTransactionCommitOrIdle(
			function () use ( $jobSpecification ) {
				$this->jobQueueGroup->lazyPush( $jobSpecification );
			},
			__METHOD__
		);
	}
}

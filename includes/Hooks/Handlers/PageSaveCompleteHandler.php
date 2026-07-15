<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CheckRevisionJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\IConnectionProvider;

class PageSaveCompleteHandler implements PageSaveCompleteHook {

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

		// Queue only after the revision's writes commit, so a rollback can't leave a job for a missing revision.
		$this->connectionProvider->getPrimaryDatabase()->onTransactionCommitOrIdle(
			function () use ( $revisionId ) {
				$this->jobQueueGroup->lazyPush( CheckRevisionJob::newSpec( $revisionId ) );
			},
			__METHOD__
		);
	}
}

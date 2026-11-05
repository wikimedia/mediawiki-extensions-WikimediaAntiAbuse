<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\Hook\ChangeTagsAfterUpdateTagsHook;
use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob;
use MediaWiki\JobQueue\JobQueueGroup;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Queues an update of the case of the author in the Flagged accounts queue when a revision gets a flag.
 */
class AccountCaseTagsHandler implements ChangeTagsAfterUpdateTagsHook {

	public function __construct(
		private readonly Config $config,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/** @inheritDoc */
	public function onChangeTagsAfterUpdateTags(
		$addedTags,
		$removedTags,
		$prevTags,
		$rc_id,
		$rev_id,
		$log_id,
		$params,
		$rc,
		$user
	): void {
		if (
			!$rev_id ||
			!$this->config->get( 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' ) ||
			!array_intersect( $addedTags, array_keys( ChangeTagsHandler::REVIEWABLE_TAGS ) )
		) {
			return;
		}

		// The job counts the new flag, so queue it only after the flag is committed.
		$this->connectionProvider->getPrimaryDatabase()->onTransactionCommitOrIdle(
			function () use ( $rev_id ) {
				$this->jobQueueGroup->lazyPush( RecountFlaggedEditsJob::newSpec( (int)$rev_id ) );
			},
			__METHOD__
		);
	}
}

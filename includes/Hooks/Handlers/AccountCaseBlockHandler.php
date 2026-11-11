<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Specials\Hook\BlockIpCompleteHook;
use Psr\Log\LoggerInterface;

/**
 * Queues closing an account's Flagged accounts case when the account gets an indefinite sitewide block.
 */
class AccountCaseBlockHandler implements BlockIpCompleteHook {

	public function __construct(
		private readonly Config $config,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @inheritDoc */
	public function onBlockIpComplete( $block, $user, $priorBlock ): void {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' ) ) {
			return;
		}

		$blockedUser = $block->getTargetUserIdentity();
		if ( !$blockedUser || !$blockedUser->isRegistered() || !$block->isSitewide() || !$block->isIndefinite() ) {
			return;
		}

		$userId = $blockedUser->getId();
		$delayedJobsEnabled = $this->jobQueueGroup->get( CloseBlockedAccountCaseJob::TYPE )->delayedJobsEnabled();
		if ( !$delayedJobsEnabled ) {
			$this->logger->warning(
				'Queueing the close of the Flagged accounts case of user {userId} without a delay: ' .
				'the job queue does not support delayed jobs',
				[ 'userId' => $userId ]
			);
		}
		$this->jobQueueGroup->lazyPush( CloseBlockedAccountCaseJob::newSpec( $userId, $delayedJobsEnabled ) );
	}
}

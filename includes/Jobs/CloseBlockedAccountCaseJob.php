<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountBlockLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\User\UserIdentityLookup;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Closes an account's open Flagged accounts case if the account still has an indefinite sitewide block.
 */
class CloseBlockedAccountCaseJob extends Job {

	public const string TYPE = 'wikimediaAntiAbuseCloseBlockedAccountCase';

	/** Delay the job to give time for a user to manually close the abuse review case */
	private const int DELAY_SECONDS = 3600;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly AbuseReviewAccountCaseLookup $caseLookup,
		private readonly AbuseReviewAccountCaseManager $caseManager,
		private readonly AbuseReviewAccountBlockLookup $blockLookup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct( self::TYPE, $params );
	}

	public static function newSpec( int $userId, bool $delayedJobsEnabled ): IJobSpecification {
		// The account can be renamed before the job runs.
		$params = [ 'userId' => $userId ];
		if ( $delayedJobsEnabled ) {
			$params['jobReleaseTimestamp'] = ConvertibleTimestamp::time() + self::DELAY_SECONDS;
		}

		return new JobSpecification( self::TYPE, $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		$userId = (int)$this->params['userId'];
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' ) ) {
			$this->logger->debug(
				'Not closing the Flagged accounts case of user {userId}: the queue is disabled',
				[ 'userId' => $userId ]
			);

			return true;
		}

		$user = $this->userIdentityLookup->getUserIdentityByUserId( $userId );
		if ( !$user ) {
			$this->logger->warning(
				'Not closing the Flagged accounts case of user {userId}: the user does not exist',
				[ 'userId' => $userId ]
			);

			return true;
		}

		$case = $this->caseLookup->getCase( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS );
		if ( !$case || $case->state !== AbuseReviewCaseState::OPEN ) {
			$this->logger->debug(
				'Not closing the Flagged accounts case of user {userId}: no open case',
				[ 'userId' => $userId ]
			);

			return true;
		}

		if ( !$this->blockLookup->isIndefinitelyBlocked( $user ) ) {
			$this->logger->info(
				'Not closing the Flagged accounts case of user {userId}: no indefinite block',
				[ 'userId' => $userId, 'caseId' => $case->id ]
			);

			return true;
		}

		$this->caseManager->closeCase( $case );

		return true;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCase;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewCaseState;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\FlaggedAccountCaseProperties;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter;
use MediaWiki\JobQueue\IJobSpecification;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Counts the flags of the author of a flagged revision, and opens or updates the case of the author in the
 * Flagged accounts queue.
 */
class RecountFlaggedEditsJob extends Job {

	public const string TYPE = 'wikimediaAntiAbuseRecountFlaggedEdits';

	/** The number of flagged edits that an account needs to open or update its case. */
	private const int FLAGGED_EDIT_THRESHOLD = 3;

	/** Flags on edits older than this number of days do not count. */
	private const int MAX_FLAGGED_EDIT_AGE_DAYS = 30;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly RevisionLookup $revisionLookup,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly AbuseReviewEnabledTagsProvider $enabledTagsProvider,
		private readonly FlaggedEditCounter $flaggedEditCounter,
		private readonly AbuseReviewAccountCaseLookup $caseLookup,
		private readonly AbuseReviewAccountCaseManager $caseManager,
		private readonly ILBFactory $lbFactory,
	) {
		parent::__construct( self::TYPE, $params );
	}

	public static function newSpec( int $revisionId ): IJobSpecification {
		return new JobSpecification( self::TYPE, [ 'revisionId' => $revisionId ] );
	}

	/** @inheritDoc */
	public function run(): bool {
		if ( !$this->config->get( 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' ) ) {
			return true;
		}

		// The job reads the new flag and the case from replicas, so wait until the replicas have every change.
		if ( !$this->waitForReplicas() ) {
			$this->setLastError( 'Timed out while waiting for the replicas to catch up' );

			return false;
		}

		$user = $this->getRegisteredAuthor();
		if ( !$user ) {
			return true;
		}

		$case = $this->caseLookup->getCase( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS );

		$storedProperties = [];
		if ( $case?->state === AbuseReviewCaseState::OPEN ) {
			$storedProperties = $this->caseLookup->getCaseProperties( $case->id );
		}

		$countStart = $this->getCountStart( $case, $storedProperties );
		$counts = $this->countFlaggedEdits( $user, $countStart );
		if ( $counts === null ) {
			return true;
		}

		$properties = $this->buildCountProperties( $counts );
		// The write moves the case to the top of the queue, so write only when a count changed.
		if ( array_diff_assoc( $properties, $storedProperties ) ) {
			$properties[FlaggedAccountCaseProperties::COUNT_START_TIMESTAMP] = (int)$countStart;
			$this->writeCase( $user, $case, $properties );
		}

		return true;
	}

	/**
	 * Waits until the replica of the wiki database and the replica of the case tables have every change.
	 * Returns false on a timeout.
	 */
	private function waitForReplicas(): bool {
		$wikiLoadBalancer = $this->lbFactory->getMainLB();
		$caseLoadBalancer = $this->lbFactory->getLoadBalancer( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN );
		$caseDomain = $this->lbFactory->getReplicaDatabase( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN )
			->getDomainID();

		return $wikiLoadBalancer->waitForPrimaryPos( $wikiLoadBalancer->getConnection( DB_REPLICA ) )
			&& $caseLoadBalancer->waitForPrimaryPos( $caseLoadBalancer->getConnection( DB_REPLICA, [], $caseDomain ) );
	}

	private function getRegisteredAuthor(): ?UserIdentity {
		$revisionId = (int)$this->params['revisionId'];
		$revisionRecord = $this->revisionLookup->getRevisionById( $revisionId )
			?? $this->archivedRevisionLookup->getArchivedRevisionRecord( null, $revisionId );

		$user = $revisionRecord?->getUser( RevisionRecord::RAW );

		return $user?->isRegistered() ? $user : null;
	}

	/**
	 * Returns the flagged edit count of each flag, or null if the user has too few flagged edits for the queue.
	 */
	private function countFlaggedEdits( UserIdentity $user, string $countStart ): ?array {
		$counts = $this->flaggedEditCounter->getFlaggedEditCountsSince(
			new SimpleAuthority( UserIdentityValue::newAnonymous( '127.0.0.1' ), [ 'deletedhistory' ] ),
			$user,
			$this->enabledTagsProvider->getEnabledReviewableTags(),
			$countStart
		);

		if ( array_sum( $counts ) < self::FLAGGED_EDIT_THRESHOLD ) {
			return null;
		}

		return $counts;
	}

	/**
	 * Returns the TS_MW time from which the flags count: the age limit, or the last decision on the case if later.
	 */
	private function getCountStart( ?AbuseReviewCase $case, array $storedProperties ): string {
		$ageLimit = ConvertibleTimestamp::convert(
			TimestampFormat::MW,
			ConvertibleTimestamp::time() - self::MAX_FLAGGED_EDIT_AGE_DAYS * ExpirationAwareness::TTL_DAY
		);

		if ( $case?->state === AbuseReviewCaseState::HANDLED ) {
			return max( $ageLimit, $case->updatedTimestamp );
		}

		// An open case stores the start of its last count, which is at or after its last decision.
		$lastCountStart = (string)( $storedProperties[FlaggedAccountCaseProperties::COUNT_START_TIMESTAMP] ?? '' );

		return max( $ageLimit, $lastCountStart );
	}

	/**
	 * @param array<string,int> $counts The count for each flag
	 * @return array<string,int> The count for each flag, keyed by property name
	 */
	private function buildCountProperties( array $counts ): array {
		$properties = [];
		foreach ( $counts as $flag => $count ) {
			$properties[FlaggedAccountCaseProperties::FLAGGED_EDIT_COUNT_PREFIX . $flag] = $count;
		}

		return $properties;
	}

	/**
	 * Opens a new case for the user, or opens the existing case again.
	 */
	private function writeCase( UserIdentity $user, ?AbuseReviewCase $case, array $properties ): void {
		if ( $case ) {
			$this->caseManager->reopenCase( $case, $properties );
		} else {
			$this->caseManager->openCase( $user, AbuseReviewAccountQueue::FLAGGED_ACCOUNTS, $properties );
		}
	}
}

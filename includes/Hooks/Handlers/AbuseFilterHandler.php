<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionStatus;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterBuilderHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterCanViewProtectedVariablesHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterCustomProtectedVariablesHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterGenerateUserVarsHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterInterceptVariableHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Wikimedia\ObjectCache\MapCacheLRU;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
// For onAbuseFilter_builder, onAbuseFilter_generateUserVars and onAbuseFilter_interceptVariable

/**
 * Defines AbuseFilter protected variables that describe the recent flagged edits of the user.
 * Only users who can see the flag can see or use the variables of that flag.
 */
class AbuseFilterHandler implements
	AbuseFilterBuilderHook,
	AbuseFilterCanViewProtectedVariablesHook,
	AbuseFilterCustomProtectedVariablesHook,
	AbuseFilterGenerateUserVarsHook,
	AbuseFilterInterceptVariableHook
{

	/** Maps each variable to the flag that the variable describes. */
	private const array FLAG_COUNT_VARIABLES = [
		'abuse_review_user_vandalism_flagged_count' => ChangeTagsHandler::VANDALISM_TAG,
		'abuse_review_user_link_spam_flagged_count' => ChangeTagsHandler::LINK_SPAM_TAG,
	];

	/** Maps each variable to the flag that the variable describes. */
	private const array LAST_FLAGGED_AGE_VARIABLES = [
		'abuse_review_user_last_vandalism_flagged_age' => ChangeTagsHandler::VANDALISM_TAG,
		'abuse_review_user_last_link_spam_flagged_age' => ChangeTagsHandler::LINK_SPAM_TAG,
	];

	/** Holds the flagged edit stats of each user, so the variables of one user share one lookup */
	private MapCacheLRU $statsCache;

	public function __construct(
		private readonly FlaggedEditCounter $flaggedEditCounter,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly AbuseReviewEnabledTagsProvider $enabledTagsProvider,
	) {
		$this->statsCache = new MapCacheLRU( 10 );
	}

	/** @inheritDoc */
	public function onAbuseFilter_builder( array &$realValues ): void {
		foreach ( array_keys( $this->getEnabledVariables() ) as $variable ) {
			// Uses the messages:
			// * abusefilter-edit-builder-vars-abuse-review-user-vandalism-flagged-count
			// * abusefilter-edit-builder-vars-abuse-review-user-link-spam-flagged-count
			// * abusefilter-edit-builder-vars-abuse-review-user-last-vandalism-flagged-age
			// * abusefilter-edit-builder-vars-abuse-review-user-last-link-spam-flagged-age
			$realValues['vars'][$variable] = $this->getMethodForVariable( $variable );
		}
	}

	/** @inheritDoc */
	public function onAbuseFilterCustomProtectedVariables( array &$variables ): void {
		$variables = array_merge( $variables, array_keys( $this->getVariables() ) );
	}

	/**
	 * Only allow users to see variables for flags they can view in Special:AbuseReview.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilterCanViewProtectedVariables(
		Authority $performer, array $variables, AbuseFilterPermissionStatus $status
	): void {
		$flags = array_unique( array_intersect_key( $this->getVariables(), array_fill_keys( $variables, true ) ) );
		foreach ( $flags as $flag ) {
			if ( !$this->changeTagsStore->canViewTag( $flag, $performer ) ) {
				$status->fatal( 'wikimediaantiabuse-abusefilter-protected-variables-access-denied' );
				return;
			}
		}
	}

	/** @inheritDoc */
	public function onAbuseFilter_generateUserVars( VariableHolder $vars, User $user, ?RecentChange $rc ): void {
		foreach ( array_keys( $this->getEnabledVariables() ) as $variable ) {
			$vars->setLazyLoadVar( $variable, $this->getMethodForVariable( $variable ), [ 'user' => $user ] );
		}
	}

	/**
	 * Computes the value of a variable. A count variable is the number of flagged edits in the last
	 * {@link FlaggedEditCounter::MAX_AGE_DAYS} days. An age variable is the number of seconds since
	 * the latest of these edits, or null if there are none.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_interceptVariable(
		string $method,
		VariableHolder $vars,
		array $parameters,
		&$result
	): bool {
		$variable = str_replace( '-', '_', $method );
		$flag = $this->getEnabledVariables()[$variable] ?? null;
		if ( $flag === null ) {
			return true;
		}

		$stats = $this->getFlaggedEditStats( $parameters['user'] )[$flag];

		if ( isset( self::FLAG_COUNT_VARIABLES[$variable] ) ) {
			$result = $stats['count'];
		} elseif ( $stats['latestTimestamp'] === null ) {
			$result = null;
		} else {
			$result = ConvertibleTimestamp::time()
				- (int)ConvertibleTimestamp::convert( TimestampFormat::UNIX, $stats['latestTimestamp'] );
		}
		return false;
	}

	/**
	 * Gets the stats of every enabled flag that has variables.
	 *
	 * Filters run without a viewer, and some users who can see a flag cannot see deleted content
	 * (for example rollbackers). So, count only edits that every user can see.
	 *
	 * @return array<string,array{count:int,latestTimestamp:?string}>
	 */
	private function getFlaggedEditStats( UserIdentity $user ): array {
		return $this->statsCache->getWithSetCallback(
			$user->getName(),
			fn () => $this->flaggedEditCounter->getFlaggedEditStats(
				new SimpleAuthority( $user, [] ),
				$user,
				array_values( array_intersect(
					array_unique( $this->getVariables() ),
					$this->enabledTagsProvider->getEnabledReviewableTags()
				) ),
				null
			)
		);
	}

	/** @return array<string,string> Maps each variable to the flag that the variable describes */
	private function getVariables(): array {
		return self::FLAG_COUNT_VARIABLES + self::LAST_FLAGGED_AGE_VARIABLES;
	}

	/** @return array<string,string> Maps each enabled variable to the flag that the variable describes */
	private function getEnabledVariables(): array {
		$enabledFlags = $this->enabledTagsProvider->getEnabledReviewableTags();
		return array_filter(
			$this->getVariables(),
			static fn ( string $flag ): bool => in_array( $flag, $enabledFlags, true )
		);
	}

	private function getMethodForVariable( string $variable ): string {
		return str_replace( '_', '-', $variable );
	}
}

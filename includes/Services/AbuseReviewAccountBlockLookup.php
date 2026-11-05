<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\GlobalBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalBlocking\Services\GlobalBlockLookup;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserIdentity;

/**
 * Checks the blocks of the accounts in the AbuseReview queues.
 */
class AbuseReviewAccountBlockLookup {

	/**
	 * @param DatabaseBlockStore $blockStore
	 * @param CentralIdLookup $centralIdLookup
	 * @param GlobalBlockLookup|null $globalBlockLookup Null when GlobalBlocking is not installed
	 */
	public function __construct(
		private readonly DatabaseBlockStore $blockStore,
		private readonly CentralIdLookup $centralIdLookup,
		private readonly ?GlobalBlockLookup $globalBlockLookup,
	) {
	}

	/**
	 * Returns the IDs of the users that have an active sitewide block, local or global. Partial blocks do not count.
	 *
	 * @param UserIdentity[] $users
	 * @return int[]
	 */
	public function getBlockedUserIds( array $users ): array {
		if ( !$users ) {
			return [];
		}

		$userIds = [];
		foreach ( $users as $user ) {
			$userIds[] = $user->getId();
		}
		$localBlocks = $this->blockStore->newListFromConds( [ 'bt_user' => $userIds ] );
		$globalBlocks = $this->getGlobalBlocks( $users );
		$blocks = array_merge( $localBlocks, $globalBlocks );

		$blockedUserIds = [];
		foreach ( $blocks as $block ) {
			$blockedUser = $block->getTargetUserIdentity();
			if ( $blockedUser && $block->isSitewide() ) {
				$blockedUserIds[] = $blockedUser->getId();
			}
		}

		return array_values( array_unique( $blockedUserIds ) );
	}

	/**
	 * @param UserIdentity[] $users
	 * @return GlobalBlock[]
	 */
	private function getGlobalBlocks( array $users ): array {
		if ( !$this->globalBlockLookup ) {
			return [];
		}

		$centralIdsByName = [];
		foreach ( $users as $user ) {
			$centralIdsByName[$user->getName()] = 0;
		}
		// Unattached names keep the central ID 0, which the global block lookup skips.
		$centralIdsByName = $this->centralIdLookup->lookupAttachedUserNames(
			$centralIdsByName,
			CentralIdLookup::AUDIENCE_RAW
		);

		return $this->globalBlockLookup->getGlobalBlocksForCentralIds( $centralIdsByName );
	}
}

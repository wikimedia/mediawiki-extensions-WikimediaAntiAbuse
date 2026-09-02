<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\IPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\RevisionDelete\Hook\ArticleRevisionVisibilitySetHook;
use Wikimedia\Rdbms\IConnectionProvider;

class RevisionVisibilityHandler implements ArticleRevisionVisibilitySetHook {

	public function __construct(
		private readonly IPersonalInfoFlagNotificationModerator $notificationModerator,
		private readonly IConnectionProvider $dbProvider,
		private readonly IAbuseReviewInstrumentationClient $instrumentationClient,
	) {
	}

	/**
	 * A suppressed revision no longer needs a reviewer, so hide its notification. A plain
	 * revision-deletion is deliberately ignored: the edit still needs suppression, so the
	 * notification stays. This matches the suppression guard in PersonalInfoFlagNotifier.
	 * To lift a suppression does not bring the notification back, because that is a rare and
	 * deliberate act which follows human review.
	 *
	 * @inheritDoc
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ): void {
		$newlySuppressedRevisionIds = [];
		foreach ( $visibilityChangeMap as $revisionId => $visibilityChange ) {
			$wasSuppressed = $this->isSuppressed( (int)$visibilityChange['oldBits'] );
			$isSuppressed = $this->isSuppressed( (int)$visibilityChange['newBits'] );
			if ( !$wasSuppressed && $isSuppressed ) {
				$newlySuppressedRevisionIds[] = (int)$revisionId;
			}
		}

		if ( !$newlySuppressedRevisionIds ) {
			return;
		}

		$this->notificationModerator->hideForRevisions( $title->getId(), $newlySuppressedRevisionIds );

		$dbr = $this->dbProvider->getReplicaDatabase();
		$revisionsIdsTaggedWithPersonalInfoTag = $dbr->newSelectQueryBuilder()
			->select( 'ct_rev_id' )
			->from( 'change_tag' )
			->join( 'change_tag_def', null, 'ct_tag_id = ctd_id' )
			->where( [ 'ctd_name' => ChangeTagsHandler::PERSONAL_INFO_TAG ] )
			->andWhere( $dbr->expr( 'ct_rev_id', '=', $newlySuppressedRevisionIds ) )
			->caller( __METHOD__ )
			->fetchFieldValues();
		$revisionsIdsTaggedWithPersonalInfoTag = array_map( 'intval', $revisionsIdsTaggedWithPersonalInfoTag );

		foreach ( $newlySuppressedRevisionIds as $revisionId ) {
			$this->instrumentationClient->submitInteraction(
				RequestContext::getMain(),
				'revision_content_suppressed',
				[
					'action_subtype' => in_array( $revisionId, $revisionsIdsTaggedWithPersonalInfoTag, true )
						? 'personal-info-tagged'
						: 'personal-info-not-tagged',
					'identifier' => $revisionId,
					'identifier_type' => 'revision',
				]
			);
		}
	}

	private function isSuppressed( int $bits ): bool {
		return ( $bits & PersonalInfoFlagNotifier::SUPPRESSED_BITS ) === PersonalInfoFlagNotifier::SUPPRESSED_BITS;
	}
}

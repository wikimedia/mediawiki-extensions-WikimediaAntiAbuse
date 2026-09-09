<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\EventSubscribers;

use MediaWiki\Context\RequestContext;
use MediaWiki\DomainEvent\DomainEventIngress;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\IPersonalInfoFlagNotificationModerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagNotifier;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedEvent;
use MediaWiki\Page\Event\PageHistoryVisibilityChangedListener;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class PageHistoryVisibilityEventIngress extends DomainEventIngress
	implements PageHistoryVisibilityChangedListener
{

	public function __construct(
		private readonly IPersonalInfoFlagNotificationModerator $notificationModerator,
		private readonly IConnectionProvider $dbProvider,
		private readonly IAbuseReviewInstrumentationClient $instrumentationClient,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly TitleFactory $titleFactory,
	) {
	}

	public function handlePageHistoryVisibilityChangedEvent( PageHistoryVisibilityChangedEvent $event ): void {
		$affectedRevIds = $event->getAffectedRevisionIDs();
		$pageIdentity = $event->getPage();

		$newlySuppressedRevisionIds = [];
		foreach ( $affectedRevIds as $revisionId ) {
			$wasSuppressed = $this->isSuppressed( $event->getVisibilityBefore( $revisionId ) );
			$isSuppressed = $this->isSuppressed( $event->getVisibilityAfter( $revisionId ) );
			if ( !$wasSuppressed && $isSuppressed ) {
				$newlySuppressedRevisionIds[] = $revisionId;
			}
		}

		if ( !$newlySuppressedRevisionIds ) {
			return;
		}

		$this->notificationModerator->hideForRevisions( $pageIdentity->getId(), $newlySuppressedRevisionIds );

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

		$title = $this->titleFactory->newFromPageIdentity( $pageIdentity );
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
					'page' => [
						'id' => $pageIdentity->getId(),
						'title' => $pageIdentity->getDBkey(),
						'namespace_id' => $pageIdentity->getNamespace(),
						'namespace_name' => $this->namespaceInfo
							->getCanonicalName( $pageIdentity->getNamespace() ) ?: '',
						'revision_id' => $event->getLatestRevisionId(),
						'content_language' => $title->getPageLanguage()->getCode(),
						'is_redirect' => $title->isRedirect(),
					],
					'reason' => $event->getReason(),
				]
			);
		}
	}

	private function isSuppressed( int $bits ): bool {
		return ( $bits & PersonalInfoFlagNotifier::SUPPRESSED_BITS ) === PersonalInfoFlagNotifier::SUPPRESSED_BITS;
	}
}

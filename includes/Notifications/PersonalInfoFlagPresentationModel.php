<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;

class PersonalInfoFlagPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'alert';
	}

	/**
	 * Recipients are frozen when the notification is created, so re-check at display time and hide it
	 * if the user can no longer view the flagged edit or its tag.
	 * PersonalInfoFlagNotificationModerator hides it outright on suppression and on a verdict.
	 *
	 * @inheritDoc
	 */
	public function canRender(): bool {
		$revision = $this->getRevisionRecord();
		if ( !$revision ) {
			return false;
		}

		return $revision->userCan( RevisionRecord::DELETED_TEXT, $this->getUser() )
			&& MediaWikiServices::getInstance()->getChangeTagsStore()
				->canViewTag( ChangeTagsHandler::PERSONAL_INFO_TAG, $this->getUser() );
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		if ( $this->isBundled() ) {
			return $this->msg( 'notification-bundle-header-personal-info-flagged' )
				->numParams( $this->getBundleCount() );
		}

		return $this->msg( 'notification-header-personal-info-flagged' )
			->params( $this->getTruncatedTitleText( $this->event->getTitle(), true ) );
	}

	/** @inheritDoc */
	public function getPrimaryLink(): array {
		return [
			'url' => SpecialPage::getTitleFor( 'AbuseReview' )->getFullURL(),
			'label' => $this->msg( 'notification-link-text-personal-info-flagged' )->text(),
		];
	}

	private function getRevisionRecord(): ?RevisionRecord {
		$revisionId = $this->event->getExtraParam( 'revisionId' );
		if ( !$revisionId ) {
			return null;
		}

		return MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $revisionId );
	}
}

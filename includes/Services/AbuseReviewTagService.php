<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\IPersonalInfoFlagNotificationModerator;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ReadOnlyMode;

class AbuseReviewTagService {

	private const int HTTP_BAD_REQUEST = 400;
	private const int HTTP_UNAUTHORIZED = 401;
	private const int HTTP_FORBIDDEN = 403;
	private const int HTTP_NOT_FOUND = 404;
	private const int HTTP_UNPROCESSABLE_ENTITY = 422;
	private const int HTTP_SERVICE_UNAVAILABLE = 503;

	public function __construct(
		/** @var string[] Base reviewable tags enabled on this wiki */
		private readonly array $enabledReviewableTags,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly IConnectionProvider $connectionProvider,
		private readonly RevisionLookup $revisionLookup,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly IPersonalInfoFlagNotificationModerator $notificationModerator,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Tag a flagged revision as a false positive, and hide its flag notification.
	 *
	 * @param Authority $authority
	 * @param int $revisionId
	 * @param string $tag The abuse review tag to review, not its false-positive variant
	 * @return StatusValue Good on success; a fatal whose value is the HTTP status code on failure.
	 */
	public function markFalsePositive( Authority $authority, int $revisionId, string $tag ): StatusValue {
		$status = $this->assertReviewable( $authority, $revisionId, $tag );
		if ( !$status->isGood() ) {
			return $status;
		}

		$revision = $status->getValue();
		$falsePositiveTag = ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'];
		$tags = $this->getTags( $revisionId );

		if ( in_array( ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['noFurtherAction'], $tags, true ) ) {
			return $this->conflictingVerdict();
		}

		if ( in_array( $tag, $tags, true ) ) {
			$this->changeTags( $revisionId, [ $falsePositiveTag ], [ $tag ] );
			$this->logger->info( 'Marked revision as false positive', [
				'revisionId' => $revisionId,
				'tag' => $tag,
				'performer' => $authority->getUser()->getName(),
			] );

			$this->notificationModerator->hideForRevisions( $revision->getPageId(), [ $revisionId ] );

			return StatusValue::newGood();
		}

		if ( in_array( $falsePositiveTag, $tags, true ) ) {
			// Idempotent: an earlier mark may have run before the notification existed.
			$this->notificationModerator->hideForRevisions( $revision->getPageId(), [ $revisionId ] );

			return StatusValue::newGood();
		}

		return $this->fatal(
			self::HTTP_UNPROCESSABLE_ENTITY,
			'wikimediaantiabuse-api-review-not-flagged'
		);
	}

	/**
	 * Put a false positive back in the review queue. The flag notification comes back
	 * with it, unless the revision is suppressed.
	 *
	 * @param Authority $authority
	 * @param int $revisionId
	 * @param string $tag The abuse review tag to restore, not its false-positive variant
	 * @return StatusValue Good on success; a fatal whose value is the HTTP status code on failure.
	 */
	public function unmarkFalsePositive( Authority $authority, int $revisionId, string $tag ): StatusValue {
		$status = $this->assertReviewable( $authority, $revisionId, $tag );
		if ( !$status->isGood() ) {
			return $status;
		}

		$falsePositiveTag = ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'];
		$tags = $this->getTags( $revisionId );

		if ( in_array( $falsePositiveTag, $tags, true ) ) {
			$this->changeTags( $revisionId, [ $tag ], [ $falsePositiveTag ] );
			$this->logger->info( 'Unmarked revision as false positive', [
				'revisionId' => $revisionId,
				'tag' => $tag,
				'performer' => $authority->getUser()->getName(),
			] );

			$this->notificationModerator->restoreForRevision( $status->getValue() );

			return StatusValue::newGood();
		}

		if ( in_array( $tag, $tags, true ) ) {
			return StatusValue::newGood();
		}

		return $this->fatal(
			self::HTTP_UNPROCESSABLE_ENTITY,
			'wikimediaantiabuse-api-falsepositive-not-marked'
		);
	}

	/**
	 * Tag a flagged revision as reviewed and needing no further action.
	 * - Keep the tag that flagged the revision for review, as "no further action"
	 *   is indicative of a true positive
	 * - Throw an error if "false positive" is already set on the revision
	 * - Hide the flag notification, as the revision no longer needs a reviewer
	 *
	 * @param Authority $authority
	 * @param int $revisionId
	 * @param string $tag The abuse review tag the revision was flagged with, not its
	 *   false-positive variant
	 * @return StatusValue Good on success; a fatal whose value is the HTTP status code on failure.
	 */
	public function markNoFurtherAction( Authority $authority, int $revisionId, string $tag ): StatusValue {
		$status = $this->assertReviewable( $authority, $revisionId, $tag );
		if ( !$status->isGood() ) {
			return $status;
		}

		$revision = $status->getValue();
		$noFurtherActionTag = ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['noFurtherAction'];
		$tags = $this->getTags( $revisionId );

		if ( in_array( $noFurtherActionTag, $tags, true ) ) {
			// Idempotent: an earlier mark may have run before the notification existed.
			$this->notificationModerator->hideForRevisions( $revision->getPageId(), [ $revisionId ] );

			return StatusValue::newGood();
		}

		if ( in_array( ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'], $tags, true ) ) {
			return $this->conflictingVerdict();
		}

		if ( !in_array( $tag, $tags, true ) ) {
			return $this->fatal(
				self::HTTP_UNPROCESSABLE_ENTITY,
				'wikimediaantiabuse-api-review-not-flagged'
			);
		}

		$this->changeTags( $revisionId, [ $noFurtherActionTag ], [] );
		$this->logger->info( 'Marked revision as needing no further action', [
			'revisionId' => $revisionId,
			'tag' => $tag,
			'performer' => $authority->getUser()->getName(),
		] );

		$this->notificationModerator->hideForRevisions( $revision->getPageId(), [ $revisionId ] );

		return StatusValue::newGood();
	}

	/**
	 * Remove the no-further-action tag from a revision, putting it back in the review queue.
	 * The flag notification comes back with it, unless the revision is suppressed.
	 *
	 * @param Authority $authority
	 * @param int $revisionId
	 * @param string $tag The abuse review tag the revision was flagged with, not its
	 *   false-positive variant
	 * @return StatusValue Good on success; a fatal whose value is the HTTP status code on failure.
	 */
	public function unmarkNoFurtherAction( Authority $authority, int $revisionId, string $tag ): StatusValue {
		$status = $this->assertReviewable( $authority, $revisionId, $tag );
		if ( !$status->isGood() ) {
			return $status;
		}

		$noFurtherActionTag = ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['noFurtherAction'];
		$tags = $this->getTags( $revisionId );

		if ( in_array( $noFurtherActionTag, $tags, true ) ) {
			$this->changeTags( $revisionId, [], [ $noFurtherActionTag ] );
			$this->logger->info( 'Unmarked revision as needing no further action', [
				'revisionId' => $revisionId,
				'tag' => $tag,
				'performer' => $authority->getUser()->getName(),
			] );

			$this->notificationModerator->restoreForRevision( $status->getValue() );

			return StatusValue::newGood();
		}

		if ( $this->isFlagged( $tags, $tag ) ) {
			return StatusValue::newGood();
		}

		return $this->fatal(
			self::HTTP_UNPROCESSABLE_ENTITY,
			'wikimediaantiabuse-api-review-not-flagged'
		);
	}

	/** Whether the revision's tags include the given abuse review tag or its false-positive variant. */
	private function isFlagged( array $tags, string $tag ): bool {
		return in_array( $tag, $tags, true )
			|| in_array( ChangeTagsHandler::REVIEWABLE_TAGS[$tag]['falsePositive'], $tags, true );
	}

	/** A revision holds one verdict: the reviewer removes the old one before recording another. */
	private function conflictingVerdict(): StatusValue {
		return $this->fatal(
			self::HTTP_UNPROCESSABLE_ENTITY,
			'wikimediaantiabuse-api-review-conflicting-verdict'
		);
	}

	/**
	 * @return StatusValue Good, with the looked-up revision as its value, on success;
	 *   a fatal whose value is the HTTP status code on failure.
	 */
	private function assertReviewable( Authority $authority, int $revisionId, string $tag ): StatusValue {
		if ( !isset( ChangeTagsHandler::REVIEWABLE_TAGS[$tag] ) ) {
			return $this->fatal(
				self::HTTP_BAD_REQUEST,
				'wikimediaantiabuse-api-review-unknown-tag',
				[ $tag ]
			);
		}

		if ( !in_array( $tag, $this->enabledReviewableTags, true ) ) {
			return $this->fatal( self::HTTP_NOT_FOUND, 'wikimediaantiabuse-api-review-disabled' );
		}

		$readOnlyReason = $this->readOnlyMode->getReason();
		if ( $readOnlyReason ) {
			return $this->fatal( self::HTTP_SERVICE_UNAVAILABLE, 'readonlytext', [ $readOnlyReason ] );
		}

		if ( !$this->changeTagsStore->canViewTag( $tag, $authority ) ) {
			return $this->fatal(
				$authority->getUser()->isRegistered() ? self::HTTP_FORBIDDEN : self::HTTP_UNAUTHORIZED,
				'wikimediaantiabuse-api-review-permission-denied'
			);
		}

		$block = $authority->getBlock();
		if ( $block && $block->isSitewide() ) {
			return $this->fatal( self::HTTP_FORBIDDEN, 'wikimediaantiabuse-api-review-blocked' );
		}

		$revision = $this->revisionLookup->getRevisionById( $revisionId );
		if ( !$revision && $authority->isAllowed( 'deletedhistory' ) ) {
			$revision = $this->archivedRevisionLookup->getArchivedRevisionRecord( null, $revisionId );
		}
		if ( !$revision ) {
			return $this->fatal( self::HTTP_NOT_FOUND, 'rest-nonexistent-revision', [ $revisionId ] );
		}

		if ( $block instanceof AbstractBlock
			&& $block->appliesToTitle( Title::newFromPageIdentity( $revision->getPage() ) )
		) {
			return $this->fatal( self::HTTP_FORBIDDEN, 'wikimediaantiabuse-api-review-blocked' );
		}

		return StatusValue::newGood( $revision );
	}

	private function fatal( int $httpCode, string $messageKey, array $params = [] ): StatusValue {
		return StatusValue::newFatal( $messageKey, ...$params )->setResult( false, $httpCode );
	}

	/** @return string[] Read from the primary: this gates a same-revision write, so must not be stale. */
	private function getTags( int $revisionId ): array {
		return $this->changeTagsStore->getTags(
			$this->connectionProvider->getPrimaryDatabase(),
			null,
			$revisionId
		);
	}

	private function changeTags( int $revisionId, array $tagsToAdd, array $tagsToRemove ): void {
		$rcId = null;
		$this->changeTagsStore->updateTags( $tagsToAdd, $tagsToRemove, $rcId, $revisionId );
	}
}

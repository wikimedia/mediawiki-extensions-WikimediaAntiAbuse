<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ReadOnlyMode;

class FalsePositiveTagService {

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
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
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

		$falsePositiveTag = ChangeTagsHandler::REVIEWABLE_TAGS[ $tag ];
		$tags = $this->getTags( $revisionId );

		if ( in_array( $tag, $tags, true ) ) {
			$this->swapTags( $revisionId, $falsePositiveTag, $tag );
			$this->logger->info( 'Marked revision as false positive', [
				'revisionId' => $revisionId,
				'tag' => $tag,
				'performer' => $authority->getUser()->getName(),
			] );

			// Follow-up: dismiss the outstanding flag notification here once that code lands.

			return StatusValue::newGood();
		}

		if ( in_array( $falsePositiveTag, $tags, true ) ) {
			return StatusValue::newGood();
		}

		return $this->fatal(
			self::HTTP_UNPROCESSABLE_ENTITY,
			'wikimediaantiabuse-api-falsepositive-not-flagged'
		);
	}

	/**
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

		$falsePositiveTag = ChangeTagsHandler::REVIEWABLE_TAGS[ $tag ];
		$tags = $this->getTags( $revisionId );

		if ( in_array( $falsePositiveTag, $tags, true ) ) {
			$this->swapTags( $revisionId, $tag, $falsePositiveTag );
			$this->logger->info( 'Unmarked revision as false positive', [
				'revisionId' => $revisionId,
				'tag' => $tag,
				'performer' => $authority->getUser()->getName(),
			] );

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

	private function assertReviewable( Authority $authority, int $revisionId, string $tag ): StatusValue {
		if ( !isset( ChangeTagsHandler::REVIEWABLE_TAGS[ $tag ] ) ) {
			return $this->fatal(
				self::HTTP_BAD_REQUEST,
				'wikimediaantiabuse-api-falsepositive-unknown-tag',
				[ $tag ]
			);
		}

		if ( !in_array( $tag, $this->enabledReviewableTags, true ) ) {
			return $this->fatal( self::HTTP_NOT_FOUND, 'wikimediaantiabuse-api-falsepositive-disabled' );
		}

		$readOnlyReason = $this->readOnlyMode->getReason();
		if ( $readOnlyReason ) {
			return $this->fatal( self::HTTP_SERVICE_UNAVAILABLE, 'readonlytext', [ $readOnlyReason ] );
		}

		if ( !$this->changeTagsStore->canViewTag( $tag, $authority ) ) {
			return $this->fatal(
				$authority->getUser()->isRegistered() ? self::HTTP_FORBIDDEN : self::HTTP_UNAUTHORIZED,
				'wikimediaantiabuse-api-falsepositive-permission-denied'
			);
		}

		$block = $authority->getBlock();
		if ( $block && $block->isSitewide() ) {
			return $this->fatal( self::HTTP_FORBIDDEN, 'wikimediaantiabuse-api-falsepositive-blocked' );
		}

		$revision = $this->revisionLookup->getRevisionById( $revisionId );
		if ( !$revision ) {
			return $this->fatal( self::HTTP_NOT_FOUND, 'rest-nonexistent-revision', [ $revisionId ] );
		}

		if ( $block instanceof AbstractBlock
			&& $block->appliesToTitle( Title::newFromPageIdentity( $revision->getPage() ) )
		) {
			return $this->fatal( self::HTTP_FORBIDDEN, 'wikimediaantiabuse-api-falsepositive-blocked' );
		}

		return StatusValue::newGood();
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

	private function swapTags( int $revisionId, string $tagToAdd, string $tagToRemove ): void {
		$rcId = null;
		$this->changeTagsStore->updateTags( [ $tagToAdd ], [ $tagToRemove ], $rcId, $revisionId );
	}
}

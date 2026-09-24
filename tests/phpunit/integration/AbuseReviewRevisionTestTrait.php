<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration;

use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;

/**
 * Helper trait for tests that need a revision carrying abuse review tags.
 * For use in classes extending {@link \MediaWikiIntegrationTestCase}.
 */
trait AbuseReviewRevisionTestTrait {

	protected function createFlaggedRevisionId(
		array $tags = [ ChangeTagsHandler::PERSONAL_INFO_TAG ]
	): int {
		$editStatus = $this->editPage( $this->getNonexistingTestPage(), 'test content' );
		$this->assertStatusGood( $editStatus );
		$revId = $editStatus->getNewRevision()->getId();
		$this->getServiceContainer()->getChangeTagsStore()->addTags( $tags, null, $revId );

		return $revId;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\AbuseReview;

use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewAccountQueue
 */
class AbuseReviewAccountQueueTest extends MediaWikiUnitTestCase {

	public function testGetIdOfFlaggedAccountsQueue(): void {
		$this->assertSame(
			1,
			AbuseReviewAccountQueue::FLAGGED_ACCOUNTS->getId(),
			'The Flagged accounts queue keeps the ID that its stored cases have'
		);
	}

	public function testGetIdIsUniqueForEachQueue(): void {
		$ids = [];
		foreach ( AbuseReviewAccountQueue::cases() as $queue ) {
			$ids[] = $queue->getId();
		}

		$this->assertSame( $ids, array_unique( $ids ), 'No two queues have the same ID' );
	}
}

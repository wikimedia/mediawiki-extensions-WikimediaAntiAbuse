<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Services;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\User\ActorNormalization;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup
 */
class AbuseReviewAccountCaseLookupTest extends MediaWikiUnitTestCase {

	public function testGetPropertiesOfCasesWithoutCases(): void {
		$caseLookup = new AbuseReviewAccountCaseLookup(
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( ActorNormalization::class )
		);

		$this->assertSame(
			[],
			$caseLookup->getPropertiesOfCases( [] ),
			'An empty list of cases has no properties, and no property is read'
		);
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Special\Navigation;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewTabsBuilder;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewTabsBuilder
 */
class AbuseReviewTabsBuilderTest extends MediaWikiUnitTestCase {
	public function testGetHtmlWhenOneIsProvided(): void {
		$tabsBuilder = new AbuseReviewTabsBuilder(
			$this->createNoOpMock( IContextSource::class ),
			[ 'mw-private-test' => 0 ],
			'mw-private-test',
			100
		);
		$this->assertSame( '', $tabsBuilder->getHtml() );
	}
}

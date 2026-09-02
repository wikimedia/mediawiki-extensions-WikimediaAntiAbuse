<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Instrumentation;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\EventLogging\MetricsPlatform\MetricsClientFactory;
use MediaWikiIntegrationTestCase;
use Wikimedia\MetricsPlatform\MetricsClient;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewInstrumentationClient
 */
class AbuseReviewInstrumentationClientTest extends MediaWikiIntegrationTestCase {
	public function testSubmitInteraction(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'EventLogging' );

		$mockContext = $this->createMock( IContextSource::class );

		$mockMetricsClient = $this->createMock( MetricsClient::class );
		$mockMetricsClient->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				'mediawiki.wikimedia_antiabuse.abuse_review_interaction',
				'/analytics/mediawiki/wikimedia_antiabuse/abuse_review_interaction/1.1.0',
				'test_action',
				[ 'mock' => 'data' ]
			);

		$mockMetricsClientFactory = $this->createMock( MetricsClientFactory::class );
		$mockMetricsClientFactory->method( 'newMetricsClient' )
			->with( $mockContext )
			->willReturn( $mockMetricsClient );
		$this->setService( 'EventLogging.MetricsClientFactory', $mockMetricsClientFactory );

		$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewInstrumentationClient' )->submitInteraction(
			$mockContext,
			'test_action',
			[ 'mock' => 'data' ]
		);
	}
}

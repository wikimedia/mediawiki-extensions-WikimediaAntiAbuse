<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ContentPolicyEvaluationResult;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyScoreEventLogger;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyScoreEventLogger
 */
class ContentPolicyScoreEventLoggerTest extends MediaWikiIntegrationTestCase {

	private EventSubmitter&MockObject $eventSubmitter;
	private UserEntitySerializer&MockObject $userEntitySerializer;
	private ContentPolicyScoreEventLogger $eventLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'EventLogging' );
		$this->markTestSkippedIfExtensionNotLoaded( 'EventBus' );

		$this->eventSubmitter = $this->createMock( EventSubmitter::class );
		$this->userEntitySerializer = $this->createMock( UserEntitySerializer::class );
		$this->eventLogger = new ContentPolicyScoreEventLogger(
			$this->eventSubmitter,
			$this->userEntitySerializer
		);
	}

	public function testRecordSubmitsEventWithMappedEvaluations(): void {
		$performer = $this->createMock( UserIdentity::class );

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->expects( $this->once() )
			->method( 'getUser' )
			->with( RevisionRecord::RAW )
			->willReturn( $performer );
		$revisionRecord->expects( $this->once() )
			->method( 'getId' )
			->willReturn( 42 );

		$serializedPerformer = [ 'user_id' => 7, 'user_text' => 'Test performer' ];
		$this->userEntitySerializer->expects( $this->once() )
			->method( 'toArray' )
			->with( $performer )
			->willReturn( $serializedPerformer );

		$expectedEvaluations = [
			[
				'content_policy' => 'policy-one',
				'model_name' => 'test-model-one',
				'violation' => true,
				'policy_version' => '1.2',
				'p_violation' => 0.9,
				'p_safe' => 0.1,
			],
			[
				'content_policy' => 'policy-two',
				'model_name' => 'test-model-two',
				'violation' => false,
				'p_violation' => 0.2,
				'p_safe' => 0.8,
			],
		];

		$this->eventSubmitter->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.wikimedia_antiabuse.content_policy_score',
				$this->callback( function ( array $event ) use ( $serializedPerformer, $expectedEvaluations ): bool {
					$this->assertSame(
						'/analytics/mediawiki/wikimedia_antiabuse/content_policy_score/1.0.0',
						$event['$schema']
					);
					$this->assertSame( WikiMap::getCurrentWikiId(), $event['wiki_id'] );
					$this->assertSame( $serializedPerformer, $event['performer'] );
					$this->assertSame( 42, $event['identifier'] );
					$this->assertSame( 'revision', $event['identifier_type'] );
					$this->assertSame( $expectedEvaluations, $event['evaluations'] );

					return true;
				} )
			);

		$results = [
			new ContentPolicyEvaluationResult(
				'policy-one',
				'test-model-one',
				new CoPEModelResponse( [ 'violation' => 1, 'p_violation' => 0.9, 'p_safe' => 0.1 ] ),
				'1.2'
			),
			new ContentPolicyEvaluationResult(
				'policy-two',
				'test-model-two',
				new CoPEModelResponse( [ 'violation' => 0, 'p_violation' => 0.2, 'p_safe' => 0.8 ] )
			),
		];

		$this->eventLogger->record( $revisionRecord, $results );
	}

	public function testRecordOmitsProbabilitiesTheModelDidNotReport(): void {
		$performer = $this->createMock( UserIdentity::class );

		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->method( 'getUser' )
			->with( RevisionRecord::RAW )
			->willReturn( $performer );
		$revisionRecord->method( 'getId' )
			->willReturn( 42 );

		$this->userEntitySerializer->method( 'toArray' )
			->willReturn( [ 'user_id' => 7 ] );

		$this->eventSubmitter->expects( $this->once() )
			->method( 'submit' )
			->with(
				'mediawiki.wikimedia_antiabuse.content_policy_score',
				$this->callback( function ( array $event ): bool {
					$this->assertSame(
						[ [
							'content_policy' => 'policy-one',
							'model_name' => 'test-model-one',
							'violation' => false,
						] ],
						$event['evaluations'],
						'Scores the model did not report must be omitted, not submitted as null'
					);

					return true;
				} )
			);

		$this->eventLogger->record( $revisionRecord, [
			new ContentPolicyEvaluationResult(
				'policy-one',
				'test-model-one',
				new CoPEModelResponse( [ 'unexpected-key' => 'unexpected-value' ] )
			),
		] );
	}

	public function testRecordSkipsSubmitWhenPerformerMissing(): void {
		$revisionRecord = $this->createMock( RevisionRecord::class );
		$revisionRecord->expects( $this->once() )
			->method( 'getUser' )
			->with( RevisionRecord::RAW )
			->willReturn( null );

		$this->userEntitySerializer->expects( $this->never() )
			->method( 'toArray' );
		$this->eventSubmitter->expects( $this->never() )
			->method( 'submit' );

		$this->eventLogger->record( $revisionRecord, [] );
	}
}

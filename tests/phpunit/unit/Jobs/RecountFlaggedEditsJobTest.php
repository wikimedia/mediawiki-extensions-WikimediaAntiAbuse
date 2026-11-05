<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Jobs;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewAccountCaseManager;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Jobs\RecountFlaggedEditsJob
 */
class RecountFlaggedEditsJobTest extends MediaWikiUnitTestCase {

	private const int REVISION_ID = 42;

	public function testNewSpec(): void {
		$spec = RecountFlaggedEditsJob::newSpec( self::REVISION_ID );

		$params = $spec->getParams();
		// JobSpecification adds the ID of the current request.
		unset( $params['requestId'] );
		$this->assertSame( [ 'revisionId' => self::REVISION_ID ], $params, 'The job gets the revision ID' );
		$this->assertNull( $spec->getReleaseTimestamp(), 'The job is not delayed' );
		$this->assertFalse( $spec->ignoreDuplicates(), 'Each flag gets its own job' );
	}

	/** @dataProvider provideReplicasThatDoNotCatchUp */
	public function testRunFailsWhenAReplicaDoesNotCatchUp(
		bool $wikiReplicaCaughtUp,
		bool $caseReplicaCaughtUp
	): void {
		$replica = $this->createMock( IDatabase::class );
		$wikiLoadBalancer = $this->createMock( ILoadBalancer::class );
		$wikiLoadBalancer->method( 'getConnection' )
			->willReturn( $replica );
		$wikiLoadBalancer->method( 'waitForPrimaryPos' )
			->with( $replica )
			->willReturn( $wikiReplicaCaughtUp );
		$caseLoadBalancer = $this->createMock( ILoadBalancer::class );
		$caseLoadBalancer->method( 'getConnection' )
			->willReturn( $replica );
		$caseLoadBalancer->method( 'waitForPrimaryPos' )
			->with( $replica )
			->willReturn( $caseReplicaCaughtUp );
		$replicaDomain = $this->createMock( IReadableDatabase::class );
		$replicaDomain->method( 'getDomainID' )
			->willReturn( 'wiki' );
		$lbFactory = $this->createMock( ILBFactory::class );
		$lbFactory->method( 'getMainLB' )
			->willReturn( $wikiLoadBalancer );
		$lbFactory->method( 'getLoadBalancer' )
			->with( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN )
			->willReturn( $caseLoadBalancer );
		$lbFactory->method( 'getReplicaDatabase' )
			->with( AbuseReviewAccountCaseLookup::VIRTUAL_DB_DOMAIN )
			->willReturn( $replicaDomain );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->never() )
			->method( 'getRevisionById' );
		$job = new RecountFlaggedEditsJob(
			[ 'revisionId' => self::REVISION_ID ],
			new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => true ] ),
			$revisionLookup,
			$this->createNoOpMock( ArchivedRevisionLookup::class ),
			$this->createNoOpMock( AbuseReviewEnabledTagsProvider::class ),
			$this->createNoOpMock( FlaggedEditCounter::class ),
			$this->createNoOpMock( AbuseReviewAccountCaseLookup::class ),
			$this->createNoOpMock( AbuseReviewAccountCaseManager::class ),
			$lbFactory
		);

		$this->assertFalse( $job->run(), 'The job fails if a replica does not catch up' );
		$this->assertSame(
			'Timed out while waiting for the replicas to catch up',
			$job->getLastError(),
			'The job reports the timeout'
		);
	}

	public static function provideReplicasThatDoNotCatchUp(): array {
		return [
			'the wiki replica' => [ 'wikiReplicaCaughtUp' => false, 'caseReplicaCaughtUp' => true ],
			'the case replica' => [ 'wikiReplicaCaughtUp' => true, 'caseReplicaCaughtUp' => false ],
		];
	}
}

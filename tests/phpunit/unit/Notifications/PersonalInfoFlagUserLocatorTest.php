<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Notifications;

use ArrayIterator;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagUserLocator;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserSelectQueryBuilder;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Notifications\PersonalInfoFlagUserLocator
 */
class PersonalInfoFlagUserLocatorTest extends MediaWikiUnitTestCase {

	public function testWarnsWhenRecipientCapIsReached(): void {
		// MAX_RECIPIENTS is 1000; returning exactly that many opted-in ids trips the cap warning.
		$selectQueryBuilder = $this->createMock( SelectQueryBuilder::class );
		$selectQueryBuilder->method( 'select' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'distinct' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'from' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'where' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'limit' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'caller' )
			->willReturnSelf();
		$selectQueryBuilder->method( 'fetchFieldValues' )
			->willReturn( array_fill( 0, 1000, '1' ) );

		$database = $this->createMock( IReadableDatabase::class );
		$database->method( 'newSelectQueryBuilder' )
			->willReturn( $selectQueryBuilder );

		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->method( 'getReplicaDatabase' )
			->willReturn( $database );

		$userSelectQueryBuilder = $this->createMock( UserSelectQueryBuilder::class );
		$userSelectQueryBuilder->method( 'whereUserIds' )
			->willReturnSelf();
		$userSelectQueryBuilder->method( 'caller' )
			->willReturnSelf();
		$userSelectQueryBuilder->method( 'fetchUserIdentities' )
			->willReturn( new ArrayIterator( [] ) );

		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->method( 'newSelectQueryBuilder' )
			->willReturn( $userSelectQueryBuilder );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'warning' );

		$locator = new PersonalInfoFlagUserLocator(
			$connectionProvider,
			$this->createMock( ChangeTagsStore::class ),
			$userIdentityLookup,
			$this->createMock( UserFactory::class ),
			$logger
		);

		$this->assertSame( [], $locator->locate() );
	}
}

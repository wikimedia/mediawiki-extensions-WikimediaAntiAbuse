<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Unit\Hooks\Handlers;

use MediaWiki\Block\AnonIpBlockTarget;
use MediaWiki\Block\BlockTarget;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\UserBlockTarget;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AccountCaseBlockHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Jobs\CloseBlockedAccountCaseJob;
use MediaWiki\JobQueue\JobQueue;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobQueueRedis;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AccountCaseBlockHandler
 */
class AccountCaseBlockHandlerTest extends MediaWikiUnitTestCase {

	private const int NOW = 1767225600;
	private const string USER_NAME = 'Blocked user';

	/** @dataProvider provideNoJob */
	public function testDoesNotQueueJob(
		bool $queueEnabled,
		BlockTarget $target,
		string $expiry,
		bool $sitewide
	): void {
		$jobQueueGroup = $this->createNoOpMock( JobQueueGroup::class );
		$config = new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => $queueEnabled ] );
		$handler = new AccountCaseBlockHandler(
			$config,
			$jobQueueGroup,
			$this->createNoOpMock( LoggerInterface::class )
		);
		$block = new DatabaseBlock( [ 'target' => $target, 'decodedExpiry' => $expiry, 'sitewide' => $sitewide ] );
		$performer = $this->createNoOpMock( User::class );

		$handler->onBlockIpComplete( $block, $performer, null );
	}

	public static function provideNoJob(): array {
		$userTarget = new UserBlockTarget( new UserIdentityValue( 42, self::USER_NAME ) );

		return [
			'Queue disabled' => [
				'queueEnabled' => false,
				'target' => $userTarget,
				'expiry' => 'infinity',
				'sitewide' => true,
			],
			'Block of an IP address' => [
				'queueEnabled' => true,
				'target' => new AnonIpBlockTarget( '1.2.3.4' ),
				'expiry' => 'infinity',
				'sitewide' => true,
			],
			'Partial block' => [
				'queueEnabled' => true,
				'target' => $userTarget,
				'expiry' => 'infinity',
				'sitewide' => false,
			],
			'Temporary block' => [
				'queueEnabled' => true,
				'target' => $userTarget,
				'expiry' => '20260201000000',
				'sitewide' => true,
			],
		];
	}

	/** @dataProvider provideJob */
	public function testQueuesJob( string $jobQueueClass, bool $delayed, int $expectedWarnings ): void {
		ConvertibleTimestamp::setFakeTime( self::NOW );
		$blockedUser = new UserIdentityValue( 42, self::USER_NAME );
		// delayedJobsEnabled() is final, and createMock() keeps the supportsDelayedJobs() of the class.
		$jobQueue = $this->createMock( $jobQueueClass );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )
			->method( 'get' )
			->with( CloseBlockedAccountCaseJob::TYPE )
			->willReturn( $jobQueue );
		$jobQueueGroup->expects( $this->once() )
			->method( 'lazyPush' )
			->with( CloseBlockedAccountCaseJob::newSpec( $blockedUser->getId(), $delayed ) );
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->exactly( $expectedWarnings ) )
			->method( 'warning' );
		$config = new HashConfig( [ 'WikimediaAntiAbuseEnableFlaggedAccountsQueue' => true ] );
		$handler = new AccountCaseBlockHandler( $config, $jobQueueGroup, $logger );
		$block = new DatabaseBlock( [
			'target' => new UserBlockTarget( $blockedUser ),
			'decodedExpiry' => 'infinity',
			'sitewide' => true,
		] );
		$performer = $this->createNoOpMock( User::class );

		$handler->onBlockIpComplete( $block, $performer, null );
	}

	public static function provideJob(): array {
		return [
			'Job queue that can delay jobs' => [
				'jobQueueClass' => JobQueueRedis::class,
				'delayed' => true,
				'expectedWarnings' => 0,
			],
			'Job queue that cannot delay jobs' => [
				'jobQueueClass' => JobQueue::class,
				'delayed' => false,
				'expectedWarnings' => 1,
			],
		];
	}
}

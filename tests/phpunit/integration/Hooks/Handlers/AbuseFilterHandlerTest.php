<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Hooks\Handlers;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseFilterHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FlaggedEditCounter;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use MockTitleTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseFilterHandler
 */
class AbuseFilterHandlerTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;
	use MockTitleTrait;

	private const array ALL_VARIABLES = [
		'abuse_review_user_vandalism_flagged_count',
		'abuse_review_user_link_spam_flagged_count',
		'abuse_review_user_last_vandalism_flagged_age',
		'abuse_review_user_last_link_spam_flagged_age',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Abuse Filter' );
	}

	/** @dataProvider provideAllVariablesAreProtected */
	public function testAllVariablesAreProtected( bool $variablesEnabled, string $variable ): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableVandalismTag' => $variablesEnabled,
			'WikimediaAntiAbuseEnableLinkSpamTag' => $variablesEnabled,
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
		] );
		$this->assertContains(
			$variable,
			AbuseFilterServices::getProtectedVariablesLookup( $this->getServiceContainer() )
				->getAllProtectedVariables()
		);
	}

	public static function provideAllVariablesAreProtected(): iterable {
		foreach ( self::ALL_VARIABLES as $variable ) {
			yield "$variable when variables are enabled" => [ 'variablesEnabled' => true, 'variable' => $variable ];
		}
		foreach ( self::ALL_VARIABLES as $variable ) {
			yield "$variable when variables are disabled" => [ 'variablesEnabled' => false, 'variable' => $variable ];
		}
	}

	/** @dataProvider provideAllVariables */
	public function testAllVariablesHaveDocumentationDefined( string $variable ): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableVandalismTag' => true,
			'WikimediaAntiAbuseEnableLinkSpamTag' => true,
		] );
		$actualBuilderValues = AbuseFilterServices::getKeywordsManager( $this->getServiceContainer() )
			->getBuilderValues();
		$this->assertArrayHasKey(
			$variable,
			$actualBuilderValues['vars'],
			'Missing WikimediaAntiAbuse AbuseFilter variable from builder description'
		);
		$this->assertSame(
			str_replace( '_', '-', $variable ),
			$actualBuilderValues['vars'][$variable],
			"Builder message key for $variable is not as expected"
		);
	}

	public static function provideAllVariables(): iterable {
		foreach ( self::ALL_VARIABLES as $variable ) {
			yield $variable => [ 'variable' => $variable ];
		}
	}

	/** @dataProvider provideCanViewCheckUserProtectedVariables */
	public function testCanViewCheckUserProtectedVariables(
		array $rights,
		array $variables,
		bool $expectedCanView
	): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableVandalismTag' => true,
			'WikimediaAntiAbuseEnableLinkSpamTag' => true,
		] );
		$actualStatus = AbuseFilterServices::getPermissionManager()->canViewProtectedVariables(
			$this->mockRegisteredAuthorityWithPermissions( $rights ),
			$variables
		);

		if ( $expectedCanView ) {
			$this->assertStatusGood( $actualStatus );
		} else {
			$this->assertStatusNotGood( $actualStatus );
			$this->assertStatusMessage(
				'wikimediaantiabuse-abusefilter-protected-variables-access-denied',
				$actualStatus
			);
		}
	}

	public static function provideCanViewCheckUserProtectedVariables(): iterable {
		foreach ( self::ALL_VARIABLES as $variable ) {
			yield "Lacks right to see $variable" => [
				'rights' => [ 'abusefilter-access-protected-vars' ],
				'variables' => [ $variable ],
				'expectedCanView' => false,
			];
			yield "Has right to see $variable" => [
				'rights' => [ 'abusefilter-access-protected-vars', 'abusereview-vandalism-alpha-tester' ],
				'variables' => [ $variable ],
				'expectedCanView' => true,
			];
		}
	}

	public function testOnAbuseFilterInterceptVariableForOtherMethod(): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableVandalismTag' => true,
			'WikimediaAntiAbuseEnableLinkSpamTag' => true,
		] );

		$objectUnderTest = new AbuseFilterHandler(
			$this->createNoOpMock( FlaggedEditCounter::class ),
			$this->createNoOpMock( ChangeTagsStore::class ),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewEnabledTagsProvider' )
		);

		$result = 'unchanged';
		$this->assertTrue(
			$objectUnderTest->onAbuseFilter_interceptVariable( 'user-age', new VariableHolder(), [], $result ),
			'Should let other handlers compute other variables'
		);
		$this->assertSame( 'unchanged', $result, 'Should not change the result of other variables' );
	}

	/** @dataProvider provideOnAbuseFilterInterceptVariable */
	public function testOnAbuseFilterInterceptVariable(
		string $method,
		string $flag,
		array $stats,
		?int $expectedResult
	): void {
		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnableVandalismTag' => true,
			'WikimediaAntiAbuseEnableLinkSpamTag' => true,
		] );
		ConvertibleTimestamp::setFakeTime( '20261007000000' );
		$userIdentity = UserIdentityValue::newRegistered( 1, 'Test' );

		// Mock the FlaggedEditCounter to return the expected stats for the given flag, to avoid needing to
		// write rows to the database for the test
		$counter = $this->createMock( FlaggedEditCounter::class );
		$counter->expects( $this->once() )
			->method( 'getFlaggedEditStats' )
			->willReturnCallback( function (
				Authority $authority,
				UserIdentity $actualUser,
				array $flags
			) use ( $userIdentity, $stats, $flag ): array {
				$this->assertTrue( $userIdentity->equals( $actualUser ), 'Should count the edits of the user' );
				$this->assertFalse(
					$authority->isAllowedAny( 'deletedhistory', 'suppressrevision', 'viewsuppressed' ),
					'Should count only edits that every user can see, otherwise the count exposes ' .
					'that deleted / suppressed edits have been flagged'
				);
				$this->assertSame(
					[ ChangeTagsHandler::VANDALISM_TAG, ChangeTagsHandler::LINK_SPAM_TAG ],
					$flags,
					'Should get the stats of every enabled flag'
				);
				$allStats = array_fill_keys(
					[ ChangeTagsHandler::VANDALISM_TAG, ChangeTagsHandler::LINK_SPAM_TAG ],
					[ 'count' => 0, 'latestTimestamp' => null ]
				);
				$allStats[$flag] = $stats;
				return $allStats;
			} );
		$this->setService( 'WikimediaAntiAbuseFlaggedEditCounter', $counter );

		$user = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $userIdentity );
		$runVariableGenerator = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator(
			$user,
			$this->makeMockTitle( 'Test' )
		);
		$runVariableGenerator->addUserVars( $user );
		$varHolder = $runVariableGenerator->getVariableHolder();

		$actualVariable = AbuseFilterServices::getLazyVariableComputer()->compute(
			$varHolder->getVarThrow( str_replace( '-', '_', $method ) ),
			$varHolder,
			static fn () => 'unused'
		);
		$this->assertSame( $expectedResult, $actualVariable->toNative(), 'Unexpected variable value' );
	}

	public static function provideOnAbuseFilterInterceptVariable(): array {
		$noEdits = [ 'count' => 0, 'latestTimestamp' => null ];
		$edits = [ 'count' => 3, 'latestTimestamp' => '20261006000000' ];
		return [
			'Vandalism count' => [
				'method' => 'abuse-review-user-vandalism-flagged-count',
				'flag' => ChangeTagsHandler::VANDALISM_TAG,
				'stats' => $edits,
				'expectedResult' => 3,
			],
			'Vandalism count with no edits' => [
				'method' => 'abuse-review-user-vandalism-flagged-count',
				'flag' => ChangeTagsHandler::VANDALISM_TAG,
				'stats' => $noEdits,
				'expectedResult' => 0,
			],
			'Link spam count' => [
				'method' => 'abuse-review-user-link-spam-flagged-count',
				'flag' => ChangeTagsHandler::LINK_SPAM_TAG,
				'stats' => $edits,
				'expectedResult' => 3,
			],
			'Vandalism age' => [
				'method' => 'abuse-review-user-last-vandalism-flagged-age',
				'flag' => ChangeTagsHandler::VANDALISM_TAG,
				'stats' => $edits,
				'expectedResult' => 86400,
			],
			'Link spam age' => [
				'method' => 'abuse-review-user-last-link-spam-flagged-age',
				'flag' => ChangeTagsHandler::LINK_SPAM_TAG,
				'stats' => $edits,
				'expectedResult' => 86400,
			],
			'Link spam age with no edits' => [
				'method' => 'abuse-review-user-last-link-spam-flagged-age',
				'flag' => ChangeTagsHandler::LINK_SPAM_TAG,
				'stats' => $noEdits,
				'expectedResult' => null,
			],
		];
	}
}

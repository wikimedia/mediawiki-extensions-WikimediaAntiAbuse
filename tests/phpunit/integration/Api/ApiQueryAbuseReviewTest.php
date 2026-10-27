<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Api;

use MediaWiki\Cache\GenderCache;
use MediaWiki\ChangeTags\ChangeTags;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\AbuseReviewRevisionTestTrait;
use MediaWiki\Interwiki\ClassicInterwikiLookup;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Api\ApiQueryAbuseReview
 * @group API
 * @group Database
 */
class ApiQueryAbuseReviewTest extends ApiTestCase {

	use AbuseReviewRevisionTestTrait;
	use TempUserTestTrait;

	private const string OLDEST_PAGE = 'ApiQueryAbuseReviewTest oldest';
	private const string TIED_PAGE = 'ApiQueryAbuseReviewTest tied';
	private const string RECENT_PAGE = 'ApiQueryAbuseReviewTest recent';
	private const string HIDDEN_SUMMARY = 'Summary hidden by revision deletion';

	/** @var array<string,int> */
	private static array $revIds = [];

	private Authority $reviewer;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'WikimediaAntiAbuseEnablePersonalInfoTag' => true,
			'WikimediaAntiAbuseEnableVandalismTag' => true,
		] );
		$this->setGroupPermissions( [
			'vandalism-queue-viewer' => [ 'abusereview-vandalism-alpha-tester' => true ],
			'hidden-field-viewer' => [ 'deletedhistory' => true, 'viewsuppressed' => true ],
		] );
		$this->reviewer = $this->getTestUser( [ 'vandalism-queue-viewer', 'hidden-field-viewer' ] )->getAuthority();
	}

	public function addDBDataOnce(): void {
		$sysop = $this->getTestSysop()->getAuthority();
		$personalInfoTags = [ ChangeTagsHandler::PERSONAL_INFO_TAG ];
		$this->createRevision( 'oldestParent', self::OLDEST_PAGE, '20260101000000', [] );
		$this->createRevision( 'oldest', self::OLDEST_PAGE, '20260101000100', $personalInfoTags, performer: $sysop );
		$this->createRevision( 'tiedFirst', self::TIED_PAGE, '20260101000200', $personalInfoTags );
		$this->createRevision( 'tiedSecond', self::TIED_PAGE, '20260101000200', $personalInfoTags );

		$vandalismTags = [ ChangeTagsHandler::VANDALISM_TAG ];
		$vandalismPage = 'ApiQueryAbuseReviewTest vandalism';
		$this->createRevision( 'revisionDeleted', $vandalismPage, '20260101000300', $vandalismTags );

		ConvertibleTimestamp::setFakeTime( '20260101000400' );
		self::$revIds['unhandled'] = $this->createFlaggedRevisionId( $vandalismTags );
		self::$revIds['falsePositive'] = $this->createFlaggedRevisionId(
			[ ChangeTagsHandler::VANDALISM_FALSE_POSITIVE_TAG ]
		);
		self::$revIds['noFurtherAction'] = $this->createFlaggedRevisionId(
			[ ChangeTagsHandler::VANDALISM_TAG, ChangeTagsHandler::VANDALISM_NO_FURTHER_ACTION_TAG ]
		);
		self::$revIds['reverted'] = $this->createFlaggedRevisionId(
			[ ChangeTagsHandler::VANDALISM_TAG, ChangeTags::TAG_REVERTED ]
		);
		self::$revIds['noFurtherActionReverted'] = $this->createFlaggedRevisionId( [
			ChangeTagsHandler::VANDALISM_TAG,
			ChangeTagsHandler::VANDALISM_NO_FURTHER_ACTION_TAG,
			ChangeTags::TAG_REVERTED,
		] );

		$this->createRevision(
			'userHidden', $vandalismPage, '20260101000500', $vandalismTags, self::HIDDEN_SUMMARY, $sysop
		);
		$this->createRevision(
			'suppressed', $vandalismPage, '20260101000600', $vandalismTags, self::HIDDEN_SUMMARY, $sysop
		);

		// An actor for an IP address cannot be created while temporary accounts are enabled.
		$this->disableAutoCreateTempUser();
		$this->createRevision(
			'ipEdit',
			$vandalismPage,
			'20260101000800',
			$vandalismTags,
			performer: $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);
		$this->createRevision(
			'temporaryAccountEdit',
			$vandalismPage,
			'20260101000900',
			$vandalismTags,
			performer: User::createNew( '~2026-1' )
		);

		$deletedPage = 'ApiQueryAbuseReviewTest deleted';
		$this->createRevision( 'pageDeleted', $deletedPage, '20260101000700', $vandalismTags );

		$this->createRevision( 'recent', self::RECENT_PAGE, '20260101010000', $personalInfoTags );
		ConvertibleTimestamp::setFakeTime( false );

		$this->revisionDelete( self::$revIds['revisionDeleted'] );
		$this->revisionDelete(
			self::$revIds['userHidden'],
			[ RevisionRecord::DELETED_USER | RevisionRecord::DELETED_COMMENT => 1 ]
		);
		$this->revisionDelete(
			self::$revIds['suppressed'],
			[ RevisionRecord::DELETED_USER | RevisionRecord::DELETED_COMMENT | RevisionRecord::DELETED_RESTRICTED => 1 ]
		);
		$this->deletePage( $deletedPage );
	}

	/** @dataProvider provideQueueRequests */
	public function testDeniesViewerWhoCannotSeeTheQueueTag( array $params, array $viewerGroups ): void {
		$this->expectApiErrorCode( 'permissiondenied' );

		$this->doApiRequest(
			[ 'action' => 'query' ] + $params,
			performer: $this->getTestUser( $viewerGroups )->getAuthority()
		);
	}

	public static function provideQueueRequests(): array {
		return [
			'personal info list' => [
				'params' => [ 'list' => 'abusereview', 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG ],
				'viewerGroups' => [],
			],
			'vandalism list' => [
				'params' => [ 'list' => 'abusereview', 'abrqueue' => ChangeTagsHandler::VANDALISM_TAG ],
				'viewerGroups' => [],
			],
			'personal info generator' => [
				'params' => [ 'generator' => 'abusereview', 'gabrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG ],
				'viewerGroups' => [],
			],
			'vandalism generator' => [
				'params' => [ 'generator' => 'abusereview', 'gabrqueue' => ChangeTagsHandler::VANDALISM_TAG ],
				'viewerGroups' => [],
			],
			'personal info list, viewer of the vandalism queue only' => [
				'params' => [ 'list' => 'abusereview', 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG ],
				'viewerGroups' => [ 'vandalism-queue-viewer' ],
			],
		];
	}

	/** @dataProvider provideDisabledQueues */
	public function testRejectsQueueDisabledOnTheWiki( string $enableConfig, string $queue ): void {
		$this->overrideConfigValue( $enableConfig, false );
		$this->expectApiErrorCode( 'badvalue' );

		$this->requestRows( [ 'abrqueue' => $queue ] );
	}

	public static function provideDisabledQueues(): array {
		return [
			'personal info' => [
				'enableConfig' => 'WikimediaAntiAbuseEnablePersonalInfoTag',
				'queue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
			],
			'vandalism' => [
				'enableConfig' => 'WikimediaAntiAbuseEnableVandalismTag',
				'queue' => ChangeTagsHandler::VANDALISM_TAG,
			],
		];
	}

	/** @dataProvider provideStatuses */
	public function testListsTheStatusOfEachRow( string $fixture, array $expectedRow ): void {
		$rowsByRevId = array_column( $this->requestRows( [
			'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
			'abrprop' => 'ids|status',
			'abrshowhandled' => '1',
			'abrshowfalsepositives' => '1',
			'abrlimit' => 'max',
		] ), null, 'revid' );

		$this->assertArrayHasKey(
			self::$revIds[$fixture],
			$rowsByRevId,
			'The queue lists the row when handled and false positive rows are asked for'
		);
		$this->assertSame(
			$expectedRow,
			array_diff_key( $rowsByRevId[self::$revIds[$fixture]], [ 'revid' => true, 'parentid' => true ] ),
			'The row carries the status of its revision'
		);
	}

	public static function provideStatuses(): array {
		return [
			'unhandled' => [ 'fixture' => 'unhandled', 'expectedRow' => [ 'status' => 'unhandled' ] ],
			'false positive' => [ 'fixture' => 'falsePositive', 'expectedRow' => [ 'status' => 'false-positive' ] ],
			'no further action' => [
				'fixture' => 'noFurtherAction',
				'expectedRow' => [ 'status' => 'no-further-action' ],
			],
			'handled by a revert' => [
				'fixture' => 'reverted',
				'expectedRow' => [ 'status' => 'handled', 'handledreason' => 'reverted' ],
			],
			'no further action on a reverted edit' => [
				'fixture' => 'noFurtherActionReverted',
				'expectedRow' => [ 'status' => 'no-further-action' ],
			],
			'handled by revision deletion' => [
				'fixture' => 'revisionDeleted',
				'expectedRow' => [ 'status' => 'handled', 'handledreason' => 'revisiondeleted' ],
			],
			'handled by page deletion' => [
				'fixture' => 'pageDeleted',
				'expectedRow' => [ 'archived' => true, 'status' => 'handled', 'handledreason' => 'pagedeleted' ],
			],
		];
	}

	public function testListsOnlyUnhandledRowsWithoutTheHandledAndFalsePositiveFlags(): void {
		$this->assertSame(
			[
				self::$revIds['temporaryAccountEdit'],
				self::$revIds['ipEdit'],
				self::$revIds['suppressed'],
				self::$revIds['userHidden'],
				self::$revIds['unhandled'],
			],
			array_column( $this->requestRows( [ 'abrqueue' => ChangeTagsHandler::VANDALISM_TAG ] ), 'revid' ),
			'The queue lists only its unhandled rows unless handled and false positive rows are asked for'
		);
	}

	/** @dataProvider provideRecentRow */
	public function testDelayHidesTheRecentRowUnlessAskedFor( array $extraParams, array $expectedFixtures ): void {
		$this->overrideConfigValue(
			'WikimediaAntiAbuseAbuseReviewDelayMinutes',
			[ ChangeTagsHandler::PERSONAL_INFO_TAG => 10 ]
		);
		ConvertibleTimestamp::setFakeTime( '20260101010500' );

		$this->assertSame(
			array_map( static fn ( string $fixture ): int => self::$revIds[$fixture], $expectedFixtures ),
			array_column(
				$this->requestRows( [ 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG ] + $extraParams ),
				'revid'
			),
			'The queue lists the rows its delay leaves visible'
		);
	}

	public static function provideRecentRow(): array {
		return [
			'hidden by the delay' => [
				'extraParams' => [],
				'expectedFixtures' => [ 'tiedSecond', 'tiedFirst', 'oldest' ],
			],
			'listed with abrshowrecent' => [
				'extraParams' => [ 'abrshowrecent' => '1' ],
				'expectedFixtures' => [ 'recent', 'tiedSecond', 'tiedFirst', 'oldest' ],
			],
		];
	}

	/** @dataProvider provideHiddenFields */
	public function testHiddenFieldsAreFlaggedAndShownOnlyToViewerWithTheRights(
		string $fixture,
		array $viewerGroups,
		array $expectedFlags,
		bool $canSeeHiddenFields
	): void {
		$editor = $this->getTestSysop()->getUserIdentity();
		$hiddenValues = [
			'user' => $editor->getName(),
			'userid' => $editor->getId(),
			'comment' => self::HIDDEN_SUMMARY,
		];

		[ $row ] = $this->requestRows( [
			'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
			'abrprop' => 'user|userid|comment',
			'abrrevid' => (string)self::$revIds[$fixture],
		], $this->getTestUser( $viewerGroups )->getAuthority() );

		$this->assertSame(
			$expectedFlags,
			array_diff_key( $row, $hiddenValues ),
			'The row flags each field hidden by revision deletion'
		);
		$this->assertSame(
			$canSeeHiddenFields ? $hiddenValues : [],
			array_intersect_key( $row, $hiddenValues ),
			'The row carries the hidden values only for a viewer with the rights to see them'
		);
	}

	public static function provideHiddenFields(): array {
		$deletedFlags = [ 'userhidden' => true, 'commenthidden' => true ];
		$suppressedFlags = [ 'userhidden' => true, 'commenthidden' => true, 'suppressed' => true ];

		return [
			'deleted, viewer without deletedhistory' => [
				'fixture' => 'userHidden',
				'viewerGroups' => [ 'vandalism-queue-viewer' ],
				'expectedFlags' => $deletedFlags,
				'canSeeHiddenFields' => false,
			],
			'deleted, viewer with deletedhistory' => [
				'fixture' => 'userHidden',
				'viewerGroups' => [ 'vandalism-queue-viewer', 'hidden-field-viewer' ],
				'expectedFlags' => $deletedFlags,
				'canSeeHiddenFields' => true,
			],
			'suppressed, viewer without viewsuppressed' => [
				'fixture' => 'suppressed',
				'viewerGroups' => [ 'vandalism-queue-viewer' ],
				'expectedFlags' => $suppressedFlags,
				'canSeeHiddenFields' => false,
			],
			'suppressed, viewer with viewsuppressed' => [
				'fixture' => 'suppressed',
				'viewerGroups' => [ 'vandalism-queue-viewer', 'hidden-field-viewer' ],
				'expectedFlags' => $suppressedFlags,
				'canSeeHiddenFields' => true,
			],
		];
	}

	public function testSuppressedRowIsNotFlaggedWithoutItsHiddenFields(): void {
		$this->assertSame(
			[ [ 'revid' => self::$revIds['suppressed'], 'parentid' => self::$revIds['userHidden'] ] ],
			$this->requestRows( [
				'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
				'abrprop' => 'ids',
				'abrrevid' => (string)self::$revIds['suppressed'],
			] ),
			'A suppressed row flags its suppression only next to a hidden field it lists'
		);
	}

	public function testBlockedViewerWithTheRightsDoesNotSeeTheHiddenFields(): void {
		$this->getServiceContainer()->getDatabaseBlockStore()->insertBlockWithParams( [
			'targetUser' => $this->reviewer->getUser(),
			'by' => $this->getTestSysop()->getUser(),
		] );

		$this->assertSame(
			[ [ 'userhidden' => true, 'commenthidden' => true ] ],
			$this->requestRows( [
				'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
				'abrprop' => 'user|comment',
				'abrrevid' => (string)self::$revIds['userHidden'],
			] ),
			'A row hides its deleted fields from a blocked viewer even with the deletedhistory right'
		);
	}

	/** @dataProvider provideEditorFlags */
	public function testUserPropsFlagIpAndTemporaryAccountEditors( string $fixture, array $expectedFlags ): void {
		$this->enableAutoCreateTempUser();
		$editor = $this->getServiceContainer()->getRevisionLookup()
			->getRevisionById( self::$revIds[$fixture] )
			->getUser( RevisionRecord::RAW );

		$this->assertSame(
			[ [ 'user' => $editor->getName(), 'userid' => $editor->getId() ] + $expectedFlags ],
			$this->requestRows( [
				'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
				'abrprop' => 'user|userid',
				'abrrevid' => (string)self::$revIds[$fixture],
			] ),
			'The row names its editor and flags an IP address or a temporary account'
		);
	}

	public static function provideEditorFlags(): array {
		return [
			'registered user' => [ 'fixture' => 'unhandled', 'expectedFlags' => [] ],
			'IP address' => [ 'fixture' => 'ipEdit', 'expectedFlags' => [ 'anon' => true ] ],
			'temporary account' => [ 'fixture' => 'temporaryAccountEdit', 'expectedFlags' => [ 'temp' => true ] ],
		];
	}

	/** @dataProvider provideTags */
	public function testTagsPropListsTheTagsOfTheRow( string $fixture, array $expectedTags ): void {
		$this->assertSame(
			[ [ 'tags' => $expectedTags ] ],
			$this->requestRows( [
				'abrqueue' => ChangeTagsHandler::VANDALISM_TAG,
				'abrprop' => 'tags',
				'abrshowfalsepositives' => '1',
				'abrrevid' => (string)self::$revIds[$fixture],
			] ),
			'The row lists the tags of its revision'
		);
	}

	public static function provideTags(): array {
		return [
			'queue tag' => [ 'fixture' => 'unhandled', 'expectedTags' => [ 'mw-private-vandalism' ] ],
			'false positive verdict tag' => [
				'fixture' => 'falsePositive',
				'expectedTags' => [ 'mw-private-vandalism-false-positive' ],
			],
		];
	}

	/** @dataProvider provideFilters */
	public function testFilterListsOnlyTheMatchingRow( string $filter, string $fixture ): void {
		$revision = $this->getServiceContainer()->getRevisionLookup()->getRevisionById( self::$revIds[$fixture] );
		$filterValues = [
			'abruser' => $revision->getUser()->getName(),
			'abrpage' => $revision->getPage()->getDBkey(),
			'abrrevid' => (string)$revision->getId(),
		];

		$this->assertSame(
			[ $revision->getId() ],
			array_column(
				$this->requestRows( [
					'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
					$filter => $filterValues[$filter],
				] ),
				'revid'
			),
			'The filter narrows the queue to the row it matches'
		);
	}

	public static function provideFilters(): array {
		return [
			'user' => [ 'filter' => 'abruser', 'fixture' => 'oldest' ],
			'page' => [ 'filter' => 'abrpage', 'fixture' => 'recent' ],
			'revision ID' => [ 'filter' => 'abrrevid', 'fixture' => 'tiedFirst' ],
		];
	}

	public function testRejectsInterwikiPageFilter(): void {
		$this->overrideConfigValue( MainConfigNames::InterwikiCache, ClassicInterwikiLookup::buildCdbHash( [
			[ 'iw_prefix' => 'otherwiki', 'iw_url' => 'https://other.example/wiki/$1' ],
		] ) );
		$this->expectApiErrorCode( 'invalidtitle' );

		$this->requestRows( [
			'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
			'abrpage' => 'otherwiki:' . self::TIED_PAGE,
		] );
	}

	public function testContinueReturnsTheOtherRowWithTheSameTimestamp(): void {
		$params = [
			'action' => 'query',
			'list' => 'abusereview',
			'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
			'abrpage' => self::TIED_PAGE,
			'abrlimit' => '1',
		];

		[ $firstPage ] = $this->doApiRequest( $params, performer: $this->reviewer );
		$this->assertSame(
			[ self::$revIds['tiedSecond'] ],
			array_column( $firstPage['query']['abusereview'], 'revid' ),
			'The first page lists one of the two rows with the same timestamp'
		);
		$this->assertArrayHasKey( 'continue', $firstPage, 'The first page tells where to continue' );

		[ $secondPage ] = $this->doApiRequest( $firstPage['continue'] + $params, performer: $this->reviewer );
		$this->assertSame(
			[ self::$revIds['tiedFirst'] ],
			array_column( $secondPage['query']['abusereview'], 'revid' ),
			'The continued page lists the other row with the same timestamp'
		);
		$this->assertArrayNotHasKey( 'continue', $secondPage, 'The continued page is the last one' );
	}

	public function testContinuesAtTheRowThatDoesNotFitInTheResult(): void {
		$this->overrideConfigValue( MainConfigNames::APIMaxResultSize, 80 );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'abusereview',
			'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
			'abrpage' => self::TIED_PAGE,
		], performer: $this->reviewer );

		$this->assertSame(
			[ self::$revIds['tiedSecond'] ],
			array_column( $result['query']['abusereview'], 'revid' ),
			'The result holds only the row that fits in its size limit'
		);
		$this->assertSame(
			'20260101000200|' . self::$revIds['tiedFirst'],
			$result['continue']['abrcontinue'],
			'The result continues at the row that did not fit in its size limit'
		);
	}

	public function testDirNewerListsTheOldestRowFirst(): void {
		$this->assertSame(
			[
				self::$revIds['oldest'],
				self::$revIds['tiedFirst'],
				self::$revIds['tiedSecond'],
				self::$revIds['recent'],
			],
			array_column(
				$this->requestRows( [ 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG, 'abrdir' => 'newer' ] ),
				'revid'
			),
			'The queue lists its rows oldest first with abrdir=newer'
		);
	}

	public function testRejectsMalformedContinue(): void {
		$this->expectApiErrorCode( 'badcontinue' );

		$this->requestRows( [ 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG, 'abrcontinue' => 'malformed' ] );
	}

	public function testGeneratorFillsThePageSetWithTheQueueRevisions(): void {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'generator' => 'abusereview',
			'gabrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
			'prop' => 'revisions',
			'rvprop' => 'ids',
		], performer: $this->reviewer );

		$titlesByRevId = [];
		foreach ( $result['query']['pages'] as $page ) {
			foreach ( $page['revisions'] as $revision ) {
				$titlesByRevId[$revision['revid']] = $page['title'];
			}
		}
		$this->assertArrayEquals(
			[
				self::$revIds['oldest'] => self::OLDEST_PAGE,
				self::$revIds['tiedFirst'] => self::TIED_PAGE,
				self::$revIds['tiedSecond'] => self::TIED_PAGE,
				self::$revIds['recent'] => self::RECENT_PAGE,
			],
			$titlesByRevId,
			named: true,
			message: 'The page set holds the revisions of the queue on their pages'
		);
	}

	public function testDefaultPropsAreIdsTitleAndTimestamp(): void {
		$this->assertSame(
			[ [
				'revid' => self::$revIds['oldest'],
				'parentid' => self::$revIds['oldestParent'],
				'ns' => NS_MAIN,
				'title' => self::OLDEST_PAGE,
				'timestamp' => '2026-01-01T00:01:00Z',
			] ],
			$this->requestRows( [
				'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG,
				'abrrevid' => (string)self::$revIds['oldest'],
			] ),
			'A row without abrprop holds its IDs, title and timestamp only'
		);
	}

	/** @dataProvider provideGenderPreloads */
	public function testTitlePropPreloadsTheGenderOfTheListedPages(
		string $contentLanguage,
		string $prop,
		int $expectedPreloads
	): void {
		$this->overrideConfigValue( MainConfigNames::LanguageCode, $contentLanguage );
		$genderCache = $this->createMock( GenderCache::class );
		$genderCache->expects( $this->exactly( $expectedPreloads ) )
			->method( 'doTitlesArray' )
			->with( array_map(
				static fn ( string $page ): TitleValue => new TitleValue( NS_MAIN, strtr( $page, ' ', '_' ) ),
				[ self::RECENT_PAGE, self::TIED_PAGE, self::TIED_PAGE, self::OLDEST_PAGE ]
			) );
		$this->setService( 'GenderCache', $genderCache );

		$this->requestRows( [ 'abrqueue' => ChangeTagsHandler::PERSONAL_INFO_TAG, 'abrprop' => $prop ] );
	}

	public static function provideGenderPreloads(): array {
		return [
			'title prop' => [ 'contentLanguage' => 'de', 'prop' => 'title', 'expectedPreloads' => 1 ],
			'no title prop' => [ 'contentLanguage' => 'de', 'prop' => 'ids', 'expectedPreloads' => 0 ],
			'title prop, language without gender distinction' => [
				'contentLanguage' => 'en',
				'prop' => 'title',
				'expectedPreloads' => 0,
			],
		];
	}

	private function requestRows( array $params, ?Authority $viewer = null ): array {
		[ $result ] = $this->doApiRequest(
			[ 'action' => 'query', 'list' => 'abusereview' ] + $params,
			performer: $viewer ?? $this->reviewer
		);

		return $result['query']['abusereview'];
	}

	private function createRevision(
		string $fixture,
		string $page,
		string $timestamp,
		array $tags,
		string $summary = '',
		?Authority $performer = null
	): void {
		ConvertibleTimestamp::setFakeTime( $timestamp );
		$editStatus = $this->editPage( $page, $fixture, $summary, performer: $performer );
		$this->assertStatusGood( $editStatus );
		self::$revIds[$fixture] = $editStatus->getNewRevision()->getId();
		$this->getServiceContainer()->getChangeTagsStore()->addTags( $tags, rev_id: self::$revIds[$fixture] );
	}
}

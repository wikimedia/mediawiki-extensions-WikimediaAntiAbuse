<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\FalsePositiveTagService;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\FalsePositiveTagService
 * @group Database
 */
class FalsePositiveTagServiceTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	private const string PERSONAL_INFO_TAG = 'mw-private-personal-info';
	private const string PERSONAL_INFO_FALSE_POSITIVE_TAG = 'mw-private-personal-info-false-positive';

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	private function getService(): FalsePositiveTagService {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseFalsePositiveTagService' );
	}

	private function reviewer(): Authority {
		return $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] );
	}

	private function createRevisionId(): int {
		return $this->editPage( 'False positive test page', 'test content' )
			->getNewRevision()
			->getId();
	}

	private function applyTag( int $revId, string $tag ): void {
		$this->getServiceContainer()->getChangeTagsStore()->addTags(
			[ $tag ], null, $revId
		);
	}

	private function getTags( int $revId ): array {
		return $this->getServiceContainer()->getChangeTagsStore()->getTags( $this->getDb(), null, $revId );
	}

	public function testMarkReturnsNotFoundWhenFeatureDisabled(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', false );

		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-disabled', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsBadRequestForUnknownTag(): void {
		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, 'mw-not-a-reviewable-tag' );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-unknown-tag', $status );
		$this->assertSame( 400, $status->getValue() );
	}

	public function testMarkReturnsServiceUnavailableWhenReadOnly(): void {
		$this->getServiceContainer()->getReadOnlyMode()->setReason( 'Maintenance in progress' );

		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'readonlytext', $status );
		$this->assertSame( 503, $status->getValue() );
	}

	/** @dataProvider provideUnprivilegedAuthorities */
	public function testMarkReturnsErrorWhenLackingSuppressionRights( bool $isRegistered, int $expected ): void {
		$authority = $isRegistered ? $this->mockRegisteredNullAuthority() : $this->mockAnonNullAuthority();

		$status = $this->getService()->markFalsePositive( $authority, 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-permission-denied', $status );
		$this->assertSame( $expected, $status->getValue() );
	}

	public static function provideUnprivilegedAuthorities(): array {
		return [
			'registered user without suppression rights' => [ 'isRegistered' => true, 'expected' => 403 ],
			'anonymous user' => [ 'isRegistered' => false, 'expected' => 401 ],
		];
	}

	public function testMarkReturnsErrorWhenUserCannotSeeDeletedRevision(): void {
		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed' ] );
		$revId = $this->createRevisionId();
		$this->deletePage( 'False positive test page' );

		$status = $this->getService()->markFalsePositive( $authority, $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'rest-nonexistent-revision', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsForbiddenWhenSitewideBlocked(): void {
		$authority = $this->mockUserAuthorityWithBlock(
			new UserIdentityValue( 9999, 'Blocked reviewer' ),
			new DatabaseBlock( [ 'sitewide' => true ] ),
			[ 'viewsuppressed' ]
		);

		$status = $this->getService()->markFalsePositive( $authority, 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-blocked', $status );
		$this->assertSame( 403, $status->getValue() );
	}

	public function testMarkReturnsForbiddenWhenPartiallyBlockedFromPage(): void {
		$revId = $this->createRevisionId();
		$block = $this->createMock( DatabaseBlock::class );
		$block->method( 'isSitewide' )->willReturn( false );
		$block->method( 'appliesToTitle' )->willReturn( true );
		$authority = $this->mockUserAuthorityWithBlock(
			new UserIdentityValue( 9999, 'Partially blocked reviewer' ),
			$block,
			[ 'viewsuppressed' ]
		);

		$status = $this->getService()->markFalsePositive( $authority, $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-blocked', $status );
		$this->assertSame( 403, $status->getValue() );
	}

	public function testMarkReturnsNotFoundForMissingRevision(): void {
		$status = $this->getService()->markFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'rest-nonexistent-revision', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testMarkReturnsUnprocessableWhenNotFlagged(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-not-flagged', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	public function testUnmarkReturnsNotFoundWhenFeatureDisabled(): void {
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', false );

		$status = $this->getService()->unmarkFalsePositive( $this->reviewer(), 1234567, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-disabled', $status );
		$this->assertSame( 404, $status->getValue() );
	}

	public function testUnmarkReturnsUnprocessableWhenNotMarked(): void {
		$revId = $this->createRevisionId();

		$status = $this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG );
		$this->assertStatusError( 'wikimediaantiabuse-api-falsepositive-not-marked', $status );
		$this->assertSame( 422, $status->getValue() );
	}

	/** @dataProvider providePageIsDeleted */
	public function testMarkAndUnmarkSwapTags( bool $pageIsDeleted ): void {
		$reviewer = $this->mockRegisteredAuthorityWithPermissions( [ 'viewsuppressed', 'deletedhistory' ] );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->assertSame( [ self::PERSONAL_INFO_TAG ], $this->getTags( $revId ) );

		if ( $pageIsDeleted ) {
			$this->deletePage( 'False positive test page' );
		}

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking must remove the personal-info tag and add the false-positive tag'
		);

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking must restore the personal-info tag and remove the false-positive tag'
		);
	}

	public static function providePageIsDeleted(): array {
		return [
			'Page is not deleted' => [ 'pageIsDeleted' => false ],
			'Page is deleted' => [ 'pageIsDeleted' => true ],
		];
	}

	/** @dataProvider provideSuppressionRights */
	public function testMarkAcceptsEitherSuppressionRight( string $right ): void {
		$reviewer = $this->mockRegisteredAuthorityWithPermissions( [ $right ] );
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $reviewer, $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame( [ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ], $this->getTags( $revId ) );
	}

	public static function provideSuppressionRights(): array {
		return [
			'viewsuppressed' => [ 'right' => 'viewsuppressed' ],
			'suppressrevision' => [ 'right' => 'suppressrevision' ],
		];
	}

	public function testMarkIsIdempotentWhenAlreadyFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking an already false-positive revision leaves it unchanged'
		);
	}

	public function testUnmarkIsIdempotentWhenNotFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking a revision that is not a false positive leaves it unchanged'
		);
	}

	public function testMarkOnRevisionWithBothTagsResolvesToFalsePositive(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->markFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_FALSE_POSITIVE_TAG ],
			$this->getTags( $revId ),
			'Marking a revision that carries both tags leaves only the false-positive tag'
		);
	}

	public function testUnmarkOnRevisionWithBothTagsResolvesToFlagged(): void {
		$revId = $this->createRevisionId();
		$this->applyTag( $revId, self::PERSONAL_INFO_TAG );
		$this->applyTag( $revId, self::PERSONAL_INFO_FALSE_POSITIVE_TAG );

		$this->assertStatusGood(
			$this->getService()->unmarkFalsePositive( $this->reviewer(), $revId, self::PERSONAL_INFO_TAG )
		);
		$this->assertSame(
			[ self::PERSONAL_INFO_TAG ],
			$this->getTags( $revId ),
			'Unmarking a revision that carries both tags leaves only the flagged tag'
		);
	}
}

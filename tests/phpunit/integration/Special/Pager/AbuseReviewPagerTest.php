<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup
 * @group Database
 */
class AbuseReviewPagerTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private const array VANDALISM_ROW = [
		'namespace' => NS_MAIN,
		'title' => 'AbuseReviewPagerTest',
		'user' => 0,
		'user_text' => '127.0.0.1',
		'deleted' => 0,
		'rev_id' => 2,
		'parent_id' => 1,
		'timestamp' => '20260101000000',
		'ts_tags' => 'mw-private-vandalism',
		'is_archive' => '0',
		'page_latest' => 2,
	];

	private const string REVERTED_MESSAGE =
		'wikimediaantiabuse-special-abuse-review-handled-reverted';
	private const string REVISION_DELETED_MESSAGE = 'wikimediaantiabuse-special-abuse-review-handled-revision-deleted';
	private const string PAGE_DELETED_MESSAGE = 'wikimediaantiabuse-special-abuse-review-handled-page-deleted';

	private function getObjectUnderTest( string $abuseReviewTag ): AbuseReviewPager {
		return new AbuseReviewPager(
			RequestContext::getMain(),
			$this->getServiceContainer()->getLinkRenderer(),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewQueueLookup' ),
			$this->getServiceContainer()->getRevisionStore(),
			$this->getServiceContainer()->getLinkBatchFactory(),
			$this->getServiceContainer()->getRowCommentFormatter(),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictPerformerLookup' ),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttributionFormatter' ),
			new AbuseReviewQueueFilters( $abuseReviewTag ),
			0
		);
	}

	public function testFormatValueWhenNameUnknown(): void {
		$objectUnderTest = $this->getObjectUnderTest( 'mw-private-test' );
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->formatValue( 'unknown', 'some value' );
	}

	public function testGetQueryInfoThrows(): void {
		$this->expectException( LogicException::class );
		$this->getObjectUnderTest( '' )->getQueryInfo();
	}

	/** @dataProvider provideUndoLink */
	public function testUndoLink( array $rowOverrides, bool $expectUndoLink ): void {
		$row = (object)array_merge( self::VANDALISM_ROW, $rowOverrides );

		$html = $this->getObjectUnderTest( 'mw-private-vandalism' )->formatRow( $row );

		$this->assertSame( $expectUndoLink, str_contains( $html, 'undo=2' ) );
	}

	public static function provideUndoLink(): array {
		return [
			'Undoable edit' => [ 'rowOverrides' => [], 'expectUndoLink' => true ],
			'Page is deleted' => [ 'rowOverrides' => [ 'is_archive' => '1' ], 'expectUndoLink' => false ],
			'Edit created the page' => [ 'rowOverrides' => [ 'parent_id' => 0 ], 'expectUndoLink' => false ],
			'Edit is reverted' => [
				'rowOverrides' => [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'expectUndoLink' => false,
			],
			'Text is hidden' => [
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectUndoLink' => false,
			],
		];
	}

	/** @dataProvider provideHandledOutsideAbuseReviewMessage */
	public function testHandledOutsideAbuseReviewMessage( array $rowOverrides, ?string $expectedMessage ): void {
		$row = (object)array_merge( self::VANDALISM_ROW, $rowOverrides );

		$html = $this->getObjectUnderTest( 'mw-private-vandalism' )->formatRow( $row );

		$mountPoint = $this->assertSelectorMatchesOneElementInNode(
			DOMUtils::parseHTML( $html ),
			'.mw-wikimediaantiabuse-abuse-review-verdicts-app'
		);
		$payload = DOMCompat::getAttribute( $mountPoint, 'data-verdicts' );
		$this->assertNotNull( $payload, 'the mount point carries the verdicts payload' );
		$verdicts = json_decode( $payload, true, flags: JSON_THROW_ON_ERROR );
		$this->assertSame(
			$expectedMessage,
			$verdicts['handledOutsideAbuseReviewMessage'],
			'the row names why it is handled outside AbuseReview, or null when it is not'
		);
	}

	public static function provideHandledOutsideAbuseReviewMessage(): array {
		return [
			'Not handled' => [ 'rowOverrides' => [], 'expectedMessage' => null ],
			'Edit is reverted' => [
				'rowOverrides' => [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'expectedMessage' => self::REVERTED_MESSAGE,
			],
			'Text is hidden' => [
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			],
			'Text is suppressed' => [
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED ],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			],
			'Only the summary is hidden' => [
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_COMMENT ],
				'expectedMessage' => null,
			],
			'Only the username is suppressed, as a hide-user block does' => [
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_USER | RevisionRecord::DELETED_RESTRICTED ],
				'expectedMessage' => null,
			],
			'Page is deleted' => [
				'rowOverrides' => [ 'is_archive' => '1' ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			],
			'Page is deleted and the edit reverted' => [
				'rowOverrides' => [ 'is_archive' => '1', 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			],
			'Page is deleted and the text hidden' => [
				'rowOverrides' => [ 'is_archive' => '1', 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			],
			'Text is hidden and the edit reverted' => [
				'rowOverrides' => [
					'deleted' => RevisionRecord::DELETED_TEXT,
					'ts_tags' => 'mw-private-vandalism,mw-reverted',
				],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			],
		];
	}

	/** @dataProvider provideRollbackLink */
	public function testRollbackLink( array $rowOverrides, bool $viewerCanRollback, bool $expectRollbackLink ): void {
		$page = $this->getNonexistingTestPage();
		$this->editPage( $page, 'First', '', NS_MAIN, $this->getTestUser()->getUser() );
		$revision = $this->editPage( $page, 'Second', '', NS_MAIN, $this->getTestSysop()->getUser() )
			->getNewRevision();
		RequestContext::getMain()->setUser(
			$viewerCanRollback ? $this->getTestSysop()->getUser() : $this->getTestUser()->getUser()
		);
		$row = (object)array_merge( [
			'namespace' => $page->getNamespace(),
			'title' => $page->getDBkey(),
			'user' => $revision->getUser()->getId(),
			'user_text' => $revision->getUser()->getName(),
			'deleted' => 0,
			'rev_id' => $revision->getId(),
			'parent_id' => $revision->getParentId(),
			'timestamp' => $revision->getTimestamp(),
			'ts_tags' => 'mw-private-vandalism',
			'is_archive' => '0',
			'page_latest' => $revision->getId(),
		], $rowOverrides );

		$html = $this->getObjectUnderTest( 'mw-private-vandalism' )->formatRow( $row );

		$this->assertSame( $expectRollbackLink, str_contains( $html, 'action=rollback' ) );
	}

	public static function provideRollbackLink(): array {
		return [
			'Latest edit' => [ 'rowOverrides' => [], 'viewerCanRollback' => true, 'expectRollbackLink' => true ],
			'Viewer cannot roll back' => [
				'rowOverrides' => [],
				'viewerCanRollback' => false,
				'expectRollbackLink' => false,
			],
			'Edit is not the latest' => [
				'rowOverrides' => [ 'page_latest' => 0 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Edit created the page' => [
				'rowOverrides' => [ 'parent_id' => 0 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Edit is reverted' => [
				'rowOverrides' => [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Revision cannot be loaded' => [
				'rowOverrides' => [ 'rev_id' => 999999999, 'page_latest' => 999999999 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
		];
	}
}

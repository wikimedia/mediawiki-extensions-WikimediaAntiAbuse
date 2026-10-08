<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager
 * @group Database
 */
class AbuseReviewPagerTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private function getObjectUnderTest( string $abuseReviewTag ): AbuseReviewPager {
		return new AbuseReviewPager(
			RequestContext::getMain(),
			$this->getServiceContainer()->getLinkRenderer(),
			$this->getServiceContainer()->getChangeTagsStore(),
			$this->getServiceContainer()->getRevisionStore(),
			$this->getServiceContainer()->getArchivedRevisionLookup(),
			$this->getServiceContainer()->getLinkBatchFactory(),
			$this->getServiceContainer()->getRowCommentFormatter(),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictPerformerLookup' ),
			$this->getServiceContainer()->get( 'WikimediaAntiAbuseAbuseReviewVerdictAttributionFormatter' ),
			$abuseReviewTag,
			false,
			false,
			[],
			[],
			[],
			0,
			0
		);
	}

	public function testFormatValueWhenNameUnknown(): void {
		$objectUnderTest = $this->getObjectUnderTest( 'mw-private-test' );
		$this->expectException( InvalidArgumentException::class );
		$objectUnderTest->formatValue( 'unknown', 'some value' );
	}

	/** @dataProvider provideGetQueryInfoWhenInvalidTableProvided */
	public function testGetQueryInfoWhenInvalidTableProvided( ?string $table ): void {
		$this->expectException( LogicException::class );
		$this->getObjectUnderTest( '' )->getQueryInfo( $table );
	}

	public static function provideGetQueryInfoWhenInvalidTableProvided(): array {
		return [
			'No table provided' => [ 'table' => null ],
			'Unhandled table provided' => [ 'table' => 'logging' ],
		];
	}

	/** @dataProvider provideTablesForEmptyTagsFilter */
	public function testGetQueryInfoWhenTagsFilterEmpty( string $table ): void {
		$objectUnderTest = $this->getObjectUnderTest( '' );
		$actualQueryInfo = $objectUnderTest->getQueryInfo( $table );
		$this->assertContains( '1=0', $actualQueryInfo['conds'] );
	}

	public static function provideTablesForEmptyTagsFilter(): array {
		return [
			'revision table' => [ 'revision' ],
			'archive table' => [ 'archive' ],
		];
	}

	/** @dataProvider provideUndoLink */
	public function testUndoLink( array $rowOverrides, bool $expectUndoLink ): void {
		$row = (object)array_merge( [
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
		], $rowOverrides );

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
			'Latest edit' => [ [], true, true ],
			'Viewer cannot roll back' => [ [], false, false ],
			'Edit is not the latest' => [ [ 'page_latest' => 0 ], true, false ],
			'Edit created the page' => [ [ 'parent_id' => 0 ], true, false ],
			'Edit is reverted' => [ [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ], true, false ],
		];
	}
}

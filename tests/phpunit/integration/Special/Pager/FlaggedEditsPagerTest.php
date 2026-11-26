<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Special\Pager;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\FlaggedEditsPager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\FlaggedEditsPager
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup
 * @group Database
 */
class FlaggedEditsPagerTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private const array FLAGGED_ROW = [
		'namespace' => NS_MAIN,
		'title' => 'FlaggedEditsPagerTest',
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

	private function getObjectUnderTest( string $abuseReviewTag ): FlaggedEditsPager {
		return new FlaggedEditsPager(
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
	public function testUndoLink( string $abuseReviewTag, array $rowOverrides, bool $expectUndoLink ): void {
		$row = (object)array_merge( self::FLAGGED_ROW, [ 'ts_tags' => $abuseReviewTag ], $rowOverrides );

		$html = $this->getObjectUnderTest( $abuseReviewTag )->formatRow( $row );

		$this->assertSame( $expectUndoLink, str_contains( $html, 'undo=2' ) );
	}

	public static function provideUndoLink(): array {
		return [
			'Undoable edit' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [],
				'expectUndoLink' => true,
			],
			'Page is deleted' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'is_archive' => '1' ],
				'expectUndoLink' => false,
			],
			'Edit created the page' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'parent_id' => 0 ],
				'expectUndoLink' => false,
			],
			'Edit is reverted' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'expectUndoLink' => false,
			],
			'Text is hidden' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectUndoLink' => false,
			],
			'Undoable edit on the link spam queue' => [
				'abuseReviewTag' => 'mw-private-link-spam',
				'rowOverrides' => [],
				'expectUndoLink' => true,
			],
			'Undoable edit on the personal info queue' => [
				'abuseReviewTag' => 'mw-private-personal-info',
				'rowOverrides' => [],
				'expectUndoLink' => true,
			],
			'Reverted edit on the personal info queue does not include undo link' => [
				'abuseReviewTag' => 'mw-private-personal-info',
				'rowOverrides' => [ 'ts_tags' => 'mw-private-personal-info,mw-reverted' ],
				'expectUndoLink' => false,
			],
		];
	}

	/** @dataProvider provideHandledOutsideAbuseReviewMessage */
	public function testHandledOutsideAbuseReviewMessage(
		string $abuseReviewTag,
		array $rowOverrides,
		?string $expectedMessage
	): void {
		$row = (object)array_merge( self::FLAGGED_ROW, [ 'ts_tags' => $abuseReviewTag ], $rowOverrides );

		$html = $this->getObjectUnderTest( $abuseReviewTag )->formatRow( $row );

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
		$cases = [];
		// Vandalism and link spam have the same rules for what is considered handled
		foreach ( [ 'mw-private-vandalism', 'mw-private-link-spam' ] as $tag ) {
			$cases["Not handled ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [],
				'expectedMessage' => null,
			];
			$cases["Edit is reverted ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'ts_tags' => "$tag,mw-reverted" ],
				'expectedMessage' => self::REVERTED_MESSAGE,
			];
			$cases["Text is hidden ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			];
			$cases["Text is suppressed ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [
					'deleted' => RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED,
				],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			];
			$cases["Only the summary is hidden ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'deleted' => RevisionRecord::DELETED_COMMENT ],
				'expectedMessage' => null,
			];
			$cases["Only the username is suppressed, as a hide-user block does ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [
					'deleted' => RevisionRecord::DELETED_USER | RevisionRecord::DELETED_RESTRICTED,
				],
				'expectedMessage' => null,
			];
			$cases["Page is deleted ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'is_archive' => '1' ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			];
			$cases["Page is deleted and the edit reverted ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'is_archive' => '1', 'ts_tags' => "$tag,mw-reverted" ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			];
			$cases["Page is deleted and the text hidden ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [ 'is_archive' => '1', 'deleted' => RevisionRecord::DELETED_TEXT ],
				'expectedMessage' => self::PAGE_DELETED_MESSAGE,
			];
			$cases["Text is hidden and the edit reverted ($tag)"] = [
				'abuseReviewTag' => $tag,
				'rowOverrides' => [
					'deleted' => RevisionRecord::DELETED_TEXT,
					'ts_tags' => "$tag,mw-reverted",
				],
				'expectedMessage' => self::REVISION_DELETED_MESSAGE,
			];
		}

		return $cases;
	}

	/** @dataProvider provideRollbackLink */
	public function testRollbackLink(
		string $abuseReviewTag,
		array $rowOverrides,
		bool $viewerCanRollback,
		bool $expectRollbackLink
	): void {
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
			'ts_tags' => $abuseReviewTag,
			'is_archive' => '0',
			'page_latest' => $revision->getId(),
		], $rowOverrides );

		$html = $this->getObjectUnderTest( $abuseReviewTag )->formatRow( $row );

		$this->assertSame( $expectRollbackLink, str_contains( $html, 'action=rollback' ) );
	}

	public static function provideRollbackLink(): array {
		return [
			'Latest edit' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [],
				'viewerCanRollback' => true,
				'expectRollbackLink' => true,
			],
			'Viewer cannot roll back' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [],
				'viewerCanRollback' => false,
				'expectRollbackLink' => false,
			],
			'Edit is not the latest' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'page_latest' => 0 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Edit created the page' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'parent_id' => 0 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Edit is reverted' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'ts_tags' => 'mw-private-vandalism,mw-reverted' ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Revision cannot be loaded' => [
				'abuseReviewTag' => 'mw-private-vandalism',
				'rowOverrides' => [ 'rev_id' => 999999999, 'page_latest' => 999999999 ],
				'viewerCanRollback' => true,
				'expectRollbackLink' => false,
			],
			'Latest edit on the link spam queue' => [
				'abuseReviewTag' => 'mw-private-link-spam',
				'rowOverrides' => [],
				'viewerCanRollback' => true,
				'expectRollbackLink' => true,
			],
			'Latest edit on the personal info queue' => [
				'abuseReviewTag' => 'mw-private-personal-info',
				'rowOverrides' => [],
				'viewerCanRollback' => true,
				'expectRollbackLink' => true,
			],
		];
	}

	public function testMovedParagraphAnchorsAreUniquePerDiff(): void {
		// Real wikidiff2 inline output for one moved paragraph (wikidiff2 tests/001.phpt)
		$diff = '<div class="mw-diff-inline-moved mw-diff-inline-moved-source mw-diff-inline-moved-downwards">' .
			'<a name="movedpara_5_0_lhs"></a>---line---<a class="mw-diff-movedpara-left" data-title-tag="old" ' .
			'href="#movedpara_7_0_rhs">&#9660;</a></div>' . "\n" .
			'<div class="mw-diff-inline-moved mw-diff-inline-moved-destination mw-diff-inline-moved-upwards">' .
			'<a name="movedpara_7_0_rhs"></a><a class="mw-diff-movedpara-right" data-title-tag="new" ' .
			'href="#movedpara_5_0_lhs">&#9650;</a>---line---</div>';

		$pager = TestingAccessWrapper::newFromClass( FlaggedEditsPager::class );

		$diffRevision5 = $pager->makeMovedParagraphAnchorsUnique( $diff, 5 );
		$diffRevision9 = $pager->makeMovedParagraphAnchorsUnique( $diff, 9 );

		// Collect anchor names from both diffs.
		preg_match_all( '/name="(movedpara_[^"]+)"/', $diffRevision5, $m );
		$all = $m[1];

		preg_match_all( '/name="(movedpara_[^"]+)"/', $diffRevision9, $m );
		$all = array_merge( $all, $m[1] );

		// Assert there are no duplicate anchor names across diffs.
		$this->assertSame(
			[],
			array_keys( array_filter( array_count_values( $all ), static fn ( $n ) => $n > 1 ) )
		);

		// Verify every href target exists in its own diff.
		foreach ( [ $diffRevision5, $diffRevision9 ] as $rowDiff ) {
			preg_match_all( '/name="(movedpara_[^"]+)"/', $rowDiff, $m );
			$names = $m[1];

			preg_match_all( '/href="#(movedpara_[^"]+)"/', $rowDiff, $m );
			foreach ( $m[1] as $href ) {
				$this->assertContains( $href, $names );
			}
		}
	}

	public function testMovedParagraphTextOutsideAnchorsIsUnchanged(): void {
		$pager = TestingAccessWrapper::newFromClass( FlaggedEditsPager::class );
		$text = '<div class="mw-diff-inline-added"><ins>see movedpara_5_0_lhs</ins></div>';

		$result = $pager->makeMovedParagraphAnchorsUnique( $text, 5 );

		$this->assertSame( $text, $result );
	}
}

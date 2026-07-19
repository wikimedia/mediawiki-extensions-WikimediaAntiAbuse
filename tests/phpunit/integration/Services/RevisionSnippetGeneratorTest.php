<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Services;

use MediaWiki\Content\FallbackContent;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\RevisionSnippetGenerator;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Services\RevisionSnippetGenerator
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Diff\DifflibUnifiedDiffFormatter
 * @group Database
 */
class RevisionSnippetGeneratorTest extends MediaWikiIntegrationTestCase {

	private function getGenerator(): RevisionSnippetGenerator {
		return $this->getServiceContainer()->get( 'WikimediaAntiAbuseRevisionSnippetGenerator' );
	}

	/**
	 * Saves each content string as a consecutive edit and returns the last revision.
	 */
	private function makeRevision( array $contents ): RevisionRecord {
		foreach ( $contents as $content ) {
			$status = $this->editPage( 'Snippet test page', $content );
		}

		return $status->getNewRevision();
	}

	public function testGetPageTitleText(): void {
		$revisionRecord = $this->editPage( 'Talk:Snippet title', 'Some text' )->getNewRevision();

		$this->assertSame( 'Talk:Snippet title', $this->getGenerator()->getPageTitleText( $revisionRecord ) );
	}

	/** @dataProvider provideFirstParagraph */
	public function testGetFirstParagraph( array $contents, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->getGenerator()->getFirstParagraph( $this->makeRevision( $contents ) )
		);
	}

	public static function provideFirstParagraph(): array {
		return [
			'parent revision preferred, whitespace collapsed' => [
				'contents' => [
					"Original  first\u{00A0}paragraph.\n\nBody line.",
					"Changed first paragraph.\n\nBody line.",
				],
				'expected' => 'Original first paragraph.',
			],
			'page creation falls back to the revision itself' => [
				'contents' => [ "New first paragraph.\n\nBody line." ],
				'expected' => 'New first paragraph.',
			],
		];
	}

	/** @dataProvider provideUnifiedDiff */
	public function testGetUnifiedDiff( array $contents, string $expected ): void {
		$this->assertSame( $expected, $this->getGenerator()->getUnifiedDiff( $this->makeRevision( $contents ) ) );
	}

	public static function provideUnifiedDiff(): array {
		return [
			'page creation' => [
				'contents' => [ "First line.\nSecond line." ],
				'expected' => "--- previous_revision\n" .
					"+++ current_revision\n" .
					"@@ -0,0 +1,2 @@\n" .
					"+First line.\n" .
					"+Second line.",
			],
			'single-line change' => [
				'contents' => [ 'Old line.', 'New line.' ],
				'expected' => "--- previous_revision\n" .
					"+++ current_revision\n" .
					"@@ -1 +1 @@\n" .
					"-Old line.\n" .
					"+New line.",
			],
			'blanked revision' => [
				'contents' => [ 'Some text.', '' ],
				'expected' => '',
			],
		];
	}

	public function testParentLookupFallsBackToPrimary(): void {
		$revisionRecord = $this->makeRevision( [ 'Old line.', 'New line.' ] );

		$parentRevision = $this->getServiceContainer()->getRevisionLookup()
			->getRevisionById( $revisionRecord->getParentId() );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->method( 'getRevisionById' )->willReturnCallback(
			static function (
				int $id,
				int $flags = IDBAccessObject::READ_NORMAL
			) use ( $parentRevision ): ?RevisionRecord {
				return $flags === IDBAccessObject::READ_LATEST ? $parentRevision : null;
			}
		);
		$this->setService( 'RevisionLookup', $revisionLookup );

		$this->assertStringEndsWith(
			"@@ -1 +1 @@\n" .
			"-Old line.\n" .
			"+New line.",
			$this->getGenerator()->getUnifiedDiff( $revisionRecord ),
			'The diff must use the primary-loaded parent instead of treating the edit as a page creation'
		);
	}

	/** @dataProvider provideAddedAndRemovedLines */
	public function testGetAddedAndRemovedLines(
		array $contents,
		string $expectedAdded,
		string $expectedRemoved
	): void {
		$revisionRecord = $this->makeRevision( $contents );

		$this->assertSame( $expectedAdded, $this->getGenerator()->getAddedLines( $revisionRecord ) );
		$this->assertSame( $expectedRemoved, $this->getGenerator()->getRemovedLines( $revisionRecord ) );
	}

	public static function provideAddedAndRemovedLines(): array {
		return [
			'changed and added lines' => [
				'contents' => [ "Kept line.\nOld line.", "Kept line.\nNew line.\nExtra line." ],
				'expectedAdded' => "New line.\nExtra line.",
				'expectedRemoved' => 'Old line.',
			],
			'page creation' => [
				'contents' => [ "First line.\nSecond line." ],
				'expectedAdded' => "First line.\nSecond line.",
				'expectedRemoved' => '',
			],
			'blanked revision' => [
				'contents' => [ 'Some text.', '' ],
				'expectedAdded' => '',
				'expectedRemoved' => 'Some text.',
			],
		];
	}

	public function testNonTextContentYieldsNull(): void {
		$revisionRecord = new MutableRevisionRecord( $this->getExistingTestPage()->getTitle() );
		$revisionRecord->setContent( SlotRecord::MAIN, new FallbackContent( '{}', 'agr-unknown' ) );

		$this->assertNull( $this->getGenerator()->getUnifiedDiff( $revisionRecord ) );
		$this->assertNull( $this->getGenerator()->getAddedLines( $revisionRecord ) );
		$this->assertNull( $this->getGenerator()->getRemovedLines( $revisionRecord ) );
	}
}

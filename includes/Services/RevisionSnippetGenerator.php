<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Content\TextContent;
use MediaWiki\Extension\WikimediaAntiAbuse\Diff\DifflibUnifiedDiffFormatter;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Diff\Diff;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Generates parts of a revision (page title, first paragraph, unified diff, added and
 * removed lines) that content policies combine into the content a model evaluates.
 * Everything is returned at full length; callers apply their own length limits.
 *
 * This service is also used by Wikimedia code that is not public. Treat its public
 * methods as stable to call, and be careful when changing their output.
 */
class RevisionSnippetGenerator {

	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly TitleFormatter $titleFormatter,
	) {
	}

	public function getPageTitleText( RevisionRecord $revisionRecord ): string {
		return $this->titleFormatter->getPrefixedText( $revisionRecord->getPage() );
	}

	/**
	 * First paragraph of the page as of the parent revision, so the checked edit cannot
	 * rewrite its own context. Falls back to the revision itself for page creations.
	 *
	 * The paragraph is the wikitext up to the first blank line, with whitespace
	 * collapsed; markup is not parsed.
	 *
	 * @return string Empty string when there is no paragraph
	 */
	public function getFirstParagraph( RevisionRecord $revisionRecord ): string {
		$paragraph = $this->extractFirstParagraph( $this->getParentText( $revisionRecord ) ?? '' );
		if ( $paragraph === '' ) {
			$paragraph = $this->extractFirstParagraph( $this->getRevisionText( $revisionRecord ) ?? '' );
		}

		return $paragraph;
	}

	/**
	 * Unified diff of the parent revision to the given revision, in Python difflib's
	 * format. A page creation is diffed against an empty document.
	 *
	 * @return string|null Empty string when the revision is blank or nothing changed,
	 *   null when the revision's content is not text
	 */
	public function getUnifiedDiff( RevisionRecord $revisionRecord ): ?string {
		$currentText = $this->getRevisionText( $revisionRecord );
		if ( $currentText === null ) {
			return null;
		}
		// A blanked revision produces no diff
		if ( $currentText === '' ) {
			return '';
		}

		$diff = $this->getDiff( $revisionRecord, $currentText );
		if ( $diff->isEmpty() ) {
			return '';
		}

		return "--- previous_revision\n+++ current_revision\n" .
			rtrim( ( new DifflibUnifiedDiffFormatter() )->format( $diff ), "\n" );
	}

	/**
	 * Lines present in the given revision but not in its parent, with surrounding
	 * whitespace trimmed. A page creation returns the whole body.
	 *
	 * @return string|null Empty string when nothing was added, null when the
	 *   revision's content is not text
	 */
	public function getAddedLines( RevisionRecord $revisionRecord ): ?string {
		return $this->getLinesFromDiffSide( $revisionRecord, true );
	}

	/**
	 * Lines present in the parent revision but not in the given revision, with
	 * surrounding whitespace trimmed.
	 *
	 * @return string|null Empty string when nothing was removed, null when the
	 *   revision's content is not text
	 */
	public function getRemovedLines( RevisionRecord $revisionRecord ): ?string {
		return $this->getLinesFromDiffSide( $revisionRecord, false );
	}

	private function getLinesFromDiffSide( RevisionRecord $revisionRecord, bool $added ): ?string {
		$currentText = $this->getRevisionText( $revisionRecord );
		if ( $currentText === null ) {
			return null;
		}

		$lines = [];
		foreach ( $this->getDiff( $revisionRecord, $currentText )->getEdits() as $edit ) {
			if ( $edit->getType() === 'copy' ) {
				continue;
			}
			$sideLines = $added ? $edit->getClosing() : $edit->getOrig();
			if ( $sideLines ) {
				array_push( $lines, ...$sideLines );
			}
		}

		return trim( implode( "\n", $lines ) );
	}

	private function getDiff( RevisionRecord $revisionRecord, string $currentText ): Diff {
		$parentText = $this->getParentText( $revisionRecord ) ?? '';

		return new Diff(
			$parentText === '' ? [] : explode( "\n", $parentText ),
			$currentText === '' ? [] : explode( "\n", $currentText )
		);
	}

	private function getParentText( RevisionRecord $revisionRecord ): ?string {
		$parentId = $revisionRecord->getParentId();
		if ( !$parentId ) {
			return null;
		}

		// Fall back to the primary so replica lag does not make the edit look like a page creation
		$parentRevision = $this->revisionLookup->getRevisionById( $parentId )
			?? $this->revisionLookup->getRevisionById( $parentId, IDBAccessObject::READ_LATEST );

		return $parentRevision ? $this->getRevisionText( $parentRevision ) : null;
	}

	private function getRevisionText( RevisionRecord $revisionRecord ): ?string {
		$content = $revisionRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW );

		return $content instanceof TextContent ? $content->getText() : null;
	}

	private function extractFirstParagraph( string $wikitext ): string {
		foreach ( preg_split( '/\n\s*\n/u', $wikitext ) as $block ) {
			$collapsed = implode( ' ', preg_split( '/\s+/u', $block, -1, PREG_SPLIT_NO_EMPTY ) );
			if ( $collapsed !== '' ) {
				return $collapsed;
			}
		}

		return '';
	}
}

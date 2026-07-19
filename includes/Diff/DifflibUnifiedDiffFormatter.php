<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Diff;

use Wikimedia\Diff\UnifiedDiffFormatter;

/**
 * Unified diffs in the format of Python's difflib, which content policies are tuned
 * against: 3 context lines, `@@ -5 +5 @@` for single-line ranges, `-0,0` for creations.
 */
class DifflibUnifiedDiffFormatter extends UnifiedDiffFormatter {

	/** @var int */
	protected $leadingContextLines = 3;

	/** @var int */
	protected $trailingContextLines = 3;

	/** @inheritDoc */
	protected function blockHeader( $xbeg, $xlen, $ybeg, $ylen ) {
		return '@@ -' . self::formatRange( $xbeg, $xlen ) . ' +' . self::formatRange( $ybeg, $ylen ) . ' @@';
	}

	private static function formatRange( int $start, int $length ): string {
		if ( $length === 1 ) {
			return (string)$start;
		}

		return ( $length === 0 ? $start - 1 : $start ) . ",$length";
	}
}

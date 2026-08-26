<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\Navigation\CodexPagerNavigationBuilder;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;

class AbuseReviewPagerNavigationBuilder extends CodexPagerNavigationBuilder {
	public function __construct(
		private readonly IContextSource $context,
		array $queryValues,
		private readonly int $numberOfFiltersApplied,
	) {
		parent::__construct( $this->context, $queryValues );
	}

	/**
	 * Returns the limit form for the CodexTablePager.
	 *
	 * This is modified to add a filters button which is hidden unless the user is using a mobile device,
	 * so that the limit and filters buttons can be on the same line on mobile devices.
	 *
	 * @inheritDoc
	 */
	public function getLimitForm(): string {
		return parent::getLimitForm() . $this->getFilterButton() . "\n";
	}

	/**
	 * Returns a Codex button that can be used to open the filter dialog on the Special:AbuseReview page
	 */
	public function getFilterButton(): string {
		$buttonLabelHtml = Html::element(
			'span',
			[
				'aria-hidden' => 'true',
				'class' => 'mw-wikimediaantiabuse-abuse-review-icon-filter cdx-button__icon',
			]
		);
		$buttonLabelHtml .= $this->msg( 'wikimediaantiabuse-special-abuse-review-filter-open-button' )->escaped();
		if ( $this->numberOfFiltersApplied !== 0 ) {
			$codex = new Codex( new MediaWikiLocalization( $this->context ) );
			$buttonLabelHtml .= $codex->infoChip()
				->setIcon( null )
				->setAttributes( [
					'class' => 'mw-wikimediaantiabuse-abuse-review-filter-button-filters-applied-chip',
				] )
				->setText( $this->context->getLanguage()->formatNum( $this->numberOfFiltersApplied ) )
				->getHtml();
		}

		return Html::rawElement(
			'button',
			[
				'type' => 'button',
				'aria-label' => $this->msg( 'wikimediaantiabuse-special-abuse-review-filter-open-button' )->text(),
				'aria-haspopover' => 'dialog',
				'class' => 'mw-wikimediaantiabuse-abuse-review-filter-button cdx-button',
			],
			$buttonLabelHtml
		);
	}
}

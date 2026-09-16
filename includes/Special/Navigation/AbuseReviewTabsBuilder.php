<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;

/**
 * Allows generating the HTML for the flag tabs shown above the special page summary.
 */
readonly class AbuseReviewTabsBuilder {

	/**
	 * @param IContextSource $context
	 * @param array<string,int> $flags The flags the user may review, in tab order as the keys.
	 *   The values are how many revisions need review in that tab.
	 * @param string $selectedTab The flag selected for the current page
	 * @param int $countCap The maximum number of revisions shown in the count chip
	 */
	public function __construct(
		private IContextSource $context,
		private array $flags,
		private string $selectedTab,
		private int $countCap,
	) {
	}

	/**
	 * Gets the HTML of the CSS-only Codex tabs component.
	 *
	 * Each tab is a link, which means that users can open in a new tab or bookmark it (which the standard button
	 * does not allow). The tabs are not shown if there is only one tab.
	 *
	 * @return string Empty when there is only one flag to show, which needs no tabs.
	 */
	public function getHtml(): string {
		if ( count( $this->flags ) < 2 ) {
			return '';
		}

		$items = '';
		foreach ( $this->flags as $flag => $count ) {
			$items .= $this->buildTab( $flag, $count );
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'cdx-tabs mw-wikimediaantiabuse-abuse-review-tabs' ],
			Html::rawElement(
				'div',
				[ 'class' => 'cdx-tabs__header' ],
				Html::rawElement(
					'nav',
					[
						'class' => 'cdx-tabs__list',
						'aria-label' => $this->context->msg(
							'wikimediaantiabuse-special-abuse-review-tabs-label'
						)->text(),
						'role' => 'tablist',
					],
					$items
				)
			)
		);
	}

	/**
	 * Builds the HTML for a single tab, which links to the page showing the default view of each flag's queue.
	 *
	 * @param string $tab The tab name, which is also the flag name.
	 * @param int $count The number of revisions that need review in this tab.
	 */
	private function buildTab( string $tab, int $count ): string {
		$isSelected = $tab === $this->selectedTab;
		$attribs = [
			'class' => 'cdx-tabs__list__item mw-wikimediaantiabuse-abuse-review-tab-' . $tab,
			'href' => $this->context->getTitle()->getLocalURL( [
				'tab' => $tab,
				'limit' => $this->context->getRequest()->getVal( 'limit' ),
			] ),
			'role' => 'tab',
			'aria-selected' => $tab === $this->selectedTab ? 'true' : 'false',
		];
		if ( $isSelected ) {
			$attribs['aria-selected'] = 'true';
		}

		// Generates:
		// * wikimediaantiabuse-special-abuse-review-tab-mw-private-personal-info
		// * wikimediaantiabuse-special-abuse-review-tab-mw-private-vandalism
		$label = $this->context->msg( 'wikimediaantiabuse-special-abuse-review-tab-' . $tab )
			->rawParams( $this->buildCount( $count ) )
			->numParams( $count )
			->escaped();
		return Html::rawElement( 'a', $attribs, Html::rawElement( 'span', [], $label ) );
	}

	/**
	 * Builds the HTML for the Codex InfoChip showing the number of revisions that need review a tab.
	 */
	private function buildCount( int $count ): string {
		$text = $count > $this->countCap
			? $this->context->msg( 'wikimediaantiabuse-special-abuse-review-tab-count-capped' )
				->numParams( $this->countCap )
				->text()
			: $this->context->getLanguage()->formatNum( $count );

		$codex = new Codex( new MediaWikiLocalization( $this->context ) );
		return $codex->infoChip()
			->setIcon( null )
			->setAttributes( [ 'class' => 'mw-wikimediaantiabuse-abuse-review-tabs__count' ] )
			->setText( $text )
			->getHtml();
	}
}

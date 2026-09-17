<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;

/**
 * Allows generating the HTML for the flag tabs shown above the special page summary.
 */
readonly class AbuseReviewTabsBuilder {

	/**
	 * @param IContextSource $context
	 * @param string[] $flags The abuse review flags the user may review, in tab order
	 * @param string $selectedTab The flag selected for the current page
	 */
	public function __construct(
		private IContextSource $context,
		private array $flags,
		private string $selectedTab,
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
		foreach ( $this->flags as $flag ) {
			$items .= $this->buildTab( $flag );
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
	 */
	private function buildTab( string $tab ): string {
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
		$label = $this->context->msg( 'wikimediaantiabuse-special-abuse-review-tab-' . $tab )->text();
		return Html::rawElement( 'a', $attribs, Html::element( 'span', [], $label ) );
	}
}

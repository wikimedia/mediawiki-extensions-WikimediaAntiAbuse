<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special;

use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Message\Message;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;

class SpecialAbuseReview extends SpecialPage {

	// The paging and ordering the pager reads out of the query string.
	private const array PAGER_STATE_PARAMS = [ 'limit', 'sort', 'asc', 'desc' ];

	private array $tagsFilter;
	private bool $includeHandledRevisions;

	/**
	 * @var int The number of filters applied (counting all filters present in the filters dialog)
	 */
	private int $numberOfFiltersApplied = 0;

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
	) {
		parent::__construct( 'AbuseReview' );
	}

	/** @inheritDoc */
	public function execute( $subPage ): void {
		parent::execute( $subPage );
		$this->addHelpLink( 'Extension:WikimediaAntiAbuse' );
		$this->getOutput()->addModuleStyles( [
			'ext.wikimediaAntiAbuse.styles',
			'mediawiki.diff.styles',
			'mediawiki.interface.helpers.styles',
		] );
		$this->getOutput()->addModules( 'ext.wikimediaAntiAbuse' );
		$this->getOutput()->addHtml( '<div id="mw-wikimediaantiabuse-abuse-review-filter-app"></div>' );

		$this->parseFilters();
		$this->displayPager();
	}

	/**
	 * Parse the filters from the request
	 */
	private function parseFilters(): void {
		$showFalsePositives = $this->getRequest()->getBool( 'wpShowFalsePositives' );
		$showHandledRevisions = $this->getRequest()->getBool( 'wpShowHandledRevisions' );

		$this->tagsFilter = $this->changeTagsStore->filterViewableTags(
			array_keys( ChangeTagsHandler::REVIEWABLE_TAGS ),
			$this->getAuthority()
		);
		if ( $showFalsePositives ) {
			$this->tagsFilter = array_merge(
				$this->tagsFilter,
				$this->changeTagsStore->filterViewableTags(
					array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'falsePositive' ),
					$this->getAuthority()
				)
			);
			$this->numberOfFiltersApplied++;
		}
		$this->includeHandledRevisions = $showHandledRevisions;
		if ( $this->includeHandledRevisions ) {
			$this->numberOfFiltersApplied++;
		}

		$this->getOutput()->addJsConfigVars(
			'wgWikimediaAntiAbuseActiveFilters',
			[
				'showFalsePositives' => $showFalsePositives,
				'showHandledRevisions' => $showHandledRevisions,
			]
		);
	}

	/**
	 * Displays the abuse review pager
	 */
	private function displayPager(): void {
		$pager = new AbuseReviewPager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$this->changeTagsStore,
			$this->changeTagsFormatter,
			$this->revisionStore,
			$this->archivedRevisionLookup,
			$this->linkBatchFactory,
			$this->rowCommentFormatter,
			$this->tagsFilter,
			$this->includeHandledRevisions,
			$this->numberOfFiltersApplied
		);
		$this->getOutput()->addParserOutputContent(
			$pager->getFullOutput(),
			ParserOptions::newFromContext( $this->getContext() )
		);
	}

	/** @inheritDoc */
	public function getDescription(): Message {
		return $this->msg( 'wikimediaantiabuse-special-abuse-review' );
	}

	/** @inheritDoc */
	protected function outputHeader( $summaryMessageKey = '' ): void {
		parent::outputHeader( 'wikimediaantiabuse-special-abuse-review-summary' );
	}

	/** @inheritDoc */
	protected function displayRestrictionError(): void {
		throw new ErrorPageError(
			'permissionserrors',
			'wikimediaantiabuse-special-abuse-review-permission-error'
		);
	}

	/**
	 * A user can view this special page if they can view at least one of the abuse review tags.
	 *
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ): bool {
		return count( $this->changeTagsStore->filterViewableTags(
			array_merge(
				array_keys( ChangeTagsHandler::REVIEWABLE_TAGS ),
				array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'falsePositive' ),
				array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'noFurtherAction' )
			),
			$user
		) ) > 0;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore Merely declarative
	 */
	public function isRestricted() {
		return true;
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore Merely declarative
	 */
	protected function getGroupName(): string {
		return 'changes';
	}
}

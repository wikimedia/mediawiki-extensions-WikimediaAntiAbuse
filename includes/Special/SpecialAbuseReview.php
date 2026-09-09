<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special;

use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewPermissionManager;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Message\Message;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;

class SpecialAbuseReview extends SpecialPage {

	/**
	 * @var string[] The list of valid referrers for Special:AbuseReview. If the referrer is not in this list,
	 *   it will be ignored for the purposes of instrumentation
	 */
	public const array VALID_REFERRERS = [ 'echo_notification' ];

	// The paging and ordering the pager reads out of the query string.
	private const array PAGER_STATE_PARAMS = [ 'limit', 'sort', 'asc', 'desc' ];

	private array $tagsFilter;
	private bool $includeHandledRevisions;
	private array $usernamesFilter;
	private array $revisionsFilter;
	/** @var Title[] */
	private array $pagesFilter;

	/**
	 * @var int The number of filters applied (counting all filters present in the filters dialog)
	 */
	private int $numberOfFiltersApplied = 0;

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly AbuseReviewPermissionManager $permissionManager,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly IAbuseReviewInstrumentationClient $instrumentationClient,
		private readonly TitleFactory $titleFactory,
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

		$appliedFilters = $this->parseFilters();
		$pager = $this->displayPager();

		$pageLoadInstrumentationData = [
			'is_paging_results' => $pager->mOffset || $pager->mIsBackwards,
			'pager_limit' => $pager->mLimit,
			'applied_filters' => $appliedFilters,
		];
		$referrer = $this->getRequest()->getText( 'referrer' );
		if ( in_array( $referrer, self::VALID_REFERRERS, true ) ) {
			$pageLoadInstrumentationData['referrer'] = $referrer;
		}
		$this->instrumentationClient->submitInteraction(
			$this->getContext(),
			'page_load',
			$pageLoadInstrumentationData
		);
	}

	/**
	 * Parse the filters from the request, returning the applied filters in a format acceptable by the
	 * instrumentation client. Also sets the class properties for the filters, which are used by the pager.
	 */
	private function parseFilters(): array {
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

		$this->usernamesFilter = $this->getArrayParam( 'username' );
		if ( $this->usernamesFilter ) {
			$this->numberOfFiltersApplied += count( $this->usernamesFilter );
		}

		$this->revisionsFilter = array_values( array_filter( array_map(
			'intval',
			$this->getArrayParam( 'revision' )
		) ) );
		if ( $this->revisionsFilter ) {
			$this->numberOfFiltersApplied += count( $this->revisionsFilter );
		}

		$this->pagesFilter = array_values( array_filter( array_map(
			$this->titleFactory->newFromText( ... ),
			$this->getArrayParam( 'page' )
		) ) );
		if ( $this->pagesFilter ) {
			$this->numberOfFiltersApplied += count( $this->pagesFilter );
		}

		$pagersFilterAsStringArray = array_map(
			static fn ( Title $title ): string => $title->getPrefixedText(),
			$this->pagesFilter
		);
		$this->getOutput()->addJsConfigVars(
			'wgWikimediaAntiAbuseActiveFilters',
			[
				'showFalsePositives' => $showFalsePositives,
				'showHandledRevisions' => $showHandledRevisions,
				'username' => $this->usernamesFilter,
				'page' => $pagersFilterAsStringArray,
			]
		);

		return [
			'show_false_positives' => $showFalsePositives,
			'show_handled_revisions' => $showHandledRevisions,
			'username' => $this->usernamesFilter,
			'revision' => $this->revisionsFilter,
			'page' => $pagersFilterAsStringArray,
		];
	}

	/**
	 * Given a parameter name that takes an array of values, return the array of values from the request
	 * after sanitising.
	 *
	 * @return string[]
	 */
	private function getArrayParam( string $paramName ): array {
		return array_values( array_filter(
			$this->getRequest()->getArray( $paramName, [] ),
			static fn ( $value ): bool => is_string( $value ) && $value !== ''
		) );
	}

	/**
	 * Displays the abuse review pager, returning the instance of the pager for use in instrumentation.
	 */
	private function displayPager(): AbuseReviewPager {
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
			$this->usernamesFilter,
			$this->revisionsFilter,
			$this->pagesFilter,
			$this->numberOfFiltersApplied
		);
		$this->getOutput()->addParserOutputContent(
			$pager->getFullOutput(),
			ParserOptions::newFromContext( $this->getContext() )
		);
		return $pager;
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

	/** @inheritDoc */
	public function userCanExecute( User $user ): bool {
		return $this->permissionManager->canViewQueue( $user );
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

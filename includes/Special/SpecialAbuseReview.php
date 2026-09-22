<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictAttributionFormatter;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewVerdictPerformerLookup;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Navigation\AbuseReviewTabsBuilder;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use Wikimedia\Codex\Component\HtmlSnippet;
use Wikimedia\Codex\Localization\MediaWikiLocalization;
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialAbuseReview extends SpecialPage {

	/**
	 * @var string[] The list of valid referrers for Special:AbuseReview. If the referrer is not in this list,
	 *   it will be ignored for the purposes of instrumentation
	 */
	public const array VALID_REFERRERS = [ 'echo_notification' ];

	// The paging and ordering the pager reads out of the query string.
	private const array PAGER_STATE_PARAMS = [ 'limit', 'sort', 'asc', 'desc' ];

	/** @var int The limit of rows to count when building the tabs or Echo banner */
	private const int ROW_COUNT_CAP = 100;

	private string $abuseReviewTag;
	private bool $includeFalsePositives;
	private bool $includeHandledRevisions;
	private array $usernamesFilter;
	private array $revisionsFilter;
	/** @var Title[] */
	private array $pagesFilter;
	private int $delayMinutes;

	/** @var string[] The flags the user may review, in tab order */
	private array $reviewableFlags;
	/** @var string The flag whose queue is shown */
	private string $selectedTab;

	/**
	 * @var int The number of filters applied (counting all filters present in the filters dialog)
	 */
	private int $numberOfFiltersApplied = 0;

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly IAbuseReviewInstrumentationClient $instrumentationClient,
		private readonly TitleFactory $titleFactory,
		private readonly IConnectionProvider $dbProvider,
		private readonly AbuseReviewEnabledTagsProvider $abuseReviewEnabledTagsProvider,
		private readonly AbuseReviewVerdictPerformerLookup $verdictPerformerLookup,
		private readonly AbuseReviewVerdictAttributionFormatter $verdictAttributionFormatter,
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
		$this->getOutput()->addJsConfigVars(
			'wgWikimediaAntiAbuseViewerBylines',
			[
				'recorded' => $this->verdictAttributionFormatter->format(
					$this->getContext(),
					$this->getUser(),
					true
				),
				'returned' => $this->verdictAttributionFormatter->format(
					$this->getContext(),
					$this->getUser(),
					false
				),
			]
		);
		$this->getOutput()->addHtml( '<div id="mw-wikimediaantiabuse-abuse-review-filter-app"></div>' );

		$appliedFilters = $this->parseFilters();

		$this->getOutput()->addHTML( $this->buildTabs() );
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
		$this->reviewableFlags = $this->changeTagsStore->filterViewableTags(
			$this->abuseReviewEnabledTagsProvider->getEnabledReviewableTags(),
			$this->getAuthority()
		);

		$selectedTab = $this->getRequest()->getVal( 'tab', '' );
		if ( $selectedTab === '' ) {
			$selectedTabForFilters = '';
			$this->selectedTab = $this->reviewableFlags[0] ?? '';
		} else {
			$this->selectedTab = in_array( $selectedTab, $this->reviewableFlags, true ) ? $selectedTab : '';
			$selectedTabForFilters = $this->selectedTab;
		}

		$this->abuseReviewTag = $this->selectedTab === '' ? '' : $this->selectedTab;

		$showFalsePositives = $this->getRequest()->getBool( 'wpShowFalsePositives' );
		$this->includeFalsePositives = $showFalsePositives;
		if ( $showFalsePositives ) {
			$this->numberOfFiltersApplied++;
		}

		$showHandledRevisions = $this->getRequest()->getBool( 'wpShowHandledRevisions' );
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

		$showRecentEdits = $this->getRequest()->getBool( 'showRecentEdits' );
		$configuredDelayMinutes = $this->getConfiguredDelayMinutes( $this->abuseReviewTag );
		$this->delayMinutes = $showRecentEdits ? 0 : $configuredDelayMinutes;
		if ( $showRecentEdits && $configuredDelayMinutes > 0 ) {
			$this->numberOfFiltersApplied++;
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
				'showRecentEdits' => $showRecentEdits,
				'recentEditsDelayMinutes' => $configuredDelayMinutes,
				'username' => $this->usernamesFilter,
				'page' => $pagersFilterAsStringArray,
				'revision' => $this->revisionsFilter,
				'tab' => $selectedTabForFilters,
			]
		);

		return [
			'show_false_positives' => $showFalsePositives,
			'show_handled_revisions' => $showHandledRevisions,
			'username' => $this->usernamesFilter,
			'revision' => $this->revisionsFilter,
			'page' => $pagersFilterAsStringArray,
			'tab' => $this->selectedTab,
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
		// If user is viewing revisions from a notification, then show a banner linking them back to the main view
		if (
			$this->getRequest()->getVal( 'referrer' ) === 'echo_notification' &&
			$this->revisionsFilter &&
			$this->selectedTab === 'mw-private-personal-info'
		) {
			// Notifications only exist for revisions with the mw-private-personal-info tag,
			// so we can filter by that to get the count of other revisions to review
			$tagFilter = $this->changeTagsStore->filterViewableTags(
				[ 'mw-private-personal-info' ],
				$this->getAuthority()
			)[0] ?? '';
			if ( $tagFilter ) {
				$pager = $this->getPager(
					$tagFilter,
					delayMinutes: $this->getConfiguredDelayMinutes( $tagFilter )
				);
				$otherRevisionsToReviewCount = $this->getRowCount( $pager, $this->revisionsFilter );
				if ( $otherRevisionsToReviewCount ) {
					$this->displayEchoNotificationBanner( $otherRevisionsToReviewCount );
				}
			}
		}

		$pager = $this->getPager(
			$this->abuseReviewTag,
			$this->includeFalsePositives,
			$this->includeHandledRevisions,
			$this->usernamesFilter,
			$this->revisionsFilter,
			$this->pagesFilter,
			$this->delayMinutes,
			$this->numberOfFiltersApplied
		);
		$this->getOutput()->addParserOutputContent(
			$pager->getFullOutput(),
			ParserOptions::newFromContext( $this->getContext() )
		);
		return $pager;
	}

	private function displayEchoNotificationBanner( int $otherRevisionsToReviewCount ): void {
		$bannerContentHtml = $this->msg( 'wikimediaantiabuse-special-abuse-review-echo-notification-banner' )
			->numParams( count( $this->revisionsFilter ), $otherRevisionsToReviewCount )
			->rawParams( $this->getLinkRenderer()->makeKnownLink(
				$this->getPageTitle(),
				$this->msg( 'wikimediaantiabuse-special-abuse-review-echo-notification-banner-link' )->text(),
				[],
				[ 'tab' => 'mw-private-personal-info' ]
			) )
			->parse();

		$bannerHtml = ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )
			->message()
			->setType( 'notice' )
			->setContent( new HtmlSnippet( $bannerContentHtml ) )
			->setAttributes( [ 'class' => 'mw-wikimediaantiabuse-abuse-review-echo-notification-banner' ] )
			->getHtml();
		$this->getOutput()->addHTML( $bannerHtml );
	}

	/**
	 * Fetches the number of rows over all pages for the provided pager, optionally excluding specific revisions
	 * from the count.
	 */
	private function getRowCount( AbuseReviewPager $pager, array $excludeRevisions ): int {
		$rowCount = 0;
		$tablesToQuery = [ 'revision' => 'rev_id', 'archive' => 'ar_rev_id' ];
		$dbr = $this->dbProvider->getReplicaDatabase();
		foreach ( $tablesToQuery as $table => $revIdField ) {
			$rowCountQueryBuilder = $dbr->newSelectQueryBuilder()
				->queryInfo( $pager->getQueryInfo( $table ) )
				->clearFields()
				->select( 'changetagdisplay.ct_id' );
			if ( $excludeRevisions ) {
				$rowCountQueryBuilder->where( $dbr->expr( $revIdField, '!=', $excludeRevisions ) );
			}
			$rowCount += $rowCountQueryBuilder
				->limit( self::ROW_COUNT_CAP + 1 )
				->caller( __METHOD__ )
				->fetchRowCount();
		}

		return $rowCount;
	}

	/**
	 * Constructs an instance of the {@link AbuseReviewPager} with the provided filters applied,
	 * or the defaults for each filter if not provided.
	 */
	private function getPager(
		string $abuseReviewTag,
		bool $includeFalsePositives = false,
		bool $includeHandledRevisions = false,
		array $usernamesFilter = [],
		array $revisionsFilter = [],
		array $pagesFilter = [],
		int $delayMinutes = 0,
		int $numberOfFiltersApplied = 0
	): AbuseReviewPager {
		return new AbuseReviewPager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$this->changeTagsStore,
			$this->revisionStore,
			$this->archivedRevisionLookup,
			$this->linkBatchFactory,
			$this->rowCommentFormatter,
			$this->verdictPerformerLookup,
			$this->verdictAttributionFormatter,
			$abuseReviewTag,
			$includeFalsePositives,
			$includeHandledRevisions,
			$usernamesFilter,
			$revisionsFilter,
			$pagesFilter,
			$delayMinutes,
			$numberOfFiltersApplied
		);
	}

	/**
	 * Builds the HTML for the tabs which allow the user to switch between the different abuse review queues.
	 */
	private function buildTabs(): string {
		$tabsBuilder = new AbuseReviewTabsBuilder(
			$this->getContext(),
			$this->getFlagsForTabsWithCounts(),
			$this->selectedTab,
			self::ROW_COUNT_CAP
		);

		$selectedTabSummary = '';
		if ( $this->selectedTab ) {
			if ( $this->selectedTab === 'mw-private-vandalism' ) {
				$selectedTabSummary .= ( new Codex( new MediaWikiLocalization( $this->getContext() ) ) )
					->message()
					->setType( 'warning' )
					->setAttributes( [
						'class' => 'mw-wikimediaantiabuse-abuse-review-tab-summary-alpha-test-warning',
					] )
					->setContent( $this->msg(
						'wikimediaantiabuse-special-abuse-review-tab-summary-alpha-test-warning-mw-private-vandalism'
					)->text() )
					->getHtml();
			}

			// Uses:
			// * wikimediaantiabuse-special-abuse-review-tab-summary-mw-private-personal-info
			// * wikimediaantiabuse-special-abuse-review-tab-summary-mw-private-vandalism
			$selectedTabMsgKey = 'wikimediaantiabuse-special-abuse-review-tab-summary-' . $this->selectedTab;
			$selectedTabSummary .= Html::rawElement(
				'div',
				[ 'class' => 'mw-wikimediaantiabuse-abuse-review-tab-summary' ],
				$this->msg( $selectedTabMsgKey )->parseAsBlock()
			);
		}

		return $tabsBuilder->getHtml() . $selectedTabSummary;
	}

	/** Gets the number of minutes for which the given queue hides a new revision. */
	private function getConfiguredDelayMinutes( string $abuseReviewTag ): int {
		$delayMinutesByTag = $this->getConfig()->get( 'WikimediaAntiAbuseAbuseReviewDelayMinutes' );
		return max( 0, (int)( $delayMinutesByTag[$abuseReviewTag] ?? 0 ) );
	}

	/**
	 * Gets the list of flags to be used as tabs, along with the number of revisions that would be shown
	 * in the specified tab.
	 *
	 * @return array<string,int>
	 */
	private function getFlagsForTabsWithCounts(): array {
		if ( count( $this->reviewableFlags ) < 2 ) {
			return array_fill_keys( $this->reviewableFlags, 0 );
		}

		$counts = [];
		foreach ( $this->reviewableFlags as $flag ) {
			$counts[$flag] = $this->getRowCount(
				$this->getPager( $flag, delayMinutes: $this->getConfiguredDelayMinutes( $flag ) ),
				[]
			);
		}

		return $counts;
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
		return (bool)$this->changeTagsStore->filterViewableTags(
			$this->abuseReviewEnabledTagsProvider->getAllEnabledTags(),
			$user
		);
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

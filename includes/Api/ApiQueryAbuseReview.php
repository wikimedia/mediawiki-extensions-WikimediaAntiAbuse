<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiPageSet;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryGeneratorBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Cache\GenderCache;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Extension\WikimediaAntiAbuse\AbuseReview\AbuseReviewQueueFilters;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler\ReviewVerdictHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewEnabledTagsProvider;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewQueueLookup;
use MediaWiki\Language\Language;
use MediaWiki\Pager\IndexPager;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\UserNameUtils;
use stdClass;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Lists the revisions of an AbuseReview queue, with the filters of Special:AbuseReview
 */
class ApiQueryAbuseReview extends ApiQueryGeneratorBase {

	private const array TAGS_BY_QUEUE = [
		'vandalism' => ChangeTagsHandler::VANDALISM_TAG,
		'personal-info' => ChangeTagsHandler::PERSONAL_INFO_TAG,
	];

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly AbuseReviewQueueLookup $queueLookup,
		private readonly AbuseReviewEnabledTagsProvider $abuseReviewEnabledTagsProvider,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly CommentStore $commentStore,
		private readonly TitleFactory $titleFactory,
		private readonly UserNameUtils $userNameUtils,
		private readonly GenderCache $genderCache,
		private readonly Language $contentLanguage,
	) {
		parent::__construct( $query, $moduleName, 'abr' );
	}

	public function execute(): void {
		$this->run();
	}

	/** @inheritDoc */
	public function executeGenerator( $resultPageSet ): void {
		$this->run( $resultPageSet );
	}

	private function run( ?ApiPageSet $resultPageSet = null ): void {
		$params = $this->extractRequestParams();
		$abuseReviewTag = self::TAGS_BY_QUEUE[$params['queue']];
		$this->assertCanViewQueue( $abuseReviewTag );

		$rows = iterator_to_array( $this->fetchRows( $abuseReviewTag, $params ) );
		$pageRows = array_slice( $rows, 0, $params['limit'] );
		$nextRow = $rows[$params['limit']] ?? null;
		if ( $nextRow !== null ) {
			$this->setContinueEnumParameter( 'continue', "$nextRow->timestamp|$nextRow->rev_id" );
		}

		if ( $resultPageSet !== null ) {
			$this->addRevisionIds( $resultPageSet, $pageRows );
		} else {
			$this->addRowsToResult( $pageRows, $abuseReviewTag, array_fill_keys( $params['prop'], true ) );
		}
	}

	private function assertCanViewQueue( string $abuseReviewTag ): void {
		if ( !$this->changeTagsStore->filterViewableTags( [ $abuseReviewTag ], $this->getAuthority() ) ) {
			$this->dieWithError( 'apierror-wikimediaantiabuse-abusereview-permissiondenied', 'permissiondenied' );
		}
	}

	private function addRevisionIds( ApiPageSet $resultPageSet, array $rows ): void {
		if ( $rows ) {
			// When only prop modules continue, start here again, because the queue can change between requests.
			$this->getContinuationManager()->addGeneratorNonContinueParam(
				$this, 'continue', "{$rows[0]->timestamp}|{$rows[0]->rev_id}"
			);
		}
		$resultPageSet->populateFromRevisionIDs( array_map(
			static fn ( stdClass $row ): int => (int)$row->rev_id,
			$rows
		) );
	}

	private function addRowsToResult( array $rows, string $abuseReviewTag, array $prop ): void {
		$this->preloadGenders( $rows, $prop );

		$result = $this->getResult();
		$path = [ 'query', $this->getModuleName() ];
		foreach ( $rows as $row ) {
			if ( !$result->addValue( $path, null, $this->extractRowInfo( $row, $abuseReviewTag, $prop ) ) ) {
				$this->setContinueEnumParameter( 'continue', "$row->timestamp|$row->rev_id" );
				break;
			}
		}
		$result->addIndexedTagName( $path, 'item' );
	}

	private function preloadGenders( array $rows, array $prop ): void {
		if ( isset( $prop['title'] ) && $this->contentLanguage->needsGenderDistinction() ) {
			$this->genderCache->doTitlesArray( array_map(
				static fn ( stdClass $row ): TitleValue => new TitleValue( (int)$row->namespace, $row->title ),
				$rows
			) );
		}
	}

	/** Fetches one more row than the limit, so that the extra row tells where to continue. */
	private function fetchRows( string $abuseReviewTag, array $params ): IResultWrapper {
		$offset = '';
		if ( $params['continue'] !== null ) {
			$offset = implode( '|', $this->parseContinueParamOrDie( $params['continue'], [ 'timestamp', 'int' ] ) );
		}

		return $this->queueLookup->fetchRows(
			$this->newQueueFilters( $abuseReviewTag, $params ),
			$this->getAuthority(),
			$offset,
			$params['limit'] + 1,
			$params['dir'] === 'newer' ? IndexPager::QUERY_ASCENDING : IndexPager::QUERY_DESCENDING,
			includeOffset: true
		);
	}

	private function newQueueFilters( string $abuseReviewTag, array $params ): AbuseReviewQueueFilters {
		return new AbuseReviewQueueFilters(
			$abuseReviewTag,
			includeFalsePositives: $params['showfalsepositives'],
			includeHandledRevisions: $params['showhandled'],
			includeRecentEdits: $params['showrecent'],
			usernames: $params['user'] ?? [],
			revisionIds: $params['revid'] ?? [],
			pages: array_map( $this->newLocalTitle( ... ), $params['page'] ?? [] )
		);
	}

	/** Makes the title of a page to filter by. Dies if the title is an interwiki link. */
	private function newLocalTitle( string $page ): Title {
		$title = $this->titleFactory->newFromTextThrow( $page );
		if ( $title->isExternal() ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $page ) ] );
		}

		return $title;
	}

	private function extractRowInfo( stdClass $row, string $abuseReviewTag, array $prop ): array {
		$title = $this->titleFactory->makeTitle( (int)$row->namespace, $row->title );
		$vals = [];
		if ( isset( $prop['ids'] ) ) {
			$vals['revid'] = (int)$row->rev_id;
			$vals['parentid'] = (int)$row->parent_id;
			if ( $this->queueLookup->isArchivedRow( $row ) ) {
				$vals['archived'] = true;
			}
		}
		if ( isset( $prop['title'] ) ) {
			self::addTitleInfo( $vals, $title );
		}
		if ( isset( $prop['timestamp'] ) ) {
			$vals['timestamp'] = ConvertibleTimestamp::convert( TimestampFormat::ISO_8601, $row->timestamp );
		}
		if ( isset( $prop['user'] ) || isset( $prop['userid'] ) ) {
			$vals += $this->extractUserInfo( $row, $title, $prop );
		}
		if ( isset( $prop['comment'] ) ) {
			$vals += $this->extractCommentInfo( $row, $title );
		}
		if ( isset( $prop['tags'] ) ) {
			$tags = $row->ts_tags ? explode( ',', $row->ts_tags ) : [];
			ApiResult::setIndexedTagName( $tags, 'tag' );
			$vals['tags'] = $tags;
		}
		if ( isset( $prop['status'] ) ) {
			$vals += $this->extractStatusInfo( $row, $abuseReviewTag );
		}
		if ( ( isset( $vals['userhidden'] ) || isset( $vals['commenthidden'] ) )
			&& ( (int)$row->deleted & RevisionRecord::DELETED_RESTRICTED ) !== 0
		) {
			$vals['suppressed'] = true;
		}

		return $vals;
	}

	private function extractUserInfo( stdClass $row, Title $title, array $prop ): array {
		$deleted = (int)$row->deleted;
		$vals = [];
		if ( ( $deleted & RevisionRecord::DELETED_USER ) !== 0 ) {
			$vals['userhidden'] = true;
		}
		if ( !RevisionRecord::userCanBitfield(
			$deleted,
			RevisionRecord::DELETED_USER,
			$this->getAuthority(),
			$title
		) ) {
			return $vals;
		}

		if ( isset( $prop['user'] ) ) {
			$vals['user'] = $row->user_text;
		}
		if ( isset( $prop['userid'] ) ) {
			$vals['userid'] = (int)$row->user;
		}
		if ( $this->userNameUtils->isTemp( $row->user_text ) ) {
			$vals['temp'] = true;
		}
		if ( !$row->user ) {
			$vals['anon'] = true;
		}

		return $vals;
	}

	private function extractCommentInfo( stdClass $row, Title $title ): array {
		$deleted = (int)$row->deleted;
		$vals = [];
		if ( ( $deleted & RevisionRecord::DELETED_COMMENT ) !== 0 ) {
			$vals['commenthidden'] = true;
		}
		if ( RevisionRecord::userCanBitfield(
			$deleted,
			RevisionRecord::DELETED_COMMENT,
			$this->getAuthority(),
			$title
		) ) {
			$vals['comment'] = $this->commentStore->getComment( 'comment', $row )->text;
		}

		return $vals;
	}

	private function extractStatusInfo( stdClass $row, string $abuseReviewTag ): array {
		$verdict = $this->queueLookup->getVerdict( $row, $abuseReviewTag );
		if ( $verdict !== null ) {
			return [ 'status' => match ( $verdict ) {
				'falsePositive' => ReviewVerdictHandler::FALSE_POSITIVE,
				'noFurtherAction' => ReviewVerdictHandler::NO_FURTHER_ACTION,
			} ];
		}

		$handledReason = $this->queueLookup->getHandledReason( $row, $abuseReviewTag );
		if ( $handledReason !== null ) {
			return [ 'status' => 'handled', 'handledreason' => $handledReason ];
		}

		return [ 'status' => 'unhandled' ];
	}

	/** @inheritDoc */
	public function getCacheMode( $params ): string {
		return 'private';
	}

	protected function getAllowedParams(): array {
		return [
			'queue' => [
				ParamValidator::PARAM_TYPE => array_keys( array_intersect(
					self::TAGS_BY_QUEUE,
					$this->abuseReviewEnabledTagsProvider->getEnabledReviewableTags()
				) ),
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'showhandled' => false,
			'showfalsepositives' => false,
			'showrecent' => false,
			'user' => [
				ParamValidator::PARAM_TYPE => 'user',
				ParamValidator::PARAM_ISMULTI => true,
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'ip', 'temp' ],
			],
			'page' => [
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'revid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'dir' => [
				ParamValidator::PARAM_DEFAULT => 'older',
				ParamValidator::PARAM_TYPE => [ 'newer', 'older' ],
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction',
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 10,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG1,
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'prop' => [
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => 'ids|title|timestamp',
				ParamValidator::PARAM_TYPE => [
					'ids',
					'title',
					'timestamp',
					'user',
					'userid',
					'comment',
					'tags',
					'status',
				],
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
		];
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore Merely declarative
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=query&list=abusereview&abrqueue=vandalism'
				=> 'apihelp-query+abusereview-example-vandalism',
			'action=query&list=abusereview&abrqueue=vandalism&abrshowhandled=1&abrshowfalsepositives=1'
				. '&abrshowrecent=1&abrprop=ids|title|timestamp|user|comment|status'
				=> 'apihelp-query+abusereview-example-all',
			'action=query&generator=abusereview&gabrqueue=vandalism&prop=revisions&rvprop=content&rvslots=main'
				=> 'apihelp-query+abusereview-example-generator',
		];
	}
}

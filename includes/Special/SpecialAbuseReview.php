<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special;

use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\RevisionSnippetGenerator;
use MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager\AbuseReviewPager;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\ArchivedRevisionLookup;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\User\User;

class SpecialAbuseReview extends FormSpecialPage {

	// The paging and ordering the pager reads out of the query string.
	private const array PAGER_STATE_PARAMS = [ 'limit', 'sort', 'asc', 'desc' ];

	private array $tagsFilter;
	private bool $includeHandledRevisions;

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly ArchivedRevisionLookup $archivedRevisionLookup,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly RowCommentFormatter $rowCommentFormatter,
		private readonly RevisionSnippetGenerator $revisionSnippetGenerator,
	) {
		parent::__construct( 'AbuseReview' );
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		parent::execute( $par );
		$this->addHelpLink( 'Extension:WikimediaAntiAbuse' );
		$this->getOutput()->addModuleStyles( [
			'ext.wikimediaAntiAbuse.styles',
			'mediawiki.interface.helpers.styles',
		] );
		$this->getOutput()->addModules( 'ext.wikimediaAntiAbuse' );
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'ShowFalsePositives' => [
				'type' => 'check',
				'label-message' => 'wikimediaantiabuse-special-abuse-review-show-false-positives',
			],
			'ShowHandledRevisions' => [
				'type' => 'check',
				'label-message' => 'wikimediaantiabuse-special-abuse-review-show-handled-revisions',
			],
		];
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ): void {
		$form->setSubmitTextMsg( 'wikimediaantiabuse-special-abuse-review-filter-submit' )
			->setWrapperLegendMsg( 'wikimediaantiabuse-special-abuse-review-filter-legend' );

		// Submitting the form replaces the whole query string, so the pager's own state
		// rides along in it. The offset is left behind: a filtered list is a different
		// one, and the page it named is not the page the reviewer would land on.
		foreach ( self::PAGER_STATE_PARAMS as $param ) {
			$value = $this->getRequest()->getRawVal( $param );
			if ( $value !== null ) {
				$form->addHiddenField( $param, $value );
			}
		}
	}

	/** @inheritDoc */
	public function onSubmit( array $data ) {
		$this->tagsFilter = $this->changeTagsStore->filterViewableTags(
			array_keys( ChangeTagsHandler::REVIEWABLE_TAGS ),
			$this->getAuthority()
		);
		if ( $data['ShowFalsePositives'] ) {
			$this->tagsFilter = array_merge(
				$this->tagsFilter,
				$this->changeTagsStore->filterViewableTags(
					array_column( ChangeTagsHandler::REVIEWABLE_TAGS, 'falsePositive' ),
					$this->getAuthority()
				)
			);
		}
		$this->includeHandledRevisions = $data['ShowHandledRevisions'];

		return true;
	}

	public function onSuccess(): void {
		$pager = new AbuseReviewPager(
			$this->getContext(),
			$this->getLinkRenderer(),
			$this->changeTagsStore,
			$this->changeTagsFormatter,
			$this->revisionStore,
			$this->archivedRevisionLookup,
			$this->linkBatchFactory,
			$this->rowCommentFormatter,
			$this->revisionSnippetGenerator,
			$this->tagsFilter,
			$this->includeHandledRevisions
		);
		$this->getOutput()->addParserOutputContent(
			$pager->getFullOutput(),
			ParserOptions::newFromContext( $this->getContext() )
		);
	}

	/** @inheritDoc */
	protected function getShowAlways(): bool {
		return true;
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
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
	public function requiresPost(): bool {
		return false;
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

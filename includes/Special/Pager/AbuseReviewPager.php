<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special\Pager;

use InvalidArgumentException;
use MediaWiki\ChangeTags\ChangeTagsFormatter;
use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\ChangeTagsHandler;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\CodexTablePager;
use MediaWiki\Pager\IndexPager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\Title;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\RawSQLExpression;

class AbuseReviewPager extends CodexTablePager {

	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		private readonly ChangeTagsStore $changeTagsStore,
		private readonly ChangeTagsFormatter $changeTagsFormatter,
		private readonly RevisionStore $revisionStore,
		private readonly UserEditTracker $userEditTracker,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly array $tagsFilter,
		private readonly bool $includeRevisionsWithSuppressedText,
	) {
		parent::__construct(
			$context->msg( 'wikimediaantiabuse-special-abuse-review-caption' )->text(),
			$context,
			$linkRenderer
		);
		$this->mDefaultDirection = IndexPager::DIR_DESCENDING;
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'rev_timestamp' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-revision' )->text(),
			'rev_user_text' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-author' )->text(),
			'ts_tags' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-tags' )->text(),
		];
	}

	/**
	 * @param string $name
	 * @param string|null $value
	 * @return string
	 */
	public function formatValue( $name, $value ): string {
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'rev_timestamp':
				$timestamp = $this->getLanguage()->userTimeAndDate( $value, $this->getUser() );
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				if ( !RevisionRecord::userCanBitfield(
					(int)$row->rev_deleted,
					RevisionRecord::DELETED_TEXT,
					$this->getAuthority(),
					$title )
				) {
					return Html::element( 'span', [ 'class' => 'history-deleted' ], $timestamp );
				}

				return $this->getLinkRenderer()->makeKnownLink(
					$title,
					$this->getLanguage()->userTimeAndDate( $value, $this->getUser() ),
					[],
					[ 'diff' => 'prev', 'oldid' => $row->rev_id ],
				);
			case 'ts_tags':
				return $this->getLanguage()->listToText( array_map(
					fn ( $tag ) => $this->changeTagsFormatter->getTagDescription( $tag, $this->getContext() ),
					array_intersect(
						explode( ',', $value ),
						array_merge(
							array_values( ChangeTagsHandler::REVIEWABLE_TAGS ),
							array_keys( ChangeTagsHandler::REVIEWABLE_TAGS )
						)
					)
				) );
			case 'rev_user_text':
				if ( !RevisionRecord::userCanBitfield(
					(int)$row->rev_deleted,
					RevisionRecord::DELETED_USER,
					$this->getAuthority(),
					Title::makeTitle( $row->page_namespace, $row->page_title )
				) ) {
					return Html::element(
						'span',
						[ 'class' => 'history-deleted' ],
						$this->msg( 'rev-deleted-user' )->text()
					);
				}

				$author = new UserIdentityValue( (int)$row->rev_user, $row->rev_user_text );
				return $this->getLinkRenderer()->makeUserLink( $author, $this->getContext() ) .
					Linker::userToolLinks( $author->getId(), $author->getName(), true );
			default:
				throw new InvalidArgumentException( "Unable to format $name" );
		}
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$queryBuilder = $this->revisionStore->newSelectQueryBuilder( $this->getDatabase() )
			->joinPage();

		if ( $this->tagsFilter ) {
			$this->changeTagsStore->addTagsToDisplayQuery(
				$queryBuilder, 'revision', $this->getAuthority(), $this->tagsFilter
			);
		} else {
			$queryBuilder->where( '1=0' );
		}

		if ( !$this->includeRevisionsWithSuppressedText ) {
			$queryBuilder->where( $this->getDatabase()->orExpr( [
				new RawSQLExpression( $this->getDatabase()->bitAnd(
					'rev_deleted',
					RevisionRecord::DELETED_RESTRICTED
				) . ' = 0' ),
				new RawSQLExpression( $this->getDatabase()->bitAnd(
					'rev_deleted',
					RevisionRecord::DELETED_TEXT
				) . ' = 0' ),
			] ) );
		}

		return $queryBuilder->getQueryInfo();
	}

	/** @inheritDoc */
	protected function doBatchLookups(): void {
		parent::doBatchLookups();

		$lb = $this->linkBatchFactory->newLinkBatch()->setCaller( __METHOD__ );
		$users = [];
		foreach ( $this->mResult as $row ) {
			$user = new UserIdentityValue( (int)$row->rev_user, $row->rev_user_text );
			$users[] = $user;
			$lb->addUser( $user );
		}

		$lb->execute();
		$this->userEditTracker->preloadUserEditCountCache( $users );
	}

	/** @inheritDoc */
	protected function getRowClass( $row ): string {
		return 'mw-wikimediaantiabuse-abuse-review-row';
	}

	/** @inheritDoc */
	protected function getRowAttrs( $row ): array {
		return array_merge(
			parent::getRowAttrs( $row ),
			[ 'data-rev-id' => $row->rev_id ]
		);
	}

	/** @inheritDoc */
	public function getIndexField(): array {
		return [ 'rev_timestamp' => [ 'rev_timestamp', 'rev_id' ] ];
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return '';
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field === 'rev_timestamp';
	}
}

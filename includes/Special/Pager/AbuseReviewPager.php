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
use Wikimedia\Codex\Utility\Codex;
use Wikimedia\Rdbms\RawSQLExpression;

class AbuseReviewPager extends CodexTablePager {

	private const string HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';

	// Marks every control/tag whose visibility flips when a row is marked/unmarked.
	private const string TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle';

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
			'actions' => $this->msg( 'wikimediaantiabuse-special-abuse-review-heading-actions' )->text(),
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
				$tag = $this->getFirstReviewableTag( $value );
				if ( $tag === null ) {
					return '';
				}

				// Render both tag variants; the one not matching the row's state starts hidden.
				$isFalsePositive = $this->isFalsePositiveRow( $value, $tag );
				return $this->renderTagVariant( $tag, false, $isFalsePositive ) .
					$this->renderTagVariant( ChangeTagsHandler::REVIEWABLE_TAGS[$tag], true, $isFalsePositive );
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
			case 'actions':
				$tag = $this->getFirstReviewableTag( $row->ts_tags );
				if ( $tag === null ) {
					return '';
				}

				$buttonAttributes = [
					'data-rev-id' => $row->rev_id,
					'data-abuse-review-tag' => $tag,
				];

				$markLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-mark-false-positive'
				)->text();
				$unmarkLabel = $this->msg(
					'wikimediaantiabuse-special-abuse-review-action-unmark-false-positive'
				)->text();

				// A flagged row shows "mark"; an already-handled row shows "unmark". Hide the other.
				$isFalsePositive = $this->isFalsePositiveRow( $row->ts_tags, $tag );
				$markClass = 'mw-wikimediaantiabuse-abuse-review-mark-button ' . self::TOGGLE_CLASS;
				$unmarkClass = 'mw-wikimediaantiabuse-abuse-review-unmark-button ' . self::TOGGLE_CLASS;
				if ( $isFalsePositive ) {
					$markClass .= ' ' . self::HIDDEN_CLASS;
				} else {
					$unmarkClass .= ' ' . self::HIDDEN_CLASS;
				}

				$codex = new Codex();
				$markButton = $codex->button()
					->setLabel( $markLabel )
					->setType( 'button' )
					->setAction( 'progressive' )
					->setAttributes( [ ...$buttonAttributes, 'class' => $markClass ] )
					->build()
					->getHtml();
				$unmarkButton = $codex->button()
					->setLabel( $unmarkLabel )
					->setType( 'button' )
					->setAttributes( [ ...$buttonAttributes, 'class' => $unmarkClass ] )
					->build()
					->getHtml();

				return Html::rawElement(
					'div',
					[ 'class' => 'mw-wikimediaantiabuse-abuse-review-actions' ],
					$markButton . $unmarkButton
				);
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

	/**
	 * The first reviewable tag on a row, normalised to its non-false-positive (base) form.
	 *
	 * A row is only ever displayed for one reviewable tag: if it somehow carries more than
	 * one, the first is the one shown and acted on.
	 *
	 * @param string|null $tsTags
	 * @return string|null Null if the row carries no reviewable tag
	 */
	private function getFirstReviewableTag( ?string $tsTags ): ?string {
		$falsePositiveToTag = array_flip( ChangeTagsHandler::REVIEWABLE_TAGS );

		foreach ( $this->splitTags( $tsTags ) as $tag ) {
			if ( isset( ChangeTagsHandler::REVIEWABLE_TAGS[$tag] ) ) {
				return $tag;
			}
			if ( isset( $falsePositiveToTag[$tag] ) ) {
				return $falsePositiveToTag[$tag];
			}
		}

		return null;
	}

	private function isFalsePositiveRow( ?string $tsTags, string $reviewableTag ): bool {
		return in_array(
			ChangeTagsHandler::REVIEWABLE_TAGS[$reviewableTag],
			$this->splitTags( $tsTags ),
			true
		);
	}

	/**
	 * @param string|null $tsTags Comma-separated tags from a row's ts_tags field
	 * @return string[]
	 */
	private function splitTags( ?string $tsTags ): array {
		return $tsTags !== null && $tsTags !== '' ? explode( ',', $tsTags ) : [];
	}

	private function renderTagVariant( string $tag, bool $falsePositive, bool $rowIsFalsePositive ): string {
		$classes = [
			'mw-wikimediaantiabuse-abuse-review-tag',
			$falsePositive
				? 'mw-wikimediaantiabuse-abuse-review-tag--false-positive'
				: 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive',
			self::TOGGLE_CLASS,
		];
		// A variant starts hidden when it does not match the row's current state.
		if ( $falsePositive !== $rowIsFalsePositive ) {
			$classes[] = self::HIDDEN_CLASS;
		}
		return Html::rawElement(
			'span',
			[ 'class' => $classes ],
			$this->changeTagsFormatter->getTagDescription( $tag, $this->getContext() )
		);
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

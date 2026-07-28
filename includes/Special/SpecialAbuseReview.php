<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Special;

use MediaWiki\ChangeTags\ChangeTagsStore;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\User\User;

class SpecialAbuseReview extends FormSpecialPage {

	/**
	 * Maps each tag that flags a revision for review to the tag that marks it as a false positive.
	 */
	public const array ABUSE_REVIEW_TAGS = [
		'mw-private-personal-info' => 'mw-private-personal-info-false-positive',
	];

	public function __construct(
		private readonly ChangeTagsStore $changeTagsStore,
	) {
		parent::__construct( 'AbuseReview' );
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		parent::execute( $par );
		$this->addHelpLink( 'Extension:WikimediaAntiAbuse' );
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
	}

	/** @inheritDoc */
	public function onSubmit( array $data ) {
		return true;
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
			array_merge( array_keys( self::ABUSE_REVIEW_TAGS ), array_values( self::ABUSE_REVIEW_TAGS ) ),
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

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Html\Html;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\HtmlArmor\HtmlArmor;

/**
 * Builds the line that names who last acted on a flag. The wording can change with the
 * gender of the user it names, and only the server can resolve that.
 */
class AbuseReviewVerdictAttributionFormatter {

	private const string RECORDED_VERDICT_MESSAGE =
		'wikimediaantiabuse-special-abuse-review-verdict-attribution';

	private const string RETURNED_TO_REVIEW_MESSAGE =
		'wikimediaantiabuse-special-abuse-review-verdict-returned-attribution';

	public function __construct(
		private readonly LinkRenderer $linkRenderer,
		private readonly TitleFactory $titleFactory,
	) {
	}

	/**
	 * Returns the byline for a user the caller already knows acted, such as the viewer who
	 * records a verdict. Use {@link self::formatFor} instead when the user comes from
	 * AbuseReviewVerdictPerformerLookup::lookUpPerformers(), which can name nobody.
	 *
	 * @param MessageLocalizer $context
	 * @param UserIdentity $performer
	 * @param bool $verdictHeld Whether the revision holds a verdict. False means the revision
	 *   goes back to review. The wording does not depend on which verdict it holds.
	 * @return string HTML byline
	 */
	public function format(
		MessageLocalizer $context,
		UserIdentity $performer,
		bool $verdictHeld
	): string {
		$messageKey = $verdictHeld
			? self::RECORDED_VERDICT_MESSAGE
			: self::RETURNED_TO_REVIEW_MESSAGE;

		return $context->msg( $messageKey )
			->params( $performer->getName() )
			->rawParams( $this->buildUserLink( $performer ) )
			->escaped();
	}

	/**
	 * Links the user page without going through {@link LinkRenderer::makeUserLink}, whose hook
	 * appends the user info card. Design asks for a plain user link on this byline.
	 */
	private function buildUserLink( UserIdentity $performer ): string {
		return $this->linkRenderer->makeLink(
			$this->titleFactory->makeTitle( NS_USER, $performer->getName() ),
			new HtmlArmor( Html::element( 'bdi', [], $performer->getName() ) ),
			[ 'class' => 'mw-userlink' ]
		);
	}

	/**
	 * Returns the byline for a revision, from the reviewers a flag names.
	 *
	 * @param MessageLocalizer $context
	 * @param array<int,UserIdentity> $performers The reviewer, keyed by revision ID, as returned
	 *   by {@link AbuseReviewVerdictPerformerLookup::lookUpPerformers()}
	 * @param int $revisionId
	 * @param bool $verdictHeld Whether the revision holds a verdict. False means the revision
	 *   goes back to review.
	 * @return ?string HTML byline, or null if the flag names no reviewer for the revision
	 */
	public function formatFor(
		MessageLocalizer $context,
		array $performers,
		int $revisionId,
		bool $verdictHeld
	): ?string {
		$performer = $performers[$revisionId] ?? null;
		if ( $performer === null ) {
			return null;
		}

		return $this->format( $context, $performer, $verdictHeld );
	}
}

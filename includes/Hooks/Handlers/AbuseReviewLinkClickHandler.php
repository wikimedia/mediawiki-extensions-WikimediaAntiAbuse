<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewPermissionManager;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;

/**
 * Records a click on a Special:AbuseReview row link, when the page the link opens loads.
 *
 * The click is not recorded in the browser, because a reviewer who blocks trackers sends no
 * event. The queue instead names each link in its own URL, and the page that link opens
 * reports the click, then redirects to the same URL without the parameters.
 */
class AbuseReviewLinkClickHandler implements BeforeInitializeHook {

	public const string SUBTYPE_PARAM = 'ar_subtype';
	public const string REVISION_PARAM = 'ar_revid';

	public const string SUBTYPE_TIMESTAMP = 'timestamp';
	public const string SUBTYPE_PAGE_TITLE = 'page_title';
	public const string SUBTYPE_FULL_DIFF = 'full_diff';
	public const string SUBTYPE_SUPPRESS = 'suppress';
	public const string SUBTYPE_REVISION_DELETE = 'revision_delete';
	public const string SUBTYPE_REVERT = 'revert';

	private const array SUBTYPES = [
		self::SUBTYPE_TIMESTAMP,
		self::SUBTYPE_PAGE_TITLE,
		self::SUBTYPE_FULL_DIFF,
		self::SUBTYPE_SUPPRESS,
		self::SUBTYPE_REVISION_DELETE,
		self::SUBTYPE_REVERT,
	];

	public function __construct(
		private readonly IAbuseReviewInstrumentationClient $instrumentationClient,
		private readonly AbuseReviewPermissionManager $permissionManager,
	) {
	}

	/** @inheritDoc */
	public function onBeforeInitialize(
		$title,
		$unused,
		$output,
		$user,
		$request,
		$mediaWikiEntryPoint
	) {
		// Every page view of the wiki reaches this, so the parameter is looked for first.
		$subtype = $request->getRawVal( self::SUBTYPE_PARAM );
		if ( $subtype === null || !in_array( $subtype, self::SUBTYPES, true ) ) {
			return;
		}
		// Every row of the queue names its revision, so a link that names none is not one of ours.
		$revisionId = $request->getInt( self::REVISION_PARAM );
		if ( $revisionId <= 0 ) {
			return;
		}
		// A form that posts to a URL holding the parameters would lose its post to the redirect.
		if ( $request->wasPosted() ) {
			return;
		}
		// Core runs this hook before it rejects an invalid title, so there may be none to
		// redirect back to.
		if ( $title === null ) {
			return;
		}
		// Only a viewer of the queue can have followed one of its links. Anyone else is left
		// alone entirely, so neither the stream nor the redirect answers to a crafted URL.
		if ( !$this->permissionManager->canViewQueue( $output->getAuthority() ) ) {
			return;
		}

		$this->recordLinkClick( $output, $subtype, $revisionId );
		$this->redirectToCleanUrl( $title, $output, $request );
	}

	private function recordLinkClick( OutputPage $output, string $subtype, int $revisionId ): void {
		$this->instrumentationClient->submitInteraction( $output->getContext(), 'link_click', [
			'action_subtype' => $subtype,
			'identifier' => $revisionId,
			'identifier_type' => 'revision',
		] );
	}

	/**
	 * The parameters have been read, and a reviewer who bookmarks or shares the page they
	 * landed on should not carry them along, so they are dropped from the address.
	 *
	 * The title this is given is the one the reviewer asked for. A page that redirects
	 * elsewhere has not been followed yet, so its own address survives the round trip.
	 */
	private function redirectToCleanUrl( Title $title, OutputPage $output, WebRequest $request ): void {
		$queryParams = $request->getQueryValues();
		unset(
			$queryParams[self::SUBTYPE_PARAM],
			$queryParams[self::REVISION_PARAM],
			$queryParams['title']
		);

		$output->redirect( $title->getLocalURL( $queryParams ) );
	}
}

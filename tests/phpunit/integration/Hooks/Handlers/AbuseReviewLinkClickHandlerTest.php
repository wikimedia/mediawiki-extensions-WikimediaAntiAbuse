<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Tests\Integration\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewLinkClickHandler;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewPermissionManager;
use MediaWiki\Extension\WikimediaAntiAbuse\Services\IAbuseReviewInstrumentationClient;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\WikimediaAntiAbuse\Hooks\Handlers\AbuseReviewLinkClickHandler
 */
class AbuseReviewLinkClickHandlerTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;

	private const VIEW_RIGHTS = [ 'viewsuppressed', 'suppressrevision' ];

	protected function setUp(): void {
		parent::setUp();

		// A tag declares its view rights only while enabled, so the queue needs one enabled tag.
		$this->overrideConfigValue( 'WikimediaAntiAbuseEnablePersonalInfoTag', true );
	}

	public function testRecordsTheClickAndRedirectsToTheCleanUrl(): void {
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$client->expects( $this->once() )
			->method( 'submitInteraction' )
			->with(
				$this->anything(),
				'link_click',
				[
					'action_subtype' => 'timestamp',
					'identifier' => 42,
					'identifier_type' => 'revision',
				]
			);

		$title = Title::makeTitle( NS_MAIN, 'Example' );
		$context = $this->newContext( $title, [
			'title' => $title->getPrefixedDBkey(),
			AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_TIMESTAMP,
			AbuseReviewLinkClickHandler::REVISION_PARAM => '42',
			'diff' => 'prev',
			'oldid' => '42',
		] );

		$this->invokeHandler( $client, $context, $title );

		$redirect = $context->getOutput()->getRedirect();
		$this->assertNotSame( '', $redirect, 'the reviewer is sent on to the clean address' );
		$this->assertStringNotContainsString( AbuseReviewLinkClickHandler::SUBTYPE_PARAM, $redirect );
		$this->assertStringNotContainsString( AbuseReviewLinkClickHandler::REVISION_PARAM, $redirect );
		$this->assertStringContainsString( 'oldid=42', $redirect );
		// The title is addressed by the path, so carrying it as well would name it twice.
		$this->assertSame( 1, substr_count( $redirect, 'Example' ) );
	}

	public function testLeavesARequestForAnInvalidTitleAlone(): void {
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$client->expects( $this->never() )->method( 'submitInteraction' );

		$title = Title::makeTitle( NS_MAIN, 'Example' );
		$context = $this->newContext( $title, [
			AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_TIMESTAMP,
			AbuseReviewLinkClickHandler::REVISION_PARAM => '42',
		] );

		// Core runs the hook before it rejects an invalid title, so it passes null through.
		$this->invokeHandler( $client, $context, null );

		$this->assertSame( '', $context->getOutput()->getRedirect() );
	}

	/**
	 * @dataProvider provideRequestsThatAreLeftAlone
	 */
	public function testLeavesTheRequestAlone( array $query, bool $wasPosted, bool $canViewQueue ): void {
		$client = $this->createMock( IAbuseReviewInstrumentationClient::class );
		$client->expects( $this->never() )->method( 'submitInteraction' );

		$title = Title::makeTitle( NS_MAIN, 'Example' );
		$context = $this->newContext( $title, $query, $wasPosted, $canViewQueue );

		$this->invokeHandler( $client, $context, $title );

		$this->assertSame(
			'',
			$context->getOutput()->getRedirect(),
			'a request this handler does not answer is left to run as it was asked for'
		);
	}

	public static function provideRequestsThatAreLeftAlone(): array {
		return [
			'names no link' => [
				'query' => [ 'oldid' => '42' ],
				'wasPosted' => false,
				'canViewQueue' => true,
			],
			'names a link this queue does not build' => [
				'query' => [
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM => 'not-a-link-we-name',
					AbuseReviewLinkClickHandler::REVISION_PARAM => '42',
				],
				'wasPosted' => false,
				'canViewQueue' => true,
			],
			'names no revision' => [
				'query' => [
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_PAGE_TITLE,
				],
				'wasPosted' => false,
				'canViewQueue' => true,
			],
			'names a revision no row could hold' => [
				'query' => [
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_PAGE_TITLE,
					AbuseReviewLinkClickHandler::REVISION_PARAM => 'not-a-revision',
				],
				'wasPosted' => false,
				'canViewQueue' => true,
			],
			'was posted' => [
				'query' => [
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_REVERT,
					AbuseReviewLinkClickHandler::REVISION_PARAM => '42',
				],
				'wasPosted' => true,
				'canViewQueue' => true,
			],
			'comes from someone who cannot view the queue' => [
				'query' => [
					AbuseReviewLinkClickHandler::SUBTYPE_PARAM => AbuseReviewLinkClickHandler::SUBTYPE_TIMESTAMP,
					AbuseReviewLinkClickHandler::REVISION_PARAM => '42',
				],
				'wasPosted' => false,
				'canViewQueue' => false,
			],
		];
	}

	private function newContext(
		Title $title,
		array $query,
		bool $wasPosted = false,
		bool $canViewQueue = true
	): RequestContext {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( $query, $wasPosted ) );
		$context->setTitle( $title );
		$context->setAuthority( $canViewQueue
			? $this->mockRegisteredAuthorityWithPermissions( self::VIEW_RIGHTS )
			: $this->mockRegisteredNullAuthority()
		);

		return $context;
	}

	private function invokeHandler(
		IAbuseReviewInstrumentationClient $client,
		RequestContext $context,
		?Title $title
	): void {
		$handler = new AbuseReviewLinkClickHandler(
			$client,
			new AbuseReviewPermissionManager( $this->getServiceContainer()->getChangeTagsStore() )
		);

		$handler->onBeforeInitialize(
			$title,
			null,
			$context->getOutput(),
			$this->createMock( User::class ),
			$context->getRequest(),
			null
		);
	}
}

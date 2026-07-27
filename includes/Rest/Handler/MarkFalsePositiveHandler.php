<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\FalsePositiveTagService;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * @internal
 */
class MarkFalsePositiveHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;

	public function __construct(
		private readonly FalsePositiveTagService $falsePositiveTagService,
	) {
	}

	public function run( int $revision, string $tag ): Response {
		$this->validateToken();
		$status = $this->falsePositiveTagService->markFalsePositive( $this->getAuthority(), $revision, $tag );
		if ( !$status->isGood() ) {
			throw new LocalizedHttpException( $status->getMessages()[0], (int)$status->getValue() );
		}

		return $this->getResponseFactory()->createJson( [
			'revision' => $revision,
			'tag' => $tag,
			'falsePositive' => true,
		] );
	}

	public function getParamSettings(): array {
		return [
			'revision' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'tag' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}

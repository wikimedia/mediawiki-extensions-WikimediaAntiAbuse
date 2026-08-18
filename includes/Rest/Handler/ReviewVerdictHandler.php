<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Rest\Handler;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\AbuseReviewTagService;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * @internal
 */
abstract class ReviewVerdictHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;

	public const string FALSE_POSITIVE = 'false-positive';
	public const string NO_FURTHER_ACTION = 'no-further-action';

	/** Every verdict this endpoint accepts as a path parameter. */
	public const array VERDICTS = [ self::FALSE_POSITIVE, self::NO_FURTHER_ACTION ];

	/** Response field reporting each verdict's new state, keyed by verdict. */
	public const array RESPONSE_FIELDS = [
		self::FALSE_POSITIVE => 'falsePositive',
		self::NO_FURTHER_ACTION => 'noFurtherAction',
	];

	public function __construct(
		protected readonly AbuseReviewTagService $abuseReviewTagService,
	) {
	}

	/** Record or remove the verdict on the revision. */
	abstract protected function applyVerdict( string $verdict, int $revision, string $tag ): StatusValue;

	/** The state this endpoint leaves the verdict in. */
	abstract protected function verdictIsSet(): bool;

	public function run( int $revision, string $tag, string $verdict ): Response {
		$this->validateToken();
		$status = $this->applyVerdict( $verdict, $revision, $tag );
		if ( !$status->isGood() ) {
			throw new LocalizedHttpException( $status->getMessages()[0], (int)$status->getValue() );
		}

		return $this->getResponseFactory()->createJson( [
			'revision' => $revision,
			'tag' => $tag,
			self::RESPONSE_FIELDS[$verdict] => $this->verdictIsSet(),
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
			'verdict' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => self::VERDICTS,
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}

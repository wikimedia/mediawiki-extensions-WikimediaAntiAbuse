<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Pairs a content policy and the model that evaluated a revision against it with the response
 * that model produced.
 */
readonly class ContentPolicyEvaluationResult {

	public function __construct(
		public string $contentPolicyName,
		public string $modelName,
		public CoPEModelResponse $response,
		public ?string $policyVersion = null
	) {
	}
}

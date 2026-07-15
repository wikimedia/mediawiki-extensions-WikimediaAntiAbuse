<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Immutable description of a model that should evaluate a revision.
 * @newable
 */
readonly class ModelToRun {

	public function __construct( private string $modelName, private string $policyText ) {
	}

	public function getModelName(): string {
		return $this->modelName;
	}

	/**
	 * The policy text this model evaluates revisions against.
	 */
	public function getPolicyText(): string {
		return $this->policyText;
	}
}

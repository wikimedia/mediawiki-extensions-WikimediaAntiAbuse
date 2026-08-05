<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Immutable description of a model that should evaluate a revision.
 * @newable
 */
readonly class ModelToRun {

	public function __construct(
		private string $contentPolicyName,
		private string $policyText,
		private string $content,
		private string $modelName,
		private ?string $policyVersion = null
	) {
	}

	/**
	 * The name of the content policy. The model uses this policy to evaluate the revision.
	 */
	public function getContentPolicyName(): string {
		return $this->contentPolicyName;
	}

	/**
	 * The name of the model being used to evaluate the content policy.
	 */
	public function getModelName(): string {
		return $this->modelName;
	}

	/**
	 * The policy text this model evaluates revisions against.
	 */
	public function getPolicyText(): string {
		return $this->policyText;
	}

	/**
	 * The content, formatted as the policy text expects, to send to the model.
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * Version of the content policy text, or null when the caller does not track one.
	 */
	public function getPolicyVersion(): ?string {
		return $this->policyVersion;
	}
}

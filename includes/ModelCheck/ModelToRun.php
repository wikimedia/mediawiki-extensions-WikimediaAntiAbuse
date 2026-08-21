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
		private string $content
	) {
	}

	/**
	 * The name of the content policy. The model uses this policy to evaluate the revision.
	 */
	public function getContentPolicyName(): string {
		return $this->contentPolicyName;
	}

	/**
	 * This method returns the content policy name, not the model name. Callers outside
	 * this extension compare the result with their own content policy name. A later
	 * change makes this method return the model name, after those callers move to
	 * getContentPolicyName().
	 */
	public function getModelName(): string {
		return $this->contentPolicyName;
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
}

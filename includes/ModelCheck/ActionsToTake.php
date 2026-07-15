<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Collects the actions that model-result hook handlers request for a model response.
 */
class ActionsToTake {

	/** @var string[] */
	private array $tagsToAdd = [];

	/**
	 * @stable to call - Called by code not visible in codesearch
	 * @param string[] $tags The tags to add as a consequence of the model
	 */
	public function addTags( array $tags ): void {
		$this->tagsToAdd = array_values( array_unique( array_merge( $this->tagsToAdd, $tags ) ) );
	}

	/**
	 * @return string[] List of unique tag names to add.
	 */
	public function getTagsToAdd(): array {
		return $this->tagsToAdd;
	}
}

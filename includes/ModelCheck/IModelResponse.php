<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Immutable wrapper around a raw decoded model response. Implementations add typed
 * accessors for their model's response contract.
 */
interface IModelResponse {

	/**
	 * The raw decoded response data.
	 */
	public function getData(): array;
}

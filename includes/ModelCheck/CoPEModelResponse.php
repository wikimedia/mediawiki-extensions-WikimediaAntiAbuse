<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck;

/**
 * Response from a model speaking the LiftWing CoPE binary-classification contract,
 * {"violation": 0|1, "p_violation": float, "p_safe": float}. For responses with
 * a different shape the typed accessors return false/null; the raw data stays
 * available via getData().
 */
readonly class CoPEModelResponse implements IModelResponse {

	public function __construct( private array $data ) {
	}

	/** @inheritDoc */
	public function getData(): array {
		return $this->data;
	}

	public function isViolation(): bool {
		$value = $this->data['violation'] ?? null;

		return is_numeric( $value ) && (int)$value === 1;
	}

	public function getViolationProbability(): ?float {
		return $this->getFloatField( 'p_violation' );
	}

	public function getSafeProbability(): ?float {
		return $this->getFloatField( 'p_safe' );
	}

	private function getFloatField( string $key ): ?float {
		$value = $this->data[$key] ?? null;

		return is_int( $value ) || is_float( $value ) ? (float)$value : null;
	}
}

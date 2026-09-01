<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Decodes and encodes the ct_params field a review verdict tag carries, which contains who recorded it and when
 */
class AbuseReviewVerdictAttribution {

	private const string ACTOR_FIELD = 'actor';
	private const string RECORDED_AT_FIELD = 'recordedAt';

	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/** Generates a ct_params field value to be attached to a verdict review tag. This stores the actor that recorded a verdict, and the MW-format time it happened. */
	public function encode( int $actorId, string $recordedAt ): string {
		return json_encode( [
			self::ACTOR_FIELD => $actorId,
			self::RECORDED_AT_FIELD => $recordedAt,
		], JSON_THROW_ON_ERROR );
	}

	/**
	 * Decodes a ct_params field present on a verdict review tag, returning the actor who applied the verdict.
	 *
	 * @param ?string $params A verdict's ct_params, which names no actor if the verdict predates attribution
	 * @return ?int The actor ID a verdict's ct_params names, or null if it names none.
	 */
	public function decodeActorId( ?string $params ): ?int {
		if ( $params === null ) {
			return null;
		}

		try {
			$decoded = json_decode( $params, true, flags: JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$this->logger->warning( 'Ignoring a review verdict whose ct_params is not valid JSON', [
				'exception' => $e,
				'params' => $params,
			] );

			return null;
		}

		$actorId = $decoded[self::ACTOR_FIELD] ?? null;

		return is_numeric( $actorId ) ? (int)$actorId : null;
	}
}

<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Extension\EventBus\Serializers\MediaWiki\UserEntitySerializer;
use MediaWiki\Extension\EventLogging\EventSubmitter\EventSubmitter;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\ContentPolicyEvaluationResult;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\WikiMap\WikiMap;

/**
 * Emits one EventLogging event per edit capturing each content policy's CoPE confidence scores.
 */
class ContentPolicyScoreEventLogger implements IContentPolicyScoreEventLogger {

	private const string STREAM = 'mediawiki.wikimedia_antiabuse.content_policy_score';
	private const string SCHEMA = '/analytics/mediawiki/wikimedia_antiabuse/content_policy_score/1.0.0';

	public function __construct(
		private readonly EventSubmitter $eventSubmitter,
		private readonly UserEntitySerializer $userEntitySerializer,
	) {
	}

	/** @inheritDoc */
	public function record( RevisionRecord $revisionRecord, array $results ): void {
		$performer = $revisionRecord->getUser( RevisionRecord::RAW );
		if ( !$performer ) {
			return;
		}

		$evaluations = array_map(
			static function ( ContentPolicyEvaluationResult $result ): array {
				$evaluation = [
					'content_policy' => $result->contentPolicyName,
					'model_name' => $result->modelName,
					'violation' => $result->response->isViolation(),
				];

				if ( $result->policyVersion !== null ) {
					$evaluation['policy_version'] = $result->policyVersion;
				}

				// The schema has no nullable types, so omit the scores the model did not report
				$violationProbability = $result->response->getViolationProbability();
				if ( $violationProbability !== null ) {
					$evaluation['p_violation'] = $violationProbability;
				}
				$safeProbability = $result->response->getSafeProbability();
				if ( $safeProbability !== null ) {
					$evaluation['p_safe'] = $safeProbability;
				}

				return $evaluation;
			},
			$results
		);

		$event = [
			'$schema' => self::SCHEMA,
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'performer' => $this->userEntitySerializer->toArray( $performer ),
			'identifier' => $revisionRecord->getId(),
			'identifier_type' => 'revision',
			'evaluations' => $evaluations,
		];

		$this->eventSubmitter->submit( self::STREAM, $event );
	}
}

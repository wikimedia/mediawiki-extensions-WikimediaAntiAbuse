<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Maintenance;

use MediaWiki\Extension\WikimediaAntiAbuse\Services\ContentPolicyEvaluator;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class EvaluateContentPolicy extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Evaluates a given content policy against provided content.' );
		$this->addOption( 'content-policy', 'The filename to a file containing the content policy', true, true );
		$this->addOption( 'content', 'The content to evaluate', true, true );
		$this->requireExtension( 'WikimediaAntiAbuse' );
	}

	public function execute() {
		$contentPolicy = file_get_contents( $this->getOption( 'content-policy', '' ) );
		if ( !$contentPolicy ) {
			$this->fatalError( 'Unable to read the content policy text file' );
		}

		/** @var ContentPolicyEvaluator $contentPolicyEvaluator */
		$contentPolicyEvaluator = $this->getServiceContainer()->getService(
			'WikimediaAntiAbuseContentPolicyEvaluator'
		);
		$modelResponse = $contentPolicyEvaluator->evaluateCoPEModel(
			$contentPolicy,
			'maintenance-script-custom-content-policy',
			$this->getOption( 'content', '' )
		);
		if ( $modelResponse === null ) {
			$this->fatalError( 'Call to CoPE model failed' );
		}

		$output = $modelResponse->isViolation() ? "Content matches the policy." : "Content does not match the policy.";
		if ( $modelResponse->getViolationProbability() ) {
			$output .= " Violation probability: {$modelResponse->getViolationProbability()}.";
		}
		if ( $modelResponse->getSafeProbability() ) {
			$output .= " Safe probability: {$modelResponse->getSafeProbability()}.";
		}

		$this->output( $output . PHP_EOL );
	}
}

// @codeCoverageIgnoreStart
$maintClass = EvaluateContentPolicy::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd

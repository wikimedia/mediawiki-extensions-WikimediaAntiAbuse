<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikimediaAntiAbuse\ModelCheck\CoPEModelResponse;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\FormatterFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Stats\StatsFactory;

/**
 * Calls the CoPE model to evaluate a given edit against a provided content policy text
 */
class ContentPolicyEvaluator {

	/** @internal Only public for use in ServiceWiring.php */
	public const array CONSTRUCTOR_OPTIONS = [
		'WikimediaAntiAbuseCoPEModelConfig',
		'WikimediaAntiAbuseDeveloperMode',
	];

	private const int MAX_REQUEST_ATTEMPTS = 2;

	/**
	 * @var int Delay between re-attempts to call the CoPE model in microseconds. Not a const so
	 *   PHPUnit tests can set this to zero to avoid slow tests
	 */
	private int $requestReattemptDelayMicroseconds = 50 * 1000;

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly FormatterFactory $formatterFactory,
		private readonly StatsFactory $statsFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Given the provided content policy and content describing the action as formatted as the
	 * content policy text expects, return whether the content policy matches or does not match.
	 *
	 * Uses the CoPE model as accessible in $wgWikimediaAntiAbuseCoPEModelConfig to do the
	 * evaluation.
	 *
	 * @param string $contentPolicy The content policy text to be passed to the model
	 * @param string $contentPolicyName The name of the content policy used for logging
	 * @param string $content The text that contains the content and any other relevant information
	 *   in the format specified in the content policy text
	 * @return CoPEModelResponse|null The response from the model, or null if the request to the model failed
	 */
	public function evaluateCoPEModel(
		string $contentPolicy,
		string $contentPolicyName,
		string $content
	): ?CoPEModelResponse {
		$start = microtime( true );

		$copeModelConfig = $this->options->get( 'WikimediaAntiAbuseCoPEModelConfig' );
		if ( !isset( $copeModelConfig['url'] ) || !$copeModelConfig['url'] ) {
			throw new ConfigException( 'CoPE model URL is not configured.' );
		}
		if ( !isset( $copeModelConfig['host'] ) || !$copeModelConfig['host'] ) {
			throw new ConfigException( 'CoPE model Host header is not configured.' );
		}

		$body = [ 'content' => $content, 'policy' => $contentPolicy ];
		$request = null;
		$response = null;
		for ( $attempt = 1; $attempt <= self::MAX_REQUEST_ATTEMPTS; $attempt++ ) {
			$requestOptions = [
				'method' => 'POST',
				'postData' => json_encode( $body ),
				'userAgent' => 'MediaWiki-WikimediaAntiAbuse/1.0 ' .
					'(https://www.mediawiki.org/wiki/Product_Safety_and_Integrity)',
				'sslVerifyCert' => !$this->options->get( 'WikimediaAntiAbuseDeveloperMode' ),
				'sslVerifyHost' => !$this->options->get( 'WikimediaAntiAbuseDeveloperMode' ),
			];
			if ( isset( $copeModelConfig['timeout'] ) && $copeModelConfig['timeout'] ) {
				$requestOptions['timeout'] = $copeModelConfig['timeout'];
			}
			$request = $this->httpRequestFactory->create( $copeModelConfig['url'], $requestOptions, __METHOD__ );
			$request->setHeader( 'Host', $copeModelConfig['host'] );
			$request->setHeader( 'Accept', 'application/json' );
			$request->setHeader( 'Content-Type', 'application/json' );
			$response = $request->execute();

			if ( $response->isOK() ) {
				break;
			}

			usleep( $this->requestReattemptDelayMicroseconds );
			if ( $attempt < self::MAX_REQUEST_ATTEMPTS ) {
				$this->logger->warning(
					'CoPE model request failed {contentPolicyName}, retrying (attempt {attempt}/{maxAttempts})',
					[
						'contentPolicyName' => $contentPolicyName,
						'attempt' => $attempt,
						'maxAttempts' => self::MAX_REQUEST_ATTEMPTS,
					]
				);
			}
		}

		if ( $response && !$response->isOK() ) {
			// Connection error or server error - return null to indicate no evaluation occured
			if ( $response->hasMessage( 'http-timed-out' ) ) {
				$this->logger->warning(
					'Timeout connecting to CoPE model for content policy {contentPolicyName}',
					[ 'contentPolicyName' => $contentPolicyName ]
				);
			} else {
				$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
				$this->logger->error( ...$statusFormatter->getPsr3MessageAndContext( $response, [
					'exception' => new RuntimeException(),
					'contentPolicyName' => $contentPolicyName,
				] ) );
			}
			return null;
		}

		$data = json_decode( $request->getContent(), true );

		if ( !$data || !isset( $data['violation'] ) ) {
			// Malformed data or error response.
			$this->logger->error(
				'Got unexpected data from CoPE model while checking content policy {contentPolicyName}',
				[ 'contentPolicyName' => $contentPolicyName, 'response' => $request->getContent() ]
			);
			return null;
		}

		$delay = microtime( true ) - $start;
		$this->statsFactory->withComponent( 'WikimediaAntiAbuse' )
			->getTiming( 'cope_model_evaluation_seconds' )
			->observeSeconds( $delay );

		return new CoPEModelResponse( $data );
	}
}

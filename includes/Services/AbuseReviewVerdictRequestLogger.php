<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikimediaAntiAbuse\Services;

use MediaWiki\Permissions\Authority;
use Psr\Log\LoggerInterface;
use StatusValue;

class AbuseReviewVerdictRequestLogger {

	public function __construct( private readonly LoggerInterface $logger ) {
	}

	public function logHandledRequest(
		Authority $performer,
		int $revisionId,
		string $tag,
		string $verdict,
		string $action,
		StatusValue $status,
		string $userAgent
	): void {
		$context = [
			'revisionId' => $revisionId,
			'tag' => $tag,
			'verdict' => $verdict,
			'action' => $action,
			'performer' => $performer->getUser()->getName(),
			'success' => $status->isGood(),
		];
		if ( !$status->isGood() ) {
			$context['error'] = $status->getMessages()[0]->getKey();
		}
		if ( $userAgent !== '' ) {
			$context['userAgent'] = $userAgent;
		}

		$this->logger->info( 'Handled an AbuseReview verdict request', $context );
	}
}

<?php

declare( strict_types=1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/Echo',
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/GlobalBlocking',
		'../../extensions/GlobalPreferences',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/Echo',
		'../../extensions/EventBus',
		'../../extensions/EventLogging',
		'../../extensions/GlobalBlocking',
		'../../extensions/GlobalPreferences',
	]
);

return $cfg;

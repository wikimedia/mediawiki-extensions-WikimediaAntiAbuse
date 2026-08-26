'use strict';

( function () {
	switch ( mw.config.get( 'wgCanonicalSpecialPageName' ) ) {
		case 'AbuseReview': {
			const { mountRowVerdicts } = require( './mountRowVerdicts.js' );
			const { mountFilterDialog } = require( './mountFilterDialog.js' );
			// This module can run before the queue exists, so mount once the DOM is ready.
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', mountRowVerdicts );
			} else {
				mountRowVerdicts();
			}
			mountFilterDialog();
			break;
		}
	}
}() );

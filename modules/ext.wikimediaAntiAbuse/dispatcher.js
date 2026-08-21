'use strict';

( function () {
	switch ( mw.config.get( 'wgCanonicalSpecialPageName' ) ) {
		case 'AbuseReview': {
			const { mountRowActions } = require( './mountRowActions.js' );
			// This module can run before the queue exists, so mount once the DOM is ready.
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', mountRowActions );
			} else {
				mountRowActions();
			}
			break;
		}
	}
}() );

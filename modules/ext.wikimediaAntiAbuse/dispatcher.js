'use strict';

( function () {
	switch ( mw.config.get( 'wgCanonicalSpecialPageName' ) ) {
		case 'AbuseReview':
			require( './SpecialAbuseReview.js' )();
			break;
	}
}() );

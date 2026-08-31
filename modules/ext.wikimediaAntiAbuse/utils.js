'use strict';

/**
 * Pick a human-readable message out of a REST error body, falling back to a generic one.
 *
 * @param {Object|null} error Parsed REST error body
 * @return {string}
 */
function actionErrorMessage( error ) {
	const translations = error && error.messageTranslations;
	if ( translations ) {
		const langs = Object.keys( translations );
		if ( langs.length ) {
			return translations[ mw.config.get( 'wgUserLanguage' ) ] || translations[ langs[ 0 ] ];
		}
	}
	return mw.msg( 'wikimediaantiabuse-special-abuse-review-action-error' );
}

/**
 * Updates the filters on the current page by redirecting the user to a view with
 * the provided filters.
 *
 * @param {*} filters A list of filters in a format acceptable for
 *   the params parameter for `mw.util.getUrl`
 * @param {window} win
 */
function updateFiltersOnPage( filters, win ) {
	// Keep the pager state params, except the offset (so the first page is shown again)
	const pagerStateParamsToKeep = [ 'limit', 'sort', 'dir' ];
	const urlParams = new URLSearchParams( win.location.search );

	pagerStateParamsToKeep.forEach( ( param ) => {
		const paramValue = urlParams.get( param );
		if ( paramValue ) {
			filters[ param ] = paramValue;
		}
	} );

	let newUrl = mw.config.get( 'wgServer' );
	newUrl += mw.util.getUrl( mw.config.get( 'wgPageName' ), filters );
	win.location.replace( newUrl );
}

module.exports = { actionErrorMessage, updateFiltersOnPage };

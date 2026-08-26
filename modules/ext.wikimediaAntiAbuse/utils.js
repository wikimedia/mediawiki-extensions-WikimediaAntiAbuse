'use strict';

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--false-positive';
const NOT_FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive';
const NO_FURTHER_ACTION_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--no-further-action';

/**
 * Show the tag chips matching the row's new verdict and hide the rest. Set from the state
 * rather than toggled, so it cannot drift if it ever runs twice for one change.
 *
 * @param {HTMLElement} row The review row whose state changed
 * @param {string|null} verdict The row's verdict, or null where it now carries none
 */
function setRowVerdict( row, verdict ) {
	const show = ( className, visible ) => {
		Array.prototype.forEach.call( row.querySelectorAll( '.' + className ), ( el ) => {
			el.classList.toggle( HIDDEN_CLASS, !visible );
		} );
	};

	show( NOT_FALSE_POSITIVE_TAG_CLASS, verdict !== 'falsePositive' );
	show( FALSE_POSITIVE_TAG_CLASS, verdict === 'falsePositive' );
	show( NO_FURTHER_ACTION_TAG_CLASS, verdict === 'noFurtherAction' );
}

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

module.exports = { setRowVerdict, actionErrorMessage, updateFiltersOnPage };

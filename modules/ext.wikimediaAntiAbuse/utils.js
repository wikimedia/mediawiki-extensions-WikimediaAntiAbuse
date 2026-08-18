'use strict';

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';

/**
 * Flip the visibility of a row's controls and tags after a successful
 * mark/unmark: within the given toggle scope, the showing elements hide
 * and the hidden ones show.
 *
 * @param {HTMLElement} row The review row whose state changed
 * @param {string} toggleClass Marks the controls/tags of the action that changed
 */
function updateRowToggles( row, toggleClass ) {
	Array.prototype.forEach.call( row.querySelectorAll( '.' + toggleClass ), ( el ) => {
		// The following classes are used here:
		// * mw-wikimediaantiabuse-hidden
		// * mw-wikimediaantiabuse-abuse-review-toggle-false-positive
		// * mw-wikimediaantiabuse-abuse-review-toggle-no-further-action
		el.classList.toggle( HIDDEN_CLASS );
	} );
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

module.exports = { updateRowToggles, actionErrorMessage };

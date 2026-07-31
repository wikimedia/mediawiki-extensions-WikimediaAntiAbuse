'use strict';

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle';

/**
 * Flip the visibility of a row's controls and tags after a successful
 * mark/unmark: the showing pair hides and the hidden pair shows.
 *
 * @param {HTMLElement} row The review row whose state changed
 */
function updateRowForFalsePositiveChange( row ) {
	Array.prototype.forEach.call( row.querySelectorAll( '.' + TOGGLE_CLASS ), ( el ) => {
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

module.exports = { updateRowForFalsePositiveChange, actionErrorMessage };

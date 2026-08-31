'use strict';

const Vue = require( 'vue' );
const RowActions = require( './components/RowActions.vue' );

const APP_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-verdicts-app';
const ROW_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row';
const DETAILS_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row__details';
const ID_PREFIX = 'mw-wikimediaantiabuse-abuse-review-row-';

/**
 * Mount one app per row. Only a row's verdict buttons are Vue; the rest of the queue
 * arrives as HTML from the server.
 */
function mountRowActions() {
	// The no-nodelist-unsupported-methods lint rule bans NodeList#forEach.
	Array.prototype.forEach.call( document.querySelectorAll( APP_SELECTOR ), ( mountPoint ) => {
		let props;
		try {
			props = JSON.parse( mountPoint.getAttribute( 'data-verdicts' ) );
		} catch ( error ) {
			mw.log.warn( 'Skipping a review row with unreadable verdicts: ' + error );
			return;
		}
		// JSON.parse( null ) is null rather than a throw, so a missing attribute lands here.
		if ( !props ) {
			mw.log.warn( 'Skipping a review row with no verdicts payload' );
			return;
		}

		const row = mountPoint.closest( ROW_SELECTOR );
		const revId = Number( row && row.dataset.revId );
		if ( !revId ) {
			mw.log.warn( 'Skipping a review row that names no revision' );
			return;
		}

		const details = row.querySelector( DETAILS_SELECTOR );
		const app = Vue.createMwApp( RowActions, Object.assign( {}, props, {
			revId,
			detailsElement: details
		} ) );
		// Without a per-app id prefix, every row's Codex components generate the same ids.
		app.config.idPrefix = ID_PREFIX + revId;
		app.mount( mountPoint );
	} );
}

module.exports = { mountRowActions };

'use strict';

const Vue = require( 'vue' );
const RowActions = require( './RowActions.vue' );
const { setRowVerdict } = require( './utils.js' );

const APP_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-verdicts-app';
const ROW_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row';
const ID_PREFIX = 'mw-wikimediaantiabuse-abuse-review-row-';

/**
 * Mount one app per row. Only a row's verdict controls are Vue; the rest of the queue
 * arrives as HTML from the server.
 */
function mountRowActions() {
	// A static list, NodeList#forEach not being available to every browser we support.
	Array.prototype.forEach.call( document.querySelectorAll( APP_SELECTOR ), ( mountPoint ) => {
		let props;
		try {
			props = JSON.parse( mountPoint.getAttribute( 'data-verdicts' ) );
		} catch ( error ) {
			// One unreadable row must not cost the rest of the queue its verdict controls.
			mw.log.warn( 'Skipping a review row with unreadable verdicts: ' + error );
			return;
		}
		// JSON.parse( null ) is null rather than a throw, so a missing attribute lands here.
		if ( !props ) {
			mw.log.warn( 'Skipping a review row with no verdicts payload' );
			return;
		}

		const row = mountPoint.closest( ROW_SELECTOR );
		// The row already names the revision it is about, so the payload only carries what
		// the viewer may do with it.
		const revId = Number( row && row.dataset.revId );
		if ( !revId ) {
			mw.log.warn( 'Skipping a review row that names no revision' );
			return;
		}

		// The tag chips are server-rendered above, so the row is flipped from here.
		const app = Vue.createMwApp( RowActions, Object.assign( {}, props, {
			revId,
			onVerdictChanged: ( verdict ) => {
				setRowVerdict( row, verdict );
			}
		} ) );
		// Vue restarts its generated ids per app, so without a prefix every row's Codex
		// components claim the same ones and a label points into the row above.
		app.config.idPrefix = ID_PREFIX + revId;
		app.mount( mountPoint );
	} );
}

module.exports = { mountRowActions };

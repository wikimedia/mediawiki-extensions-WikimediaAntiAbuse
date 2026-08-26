'use strict';

const Vue = require( 'vue' );
const RowVerdicts = require( './components/RowVerdicts.vue' );

const APP_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-verdicts-app';
const ROW_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row';
const DETAILS_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row__details';
const HELD_VERDICT_SELECTOR =
	'.mw-wikimediaantiabuse-abuse-review-verdicts button[aria-pressed="true"]';
const ID_PREFIX = 'mw-wikimediaantiabuse-abuse-review-row-';

/**
 * Whether the row is already handled, so the queue steps over it. A filter can show a
 * row that holds a verdict or whose text is suppressed, and neither waits for review.
 *
 * @param {HTMLElement} row
 * @param {Set} suppressedRows The rows the pager marked as suppressed
 * @return {boolean}
 */
function isHandled( row, suppressedRows ) {
	return !!row.querySelector( HELD_VERDICT_SELECTOR ) || suppressedRows.has( row );
}

/**
 * Close the row a verdict was just given to and open the next closed row, so the
 * reviewer is handed the next edit. Clearing a verdict puts its row back in the
 * queue, so it advances nothing.
 *
 * @param {HTMLElement} row The row the verdict was given to
 * @param {string|null} verdict The verdict now held, or null if it was cleared
 * @param {Set} suppressedRows The rows the pager marked as suppressed
 */
function advanceQueue( row, verdict, suppressedRows ) {
	if ( verdict === null ) {
		return;
	}

	const details = row.querySelector( DETAILS_SELECTOR );
	if ( details ) {
		details.open = false;
	}

	for ( let next = row.nextElementSibling; next; next = next.nextElementSibling ) {
		if ( !next.matches( ROW_SELECTOR ) ) {
			continue;
		}
		const nextDetails = next.querySelector( DETAILS_SELECTOR );
		if ( nextDetails && !nextDetails.open && !isHandled( next, suppressedRows ) ) {
			nextDetails.open = true;
			return;
		}
	}
}

/**
 * Mount one app per row. Only a row's verdict buttons are Vue; the rest of the queue
 * arrives as HTML from the server.
 */
function mountRowVerdicts() {
	const suppressedRows = new Set();
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

		if ( props.isSuppressed ) {
			suppressedRows.add( row );
		}

		const details = row.querySelector( DETAILS_SELECTOR );
		const app = Vue.createMwApp( RowVerdicts, Object.assign( {}, props, {
			revId,
			detailsElement: details,
			onVerdictChanged: ( verdict ) => {
				advanceQueue( row, verdict, suppressedRows );
			}
		} ) );
		// Without a per-app id prefix, every row's Codex components generate the same ids.
		app.config.idPrefix = ID_PREFIX + revId;
		app.mount( mountPoint );
	} );
}

module.exports = { mountRowVerdicts };

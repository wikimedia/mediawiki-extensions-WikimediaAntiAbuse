'use strict';

const Vue = require( 'vue' );
const RowVerdicts = require( './components/RowVerdicts.vue' );

const APP_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-verdicts-app';
const ROW_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row';
const DETAILS_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-row__details';
const HELD_VERDICT_SELECTOR =
	'.mw-wikimediaantiabuse-abuse-review-verdicts[data-verdict-held]';
const ID_PREFIX = 'mw-wikimediaantiabuse-abuse-review-row-';

/**
 * Whether the row is already handled, so the queue steps over it. A filter can show a
 * row that holds a verdict or whose text is suppressed, and neither waits for review.
 *
 * @param {HTMLElement} row
 * @param {Set} handledOutsideAbuseReviewRows The rows the pager marked as handled outside AbuseReview
 * @return {boolean}
 */
function isHandled( row, handledOutsideAbuseReviewRows ) {
	return !!row.querySelector( HELD_VERDICT_SELECTOR ) || handledOutsideAbuseReviewRows.has( row );
}

/**
 * @param {HTMLElement} row
 * @return {HTMLElement|null}
 */
function makeActionsGroup( row ) {
	const content = row.querySelector( '.mw-wikimediaantiabuse-abuse-review-row__content' );
	if ( !content ) {
		return null;
	}

	const actions = document.createElement( 'div' );
	actions.className = 'mw-wikimediaantiabuse-abuse-review-actions';
	content.appendChild( actions );

	return actions;
}

/**
 * Close the row a verdict was just given to and open the next closed row, so the
 * reviewer is handed the next edit. Clearing a verdict puts its row back in the
 * queue, so it advances nothing.
 *
 * @param {HTMLElement} row The row the verdict was given to
 * @param {string|null} verdict The verdict now held, or null if it was cleared
 * @param {Set} handledOutsideAbuseReviewRows The rows the pager marked as handled outside AbuseReview
 */
function advanceQueue( row, verdict, handledOutsideAbuseReviewRows ) {
	if ( verdict === null ) {
		return;
	}

	const details = row.querySelector( DETAILS_SELECTOR );
	// If the current row is closed, don't advance the queue.
	if ( !details || !details.open ) {
		return;
	}
	details.open = false;

	for ( let next = row.nextElementSibling; next; next = next.nextElementSibling ) {
		if ( !next.matches( ROW_SELECTOR ) ) {
			continue;
		}
		const nextDetails = next.querySelector( DETAILS_SELECTOR );
		if ( nextDetails && !nextDetails.open && !isHandled( next, handledOutsideAbuseReviewRows ) ) {
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
	const referrer = mw.util.getParamValue( 'referrer' ) || '';

	const handledOutsideAbuseReviewRows = new Set();
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

		if ( props.isHandledOutsideAbuseReview ) {
			handledOutsideAbuseReviewRows.add( row );
		}

		const details = row.querySelector( DETAILS_SELECTOR );
		const app = Vue.createMwApp( RowVerdicts, Object.assign( {}, props, {
			revId,
			detailsElement: details,
			actionsElement: makeActionsGroup( row ),
			referrer: referrer,
			onVerdictChanged: ( verdict ) => {
				advanceQueue( row, verdict, handledOutsideAbuseReviewRows );
			}
		} ) );
		// Without a per-app id prefix, every row's Codex components generate the same ids.
		app.config.idPrefix = ID_PREFIX + revId;
		app.mount( mountPoint );
	} );
}

module.exports = { mountRowVerdicts };

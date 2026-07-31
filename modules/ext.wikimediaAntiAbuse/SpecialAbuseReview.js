'use strict';

const { markAsFalsePositive, unmarkAsFalsePositive } = require( './rest.js' );
const { updateRowForFalsePositiveChange, actionErrorMessage } = require( './utils.js' );

module.exports = function () {
	// This module can run before the pager table exists, so bind once the DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindButtons );
	} else {
		bindButtons();
	}
};

function bindButtons() {
	addClickHandler( 'mw-wikimediaantiabuse-abuse-review-mark-button', true );
	addClickHandler( 'mw-wikimediaantiabuse-abuse-review-unmark-button', false );
}

/**
 * @param {string} className
 * @param {boolean} markingFalsePositive Whether a click marks (vs unmarks) a false positive
 */
function addClickHandler( className, markingFalsePositive ) {
	Array.prototype.forEach.call(
		document.getElementsByClassName( className ),
		( button ) => button.addEventListener(
			'click', ( event ) => onActionClick( event, markingFalsePositive )
		)
	);
}

/**
 * Handle a click on a mark/unmark button: POST to the REST endpoint and, on
 * success, flip the row. The clicked button is disabled while the request runs.
 *
 * @param {Event} event
 * @param {boolean} markingFalsePositive
 */
async function onActionClick( event, markingFalsePositive ) {
	event.preventDefault();

	const button = event.currentTarget;
	const revId = button.getAttribute( 'data-rev-id' );
	const tag = button.getAttribute( 'data-abuse-review-tag' );
	if ( !tag ) {
		return;
	}

	const request = markingFalsePositive ? markAsFalsePositive : unmarkAsFalsePositive;

	// Disable the clicked button while the request is in flight.
	button.disabled = true;

	try {
		await request( revId, tag );
		updateRowForFalsePositiveChange( button.closest( '.mw-wikimediaantiabuse-abuse-review-row' ) );
	} catch ( error ) {
		mw.notify( actionErrorMessage( error ), { type: 'error' } );
	} finally {
		button.disabled = false;
	}
}

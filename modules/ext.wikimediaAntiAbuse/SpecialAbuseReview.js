'use strict';

const {
	markAsFalsePositive,
	unmarkAsFalsePositive,
	markNoFurtherAction,
	unmarkNoFurtherAction
} = require( './rest.js' );
const { updateRowToggles, actionErrorMessage } = require( './utils.js' );

const FALSE_POSITIVE_TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle-false-positive';
const NO_FURTHER_ACTION_TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle-no-further-action';

module.exports = function () {
	// This module can run before the pager table exists, so bind once the DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindButtons );
	} else {
		bindButtons();
	}
};

function bindButtons() {
	addClickHandler(
		'mw-wikimediaantiabuse-abuse-review-mark-button',
		markAsFalsePositive, FALSE_POSITIVE_TOGGLE_CLASS
	);
	addClickHandler(
		'mw-wikimediaantiabuse-abuse-review-unmark-button',
		unmarkAsFalsePositive, FALSE_POSITIVE_TOGGLE_CLASS
	);
	addClickHandler(
		'mw-wikimediaantiabuse-abuse-review-mark-no-further-action-button',
		markNoFurtherAction, NO_FURTHER_ACTION_TOGGLE_CLASS
	);
	addClickHandler(
		'mw-wikimediaantiabuse-abuse-review-unmark-no-further-action-button',
		unmarkNoFurtherAction, NO_FURTHER_ACTION_TOGGLE_CLASS
	);
}

/**
 * @param {string} className
 * @param {Function} request REST call that marks or unmarks the row
 * @param {string} toggleClass Marks the row elements that flip on success
 */
function addClickHandler( className, request, toggleClass ) {
	Array.prototype.forEach.call(
		document.getElementsByClassName( className ),
		( button ) => button.addEventListener(
			'click', ( event ) => onActionClick( event, request, toggleClass )
		)
	);
}

/**
 * Handle a click on a mark/unmark button: POST to the REST endpoint and, on
 * success, flip the row. The clicked button is disabled while the request runs.
 *
 * @param {Event} event
 * @param {Function} request
 * @param {string} toggleClass
 */
async function onActionClick( event, request, toggleClass ) {
	event.preventDefault();

	const button = event.currentTarget;
	const revId = button.getAttribute( 'data-rev-id' );
	const tag = button.getAttribute( 'data-abuse-review-tag' );
	if ( !tag ) {
		return;
	}

	// Disable the clicked button while the request is in flight.
	button.disabled = true;

	try {
		await request( revId, tag );
		updateRowToggles(
			button.closest( '.mw-wikimediaantiabuse-abuse-review-row' ),
			toggleClass
		);
	} catch ( error ) {
		mw.notify( actionErrorMessage( error ), { type: 'error' } );
	} finally {
		button.disabled = false;
	}
}

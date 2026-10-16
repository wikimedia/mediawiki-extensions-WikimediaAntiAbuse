'use strict';

const { flushPromises } = require( 'vue-test-utils' );
const { mountRowVerdicts } = require( 'ext.wikimediaAntiAbuse/mountRowVerdicts.js' );

const APP_CLASS = 'mw-wikimediaantiabuse-abuse-review-verdicts-app';
const ACTIONS_SELECTOR = '.mw-wikimediaantiabuse-abuse-review-actions';
const MARK_NO_FURTHER_ACTION_BUTTON_LABEL =
	'(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)';
const SEND_BACK_BUTTON_LABEL =
	'(wikimediaantiabuse-special-abuse-review-action-send-back-for-review)';

QUnit.module( 'ext.wikimediaAntiAbuse.mountRowVerdicts', QUnit.newMwEnvironment() );

/**
 * A review row shaped the way the pager renders one: a details element holding the flag
 * and the mount point.
 *
 * @param {number|null} revId Null omits the row's data-rev-id attribute
 * @param {Object|null} payload Null omits the data-verdicts attribute entirely
 * @param {boolean} open Whether the row starts open, as the first one does
 * @return {HTMLElement}
 */
function makeRow( revId, payload, open ) {
	const row = document.createElement( 'tr' );
	row.className = 'mw-wikimediaantiabuse-abuse-review-row';
	if ( revId !== null ) {
		row.dataset.revId = revId;
	}

	const cell = document.createElement( 'td' );
	const details = document.createElement( 'details' );
	details.className = 'mw-wikimediaantiabuse-abuse-review-row__details';
	details.open = !!open;

	const summary = document.createElement( 'summary' );
	const mountPoint = document.createElement( 'span' );
	// The following classes are used here:
	// * mw-wikimediaantiabuse-abuse-review-verdicts-app
	mountPoint.className = APP_CLASS;
	if ( payload !== null ) {
		mountPoint.setAttribute( 'data-verdicts', JSON.stringify( payload ) );
	}
	summary.appendChild( mountPoint );
	details.appendChild( summary );

	const content = document.createElement( 'div' );
	content.className = 'mw-wikimediaantiabuse-abuse-review-row__content';
	details.appendChild( content );

	cell.appendChild( details );
	row.appendChild( cell );

	let tbody = document.getElementById( 'qunit-fixture' ).querySelector( 'tbody' );
	if ( !tbody ) {
		const table = document.createElement( 'table' );
		tbody = document.createElement( 'tbody' );
		table.appendChild( tbody );
		document.getElementById( 'qunit-fixture' ).appendChild( table );
	}
	tbody.appendChild( row );
	return row;
}

const payloadFor = ( overrides ) => Object.assign( {
	tag: 'mw-private-personal-info',
	isFalsePositive: false,
	isNoFurtherAction: false,
	handledOutsideAbuseReviewMessage: null
}, overrides );

const isOpen = ( row ) => row.querySelector( '.mw-wikimediaantiabuse-abuse-review-row__details' ).open;

/**
 * Mark buttons carry an accessible name; the send-back control carries its label as text.
 *
 * @param {HTMLElement} row
 * @param {string} label
 */
function clickButton( row, label ) {
	const buttons = Array.prototype.filter.call(
		row.querySelectorAll( 'button' ),
		( button ) => ( button.getAttribute( 'aria-label' ) || button.textContent.trim() ) === label
	);
	if ( buttons.length !== 1 ) {
		throw new Error( 'Expected one "' + label + '" control, found ' + buttons.length );
	}
	buttons[ 0 ].click();
}

QUnit.test( 'it mounts an app in every row', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );

	mountRowVerdicts();
	await flushPromises();

	assert.strictEqual( first.querySelectorAll( 'button' ).length, 2, 'the first row offers two verdict buttons' );
	assert.strictEqual( second.querySelectorAll( 'button' ).length, 2, 'the second row offers two verdict buttons' );
} );

QUnit.test( 'a closed row can be judged as well as an open one', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );

	mountRowVerdicts();
	await flushPromises();

	assert.false(
		first.querySelector( '.mw-wikimediaantiabuse-abuse-review-verdicts button' ).disabled,
		'the open row can be judged'
	);
	assert.false(
		second.querySelector( '.mw-wikimediaantiabuse-abuse-review-verdicts button' ).disabled,
		'the closed row can be judged without opening it first'
	);
} );

QUnit.test( 'a verdict closes its row and opens the next one waiting', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );
	const third = makeRow( 3, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isOpen( first ), 'the row just judged is closed' );
	assert.true( isOpen( second ), 'the next row is opened' );
	assert.false( isOpen( third ), 'the row after the one opened stays closed' );
} );

QUnit.test( 'a verdict on a closed row leaves the queue where it stands', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const openRow = makeRow( 1, payloadFor(), true );
	const closedRow = makeRow( 2, payloadFor(), false );
	const waitingRow = makeRow( 3, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( closedRow, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.true(
		!!closedRow.querySelector( '.mw-wikimediaantiabuse-abuse-review-verdicts .cdx-info-chip' ),
		'the closed row takes the verdict, the chip standing for it'
	);
	assert.false( isOpen( closedRow ), 'and stays closed' );
	assert.true( isOpen( openRow ), 'the row the reviewer opened stays open' );
	assert.false( isOpen( waitingRow ), 'no other row is opened' );
} );

QUnit.test( 'a verdict skips a row that is already open', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), true );
	const third = makeRow( 3, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.true( isOpen( second ), 'the row already open is left open' );
	assert.true( isOpen( third ), 'the next closed row is the one opened' );
} );

QUnit.test( 'a verdict skips a row a filter shows as handled', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor(), true );
	const judged = makeRow( 2, payloadFor( { isNoFurtherAction: true } ), false );
	const handledOutsideAbuseReview = makeRow(
		3,
		payloadFor( {
			handledOutsideAbuseReviewMessage:
				'wikimediaantiabuse-special-abuse-review-handled-outside-abuse-review-mw-private-personal-info'
		} ),
		false
	);
	const waiting = makeRow( 4, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isOpen( judged ), 'the row holding a verdict is stepped over' );
	assert.false(
		isOpen( handledOutsideAbuseReview ),
		'the handled outside abuse review row is stepped over as well'
	);
	assert.true( isOpen( waiting ), 'the next row waiting for review is opened' );
} );

QUnit.test( 'a verdict skips a row the poll reports as handled', async function ( assert ) {
	const clock = this.sandbox.useFakeTimers();
	// Stop the poller from adding its listener, so later tests send no requests.
	this.sandbox.stub( document, 'addEventListener' );
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', {
		queue: 'mw-private-personal-info',
		pollSeconds: 30,
		handledReasonMessages: {
			suppressed: 'wikimediaantiabuse-special-abuse-review-handled-outside-abuse-review-mw-private-personal-info'
		}
	} );
	this.sandbox.stub( mw.Api.prototype, 'get' ).resolves( { query: { abusereview: [
		{ revid: 1, status: 'unhandled' },
		{ revid: 2, status: 'handled', handledreason: 'suppressed' },
		{ revid: 3, status: 'unhandled' }
	] } } );
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor(), true );
	const handledElsewhere = makeRow( 2, payloadFor(), false );
	const waiting = makeRow( 3, payloadFor(), false );
	mountRowVerdicts();
	await clock.tickAsync( 30000 );

	clickButton( first, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isOpen( handledElsewhere ), 'the row the poll reports as handled is stepped over' );
	assert.true( isOpen( waiting ), 'the next row waiting for review is opened' );
} );

QUnit.test( 'a mark refused as a conflicting verdict shows the server verdict on that row', async function ( assert ) {
	// The poll is turned off, so only the refusal sends a request.
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', {
		queue: 'mw-private-personal-info',
		pollSeconds: 0,
		handledReasonMessages: {}
	} );
	const get = this.sandbox.stub( mw.Api.prototype, 'get' ).resolves( { query: { abusereview: [
		{ revid: 2, status: 'false-positive' }
	] } } );
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( {
		then: ( onSuccess, onFailure ) => onFailure( 'http', { xhr: { responseJSON: {
			errorKey: 'wikimediaantiabuse-api-review-conflicting-verdict'
		} } } )
	} );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );
	this.sandbox.stub( mw, 'notify' );

	makeRow( 1, payloadFor(), false );
	const refused = makeRow( 2, payloadFor(), true );
	mountRowVerdicts();
	await flushPromises();

	clickButton( refused, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.strictEqual( get.callCount, 1, 'one status request is sent' );
	assert.deepEqual( get.firstCall.args[ 0 ], {
		action: 'query',
		list: 'abusereview',
		abrqueue: 'mw-private-personal-info',
		abrrevid: [ 2 ],
		abrshowhandled: true,
		abrshowfalsepositives: true,
		abrprop: [ 'ids', 'status' ],
		abrlimit: 50,
		formatversion: 2
	}, 'the status request asks the queue about the refused row only' );
	assert.strictEqual(
		refused.querySelector( '.cdx-info-chip__text' ).textContent.trim(),
		'(wikimediaantiabuse-special-abuse-review-verdict-chip-false-positive)',
		'the refused row shows the verdict the server holds'
	);
} );

QUnit.test( 'sending a row back does not advance the queue', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor( { isFalsePositive: true } ), true );
	const second = makeRow( 2, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, SEND_BACK_BUTTON_LABEL );
	await flushPromises();

	assert.true( isOpen( first ), 'the row put back in the queue stays open' );
	assert.false( isOpen( second ), 'nothing else is opened' );
} );

QUnit.test( 'a verdict on the last row opens nothing', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const only = makeRow( 1, payloadFor(), true );
	mountRowVerdicts();
	await flushPromises();

	clickButton( only, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isOpen( only ), 'the row is closed, there being nothing after it' );
} );

QUnit.test( 'the send-back control is given a group of its own', async ( assert ) => {
	const row = makeRow( 1, payloadFor( { isNoFurtherAction: true } ), true );

	mountRowVerdicts();
	await flushPromises();

	const actions = row.querySelector( ACTIONS_SELECTOR );
	assert.true( !!actions, 'the row is given an action group' );
	assert.strictEqual(
		actions.querySelectorAll( 'button' ).length,
		1,
		'the send-back control is the only thing in the group made for it'
	);
} );

QUnit.test( 'a row with an unreadable payload is skipped, not fatal', async ( assert ) => {
	const broken = makeRow( 1, payloadFor(), false );
	broken.querySelector( '.' + APP_CLASS ).setAttribute( 'data-verdicts', '{not json' );
	const missing = makeRow( 2, null, false );
	const anonymous = makeRow( null, payloadFor(), false );
	const healthy = makeRow( 3, payloadFor(), false );

	mountRowVerdicts();
	await flushPromises();

	assert.strictEqual(
		broken.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'the row with malformed JSON gets no app mounted'
	);
	assert.strictEqual(
		missing.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'the row with no payload gets no app, rather than one with no props'
	);
	assert.strictEqual(
		anonymous.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'the row that names no revision gets no app, having nothing to act on'
	);
	assert.strictEqual(
		healthy.querySelectorAll( 'button' ).length,
		2,
		'the healthy row after the skipped ones gets its two verdict buttons'
	);
} );

QUnit.test( 'The referrer in the query string is sent with a verdict', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );
	this.sandbox.stub( mw.util, 'getParamValue' )
		.withArgs( 'referrer' ).returns( 'echo_notification' );

	const row = makeRow( 1, payloadFor(), true );
	mountRowVerdicts();
	await flushPromises();

	clickButton( row, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.deepEqual(
		post.firstCall.args[ 1 ],
		{ token: 'token', referrer: 'echo_notification' },
		'the request body carries the referrer read from the query string'
	);
} );

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
 * The buttons the pager renders into the mount point, which the app replaces. What they
 * hold does not matter here, only that a test can tell a replaced one from a fresh one.
 *
 * @return {HTMLElement}
 */
function makeServerVerdicts() {
	const verdicts = document.createElement( 'span' );
	verdicts.className = 'mw-wikimediaantiabuse-abuse-review-verdicts';
	verdicts.appendChild( document.createElement( 'button' ) );
	verdicts.appendChild( document.createElement( 'button' ) );
	return verdicts;
}

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
		mountPoint.appendChild( makeServerVerdicts() );
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
	isHandledOutsideAbuseReview: false
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

QUnit.test( 'it mounts an app over the buttons the pager rendered', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );
	const serverRendered = first.querySelector( 'button' );

	mountRowVerdicts();
	await flushPromises();

	assert.false(
		first.contains( serverRendered ),
		'the app takes the place of the buttons that came from the server'
	);
	assert.strictEqual(
		second.querySelectorAll( 'button' ).length,
		2,
		'and the second row still offers two buttons, not four'
	);
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
		payloadFor( { isHandledOutsideAbuseReview: true } ),
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
	// A skipped row keeps the buttons the server sent, so identity is the signal here.
	const brokenButton = broken.querySelector( 'button' );
	const anonymousButton = anonymous.querySelector( 'button' );
	const healthyButton = healthy.querySelector( 'button' );

	mountRowVerdicts();
	await flushPromises();

	assert.true(
		broken.contains( brokenButton ),
		'the malformed row gets no app mounted over it'
	);
	assert.strictEqual(
		missing.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'the row with no payload gets no app either, rather than one with no props'
	);
	assert.true(
		anonymous.contains( anonymousButton ),
		'nor does a row that names no revision, the app having nothing to act on'
	);
	assert.false(
		healthy.contains( healthyButton ),
		'the healthy row still mounts, which is the point of the guard'
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

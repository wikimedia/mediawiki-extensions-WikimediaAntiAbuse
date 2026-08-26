'use strict';

const { flushPromises } = require( 'vue-test-utils' );
const { mountRowVerdicts } = require( 'ext.wikimediaAntiAbuse/mountRowVerdicts.js' );

const APP_CLASS = 'mw-wikimediaantiabuse-abuse-review-verdicts-app';
const MARK_NO_FURTHER_ACTION_BUTTON_LABEL =
	'(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)';
const UNMARK_BUTTON_LABEL =
	'(wikimediaantiabuse-special-abuse-review-action-unmark-false-positive)';

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
	isSuppressed: false
}, overrides );

const isOpen = ( row ) => row.querySelector( '.mw-wikimediaantiabuse-abuse-review-row__details' ).open;

/**
 * Click the row's control carrying the given name. The buttons carry an icon rather than
 * a label, so the accessible name is what tells them apart.
 *
 * @param {HTMLElement} row
 * @param {string} label
 */
function clickButton( row, label ) {
	const buttons = Array.prototype.filter.call(
		row.querySelectorAll( '.mw-wikimediaantiabuse-abuse-review-verdicts button' ),
		( button ) => button.getAttribute( 'aria-label' ) === label
	);
	if ( buttons.length !== 1 ) {
		throw new Error( 'Expected one "' + label + '" control, found ' + buttons.length );
	}
	buttons[ 0 ].click();
}

QUnit.test( 'it mounts an app into every row', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );

	mountRowVerdicts();
	await flushPromises();

	assert.notStrictEqual(
		first.querySelector( 'button' ),
		null,
		'the first row gained its controls'
	);
	assert.notStrictEqual(
		second.querySelector( 'button' ),
		null,
		'the second row mounted too'
	);
} );

QUnit.test( 'only the open row can be judged', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );

	mountRowVerdicts();
	await flushPromises();

	assert.false(
		first.querySelector( '.mw-wikimediaantiabuse-abuse-review-verdicts button' ).disabled,
		'the open row can be judged'
	);
	assert.true(
		second.querySelector( '.mw-wikimediaantiabuse-abuse-review-verdicts button' ).disabled,
		'a closed one cannot'
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
	assert.false( isOpen( third ), 'and only that one' );
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
	assert.true( isOpen( third ), 'and the next closed one is the one opened' );
} );

QUnit.test( 'a verdict skips a row a filter shows as handled', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor(), true );
	const judged = makeRow( 2, payloadFor( { isNoFurtherAction: true } ), false );
	const suppressed = makeRow( 3, payloadFor( { isSuppressed: true } ), false );
	const waiting = makeRow( 4, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isOpen( judged ), 'the row holding a verdict is stepped over' );
	assert.false( isOpen( suppressed ), 'so is the one already suppressed' );
	assert.true( isOpen( waiting ), 'and the next row waiting for review is opened' );
} );

QUnit.test( 'clearing a verdict does not advance the queue', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const first = makeRow( 1, payloadFor( { isFalsePositive: true } ), true );
	const second = makeRow( 2, payloadFor(), false );
	mountRowVerdicts();
	await flushPromises();

	clickButton( first, UNMARK_BUTTON_LABEL );
	await flushPromises();

	assert.true( isOpen( first ), 'the row put back in the queue stays open' );
	assert.false( isOpen( second ), 'and nothing else is opened' );
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
		'the malformed row gets no app mounted over it'
	);
	assert.strictEqual(
		missing.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'the row with no payload gets no app either, rather than one with no props'
	);
	assert.strictEqual(
		anonymous.querySelector( '.' + APP_CLASS ).children.length,
		0,
		'nor does a row that names no revision, the app having nothing to act on'
	);
	assert.notStrictEqual(
		healthy.querySelector( 'button' ),
		null,
		'the healthy row still mounts, which is the point of the guard'
	);
} );

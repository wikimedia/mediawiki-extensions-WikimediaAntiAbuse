'use strict';

const { flushPromises } = require( 'vue-test-utils' );
const { mountRowActions } = require( 'ext.wikimediaAntiAbuse/mountRowActions.js' );

const APP_CLASS = 'mw-wikimediaantiabuse-abuse-review-verdicts-app';

QUnit.module( 'ext.wikimediaAntiAbuse.mountRowActions', QUnit.newMwEnvironment() );

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

QUnit.test( 'it mounts an app into every row', async ( assert ) => {
	const first = makeRow( 1, payloadFor(), true );
	const second = makeRow( 2, payloadFor(), false );

	mountRowActions();
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

QUnit.test( 'a row with an unreadable payload is skipped, not fatal', async ( assert ) => {
	const broken = makeRow( 1, payloadFor(), false );
	broken.querySelector( '.' + APP_CLASS ).setAttribute( 'data-verdicts', '{not json' );
	const missing = makeRow( 2, null, false );
	const anonymous = makeRow( null, payloadFor(), false );
	const healthy = makeRow( 3, payloadFor(), false );

	mountRowActions();
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

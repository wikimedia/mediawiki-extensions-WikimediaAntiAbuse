'use strict';

const { flushPromises } = require( 'vue-test-utils' );
const { mountRowActions } = require( 'ext.wikimediaAntiAbuse/mountRowActions.js' );

const APP_CLASS = 'mw-wikimediaantiabuse-abuse-review-row-actions-app';
const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const FALSE_POSITIVE_TAG = 'mw-wikimediaantiabuse-abuse-review-tag--false-positive';
const NOT_FALSE_POSITIVE_TAG = 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive';
const NO_FURTHER_ACTION_TAG = 'mw-wikimediaantiabuse-abuse-review-tag--no-further-action';
const MARK_BUTTON_LABEL = 'Mark as false positive';
const MARK_NO_FURTHER_ACTION_BUTTON_LABEL = 'No further action needed';

QUnit.module( 'ext.wikimediaAntiAbuse.mountRowActions', QUnit.newMwEnvironment( {
	messages: {
		'wikimediaantiabuse-special-abuse-review-action-mark-false-positive': MARK_BUTTON_LABEL,
		'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action':
			MARK_NO_FURTHER_ACTION_BUTTON_LABEL
	}
} ) );

/**
 * A review row shaped the way the pager renders one, no-JS links and all.
 *
 * @param {number|null} revId Null omits the row's data-rev-id attribute
 * @param {Object|null} payload Null omits the data-actions attribute entirely
 * @return {HTMLElement}
 */
function makeRow( revId, payload ) {
	const row = document.createElement( 'div' );
	row.className = 'mw-wikimediaantiabuse-abuse-review-row';
	if ( revId !== null ) {
		row.dataset.revId = revId;
	}

	const el = ( tag, className, hidden ) => {
		const node = document.createElement( tag );
		// The following classes are used here:
		// * mw-wikimediaantiabuse-hidden
		// * mw-wikimediaantiabuse-abuse-review-row-actions-app
		// * mw-wikimediaantiabuse-abuse-review-tag--false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--not-false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--no-further-action
		node.className = className + ( hidden ? ' ' + HIDDEN_CLASS : '' );
		return node;
	};

	const summary = document.createElement( 'div' );
	summary.append(
		el( 'span', FALSE_POSITIVE_TAG, true ),
		el( 'span', NOT_FALSE_POSITIVE_TAG, false ),
		el( 'span', NO_FURTHER_ACTION_TAG, true )
	);

	const content = document.createElement( 'div' );
	content.className = 'mw-wikimediaantiabuse-abuse-review-row__content';

	const mountPoint = el( 'div', APP_CLASS, false );
	if ( payload !== null ) {
		mountPoint.setAttribute( 'data-actions', JSON.stringify( payload ) );
		if ( payload.revertUrl ) {
			const link = document.createElement( 'a' );
			link.href = payload.revertUrl;
			mountPoint.appendChild( link );
		}
	}

	content.appendChild( mountPoint );
	row.append( summary, content );
	document.getElementById( 'qunit-fixture' ).appendChild( row );
	return row;
}

const payloadFor = ( revId, overrides ) => Object.assign( {
	revisionDeleteUrl: null,
	revertUrl: '/wiki/Page?action=edit&undo=' + revId,
	tag: 'mw-private-personal-info',
	isFalsePositive: false,
	isNoFurtherAction: false,
	isSuppressed: false
}, overrides );

const isHidden = ( row, className ) => row.querySelector( '.' + className ).classList.contains( HIDDEN_CLASS );

/**
 * Click the row's control carrying the given label. A row offers one control per verdict,
 * so the label is what tells them apart.
 *
 * @param {HTMLElement} row
 * @param {string} label
 */
function clickButton( row, label ) {
	const buttons = Array.prototype.filter.call(
		row.querySelectorAll( '.mw-wikimediaantiabuse-abuse-review-actions button' ),
		( button ) => button.textContent.trim() === label
	);
	if ( buttons.length !== 1 ) {
		throw new Error( 'Expected one "' + label + '" control, found ' + buttons.length );
	}
	buttons[ 0 ].click();
}

QUnit.test( 'it mounts an app into every row', async ( assert ) => {
	const first = makeRow( 1, payloadFor( 1 ) );
	const second = makeRow( 2, payloadFor( 2 ) );

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

QUnit.test( 'the no-JS links are replaced rather than left beside the app', async ( assert ) => {
	const row = makeRow( 1, payloadFor( 1 ) );
	const mountPoint = row.querySelector( '.' + APP_CLASS );

	assert.strictEqual(
		mountPoint.querySelectorAll( 'a' ).length,
		1,
		'the server rendered a link inside the mount point'
	);

	mountRowActions();
	await flushPromises();

	assert.strictEqual(
		mountPoint.querySelectorAll( 'a' ).length,
		1,
		'mounting leaves one, the app emptying the container it took over'
	);
	assert.notStrictEqual(
		mountPoint.querySelector( 'button' ),
		null,
		'the link there now being the app\'s own, beside its controls'
	);
} );

QUnit.test( 'a row with an unreadable payload is skipped, not fatal', async ( assert ) => {
	const broken = makeRow( 1, payloadFor( 1 ) );
	broken.querySelector( '.' + APP_CLASS ).setAttribute( 'data-actions', '{not json' );
	const missing = makeRow( 2, null );
	const anonymous = makeRow( null, payloadFor( 4, { revertUrl: null } ) );
	const healthy = makeRow( 3, payloadFor( 3 ) );

	mountRowActions();
	await flushPromises();

	const brokenChildren = broken.querySelector( '.' + APP_CLASS ).children;
	assert.strictEqual( brokenChildren.length, 1, 'the malformed row keeps what it arrived with' );
	assert.strictEqual(
		brokenChildren[ 0 ].tagName,
		'A',
		'namely its no-JS link, so no app was mounted over it'
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

QUnit.test( 'marking a false positive flips the server-rendered tag chips', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const row = makeRow( 1, payloadFor( 1, { revertUrl: null } ) );
	const other = makeRow( 2, payloadFor( 2, { revertUrl: null } ) );
	mountRowActions();
	await flushPromises();

	assert.true( isHidden( row, FALSE_POSITIVE_TAG ), 'the false-positive chip starts hidden' );

	clickButton( row, MARK_BUTTON_LABEL );
	await flushPromises();

	assert.false(
		isHidden( row, FALSE_POSITIVE_TAG ),
		'the false-positive chip is shown once the row is marked'
	);
	assert.true( isHidden( row, NOT_FALSE_POSITIVE_TAG ), 'the original chip is hidden' );
	assert.true( isHidden( row, NO_FURTHER_ACTION_TAG ), 'the other verdict\'s chip stays hidden' );
	assert.true(
		isHidden( other, FALSE_POSITIVE_TAG ),
		'a neighbouring row is left alone'
	);
} );

QUnit.test( 'marking as needing no further action flips its own chip', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( { then: ( onSuccess ) => onSuccess( {} ) } );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const row = makeRow( 1, payloadFor( 1, { revertUrl: null } ) );
	mountRowActions();
	await flushPromises();

	assert.true( isHidden( row, NO_FURTHER_ACTION_TAG ), 'the chip starts hidden' );

	clickButton( row, MARK_NO_FURTHER_ACTION_BUTTON_LABEL );
	await flushPromises();

	assert.false( isHidden( row, NO_FURTHER_ACTION_TAG ), 'the chip is shown once the row is marked' );
	assert.false(
		isHidden( row, NOT_FALSE_POSITIVE_TAG ),
		'the flag itself stays, the verdict standing beside it'
	);
	assert.true( isHidden( row, FALSE_POSITIVE_TAG ), 'the other verdict\'s chip stays hidden' );
} );

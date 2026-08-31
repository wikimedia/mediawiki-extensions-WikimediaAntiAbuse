'use strict';

const { mount, flushPromises } = require( 'vue-test-utils' );
// Reached by module name: the component requires the synthetic codex.js that only exists
// inside its own CodexModule.
const RowActions = require( 'ext.wikimediaAntiAbuse/components/RowActions.vue' );

const MARK_FALSE_POSITIVE =
	'(wikimediaantiabuse-special-abuse-review-action-mark-false-positive)';
const UNMARK_FALSE_POSITIVE =
	'(wikimediaantiabuse-special-abuse-review-action-unmark-false-positive)';
const MARK_NO_FURTHER_ACTION =
	'(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)';
const UNMARK_NO_FURTHER_ACTION =
	'(wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action)';
const SUPPRESSED_NOTE = '(wikimediaantiabuse-special-abuse-review-already-suppressed-note)';
const CLOSED_ROW_NOTE = '(wikimediaantiabuse-special-abuse-review-closed-row-note)';

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.RowActions', QUnit.newMwEnvironment( {
	afterEach() {
		// Left mounted, wrappers accumulate across the module and eventually wedge the runner.
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

/**
 * The details element the component follows. A test that toggles the row brings its own.
 *
 * @param {boolean} open
 * @return {HTMLElement}
 */
function makeDetails( open ) {
	const details = document.createElement( 'details' );
	details.open = open;
	document.getElementById( 'qunit-fixture' ).appendChild( details );
	return details;
}

const mountRow = ( given, options ) => {
	const props = Object.assign( {
		revId: 991,
		tag: 'mw-private-personal-info',
		isFalsePositive: false,
		isNoFurtherAction: false,
		isSuppressed: false
	}, given );
	if ( !props.detailsElement ) {
		// Only an open row is judged, and most of these tests are about judging.
		props.detailsElement = makeDetails( props.isOpen !== false );
	}
	delete props.isOpen;

	const wrapper = mount( RowActions, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: { mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) } },
		props
	}, options ) );
	mounted.push( wrapper );
	return wrapper;
};

const PROGRESS_INDICATOR = '.cdx-progress-indicator';

// The buttons carry an icon rather than a label, so what tells them apart is the name
// they are given for assistive technology.
const buttonWithLabel = ( wrapper, label ) => wrapper.findAll( 'button' )
	.find( ( button ) => button.attributes( 'aria-label' ) === label );

// mw.Rest#post rejects jQuery-style with ( code, details ), which a native promise cannot
// express, so stand in a thenable carrying the same contract rest.js is written against.
const restResolving = ( value ) => ( { then: ( onSuccess ) => onSuccess( value ) } );
const restPending = () => ( { then: () => {} } );
const restRejecting = ( code, details ) => ( {
	then: ( onSuccess, onError ) => onError( code, details )
} );

QUnit.test( 'both verdicts are offered, neither pressed', ( assert ) => {
	const wrapper = mountRow();

	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		const button = buttonWithLabel( wrapper, label );
		assert.notStrictEqual( button, undefined, '"' + label + '" is offered' );
		assert.strictEqual(
			button.attributes( 'aria-pressed' ),
			'false',
			'"' + label + '" is not pressed'
		);
		assert.false( button.element.disabled, '"' + label + '" is usable' );
	} );
	assert.strictEqual(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).attributes( 'title' ),
		MARK_NO_FURTHER_ACTION,
		'the name is also a tooltip, an icon alone saying nothing on hover'
	);
} );

QUnit.test( 'a request in flight is reported beside the buttons', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( restPending() );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	assert.false( wrapper.find( PROGRESS_INDICATOR ).exists(), 'nothing spinning to begin with' );

	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		wrapper.findAll( PROGRESS_INDICATOR ).length,
		1,
		'one spinner is shown, not one per control'
	);
	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		assert.true(
			buttonWithLabel( wrapper, label ).element.disabled,
			'"' + label + '" cannot be pressed while the request is in flight'
		);
	} );
} );

QUnit.test( 'a suppressed revision cannot be marked, and says why', ( assert ) => {
	const wrapper = mountRow( { isSuppressed: true } );

	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		const mark = buttonWithLabel( wrapper, label );
		assert.true(
			mark.element.disabled,
			'"' + label + '" is disabled on an already-suppressed revision'
		);
		assert.strictEqual(
			mark.attributes( 'aria-describedby' ),
			'mw-wikimediaantiabuse-abuse-review-disabled-note-991',
			'"' + label + '" points at the note explaining it'
		);
	} );
	assert.strictEqual(
		wrapper.find( '.mw-wikimediaantiabuse-abuse-review-disabled-note' ).text(),
		SUPPRESSED_NOTE,
		'the note is shown'
	);
} );

QUnit.test( 'a suppressed revision that was called a false positive can still be unmarked', ( assert ) => {
	const wrapper = mountRow( { isSuppressed: true, isFalsePositive: true } );
	const unmark = buttonWithLabel( wrapper, UNMARK_FALSE_POSITIVE );

	assert.notStrictEqual( unmark, undefined, 'the undo control is offered' );
	assert.false(
		unmark.element.disabled,
		'the undo control is usable, suppression blocking marking but not unmarking'
	);
	assert.strictEqual(
		unmark.attributes( 'aria-describedby' ),
		undefined,
		'the undo control is undescribed, the note explaining a disabled control'
	);
} );

QUnit.test( 'the verdict a row holds blocks the other one', ( assert ) => {
	const wrapper = mountRow( { isNoFurtherAction: true } );

	const held = buttonWithLabel( wrapper, UNMARK_NO_FURTHER_ACTION );
	assert.strictEqual( held.attributes( 'aria-pressed' ), 'true', 'the verdict held is pressed' );
	assert.false( held.element.disabled, 'and can be cleared' );
	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'the other verdict is out of reach, the server refusing two on one flag'
	);
} );

QUnit.test( 'marking a false positive presses its button and blocks the other', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual( post.callCount, 1, 'one REST call' );
	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/mark/revision/991/mw-private-personal-info/false-positive',
		'to the mark endpoint for this revision and tag'
	);
	const marked = buttonWithLabel( wrapper, UNMARK_FALSE_POSITIVE );
	assert.notStrictEqual( marked, undefined, 'the button now offers to undo the verdict' );
	assert.strictEqual( marked.attributes( 'aria-pressed' ), 'true', 'and reads as pressed' );
	assert.true(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).element.disabled,
		'the other verdict is out of reach, a row holding one at a time'
	);
} );

QUnit.test( 'marking as needing no further action uses its own endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/mark/revision/991/mw-private-personal-info/no-further-action',
		'the no-further-action mark endpoint is used'
	);
	assert.notStrictEqual(
		buttonWithLabel( wrapper, UNMARK_NO_FURTHER_ACTION ),
		undefined,
		'the button now offers to undo the verdict'
	);
	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'the false-positive verdict is out of reach'
	);
} );

QUnit.test( 'unmarking no further action calls its own unmark endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( { isNoFurtherAction: true } );
	await buttonWithLabel( wrapper, UNMARK_NO_FURTHER_ACTION ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/no-further-action',
		'the no-further-action unmark endpoint is used'
	);
	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		assert.false(
			buttonWithLabel( wrapper, label ).element.disabled,
			'"' + label + '" is offered again'
		);
	} );
} );

QUnit.test( 'unmarking a false positive calls the unmark endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( { isFalsePositive: true } );
	await buttonWithLabel( wrapper, UNMARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/false-positive',
		'the unmark endpoint is used'
	);
	assert.notStrictEqual(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ),
		undefined,
		'the mark control comes back'
	);
} );

QUnit.test( 'a failed mark leaves the control alone and reports the error', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns(
		restRejecting( 'http', { xhr: { responseJSON: {
			messageTranslations: { en: 'You may not do that.' }
		} } } )
	);
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );
	const notify = this.sandbox.stub( mw, 'notify' );

	const wrapper = mountRow();
	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();

	assert.deepEqual(
		notify.firstCall.args,
		[ 'You may not do that.', { type: 'error' } ],
		'the REST message is surfaced'
	);
	assert.notStrictEqual(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ),
		undefined,
		'the row still offers marking, nothing having changed'
	);
	assert.false( wrapper.vm.busy, 'the row is usable again' );
} );

QUnit.test( 'marking reports upwards, since the tag chips live outside the app', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();
	assert.deepEqual(
		wrapper.emitted( 'verdict-changed' ),
		[ [ 'falsePositive' ] ],
		'marking reports the new verdict so the row summary can be flipped'
	);

	await buttonWithLabel( wrapper, UNMARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();
	assert.deepEqual(
		wrapper.emitted( 'verdict-changed' )[ 1 ],
		[ null ],
		'unmarking reports that the row now carries no verdict'
	);
} );

QUnit.test( 'a failed mark reports nothing upwards, the state not having changed', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restRejecting( 'http', { xhr: { responseJSON: {} } } ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );
	this.sandbox.stub( mw, 'notify' );

	const wrapper = mountRow();
	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		wrapper.emitted( 'verdict-changed' ),
		undefined,
		'nothing is reported for a change that did not happen'
	);
} );

QUnit.test( 'a closed row cannot be judged, and says so', ( assert ) => {
	const wrapper = mountRow( { isOpen: false } );

	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		const button = buttonWithLabel( wrapper, label );
		assert.true( button.element.disabled, '"' + label + '" is out of reach' );
		assert.strictEqual(
			button.attributes( 'title' ),
			CLOSED_ROW_NOTE,
			'"' + label + '" says why on hover, rather than what it would have done'
		);
		assert.strictEqual(
			button.attributes( 'aria-describedby' ),
			'mw-wikimediaantiabuse-abuse-review-disabled-note-991',
			'"' + label + '" points at the note saying so'
		);
	} );
} );

QUnit.test( 'a closed row cannot clear the verdict it holds', ( assert ) => {
	const wrapper = mountRow( { isOpen: false, isNoFurtherAction: true } );

	const held = buttonWithLabel( wrapper, UNMARK_NO_FURTHER_ACTION );
	assert.strictEqual( held.attributes( 'aria-pressed' ), 'true', 'the verdict reads as pressed' );
	assert.true(
		held.element.disabled,
		'but is out of reach, a verdict changing only on a row the reviewer has opened'
	);
	assert.strictEqual(
		held.attributes( 'title' ),
		CLOSED_ROW_NOTE,
		'and says why on hover, rather than what clearing it would do'
	);
	const other = buttonWithLabel( wrapper, MARK_FALSE_POSITIVE );
	assert.true( other.element.disabled, 'the verdict the row does not hold is out of reach too' );
	assert.strictEqual(
		other.attributes( 'title' ),
		CLOSED_ROW_NOTE,
		'and says why on hover too'
	);
} );

QUnit.test( 'opening the row brings its buttons into reach', async ( assert ) => {
	const details = document.createElement( 'details' );
	document.getElementById( 'qunit-fixture' ).appendChild( details );
	const wrapper = mountRow( { detailsElement: details } );

	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'out of reach while the row is closed'
	);

	details.open = true;
	details.dispatchEvent( new Event( 'toggle' ) );
	await wrapper.vm.$nextTick();

	assert.false(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'and in reach once it is open'
	);
	assert.strictEqual(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).attributes( 'title' ),
		MARK_FALSE_POSITIVE,
		'with the tooltip back to what pressing it does'
	);

	details.open = false;
	details.dispatchEvent( new Event( 'toggle' ) );
	await wrapper.vm.$nextTick();

	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'and out of reach again once it is closed'
	);
} );

QUnit.test( 'a click on a button does not reach the row it would open', async ( assert ) => {
	// On the page the app sits inside the summary element that opens the row, which any
	// click reaching it would toggle. This stands in for that ancestor.
	const ancestor = document.createElement( 'div' );
	document.getElementById( 'qunit-fixture' ).appendChild( ancestor );
	let reachedAncestor = 0;
	ancestor.addEventListener( 'click', () => {
		reachedAncestor++;
	} );

	const wrapper = mountRow( {}, { attachTo: ancestor } );
	await buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).trigger( 'click' );

	assert.strictEqual( reachedAncestor, 0, 'the click stops at the buttons' );
} );

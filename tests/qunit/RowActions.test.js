'use strict';

const { mount, flushPromises } = require( 'vue-test-utils' );
// Reached by module name: the component requires the synthetic codex.js that only exists
// inside its own CodexModule.
const RowActions = require( 'ext.wikimediaAntiAbuse/RowActions.vue' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.RowActions', QUnit.newMwEnvironment( {
	messages: {
		'wikimediaantiabuse-special-abuse-review-action-mark-false-positive': 'No action needed',
		'wikimediaantiabuse-special-abuse-review-action-unmark-false-positive': 'Undo no action needed',
		'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action': 'No further action needed',
		'wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action': 'Needs further action',
		'wikimediaantiabuse-special-abuse-review-already-suppressed-note': 'Already suppressed.',
		'wikimediaantiabuse-special-abuse-review-action-in-progress': 'Working…'
	},
	afterEach() {
		// Left mounted, wrappers accumulate across the module and eventually wedge the runner.
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

const mountRow = ( props, options ) => {
	const wrapper = mount( RowActions, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: { mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) } },
		props: Object.assign( {
			revId: 991,
			revisionDeleteUrl: '/wiki/Special:RevisionDelete?ids=991',
			revertUrl: '/wiki/Page?action=edit&undo=991',
			tag: 'mw-private-personal-info',
			isFalsePositive: false,
			isNoFurtherAction: false,
			isSuppressed: false
		}, props )
	}, options ) );
	mounted.push( wrapper );
	return wrapper;
};

const PROGRESS_INDICATOR = '.cdx-progress-indicator';
const ACTIONS_PROGRESS_INDICATOR =
	'.mw-wikimediaantiabuse-abuse-review-actions-heading > .cdx-progress-indicator';

const buttonWithText = ( wrapper, text ) => wrapper.findAll( 'button' )
	.find( ( button ) => button.text() === text );

// mw.Rest#post rejects jQuery-style with ( code, details ), which a native promise cannot
// express, so stand in a thenable carrying the same contract rest.js is written against.
const restResolving = ( value ) => ( { then: ( onSuccess ) => onSuccess( value ) } );
const restPending = () => ( { then: () => {} } );
const restRejecting = ( code, details ) => ( {
	then: ( onSuccess, onError ) => onError( code, details )
} );

QUnit.test( 'a request in flight is reported on the actions heading', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( restPending() );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	assert.false( wrapper.find( PROGRESS_INDICATOR ).exists(), 'nothing spinning to begin with' );

	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();

	assert.true(
		wrapper.find( ACTIONS_PROGRESS_INDICATOR ).exists(),
		'the heading reports it'
	);
	assert.strictEqual(
		wrapper.findAll( PROGRESS_INDICATOR ).length,
		1,
		'one spinner is shown, not one per control'
	);
} );

QUnit.test( 'a suppressed revision cannot be marked, and says why', ( assert ) => {
	const wrapper = mountRow( { isSuppressed: true } );

	[ 'No action needed', 'No further action needed' ].forEach( ( label ) => {
		const mark = buttonWithText( wrapper, label );
		assert.true(
			mark.element.disabled,
			'"' + label + '" is disabled on an already-suppressed revision'
		);
		assert.strictEqual(
			mark.attributes( 'aria-describedby' ),
			'mw-wikimediaantiabuse-abuse-review-suppressed-note-991',
			'"' + label + '" points at the note explaining it'
		);
	} );
	assert.strictEqual(
		wrapper.find( '.mw-wikimediaantiabuse-abuse-review-suppressed-note' ).text(),
		'Already suppressed.',
		'the note is shown'
	);
} );

QUnit.test( 'a suppressed revision that was called a false positive can still be unmarked', ( assert ) => {
	const wrapper = mountRow( { isSuppressed: true, isFalsePositive: true } );
	const unmark = buttonWithText( wrapper, 'Undo no action needed' );

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

QUnit.test( 'an in-flight request takes only the verdicts out of reach', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( restPending() );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	const hrefsBefore = wrapper.findAll( 'a' ).map( ( link ) => link.attributes( 'href' ) );
	hrefsBefore.forEach( ( href ) => {
		assert.notStrictEqual( href, undefined, 'links start navigable' );
	} );

	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();

	assert.true(
		buttonWithText( wrapper, 'No action needed' ).element.disabled,
		'the control that started the request cannot be pressed again'
	);
	assert.deepEqual(
		wrapper.findAll( 'a' ).map( ( link ) => link.attributes( 'href' ) ),
		hrefsBefore,
		'the actions stay navigable, being links the request has no business touching'
	);
} );

QUnit.test( 'marking a false positive swaps the control for its undo', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual( post.callCount, 1, 'one REST call' );
	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/mark/revision/991/mw-private-personal-info/false-positive',
		'to the mark endpoint for this revision and tag'
	);
	assert.strictEqual(
		buttonWithText( wrapper, 'No action needed' ),
		undefined,
		'the mark button is gone'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'Undo no action needed' ),
		undefined,
		'the undo control has taken its place'
	);
	assert.strictEqual(
		buttonWithText( wrapper, 'No further action needed' ),
		undefined,
		'the other verdict is withdrawn, a row holding one at a time'
	);
} );

QUnit.test( 'marking as needing no further action swaps the control for its undo', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithText( wrapper, 'No further action needed' ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/mark/revision/991/mw-private-personal-info/no-further-action',
		'the no-further-action mark endpoint is used'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'Needs further action' ),
		undefined,
		'the undo control has taken its place'
	);
	assert.strictEqual(
		buttonWithText( wrapper, 'No action needed' ),
		undefined,
		'the false-positive verdict is withdrawn'
	);
} );

QUnit.test( 'unmarking no further action calls its own unmark endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( { isNoFurtherAction: true } );
	await buttonWithText( wrapper, 'Needs further action' ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/no-further-action',
		'the no-further-action unmark endpoint is used'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'No action needed' ),
		undefined,
		'the false-positive verdict is offered again'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'No further action needed' ),
		undefined,
		'the no-further-action verdict is offered again'
	);
} );

QUnit.test( 'unmarking calls the unmark endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( { isFalsePositive: true } );
	await buttonWithText( wrapper, 'Undo no action needed' ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/false-positive',
		'the unmark endpoint is used'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'No action needed' ),
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
	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();

	assert.deepEqual(
		notify.firstCall.args,
		[ 'You may not do that.', { type: 'error' } ],
		'the REST message is surfaced'
	);
	assert.notStrictEqual(
		buttonWithText( wrapper, 'No action needed' ),
		undefined,
		'the row still offers marking, nothing having changed'
	);
	assert.false( wrapper.vm.busy, 'the row is usable again' );
} );

QUnit.test( 'marking reports upwards, since the tag chips live outside the app', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow();
	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();
	assert.deepEqual(
		wrapper.emitted( 'verdict-changed' ),
		[ [ 'falsePositive' ] ],
		'marking reports the new verdict so the row summary can be flipped'
	);

	await buttonWithText( wrapper, 'Undo no action needed' ).trigger( 'click' );
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
	await buttonWithText( wrapper, 'No action needed' ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		wrapper.emitted( 'verdict-changed' ),
		undefined,
		'nothing is reported for a change that did not happen'
	);
} );

QUnit.test( 'a row offers only the actions its payload allows', ( assert ) => {
	const bare = mountRow( {
		revisionDeleteUrl: null,
		revertUrl: null,
		tag: null
	} );

	assert.strictEqual( bare.findAll( 'a' ).length, 0, 'no revision-delete or revert links' );
	assert.strictEqual(
		bare.findAll( 'button' ).length,
		0,
		'no verdict controls, there being no tag to mark'
	);

	const revertOnly = mountRow( { revisionDeleteUrl: null, tag: null } );
	assert.strictEqual(
		revertOnly.findAll( 'a' ).length,
		1,
		'a row with only a revert URL offers only that link'
	);
} );

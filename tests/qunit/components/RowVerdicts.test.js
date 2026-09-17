'use strict';

const { mount, flushPromises } = require( 'vue-test-utils' );
// Reached by module name: the component requires the synthetic codex.js that only exists
// inside its own CodexModule.
const RowVerdicts = require( 'ext.wikimediaAntiAbuse/components/RowVerdicts.vue' );

const MARK_FALSE_POSITIVE =
	'(wikimediaantiabuse-special-abuse-review-action-mark-false-positive)';
const MARK_NO_FURTHER_ACTION =
	'(wikimediaantiabuse-special-abuse-review-action-mark-no-further-action)';
const CHIP_FALSE_POSITIVE =
	'(wikimediaantiabuse-special-abuse-review-verdict-chip-false-positive)';
const CHIP_NO_FURTHER_ACTION =
	'(wikimediaantiabuse-special-abuse-review-verdict-chip-no-further-action)';
const SEND_BACK = '(wikimediaantiabuse-special-abuse-review-action-send-back-for-review)';
const SEND_BACK_TOOLTIP =
	'(wikimediaantiabuse-special-abuse-review-action-send-back-for-review-tooltip)';
const SUPPRESSED_NOTE = '(wikimediaantiabuse-special-abuse-review-already-suppressed-note)';
const CLOSED_ROW_NOTE = '(wikimediaantiabuse-special-abuse-review-closed-row-note)';

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.RowVerdicts', QUnit.newMwEnvironment( {
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
	details.appendChild( document.createElement( 'summary' ) );
	document.getElementById( 'qunit-fixture' ).appendChild( details );
	return details;
}

const mountRow = ( given, options ) => {
	const props = Object.assign( {
		revId: 991,
		tag: 'mw-private-personal-info',
		isFalsePositive: false,
		isNoFurtherAction: false,
		isSuppressed: false,
		referrer: ''
	}, given );
	if ( !props.detailsElement ) {
		// Only an open row is judged, and most of these tests are about judging.
		props.detailsElement = makeDetails( props.isOpen !== false );
	}
	delete props.isOpen;

	const wrapper = mount( RowVerdicts, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: { mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) } },
		props
	}, options ) );
	mounted.push( wrapper );
	return wrapper;
};

const PROGRESS_INDICATOR = '.cdx-progress-indicator';
const CHIP = '.cdx-info-chip';
const CHIP_TEXT = '.cdx-info-chip__text';

// The buttons carry an icon rather than a label, so what tells them apart is the name
// they are given for assistive technology.
const buttonWithLabel = ( wrapper, label ) => wrapper.findAll( 'button' )
	.find( ( button ) => button.attributes( 'aria-label' ) === label );

const sendBackButton = ( wrapper ) => wrapper.findAll( 'button' )
	.find( ( button ) => button.text() === SEND_BACK );

// wrapper.findAll() does not reach teleported DOM, so the fixture is searched instead.
const teleportedSendBackButton = () => Array.prototype.find.call(
	document.getElementById( 'qunit-fixture' ).querySelectorAll( 'button' ),
	( button ) => button.textContent.trim() === SEND_BACK
);

// mw.Rest#post rejects jQuery-style with ( code, details ), which a native promise cannot
// express, so stand in a thenable carrying the same contract rest.js is written against.
const restResolving = ( value ) => ( { then: ( onSuccess ) => onSuccess( value ) } );
const restPending = () => ( { then: () => {} } );
const restRejecting = ( code, details ) => ( {
	then: ( onSuccess, onError ) => onError( code, details )
} );

QUnit.test( 'both verdicts are offered, as push buttons', ( assert ) => {
	const wrapper = mountRow();

	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		const button = buttonWithLabel( wrapper, label );
		assert.notStrictEqual( button, undefined, '"' + label + '" is offered' );
		assert.strictEqual(
			button.attributes( 'aria-pressed' ),
			undefined,
			'"' + label + '" is not announced as a toggle, the chip carrying the state instead'
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

QUnit.test( 'the verdict a row holds shows as a chip', ( assert ) => {
	const wrapper = mountRow( { isNoFurtherAction: true } );

	assert.strictEqual(
		wrapper.find( CHIP_TEXT ).text(),
		CHIP_NO_FURTHER_ACTION,
		'the chip names the verdict the row holds'
	);
	assert.true(
		wrapper.find( CHIP ).classes().includes( 'cdx-info-chip--success' ),
		'the chip carries the no-further-action status'
	);
	assert.strictEqual(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ),
		undefined,
		'the mark buttons give way to the chip'
	);
	assert.strictEqual(
		wrapper.attributes( 'data-verdict-held' ),
		'noFurtherAction',
		'the wrapper names the verdict held, which is what the queue steps over'
	);
} );

QUnit.test( 'only a row holding a verdict offers to send it back', ( assert ) => {
	const sendBack = sendBackButton( mountRow( { isFalsePositive: true } ) );

	assert.notStrictEqual(
		sendBack,
		undefined,
		'a row holding a verdict offers to send it back, under its own label'
	);
	assert.strictEqual(
		sendBack.attributes( 'title' ),
		SEND_BACK_TOOLTIP,
		'the send-back control says on hover what sending the row back does'
	);
	assert.false( sendBack.element.disabled, 'the send-back control is usable' );
	assert.strictEqual(
		sendBackButton( mountRow() ),
		undefined,
		'a row holding no verdict offers no way to send it back'
	);
} );

QUnit.test( 'suppression blocks recording a verdict, not clearing the one held', ( assert ) => {
	const sendBack = sendBackButton( mountRow( { isFalsePositive: true, isSuppressed: true } ) );

	assert.notStrictEqual(
		sendBack,
		undefined,
		'a suppressed row holding a verdict still offers to send it back'
	);
	assert.false( sendBack.element.disabled, 'the send-back control is usable on a suppressed row' );
} );

QUnit.test( 'marking a false positive replaces the buttons with its chip', async function ( assert ) {
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
		'The mark endpoint path is as expected for this revision and tag'
	);
	assert.deepEqual(
		post.firstCall.args[ 1 ],
		{ token: 'token', referrer: '' },
		'The mark endpoint body carries the CSRF token and referrer'
	);
	assert.strictEqual(
		wrapper.find( CHIP_TEXT ).text(),
		CHIP_FALSE_POSITIVE,
		'the verdict just recorded shows as a chip'
	);
	assert.true(
		wrapper.find( CHIP ).classes().includes( 'cdx-info-chip--warning' ),
		'the chip carries the false-positive status'
	);
	assert.strictEqual(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ),
		undefined,
		'the mark buttons give way to the chip'
	);
} );

QUnit.test( 'marking as needing no further action uses its own endpoint', async function ( assert ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( { referrer: 'echo_notification' } );
	await buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual(
		post.firstCall.args[ 0 ],
		'/wikimediaantiabuse/v0/mark/revision/991/mw-private-personal-info/no-further-action',
		'the no-further-action mark endpoint is used'
	);
	assert.deepEqual(
		post.firstCall.args[ 1 ],
		{ token: 'token', referrer: 'echo_notification' },
		'The mark endpoint body carries the CSRF token and referrer'
	);
	assert.strictEqual(
		wrapper.find( CHIP_TEXT ).text(),
		CHIP_NO_FURTHER_ACTION,
		'the verdict just recorded shows as a chip'
	);
} );

QUnit.test.each( 'sending a row back unmarks the verdict it holds', {
	'a false positive': {
		props: { isFalsePositive: true },
		path: '/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/false-positive'
	},
	'no further action': {
		props: { isNoFurtherAction: true },
		path: '/wikimediaantiabuse/v0/unmark/revision/991/mw-private-personal-info/no-further-action'
	}
}, async function ( assert, data ) {
	const post = this.sandbox.stub( mw.Rest.prototype, 'post' )
		.returns( restResolving( {} ) );
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );

	const wrapper = mountRow( data.props );
	await sendBackButton( wrapper ).trigger( 'click' );
	await flushPromises();

	assert.strictEqual( post.callCount, 1, 'one REST call' );
	assert.strictEqual(
		post.firstCall.args[ 0 ],
		data.path,
		'the unmark endpoint of the verdict the row held is called'
	);
	assert.false( wrapper.find( CHIP ).exists(), 'the chip goes with the verdict it named' );
	assert.strictEqual(
		wrapper.attributes( 'data-verdict-held' ),
		undefined,
		'the wrapper names no verdict held, so the queue stops stepping over the row'
	);
	[ MARK_NO_FURTHER_ACTION, MARK_FALSE_POSITIVE ].forEach( ( label ) => {
		assert.false(
			buttonWithLabel( wrapper, label ).element.disabled,
			'"' + label + '" is offered again'
		);
	} );
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

QUnit.test( 'a failed send-back leaves the verdict standing and reports the error', async function ( assert ) {
	this.sandbox.stub( mw.Rest.prototype, 'post' ).returns(
		restRejecting( 'http', { xhr: { responseJSON: {
			messageTranslations: { en: 'You may not do that.' }
		} } } )
	);
	this.sandbox.stub( mw.Api.prototype, 'getToken' ).returns( Promise.resolve( 'token' ) );
	const notify = this.sandbox.stub( mw, 'notify' );

	const wrapper = mountRow( { isNoFurtherAction: true } );
	await sendBackButton( wrapper ).trigger( 'click' );
	await flushPromises();

	assert.deepEqual(
		notify.firstCall.args,
		[ 'You may not do that.', { type: 'error' } ],
		'the REST message is surfaced'
	);
	assert.strictEqual(
		wrapper.attributes( 'data-verdict-held' ),
		'noFurtherAction',
		'the wrapper still names the verdict held'
	);
	assert.strictEqual(
		wrapper.find( CHIP_TEXT ).text(),
		CHIP_NO_FURTHER_ACTION,
		'the chip still names the verdict the row holds'
	);
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

QUnit.test( 'the send-back control is rendered among the row\'s other actions', ( assert ) => {
	const actions = document.createElement( 'div' );
	document.getElementById( 'qunit-fixture' ).appendChild( actions );

	const wrapper = mountRow( { isNoFurtherAction: true, actionsElement: actions } );

	assert.strictEqual(
		sendBackButton( wrapper ),
		undefined,
		'the send-back control is not rendered beside the chip'
	);
	const teleported = teleportedSendBackButton();
	assert.true( !!teleported, 'the send-back control is rendered' );
	assert.strictEqual(
		teleported.parentNode,
		actions,
		'the send-back control sits in the row\'s action group'
	);
} );

QUnit.test( 'opening the row brings its buttons into reach', async ( assert ) => {
	const details = document.createElement( 'details' );
	document.getElementById( 'qunit-fixture' ).appendChild( details );
	const wrapper = mountRow( { detailsElement: details } );

	assert.true(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).element.disabled,
		'The mark no further action needed button is out of reach while the row is closed'
	);
	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'The mark false positive button is out of reach while the row is closed'
	);

	details.open = true;
	details.dispatchEvent( new Event( 'toggle' ) );
	await wrapper.vm.$nextTick();

	const noFurtherActionButton = buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION );
	assert.false(
		noFurtherActionButton.element.disabled,
		'The mark no further action needed button is usable once the row is opened'
	);
	assert.strictEqual(
		noFurtherActionButton.attributes( 'title' ),
		MARK_NO_FURTHER_ACTION,
		'the tooltip says what pressing the mark as handled button does'
	);
	const falsePositiveButton = buttonWithLabel( wrapper, MARK_FALSE_POSITIVE );
	assert.false(
		falsePositiveButton.element.disabled,
		'The mark false positive button is usable once the row is opened'
	);
	assert.strictEqual(
		falsePositiveButton.attributes( 'title' ),
		MARK_FALSE_POSITIVE,
		'the tooltip says what pressing the false positive button does'
	);

	details.open = false;
	details.dispatchEvent( new Event( 'toggle' ) );
	await wrapper.vm.$nextTick();

	assert.true(
		buttonWithLabel( wrapper, MARK_FALSE_POSITIVE ).element.disabled,
		'The mark false positive button is out of reach again once the row is closed'
	);
	assert.true(
		buttonWithLabel( wrapper, MARK_NO_FURTHER_ACTION ).element.disabled,
		'The mark no further action needed button is out of reach again once the row is closed'
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

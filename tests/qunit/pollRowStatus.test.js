'use strict';

const { pollRowStatus } = require( 'ext.wikimediaAntiAbuse/pollRowStatus.js' );

const SUPPRESSED_MESSAGE =
	'wikimediaantiabuse-special-abuse-review-handled-outside-abuse-review-mw-private-personal-info';
const PAGE_DELETED_MESSAGE = 'wikimediaantiabuse-special-abuse-review-handled-page-deleted';
const ROW_STATUS = {
	queue: 'mw-private-personal-info',
	pollSeconds: 30,
	handledReasonMessages: { suppressed: SUPPRESSED_MESSAGE, pagedeleted: PAGE_DELETED_MESSAGE }
};
const INTERVAL = 30000;

QUnit.module( 'ext.wikimediaAntiAbuse.pollRowStatus', QUnit.newMwEnvironment( {
	beforeEach() {
		this.clock = this.sandbox.useFakeTimers();
		this.get = this.sandbox.stub( mw.Api.prototype, 'get' );
		this.addEventListener = this.sandbox.spy( document, 'addEventListener' );
	},
	afterEach() {
		// Remove the poller's listeners, so later tests send no requests.
		this.addEventListener.args.forEach( ( args ) => document.removeEventListener( ...args ) );
	}
} ) );

QUnit.test( 'each row is given the status the server holds once an interval has passed', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	// Answer 1 second later, to check that rows get the time of the request, not of the answer.
	this.get.callsFake( () => new Promise( ( resolve ) => {
		setTimeout( resolve, 1000, { query: { abusereview: [
			{ revid: 1, status: 'false-positive' },
			{ revid: 2, status: 'no-further-action' },
			{ revid: 3, status: 'handled', handledreason: 'suppressed' },
			{ revid: 4, status: 'unhandled' }
		] } } );
	} ) );
	const rows = makeRows( this.sandbox, [ 1, 2, 3, 4, 5 ] );

	pollRowStatus( rows );
	await this.clock.tickAsync( INTERVAL + 1000 );

	assert.strictEqual( this.get.callCount, 1, 'one request is sent' );
	assert.deepEqual( this.get.firstCall.args[ 0 ], {
		action: 'query',
		list: 'abusereview',
		abrqueue: 'mw-private-personal-info',
		abrrevid: [ 1, 2, 3, 4, 5 ],
		abrshowhandled: true,
		abrshowfalsepositives: true,
		abrprop: [ 'ids', 'status' ],
		abrlimit: 50,
		formatversion: 2
	}, 'the request asks the queue for the status of every row' );
	assert.deepEqual(
		[ 1, 2, 3, 4 ].map( ( revId ) => rows.get( revId ).applyStatus.args ),
		[
			[ [ { verdict: 'falsePositive', handledMessage: null }, INTERVAL ] ],
			[ [ { verdict: 'noFurtherAction', handledMessage: null }, INTERVAL ] ],
			[ [ { verdict: null, handledMessage: SUPPRESSED_MESSAGE }, INTERVAL ] ],
			[ [ { verdict: null, handledMessage: null }, INTERVAL ] ]
		],
		'each answered row is given its status, stamped with the time the request was sent'
	);
	assert.deepEqual(
		rows.get( 5 ).showPageDeleted.args,
		[ [ PAGE_DELETED_MESSAGE, INTERVAL ] ],
		'the row missing from the answer is shown as a revision of a deleted page'
	);
} );

QUnit.test( 'the rows are asked about fifty at a time', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.resolves( { query: { abusereview: [] } } );
	const revIds = Array.from( { length: 51 }, ( value, index ) => index + 1 );

	pollRowStatus( makeRows( this.sandbox, revIds ) );
	await this.clock.tickAsync( INTERVAL );

	assert.deepEqual(
		this.get.args.map( ( args ) => args[ 0 ].abrrevid ),
		[ revIds.slice( 0, 50 ), [ 51 ] ],
		'fifty-one rows take one request for the first fifty and another for the last one'
	);
} );

QUnit.test( 'nothing is asked while the page is hidden, and the rows are asked about once it is visible', async function ( assert ) {
	let hidden = true;
	this.sandbox.stub( document, 'hidden' ).get( () => hidden );
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( INTERVAL );
	assert.true( this.get.notCalled, 'no request is sent while the page is hidden' );

	hidden = false;
	document.dispatchEvent( new Event( 'visibilitychange' ) );
	assert.strictEqual( this.get.callCount, 1, 'a request is sent as soon as the page is visible' );
} );

QUnit.test.each( 'nothing is polled without a configuration that turns the poll on', {
	'without the configuration': { config: null },
	'with the poll turned off': { config: Object.assign( {}, ROW_STATUS, { pollSeconds: 0 } ) }
}, async function ( assert, data ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', data.config );

	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );
	await this.clock.tickAsync( INTERVAL );

	assert.true( this.get.notCalled, 'no request is sent' );
} );

QUnit.test( 'after a failed request the next one waits twice the interval', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.rejects();
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( 2 * INTERVAL );
	assert.strictEqual( this.get.callCount, 1, 'no request follows one interval after the failed one' );

	await this.clock.tickAsync( INTERVAL );
	assert.strictEqual( this.get.callCount, 2, 'the next request follows two intervals after the failed one' );
} );

QUnit.test( 'a page shown again while a request is in flight sends no second request', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.callsFake( () => new Promise( ( resolve ) => {
		setTimeout( resolve, 5000, { query: { abusereview: [] } } );
	} ) );
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( INTERVAL + 1000 );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
	await this.clock.tickAsync( INTERVAL + 4000 );

	assert.strictEqual( this.get.callCount, 2, 'the next request follows an interval after the slow one ended' );
} );

QUnit.test( 'a page shown again restarts the wait rather than adding one', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.resolves( { query: { abusereview: [] } } );
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( 10000 );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
	await this.clock.tickAsync( INTERVAL );

	assert.strictEqual( this.get.callCount, 2, 'one request when shown, the next a full interval later' );
} );

QUnit.test( 'a request that succeeds after a failed one brings the interval back', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.resolves( { query: { abusereview: [] } } );
	this.get.onFirstCall().rejects();
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( 4 * INTERVAL );

	assert.strictEqual( this.get.callCount, 3, 'requests after one, three and four intervals' );
} );

QUnit.test( 'failed requests never wait more than eight intervals', async function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseRowStatus', ROW_STATUS );
	this.get.rejects();
	pollRowStatus( makeRows( this.sandbox, [ 1 ] ) );

	await this.clock.tickAsync( 15 * INTERVAL );
	assert.strictEqual( this.get.callCount, 4, 'four requests after waits of one, two, four and eight intervals' );

	await this.clock.tickAsync( 8 * INTERVAL );
	assert.strictEqual( this.get.callCount, 5, 'the wait before the fifth request stays at eight intervals' );
} );

/**
 * @param {Object} sandbox
 * @param {number[]} revIds
 * @return {Map<number,Object>} A stand-in for the RowVerdicts app of each revision
 */
function makeRows( sandbox, revIds ) {
	return new Map( revIds.map( ( revId ) => [
		revId,
		{ applyStatus: sandbox.spy(), showPageDeleted: sandbox.spy() }
	] ) );
}

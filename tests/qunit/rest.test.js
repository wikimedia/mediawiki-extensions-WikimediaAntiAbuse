'use strict';

const { markAsFalsePositive, unmarkAsFalsePositive } =
	require( '../../modules/ext.wikimediaAntiAbuse/rest.js' );

const TAG = 'mw-private-personal-info';

let server;

QUnit.module( 'ext.wikimediaAntiAbuse.rest', QUnit.newMwEnvironment( {
	beforeEach: function () {
		this.server = this.sandbox.useFakeServer();
		this.server.respondImmediately = true;
		server = this.server;
	},
	afterEach: function () {
		server.restore();
	}
} ) );

function isTokenRequest( request ) {
	return request.url.includes( 'type=csrf' ) && request.url.includes( 'meta=tokens' );
}

function respondWithToken( request ) {
	request.respond( 200, { 'Content-Type': 'application/json' }, JSON.stringify( {
		query: { tokens: { csrftoken: 'newtoken' } }
	} ) );
}

QUnit.test( 'markAsFalsePositive resolves with the response body on success', async ( assert ) => {
	const body = { revision: 1, tag: TAG, falsePositive: true };
	server.respond( ( request ) => {
		if ( request.url.endsWith( 'wikimediaantiabuse/v0/mark/revision/1/' + TAG + '/false-positive' ) ) {
			assert.strictEqual( request.method, 'POST', 'endpoint is called with POST' );
			request.respond( 200, { 'Content-Type': 'application/json' }, JSON.stringify( body ) );
		} else if ( isTokenRequest( request ) ) {
			respondWithToken( request );
		} else {
			assert.true( false, 'Unexpected request to ' + request.url );
		}
	} );

	const data = await markAsFalsePositive( 1, TAG );
	assert.deepEqual( data, body, 'resolves with the response body' );
} );

QUnit.test( 'unmarkAsFalsePositive resolves with the response body on success', async ( assert ) => {
	const body = { revision: 1, tag: TAG, falsePositive: false };
	server.respond( ( request ) => {
		if ( request.url.endsWith( 'wikimediaantiabuse/v0/unmark/revision/1/' + TAG + '/false-positive' ) ) {
			assert.strictEqual( request.method, 'POST', 'endpoint is called with POST' );
			request.respond( 200, { 'Content-Type': 'application/json' }, JSON.stringify( body ) );
		} else if ( isTokenRequest( request ) ) {
			respondWithToken( request );
		} else {
			assert.true( false, 'Unexpected request to ' + request.url );
		}
	} );

	const data = await unmarkAsFalsePositive( 1, TAG );
	assert.deepEqual( data, body, 'resolves with the response body' );
} );

QUnit.test( 'rejects with the parsed error body on a non-token error', async ( assert ) => {
	const error = { errorKey: 'wikimediaantiabuse-api-review-blocked', httpCode: 403 };
	server.respond( ( request ) => {
		if ( request.url.endsWith( 'wikimediaantiabuse/v0/mark/revision/2/' + TAG + '/false-positive' ) ) {
			request.respond( 403, { 'Content-Type': 'application/json' }, JSON.stringify( error ) );
		} else if ( isTokenRequest( request ) ) {
			respondWithToken( request );
		} else {
			assert.true( false, 'Unexpected request to ' + request.url );
		}
	} );

	await assert.rejects(
		markAsFalsePositive( 2, TAG ),
		( err ) => err.errorKey === error.errorKey,
		'rejects with the parsed error body'
	);
} );

QUnit.test( 'retries once with a fresh token on a bad-token error, then succeeds', async ( assert ) => {
	const url = 'wikimediaantiabuse/v0/mark/revision/3/' + TAG + '/false-positive';
	const body = { revision: 3, tag: TAG, falsePositive: true };
	let firstAttempt = true;
	server.respond( ( request ) => {
		if ( request.url.endsWith( url ) ) {
			if ( firstAttempt ) {
				firstAttempt = false;
				request.respond( 403, { 'Content-Type': 'application/json' },
					JSON.stringify( { errorKey: 'rest-badtoken' } ) );
			} else {
				request.respond( 200, { 'Content-Type': 'application/json' }, JSON.stringify( body ) );
			}
		} else if ( isTokenRequest( request ) ) {
			respondWithToken( request );
		} else {
			assert.true( false, 'Unexpected request to ' + request.url );
		}
	} );

	const data = await markAsFalsePositive( 3, TAG );
	assert.deepEqual( data, body, 'resolves after the retry' );
	assert.false( firstAttempt, 'the endpoint was retried' );
} );

QUnit.test( 'gives up after one retry when the bad-token error persists', async ( assert ) => {
	const url = 'wikimediaantiabuse/v0/mark/revision/4/' + TAG + '/false-positive';
	let attempts = 0;
	server.respond( ( request ) => {
		if ( request.url.endsWith( url ) ) {
			attempts++;
			request.respond( 403, { 'Content-Type': 'application/json' },
				JSON.stringify( { errorKey: 'rest-badtoken' } ) );
		} else if ( isTokenRequest( request ) ) {
			respondWithToken( request );
		} else {
			assert.true( false, 'Unexpected request to ' + request.url );
		}
	} );

	await assert.rejects(
		markAsFalsePositive( 4, TAG ),
		( err ) => err.errorKey === 'rest-badtoken',
		'rejects with the bad-token error'
	);
	assert.strictEqual( attempts, 2, 'the endpoint was tried exactly twice' );
} );

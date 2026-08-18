'use strict';

/**
 * Marks the automatic flag of a revision as a false positive.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @return {Promise<Object>} Resolves with the REST response body
 */
function markAsFalsePositive( revId, reviewTag ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/mark/revision/' + revId + '/' + reviewTag + '/false-positive'
	);
}

/**
 * Unmarks the automatic flag of a revision as a false positive, restoring the original flag.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @return {Promise<Object>} Resolves with the REST response body
 */
function unmarkAsFalsePositive( revId, reviewTag ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/unmark/revision/' + revId + '/' + reviewTag + '/false-positive'
	);
}

/**
 * Marks a flagged revision as reviewed and needing no further action.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @return {Promise<Object>} Resolves with the REST response body
 */
function markNoFurtherAction( revId, reviewTag ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/mark/revision/' + revId + '/' + reviewTag + '/no-further-action'
	);
}

/**
 * Removes the no-further-action marking from a revision.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @return {Promise<Object>} Resolves with the REST response body
 */
function unmarkNoFurtherAction( revId, reviewTag ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/unmark/revision/' + revId + '/' + reviewTag + '/no-further-action'
	);
}

/**
 * POST to a REST path with a CSRF token, refreshing the token and retrying once
 * if the first attempt fails because the token was stale.
 *
 * @param {string} path The URL path of the REST endpoint to call
 * @return {Promise<Object>} Resolves with the response body; rejects with the parsed error body
 * @internal
 */
async function postWithToken( path ) {
	const rest = new mw.Rest();
	const api = new mw.Api();

	try {
		return await post( rest, path, await api.getToken( 'csrf' ) );
	} catch ( error ) {
		if ( error.errorKey !== 'rest-badtoken' ) {
			throw error;
		}
	}

	// The CSRF token was stale; refresh it and try once more.
	api.badToken( 'csrf' );
	return post( rest, path, await api.getToken( 'csrf' ) );
}

/**
 * Bridge mw.Rest#post, which rejects jQuery-style with ( code, details ), to a native promise
 * that rejects with the parsed JSON error body so callers can inspect it with async/await.
 *
 * @param {mw.Rest} rest
 * @param {string} path
 * @param {string} token
 * @return {Promise<Object>}
 * @internal
 */
function post( rest, path, token ) {
	return new Promise( ( resolve, reject ) => {
		rest.post( path, { token: token } ).then(
			resolve,
			( code, details ) => {
				const responseJson = details && details.xhr && details.xhr.responseJSON;
				reject( responseJson || { errorKey: null } );
			}
		);
	} );
}

module.exports = {
	markAsFalsePositive,
	unmarkAsFalsePositive,
	markNoFurtherAction,
	unmarkNoFurtherAction
};

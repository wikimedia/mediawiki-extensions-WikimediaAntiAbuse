'use strict';

/**
 * Marks the automatic flag of a revision as a false positive.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @param {string} referrer The referrer to include in the request body, or an
 *   empty string if there is none
 * @return {Promise<Object>} Resolves with the REST response body
 */
function markAsFalsePositive( revId, reviewTag, referrer ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/mark/revision/' + revId + '/' + reviewTag + '/false-positive',
		{ referrer: referrer }
	);
}

/**
 * Unmarks the automatic flag of a revision as a false positive, restoring the original flag.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @param {string} referrer The referrer to include in the request body, or an
 *   empty string if there is none
 * @return {Promise<Object>} Resolves with the REST response body
 */
function unmarkAsFalsePositive( revId, reviewTag, referrer ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/unmark/revision/' + revId + '/' + reviewTag + '/false-positive',
		{ referrer: referrer }
	);
}

/**
 * Marks a flagged revision as reviewed and needing no further action.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @param {string} referrer The referrer to include in the request body, or an
 *   empty string if there is none
 * @return {Promise<Object>} Resolves with the REST response body
 */
function markNoFurtherAction( revId, reviewTag, referrer ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/mark/revision/' + revId + '/' + reviewTag + '/no-further-action',
		{ referrer: referrer }
	);
}

/**
 * Removes the no-further-action marking from a revision.
 *
 * @param {number|string} revId The revision ID being changed
 * @param {string} reviewTag The flagged tag (not its false-positive variant)
 * @param {string} referrer The referrer to include in the request body, or an
 *   empty string if there is none
 * @return {Promise<Object>} Resolves with the REST response body
 */
function unmarkNoFurtherAction( revId, reviewTag, referrer ) {
	return postWithToken(
		'/wikimediaantiabuse/v0/unmark/revision/' + revId + '/' + reviewTag + '/no-further-action',
		{ referrer: referrer }
	);
}

/**
 * POST to a REST path with a CSRF token, refreshing the token and retrying once
 * if the first attempt fails because the token was stale.
 *
 * @param {string} path The URL path of the REST endpoint to call
 * @param {Object} body Data to add to the request body
 * @return {Promise<Object>} Resolves with the response body; rejects with the parsed error body
 * @internal
 */
async function postWithToken( path, body ) {
	const rest = new mw.Rest();
	const api = new mw.Api();

	try {
		return await post(
			rest,
			path,
			Object.assign( body, { token: await api.getToken( 'csrf' ) } )
		);
	} catch ( error ) {
		if ( error.errorKey !== 'rest-badtoken' ) {
			throw error;
		}
	}

	// The CSRF token was stale; refresh it and try once more.
	api.badToken( 'csrf' );
	return post(
		rest,
		path,
		Object.assign( body, { token: await api.getToken( 'csrf' ) } )
	);
}

/**
 * Bridge mw.Rest#post, which rejects jQuery-style with ( code, details ), to a native promise
 * that rejects with the parsed JSON error body so callers can inspect it with async/await.
 *
 * @param {mw.Rest} rest
 * @param {string} path
 * @param {Object} body
 * @return {Promise<Object>}
 * @internal
 */
function post( rest, path, body ) {
	return new Promise( ( resolve, reject ) => {
		rest.post( path, body ).then(
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

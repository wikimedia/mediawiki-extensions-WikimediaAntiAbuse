'use strict';

// Most users can send at most this many revision IDs in one request.
const BATCH_SIZE = 50;
// After failed requests, wait at most this many intervals before the next one.
const MAX_BACKOFF = 8;

const VERDICTS = {
	'false-positive': 'falsePositive',
	'no-further-action': 'noFurtherAction'
};

/**
 * @private
 * @param {string} queue
 * @param {number[]} revIds
 * @return {Promise<Object[]>} The revisions of the queue that the viewer can still see
 */
async function fetchStatuses( queue, revIds ) {
	const api = new mw.Api();
	const statuses = [];
	// Send the requests one at a time.
	for ( let i = 0; i < revIds.length; i += BATCH_SIZE ) {
		const response = await api.get( {
			action: 'query',
			list: 'abusereview',
			abrqueue: queue,
			abrrevid: revIds.slice( i, i + BATCH_SIZE ),
			abrshowhandled: true,
			abrshowfalsepositives: true,
			abrprop: [ 'ids', 'status' ],
			abrlimit: BATCH_SIZE,
			formatversion: 2
		} );
		statuses.push( ...response.query.abusereview );
	}

	return statuses;
}

/**
 * Show the status the server now holds for the given rows.
 *
 * @param {Map<number,Object>} rows The RowVerdicts app of each row, keyed by revision ID
 * @param {number[]} revIds
 * @return {Promise<void>}
 */
async function refreshRows( rows, revIds ) {
	const config = mw.config.get( 'wgWikimediaAntiAbuseRowStatus' );
	const requestedAt = Date.now();
	const statuses = await fetchStatuses( config.queue, revIds );
	const statusById = new Map( statuses.map( ( status ) => [ status.revid, status ] ) );
	revIds.forEach( ( revId ) => {
		const row = rows.get( revId );
		const status = statusById.get( revId );
		if ( status ) {
			row.applyStatus( {
				verdict: VERDICTS[ status.status ] || null,
				handledMessage: config.handledReasonMessages[ status.handledreason ] || null
			}, requestedAt );
		} else {
			// Revisions of deleted pages are missing for viewers who cannot see deleted history.
			row.showPageDeleted( config.handledReasonMessages.pagedeleted, requestedAt );
		}
	} );
}

/**
 * Checks the status of all rows every few seconds while the page is visible. Stops while the
 * page is hidden, and checks at once when the page is visible again. Never runs two checks at
 * once. After a failed check, waits twice as long, up to MAX_BACKOFF times the interval.
 */
class RowStatusPoller {
	/**
	 * @param {Map<number,Object>} rows The RowVerdicts app of each row, keyed by revision ID
	 * @param {number} interval Milliseconds between two checks
	 */
	constructor( rows, interval ) {
		this.rows = rows;
		this.interval = interval;
		this.timer = null;
		this.isRequestInProgress = false;
		this.failures = 0;
	}

	start() {
		document.addEventListener( 'visibilitychange', () => this.onVisibilityChange() );
		if ( !document.hidden ) {
			this.scheduleNext();
		}
	}

	/** @private */
	onVisibilityChange() {
		if ( document.hidden ) {
			this.stop();
		} else {
			this.checkNow();
		}
	}

	/** @private */
	stop() {
		clearTimeout( this.timer );
		this.timer = null;
	}

	/** @private */
	scheduleNext() {
		const delay = this.interval * Math.min( 2 ** this.failures, MAX_BACKOFF );
		this.timer = setTimeout( () => this.checkNow(), delay );
	}

	/** @private */
	async checkNow() {
		if ( this.isRequestInProgress ) {
			return;
		}
		this.stop();
		this.isRequestInProgress = true;
		try {
			await refreshRows( this.rows, Array.from( this.rows.keys() ) );
			this.failures = 0;
		} catch ( error ) {
			this.failures++;
		}
		this.isRequestInProgress = false;
		if ( !document.hidden ) {
			this.scheduleNext();
		}
	}
}

/**
 * Keep the rows on screen up to date while other reviewers work on the same queue.
 *
 * @param {Map<number,Object>} rows The RowVerdicts app of each row, keyed by revision ID
 */
function pollRowStatus( rows ) {
	const config = mw.config.get( 'wgWikimediaAntiAbuseRowStatus' );
	if ( !config || !config.pollSeconds || !rows.size ) {
		return;
	}

	new RowStatusPoller( rows, config.pollSeconds * 1000 ).start();
}

module.exports = { pollRowStatus };

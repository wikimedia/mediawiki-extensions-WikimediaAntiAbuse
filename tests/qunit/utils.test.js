'use strict';

const { setRowVerdict, actionErrorMessage, updateFiltersOnPage } =
	require( 'ext.wikimediaAntiAbuse/utils.js' );

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const PAGE_CLASS = 'mw-wikimediaantiabuse-abuse-review-row__page';
const CHANGES_HEADER_CLASS = 'mw-wikimediaantiabuse-abuse-review-row__changes-header';
const DIFF_CLASS = 'mw-wikimediaantiabuse-abuse-review-row__diff';
const SUMMARY_CLASS = 'mw-wikimediaantiabuse-abuse-review-row__summary';
const FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--false-positive';
const NOT_FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive';
const NO_FURTHER_ACTION_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--no-further-action';

QUnit.module( 'ext.wikimediaAntiAbuse.utils', QUnit.newMwEnvironment() );

/**
 * A review row shaped the way the pager renders one.
 *
 * @param {string|null} verdict The verdict the row starts with, or null for none
 * @return {HTMLElement}
 */
function makeRow( verdict ) {
	const row = document.createElement( 'tr' );
	row.className = 'mw-wikimediaantiabuse-abuse-review-row';

	const add = ( className, hidden ) => {
		const el = document.createElement( 'span' );
		// The following classes are used here:
		// * mw-wikimediaantiabuse-hidden
		// * mw-wikimediaantiabuse-abuse-review-row__page
		// * mw-wikimediaantiabuse-abuse-review-row__summary
		// * mw-wikimediaantiabuse-abuse-review-row__changes-header
		// * mw-wikimediaantiabuse-abuse-review-row__diff
		// * mw-wikimediaantiabuse-abuse-review-tag--false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--not-false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--no-further-action
		el.className = className + ( hidden ? ' ' + HIDDEN_CLASS : '' );
		row.appendChild( el );
	};
	add( PAGE_CLASS, false );
	add( NOT_FALSE_POSITIVE_TAG_CLASS, verdict === 'falsePositive' );
	add( FALSE_POSITIVE_TAG_CLASS, verdict !== 'falsePositive' );
	add( NO_FURTHER_ACTION_TAG_CLASS, verdict !== 'noFurtherAction' );
	add( SUMMARY_CLASS, false );
	add( CHANGES_HEADER_CLASS, false );
	add( DIFF_CLASS, false );

	document.getElementById( 'qunit-fixture' ).appendChild( row );
	return row;
}

const isHidden = ( row, className ) => row.querySelector( '.' + className ).classList.contains( HIDDEN_CLASS );

QUnit.test( 'showing a row as a false positive shows the matching tag only', ( assert ) => {
	const row = makeRow( null );

	setRowVerdict( row, 'falsePositive' );
	assert.false( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag shown' );
	assert.true( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'not-false-positive tag hidden' );
	assert.true( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag hidden' );
} );

QUnit.test( 'showing a row as needing no further action shows the matching tag only', ( assert ) => {
	const row = makeRow( null );

	setRowVerdict( row, 'noFurtherAction' );
	assert.false( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag shown' );
	assert.true( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag hidden' );
	assert.false(
		isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ),
		'the flag itself stays, the verdict standing beside it'
	);
} );

QUnit.test( 'clearing a verdict restores the original tag alone', ( assert ) => {
	[ 'falsePositive', 'noFurtherAction' ].forEach( ( verdict ) => {
		const row = makeRow( verdict );

		setRowVerdict( row, null );
		assert.false(
			isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ),
			'not-false-positive tag shown after clearing ' + verdict
		);
		assert.true( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag hidden' );
		assert.true( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag hidden' );
	} );
} );

QUnit.test( 'it sets the state rather than flipping it, so repeats cannot drift', ( assert ) => {
	const row = makeRow( null );

	setRowVerdict( row, 'falsePositive' );
	setRowVerdict( row, 'falsePositive' );
	assert.false(
		isHidden( row, FALSE_POSITIVE_TAG_CLASS ),
		'still showing the false-positive tag after a repeated call'
	);
	assert.true( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'still hiding the not-false-positive tag after a repeated call' );
} );

QUnit.test( 'it touches only the given row', ( assert ) => {
	const target = makeRow( null );
	const other = makeRow( null );

	setRowVerdict( target, 'falsePositive' );

	assert.false( isHidden( target, FALSE_POSITIVE_TAG_CLASS ), 'target row updated' );
	assert.true( isHidden( other, FALSE_POSITIVE_TAG_CLASS ), 'other row untouched' );
} );

QUnit.module( 'ext.wikimediaAntiAbuse.utils.actionErrorMessage', QUnit.newMwEnvironment( {
	beforeEach: function () {
		mw.config.set( 'wgUserLanguage', 'en' );
		mw.messages.set( 'wikimediaantiabuse-special-abuse-review-action-error', 'Generic failure.' );
	}
} ) );

QUnit.test( 'prefers the translation for the user language', ( assert ) => {
	assert.strictEqual(
		actionErrorMessage( { messageTranslations: { en: 'English error', fr: 'Erreur' } } ),
		'English error'
	);
} );

QUnit.test( 'falls back to the first translation when the user language is absent', ( assert ) => {
	assert.strictEqual(
		actionErrorMessage( { messageTranslations: { fr: 'Erreur' } } ),
		'Erreur'
	);
} );

QUnit.test( 'falls back to the generic message when there are no translations', ( assert ) => {
	assert.strictEqual( actionErrorMessage( null ), 'Generic failure.', 'null error' );
	assert.strictEqual( actionErrorMessage( {} ), 'Generic failure.', 'no messageTranslations key' );
	assert.strictEqual( actionErrorMessage( { messageTranslations: {} } ), 'Generic failure.', 'empty translations' );
} );

QUnit.module( 'ext.wikimediaAntiAbuse.utils.updateFiltersOnPage', QUnit.newMwEnvironment() );

QUnit.test.each( 'Correctly reloads the page with the applied filters', {
	'Current URL contains just the limit': {
		currentPageQueryString: '?limit=50',
		newFilters: { status: [ 'open' ], username: 'abc' },
		expectedQueryParams: { status: [ 'open' ], username: 'abc', limit: '50' }
	},
	'No query parameters in the current URL': {
		currentPageQueryString: '',
		newFilters: { status: [ 'closed', 'invalid' ], username: 'def' },
		expectedQueryParams: { status: [ 'closed', 'invalid' ], username: 'def' }
	},
	'Current URL contains sort and dir params': {
		currentPageQueryString: '?sort=timestamp&dir=desc',
		newFilters: { status: [ 'open' ] },
		expectedQueryParams: { status: [ 'open' ], sort: 'timestamp', dir: 'desc' }
	},
	'Other query parameters in the current URL are ignored': {
		currentPageQueryString: '?limit=4&status=open&foo=bar&sort=timestamp',
		newFilters: { status: [ 'closed' ] },
		expectedQueryParams: { status: [ 'closed' ], limit: '4', sort: 'timestamp' }
	}
}, ( assert, options ) => {
	mw.config.set( 'wgServer', 'https://example.com' );
	mw.config.set( 'wgPageName', 'Special:AbuseReview' );

	let actualUrl = '';
	const mockWindow = {
		location: {
			replace: function ( providedUrl ) {
				actualUrl = providedUrl;
			},
			search: options.currentPageQueryString
		}
	};

	updateFiltersOnPage( options.newFilters, mockWindow );

	assert.strictEqual(
		actualUrl,
		'https://example.com' + mw.util.getUrl( 'Special:AbuseReview', options.expectedQueryParams ),
		'URL redirected to is as expected'
	);
} );

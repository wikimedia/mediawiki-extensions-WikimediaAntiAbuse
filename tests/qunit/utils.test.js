'use strict';

const { actionErrorMessage, updateFiltersOnPage } = require( 'ext.wikimediaAntiAbuse/utils.js' );

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

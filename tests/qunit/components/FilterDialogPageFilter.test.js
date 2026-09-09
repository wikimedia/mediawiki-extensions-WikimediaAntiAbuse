'use strict';

const { mount } = require( 'vue-test-utils' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.FilterDialogPageFilter', QUnit.newMwEnvironment( {
	afterEach() {
		// Clear up mounted components to avoid slower tests
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

const mountPageFilter = ( selectedPages ) => {
	const FilterDialogPageFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogPageFilter.vue' );
	const wrapper = mount( FilterDialogPageFilter, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: {
			mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) },
			stubs: {
				FilterDialogMultiselectLookup: {
					name: 'FilterDialogMultiselectLookup',
					template: '<div />',
					props: [
						'selectedItems',
						'placeholder',
						'name',
						'class',
						'loadSuggestedItemsCallback'
					]
				}
			}
		},
		props: {
			selectedPages: selectedPages === undefined ? [] : selectedPages
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test( 'Should render correctly', async ( assert ) => {
	const wrapper = mountPageFilter( [ 'Test', 'Testing' ] );
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );

	assert.strictEqual(
		lookup.vm.class,
		'mw-wikimediaantiabuse-abuse-review-filter-dialog-page-filter',
		'The lookup component should have the expected CSS class'
	);
	assert.strictEqual(
		lookup.vm.name,
		'filter-page',
		'The lookup component should have the expected name'
	);
	assert.strictEqual(
		lookup.vm.placeholder,
		'(wikimediaantiabuse-special-abuse-review-filter-page-placeholder)',
		'The lookup component should have the expected placeholder'
	);
	assert.deepEqual(
		lookup.vm.selectedItems,
		[ 'Test', 'Testing' ],
		'The lookup component should have the expected selected usernames'
	);
	assert.strictEqual(
		lookup.vm.$slots.label()[ 0 ].children,
		'(wikimediaantiabuse-special-abuse-review-filter-page-header)',
		'The lookup component should have the expected label slot content'
	);
} );

QUnit.test.each( 'Should query prefixsearch API on suggested items update', {
	'prefixsearch API result includes exact search term': {
		apiResult: { query: { prefixsearch: [
			{ title: 'Testing' },
			{ title: 'Testing2' },
			{ title: 'Testing3' }
		] } },
		searchTerm: 'Testing',
		expectedSuggestions: [
			{ value: 'Testing' },
			{ value: 'Testing2' },
			{ value: 'Testing3' }
		]
	},
	'prefixsearch API result does not include exact search term': {
		apiResult: { query: { prefixsearch: [
			{ title: 'Testing2' },
			{ title: 'Testing3' }
		] } },
		searchTerm: 'Testing',
		expectedSuggestions: [
			{ value: 'Testing2' },
			{ value: 'Testing3' },
			{ value: 'Testing' }
		]
	}
}, async function ( assert, options ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' )
		.callsFake( () => Promise.resolve( options.apiResult ) );

	const wrapper = mountPageFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( options.searchTerm );

	assert.deepEqual(
		suggestions,
		options.expectedSuggestions,
		'The suggestions returned from the API should be transformed into the expected format'
	);
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'prefixsearch',
		pssearch: options.searchTerm,
		pslimit: 10
	} ) );
} );

QUnit.test( 'Suggested items update when prefixsearch API request errors', async function ( assert ) {
	const rejectedPromise = Promise.reject( 'error' );
	rejectedPromise.catch( () => {} );

	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => rejectedPromise );
	const mwLogError = this.sandbox.stub( mw.log, 'error' );

	const wrapper = mountPageFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( 'testing' );

	assert.deepEqual(
		suggestions,
		[ { value: 'testing' } ],
		'The suggestions should only be the search term if the prefixsearch API request fails'
	);
	assert.true( mwLogError.calledWith( 'error' ) );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'prefixsearch',
		pssearch: 'testing',
		pslimit: 10
	} ) );
} );

QUnit.test( 'Suggested item update when prefixsearch API returns unparsable response', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ test: 'test' }
	) );

	const wrapper = mountPageFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( 'testing123' );

	assert.deepEqual(
		suggestions,
		[ { value: 'testing123' } ],
		'The suggestions should only have search term if the prefixsearch API request is unparsable'
	);
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'prefixsearch',
		pssearch: 'testing123',
		pslimit: 10
	} ) );
} );

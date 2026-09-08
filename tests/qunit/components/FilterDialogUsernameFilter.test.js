'use strict';

const { mount } = require( 'vue-test-utils' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.FilterDialogUsernameFilter', QUnit.newMwEnvironment( {
	afterEach() {
		// Clear up mounted components to avoid slower tests
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

const mountUsernameFilter = ( selectedUsernames ) => {
	const FilterDialogUsernameFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogUsernameFilter.vue' );
	const wrapper = mount( FilterDialogUsernameFilter, Object.assign( {
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
			selectedUsernames: selectedUsernames === undefined ? [] : selectedUsernames
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test( 'Should render correctly', async ( assert ) => {
	const wrapper = mountUsernameFilter( [ 'Test', 'Testing' ] );
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );

	assert.strictEqual(
		lookup.vm.class,
		'mw-wikimediaantiabuse-abuse-review-filter-dialog-username-filter',
		'The lookup component should have the expected CSS class'
	);
	assert.strictEqual(
		lookup.vm.name,
		'filter-username',
		'The lookup component should have the expected name'
	);
	assert.strictEqual(
		lookup.vm.placeholder,
		'(wikimediaantiabuse-special-abuse-review-filter-username-placeholder)',
		'The lookup component should have the expected placeholder'
	);
	assert.deepEqual(
		lookup.vm.selectedItems,
		[ 'Test', 'Testing' ],
		'The lookup component should have the expected selected usernames'
	);
	assert.strictEqual(
		lookup.vm.$slots.label()[ 0 ].children,
		'(wikimediaantiabuse-special-abuse-review-filter-username-header)',
		'The lookup component should have the expected label slot content'
	);
} );

QUnit.test( 'Should query allusers API on suggested items update', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ query: { allusers: [
			{ userid: 1, name: 'testing' },
			{ userid: 2, name: 'testing1' },
			{ userid: 3, name: 'testing2' }
		] } }
	) );
	const wrapper = mountUsernameFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( 'testing' );

	assert.deepEqual(
		suggestions,
		[
			{ value: 'testing' },
			{ value: 'testing1' },
			{ value: 'testing2' }
		],
		'The suggestions returned from the API should be transformed into the expected format'
	);
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing',
		aulimit: '10'
	} ) );
} );

QUnit.test( 'Should suggest no users if allusers API request errors', async function ( assert ) {
	const rejectedPromise = Promise.reject( 'error' );
	rejectedPromise.catch( () => {} );

	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => rejectedPromise );
	const mwLogError = this.sandbox.stub( mw.log, 'error' );

	const wrapper = mountUsernameFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( 'testing' );

	assert.deepEqual(
		suggestions,
		[],
		'The suggestions should be empty if the allusers API request fails'
	);
	assert.true( mwLogError.calledWith( 'error' ) );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing',
		aulimit: '10'
	} ) );
} );

QUnit.test( 'Should suggest no users if allusers API returns unparsable response', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ test: 'test' }
	) );
	const mwLogError = this.sandbox.stub( mw.log, 'error' );

	const wrapper = mountUsernameFilter();
	const lookup = wrapper.findComponent( { name: 'FilterDialogMultiselectLookup' } );
	const suggestions = await lookup.vm.loadSuggestedItemsCallback( 'testing123' );

	assert.deepEqual(
		suggestions,
		[],
		'The suggestions should be empty if the allusers API request is unparsable'
	);
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing123',
		aulimit: '10'
	} ) );
	assert.false(
		mwLogError.called,
		'The unexpected response shape is handled without an error being thrown'
	);
} );

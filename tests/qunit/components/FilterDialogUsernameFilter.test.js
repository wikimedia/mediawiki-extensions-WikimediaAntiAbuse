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

/**
 * Waits for the debounce time set for the username lookup component to complete.
 * Used to ensure that tests wait for long enough for the state of the page to be updated.
 *
 * @return {Promise}
 */
const waitUntilDebounceComplete = () => new Promise( ( resolve ) => {
	setTimeout( () => {
		resolve();
	}, 120 );
} );

const mountUsernameFilter = ( selectedUsernames ) => {
	const FilterDialogUsernameFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogUsernameFilter.vue' );
	const wrapper = mount( FilterDialogUsernameFilter, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: {
			mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) }
		},
		props: {
			selectedUsernames: selectedUsernames === undefined ? [] : selectedUsernames
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test( 'Should update menu config on change in window height', ( assert ) => {
	const wrapper = mountUsernameFilter();

	wrapper.vm.windowHeight = 1;
	assert.strictEqual(
		wrapper.vm.menuConfig.visibleItemLimit,
		2,
		'Minimum visible item limit should be 2'
	);

	wrapper.vm.windowHeight = 1000;
	assert.strictEqual(
		wrapper.vm.menuConfig.visibleItemLimit,
		4,
		'Maximum visible item limit should be 4'
	);

	// Set the window height to 500 to test the x / 150 calculation
	wrapper.vm.windowHeight = 500;
	// The floor division of 500 by 150 is 3.
	assert.strictEqual(
		wrapper.vm.menuConfig.visibleItemLimit,
		3,
		'Visible item limit should be 3 for a window height of 500'
	);
} );

QUnit.test( 'Should query allusers API on inputValue update', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ query: { allusers: [
			{ userid: 1, name: 'testing' },
			{ userid: 2, name: 'testing1' },
			{ userid: 3, name: 'testing2' }
		] } }
	) );
	const wrapper = mountUsernameFilter();

	// Update the input value
	const inputField = wrapper.find( 'input[name=filter-username]' );
	await inputField.setValue( 'testing' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run.
	await waitUntilDebounceComplete();

	// The suggestions should now be set.
	assert.deepEqual( wrapper.vm.suggestedUsernames, [
		{ value: 'testing' },
		{ value: 'testing1' },
		{ value: 'testing2' }
	] );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing',
		aulimit: '10'
	} ) );
} );

QUnit.test( 'inputValue update but allusers API request errors', async function ( assert ) {
	const rejectedPromise = Promise.reject( 'error' );
	rejectedPromise.catch( () => {} );

	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => rejectedPromise );
	const mwLogError = this.sandbox.stub( mw.log, 'error' );

	const wrapper = mountUsernameFilter();

	// Set suggestedUsernames so that the test can verify it empties on a failed request
	wrapper.vm.suggestedUsernames.value = [ { value: 'test123123123123123' } ];

	// Update the input value
	const inputField = wrapper.find( 'input[name=filter-username]' );
	await inputField.setValue( 'testing' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run
	await waitUntilDebounceComplete();

	// The suggestions should now be set
	assert.deepEqual( wrapper.vm.suggestedUsernames, [] );
	assert.true( mwLogError.calledWith( 'error' ) );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing',
		aulimit: '10'
	} ) );
} );

QUnit.test( 'inputValue updated but allusers API returns unparsable response', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ test: 'test' }
	) );

	const wrapper = mountUsernameFilter();

	// Set suggestedUsernames so that the test can verify it empties on a failed request
	wrapper.vm.suggestedUsernames.value = [ { value: 'test123123123123123' } ];

	// Update the input value
	const inputField = wrapper.find( 'input[name=filter-username]' );
	await inputField.setValue( 'testing123' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run
	await waitUntilDebounceComplete();

	// The suggestions should now be set
	assert.deepEqual( wrapper.vm.suggestedUsernames, [] );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing123',
		aulimit: '10'
	} ) );
} );

QUnit.test( 'Should select no usernames for an empty input field', ( assert ) => {
	const wrapper = mountUsernameFilter();

	const inputField = wrapper.find( 'input[name=filter-username]' );
	inputField.setValue( '' );

	// The suggestions should be empty for an empty input
	assert.deepEqual(
		wrapper.vm.suggestedUsernames,
		[],
		'No suggested usernames are set for empty input'
	);
} );

QUnit.test( 'inputValue updated twice within the debounce period', async function ( assert ) {
	const apiGet = this.sandbox.stub( mw.Api.prototype, 'get' ).callsFake( () => Promise.resolve(
		{ query: { allusers: [
			{ userid: 1, name: 'testing123' },
			{ userid: 2, name: 'testing1234' }
		] } }
	) );
	const wrapper = mountUsernameFilter();

	// Update the input value twice to test debouncing
	const inputField = wrapper.find( 'input[name=filter-username]' );
	await inputField.setValue( 'testing12' );
	await inputField.setValue( 'testing123' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run
	await waitUntilDebounceComplete();

	// The suggestions should now be set.
	assert.deepEqual( wrapper.vm.suggestedUsernames, [
		{ value: 'testing123' },
		{ value: 'testing1234' }
	] );
	assert.true( apiGet.calledWith( {
		action: 'query',
		list: 'allusers',
		auprefix: 'testing123',
		aulimit: '10'
	} ) );
} );

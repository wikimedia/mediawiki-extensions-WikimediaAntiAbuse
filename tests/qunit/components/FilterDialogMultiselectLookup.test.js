'use strict';

const { mount } = require( 'vue-test-utils' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.FilterDialogMultiselectLookup', QUnit.newMwEnvironment( {
	afterEach() {
		// Clear up mounted components to avoid slower tests
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

/**
 * Waits for the debounce time set for the lookup component to complete.
 * Used to ensure that tests wait for long enough for the state of the page to be updated.
 *
 * @return {Promise}
 */
const waitUntilDebounceComplete = () => new Promise( ( resolve ) => {
	setTimeout( () => {
		resolve();
	}, 120 );
} );

const mountMultiselectLookup = ( props ) => {
	const FilterDialogMultiselectLookup = require( 'ext.wikimediaAntiAbuse/components/FilterDialogMultiselectLookup.vue' );
	const wrapper = mount( FilterDialogMultiselectLookup, Object.assign( {
		props: Object.assign( {
			selectedItems: [],
			placeholder: 'Test Placeholder',
			name: 'filter-username',
			loadSuggestedItemsCallback: () => Promise.resolve( [
				{ value: 'testing' },
				{ value: 'testing1' },
				{ value: 'testing2' }
			] )
		}, props )
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test( 'Should update menu config on change in window height', ( assert ) => {
	const wrapper = mountMultiselectLookup();

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

QUnit.test( 'Should render multi-select lookup correctly', ( assert ) => {
	const wrapper = mountMultiselectLookup( {
		name: 'filter-test',
		placeholder: 'Test Placeholder',
		class: 'test-css-class'
	} );

	const fieldElement = wrapper.find( '.test-css-class' );
	assert.true( fieldElement.exists(), 'The field element should be rendered with the correct CSS class' );

	const inputField = wrapper.find( 'input[name=filter-test]' );
	assert.true( inputField.exists(), 'The input field should be rendered with the correct name attribute' );

	assert.strictEqual(
		inputField.wrapperElement.placeholder,
		'Test Placeholder',
		'The input field should have the correct placeholder'
	);
} );

QUnit.test( 'Should call suggested items callback on inputValue update', async function ( assert ) {
	const callbackStub = this.sandbox.stub().resolves( [
		{ value: 'testing' },
		{ value: 'testing1' },
		{ value: 'testing2' }
	] );
	const wrapper = mountMultiselectLookup( {
		loadSuggestedItemsCallback: callbackStub,
		name: 'filter-test'
	} );

	// Update the input value
	const inputField = wrapper.find( 'input[name=filter-test]' );
	await inputField.setValue( 'testing' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run.
	await waitUntilDebounceComplete();

	// The suggestions should now be set.
	assert.deepEqual(
		wrapper.vm.suggestedItems,
		[
			{ value: 'testing' },
			{ value: 'testing1' },
			{ value: 'testing2' }
		],
		'The suggested items should be set to the values returned by the callback'
	);
	assert.true(
		callbackStub.calledWith( 'testing' ),
		'The callback should be called with the updated input value'
	);
} );

QUnit.test( 'inputValue update but suggested items callback rejects', async ( assert ) => {
	const rejectedPromise = Promise.reject( 'error' );
	rejectedPromise.catch( () => {} );

	const wrapper = mountMultiselectLookup( {
		loadSuggestedItemsCallback: () => rejectedPromise,
		name: 'filter-test'
	} );

	// Set suggestedItems so that the test can verify it empties on a failed request
	wrapper.vm.suggestedItems = [ { value: 'test123123123123123' } ];

	// Update the input value
	const inputField = wrapper.find( 'input[name=filter-test]' );
	await inputField.setValue( 'testing' );

	await waitUntilDebounceComplete();

	assert.deepEqual(
		wrapper.vm.suggestedItems,
		[],
		'The suggested items should be empty if the load suggested items callback rejects'
	);
} );

QUnit.test( 'inputValue updated twice within the debounce period', async function ( assert ) {
	const suggestedItemsCallback = this.sandbox.stub().resolves( [
		{ value: 'testing123' },
		{ value: 'testing1234' }
	] );
	const wrapper = mountMultiselectLookup( {
		loadSuggestedItemsCallback: suggestedItemsCallback,
		name: 'filter-test'
	} );

	// Update the input value twice to test debouncing
	const inputField = wrapper.find( 'input[name=filter-test]' );
	await inputField.setValue( 'testing12' );
	await inputField.setValue( 'testing123' );

	// Wait until the debounce time has expired and add around 20ms to be sure it has run
	await waitUntilDebounceComplete();

	// The suggestions should now be set.
	assert.deepEqual(
		wrapper.vm.suggestedItems,
		[
			{ value: 'testing123' },
			{ value: 'testing1234' }
		],
		'The suggested items should be set to the values returned by the callback'
	);
	assert.strictEqual( suggestedItemsCallback.callCount, 1, 'The callback should only be called once' );
} );

QUnit.test( 'When inputValue is empty no suggestions should be shown', async function ( assert ) {
	const suggestedItemsCallback = this.sandbox.stub();
	const wrapper = mountMultiselectLookup( {
		loadSuggestedItemsCallback: suggestedItemsCallback,
		name: 'filter-test'
	} );

	const inputField = wrapper.find( 'input[name=filter-test]' );
	await inputField.setValue( '' );

	// Wait until the debounce time would have expired, otherwise we cannot fail if the
	// callback is called after the standard debounce time
	await waitUntilDebounceComplete();

	assert.deepEqual(
		wrapper.vm.suggestedItems,
		[],
		'Suggested items list is empty for empty input'
	);
	assert.false( suggestedItemsCallback.called, 'No suggestions are loaded for empty inputValue' );
} );

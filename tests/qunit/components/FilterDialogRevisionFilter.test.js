'use strict';

const { mount } = require( 'vue-test-utils' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.FilterDialogRevisionFilter', QUnit.newMwEnvironment( {
	afterEach() {
		// Clear up mounted components to avoid slower tests
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

const mountRevisionFilter = ( selectedRevisionIds ) => {
	const FilterDialogRevisionFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogRevisionFilter.vue' );
	const wrapper = mount( FilterDialogRevisionFilter, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: {
			mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) }
		},
		props: {
			selectedRevisionIds: selectedRevisionIds === undefined ? [] : selectedRevisionIds
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test.each( 'Should render correctly', {
	'Revision filter is empty': {
		selectedRevisionIds: [],
		shouldFilterBeShown: false
	},
	'Revision filter has selected revision ID': {
		selectedRevisionIds: [ 123 ],
		shouldFilterBeShown: true
	},
	'Revision filter has multiple selected revision IDs': {
		selectedRevisionIds: [ 123, 321, 456 ],
		shouldFilterBeShown: true
	}
}, async ( assert, options ) => {
	const wrapper = mountRevisionFilter( options.selectedRevisionIds );
	const filter = wrapper.find( '.mw-wikimediaantiabuse-abuse-review-filter-dialog-revision-filter' );

	if ( !options.shouldFilterBeShown ) {
		assert.false(
			filter.exists(),
			'The revision filter should not be shown when there are selected revision IDs'
		);
		return;
	}

	assert.true(
		filter.exists(),
		'The revision filter should be shown when there are no selected revision IDs'
	);

	const fieldLabel = filter.find( '.cdx-label__label__text' );
	assert.true( fieldLabel.exists(), 'The label for the input field should be rendered' );
	assert.strictEqual(
		fieldLabel.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-revision-header)',
		'The label for the input field has the correct text'
	);

	const inputField = filter.find( 'input[name=filter-revision-ids]' );
	assert.true( inputField.exists(), 'The input field should be rendered with the correct name attribute' );
	assert.strictEqual(
		inputField.wrapperElement.placeholder,
		'(wikimediaantiabuse-special-abuse-review-filter-revision-placeholder)',
		'The input field should has the correct placeholder'
	);

	const validationErrorMessage = filter.find( '.cdx-message--error' );
	assert.false( validationErrorMessage.exists(), 'Error message is not shown for empty input field value' );
} );

QUnit.test.each( 'Error message shown when input value is not an integer', {
	'Input value is a string': {
		inputValue: 'abc'
	},
	'Input value is a float': {
		inputValue: '123.45'
	},
	'Input value is a negative number': {
		inputValue: '-123'
	},
	'Input value is integer with letter suffix': {
		inputValue: '123abc'
	},
	'Input value is integer with letter prefix': {
		inputValue: 'abc123'
	}
}, async ( assert, options ) => {
	const wrapper = mountRevisionFilter( [ 1234 ] );
	const filter = wrapper.find( '.mw-wikimediaantiabuse-abuse-review-filter-dialog-revision-filter' );

	let validationErrorMessage = filter.find( '.cdx-message--error' );
	assert.false( validationErrorMessage.exists(), 'Error message is not shown initially' );

	const inputField = wrapper.find( 'input[name=filter-revision-ids]' );
	await inputField.setValue( options.inputValue );

	validationErrorMessage = filter.find( '.cdx-message--error' );
	assert.true( validationErrorMessage.exists(), 'Error message is shown after invalid input entered' );
	assert.strictEqual(
		validationErrorMessage.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-revision-not-integer-error)',
		'The error message has the correct text'
	);
} );

QUnit.test.each( 'Error message not shown when input is an integer', {
	'Input value is an single digit integer': {
		inputValue: '1'
	},
	'Input value is a multiple digit integer': {
		inputValue: '123'
	}
}, async ( assert, options ) => {
	const wrapper = mountRevisionFilter( [ 1234 ] );
	const filter = wrapper.find( '.mw-wikimediaantiabuse-abuse-review-filter-dialog-revision-filter' );

	let validationErrorMessage = filter.find( '.cdx-message--error' );
	assert.false( validationErrorMessage.exists(), 'Error message is not shown initially' );

	const inputField = wrapper.find( 'input[name=filter-revision-ids]' );
	await inputField.setValue( options.inputValue );

	validationErrorMessage = filter.find( '.cdx-message--error' );
	assert.false( validationErrorMessage.exists(), 'Error message is not shown after valid integer input entered' );
} );

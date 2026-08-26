'use strict';

const { mount } = require( 'vue-test-utils' );
const utils = require( 'ext.wikimediaAntiAbuse/utils.js' );

const mounted = [];

QUnit.module( 'ext.wikimediaAntiAbuse.FilterDialog', QUnit.newMwEnvironment( {
	afterEach() {
		// Clear up mounted components to avoid slower tests
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	}
} ) );

const mountDialog = ( initialFilters ) => {
	const FilterDialog = require( 'ext.wikimediaAntiAbuse/components/FilterDialog.vue' );
	const wrapper = mount( FilterDialog, Object.assign( {
		// $i18n is installed by createMwApp, which mounting the component directly bypasses.
		global: {
			stubs: { teleport: true },
			mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) }
		},
		props: {
			initialFilters: Object.assign( {
				showFalsePositives: false,
				showHandledRevisions: false
			}, initialFilters )
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test.each( 'Renders correctly when opened', {
	'No filters enabled': {
		initialFilters: { showFalsePositives: false, showHandledRevisions: false }
	},
	'False positives shown': {
		initialFilters: { showFalsePositives: true, showHandledRevisions: false }
	},
	'Handled revisions shown': {
		initialFilters: { showFalsePositives: false, showHandledRevisions: true }
	},
	'Handled revisions and false positives shown': {
		initialFilters: { showFalsePositives: true, showHandledRevisions: true }
	}
}, async function ( assert, options ) {
	const updateFiltersStub = this.sandbox.stub( utils, 'updateFiltersOnPage' );

	const wrapper = mountDialog( options.initialFilters );

	const falsePositivesCheckbox = wrapper.find( 'input[name="filter-show-false-positives"]' );
	assert.strictEqual(
		falsePositivesCheckbox.element.checked,
		options.initialFilters.showFalsePositives,
		'False positives checkbox has correct state'
	);
	assert.strictEqual(
		falsePositivesCheckbox.element.labels.length,
		1,
		'False positives checkbox has a label'
	);
	assert.strictEqual(
		falsePositivesCheckbox.element.labels[ 0 ].textContent,
		'(wikimediaantiabuse-special-abuse-review-show-false-positives)',
		'False positives checkbox has correct label'
	);

	const handledRevisionsCheckbox = wrapper.find( 'input[name="filter-show-handled-revisions"]' );
	assert.strictEqual(
		handledRevisionsCheckbox.element.checked,
		options.initialFilters.showHandledRevisions,
		'Handled revisions checkbox has correct state'
	);
	assert.strictEqual(
		handledRevisionsCheckbox.element.labels.length,
		1,
		'Handled revisions checkbox has a label'
	);
	assert.strictEqual(
		handledRevisionsCheckbox.element.labels[ 0 ].textContent,
		'(wikimediaantiabuse-special-abuse-review-show-handled-revisions)',
		'Handled revisions checkbox has correct label'
	);

	const dialogTitle = wrapper.find( '.cdx-dialog__header__title' );
	assert.strictEqual(
		dialogTitle.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-legend)',
		'Dialog has the expected title'
	);

	const dialogSecondaryAction = wrapper.find( '.cdx-dialog__footer__default-action' );
	assert.strictEqual(
		dialogSecondaryAction.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-close)',
		'Dialog has the expected label for the secondary action'
	);

	const dialogPrimaryAction = wrapper.find( '.cdx-dialog__footer__primary-action' );
	assert.strictEqual(
		dialogPrimaryAction.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-submit)',
		'Dialog has the expected label for the primary action'
	);

	assert.true(
		updateFiltersStub.notCalled,
		'updateFiltersOnPage is not called before primary action is clicked'
	);
} );

QUnit.test.each( 'Pressing primary action updates filters', {
	'No checkboxes are checked': {
		checkedState: { showFalsePositives: false, showHandledRevisions: false },
		expectedFiltersForUrl: {}
	},
	'Only false positives checkbox is checked': {
		checkedState: { showFalsePositives: true, showHandledRevisions: false },
		expectedFiltersForUrl: { wpShowFalsePositives: 1 }
	},
	'Only handled revisions checkbox is checked': {
		checkedState: { showFalsePositives: false, showHandledRevisions: true },
		expectedFiltersForUrl: { wpShowHandledRevisions: 1 }
	},
	'Both checkboxes are checked': {
		checkedState: { showFalsePositives: true, showHandledRevisions: true },
		expectedFiltersForUrl: { wpShowFalsePositives: 1, wpShowHandledRevisions: 1 }
	}
}, async function ( assert, options ) {
	const updateFiltersStub = this.sandbox.stub( utils, 'updateFiltersOnPage' );

	const wrapper = mountDialog();

	wrapper.find( 'input[name="filter-show-false-positives"]' ).setValue(
		options.checkedState.showFalsePositives
	);
	wrapper.find( 'input[name="filter-show-handled-revisions"]' ).setValue(
		options.checkedState.showHandledRevisions
	);

	const dialogPrimaryAction = wrapper.find( '.cdx-dialog__footer__primary-action' );
	dialogPrimaryAction.trigger( 'click' );

	assert.true(
		updateFiltersStub.calledOnce,
		'updateFiltersOnPage is called once when primary action is clicked'
	);
	assert.deepEqual(
		updateFiltersStub.firstCall.args[ 0 ],
		options.expectedFiltersForUrl,
		'updateFiltersOnPage is called with the expected filters for the URL'
	);
	assert.strictEqual(
		updateFiltersStub.firstCall.args[ 1 ],
		window,
		'updateFiltersOnPage is called with the window object'
	);
} );

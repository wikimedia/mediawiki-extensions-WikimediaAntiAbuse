'use strict';

const { mount } = require( 'vue-test-utils' );
const utils = require( 'ext.wikimediaAntiAbuse/utils.js' );
const FilterDialogUsernameFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogUsernameFilter.vue' );
const FilterDialogPageFilter = require( 'ext.wikimediaAntiAbuse/components/FilterDialogPageFilter.vue' );

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
			stubs: {
				teleport: true,
				FilterDialogUsernameFilter: {
					name: 'FilterDialogUsernameFilter',
					template: '<div class="mw-wikimediaantiabuse-abuse-review-filter-dialog-username-filter"></div>',
					props: [ 'selectedUsernames' ],
					emits: [ 'update:selected-usernames' ]
				},
				FilterDialogPageFilter: {
					name: 'FilterDialogPageFilter',
					template: '<div class="mw-wikimediaantiabuse-abuse-review-filter-dialog-page-filter"></div>',
					props: [ 'selectedPages' ],
					emits: [ 'update:selected-pages' ]
				}
			},
			mocks: { $i18n: ( key ) => ( { text: () => mw.msg( key ) } ) }
		},
		props: {
			initialFilters: Object.assign( {
				showFalsePositives: false,
				showHandledRevisions: false,
				username: []
			}, initialFilters )
		}
	} ) );
	mounted.push( wrapper );
	return wrapper;
};

QUnit.test.each( 'Renders correctly when opened', {
	'No filters enabled': {
		initialFilters: {
			showFalsePositives: false,
			showHandledRevisions: false,
			username: [],
			page: []
		}
	},
	'False positives shown': {
		initialFilters: {
			showFalsePositives: true,
			showHandledRevisions: false,
			username: [],
			page: []
		}
	},
	'Handled revisions shown': {
		initialFilters: {
			showFalsePositives: false,
			showHandledRevisions: true,
			username: [],
			page: []
		}
	},
	'Handled revisions and false positives shown': {
		initialFilters: {
			showFalsePositives: true,
			showHandledRevisions: true,
			username: [],
			page: []
		}
	},
	'Username filter set': {
		initialFilters: {
			showFalsePositives: false,
			showHandledRevisions: false,
			username: [ 'Test', 'Test2' ],
			page: []
		}
	},
	'Page filter set': {
		initialFilters: {
			showFalsePositives: false,
			showHandledRevisions: false,
			username: [],
			page: [ 'Page1', 'Page2' ]
		}
	}
}, async function ( assert, options ) {
	const updateFiltersStub = this.sandbox.stub( utils, 'updateFiltersOnPage' );

	const wrapper = mountDialog( options.initialFilters );

	const showAdditionalItemsField = wrapper.find(
		'.mw-wikimediaantiabuse-abuse-review-filter-dialog-show-additional-items'
	);

	const helpText = showAdditionalItemsField.find( '.cdx-field__help-text' );
	assert.strictEqual(
		helpText.text(),
		'(wikimediaantiabuse-special-abuse-review-filter-show-additional-items-help)',
		'Show additional items field has the expected help text'
	);

	const falsePositivesCheckbox = showAdditionalItemsField.find( 'input[name="filter-show-false-positives"]' );
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

	const handledRevisionsCheckbox = showAdditionalItemsField.find( 'input[name="filter-show-handled-revisions"]' );
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

	const usernameFilter = wrapper.findComponent( FilterDialogUsernameFilter );
	assert.true(
		usernameFilter.exists(),
		'Username filter component is rendered'
	);
	assert.deepEqual(
		usernameFilter.vm.selectedUsernames,
		options.initialFilters.username,
		'Username filter component has the expected initial selected usernames'
	);

	const pageFilter = wrapper.findComponent( FilterDialogPageFilter );
	assert.true(
		pageFilter.exists(),
		'Page filter component is rendered'
	);
	assert.deepEqual(
		pageFilter.vm.selectedPages,
		options.initialFilters.page,
		'Page filter component has the expected initial selected pages'
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
	'No filters are applied': {
		filterState: {
			showFalsePositives: false,
			showHandledRevisions: false,
			username: [],
			page: []
		},
		expectedFiltersForUrl: { username: [], page: [] }
	},
	'Only false positives checkbox is checked': {
		filterState: {
			showFalsePositives: true,
			showHandledRevisions: false,
			username: [],
			page: []
		},
		expectedFiltersForUrl: { wpShowFalsePositives: 1, username: [], page: [] }
	},
	'Only handled revisions checkbox is checked': {
		filterState: {
			showFalsePositives: false,
			showHandledRevisions: true,
			username: [],
			page: []
		},
		expectedFiltersForUrl: { wpShowHandledRevisions: 1, username: [], page: [] }
	},
	'Both verdict checkboxes are checked': {
		filterState: {
			showFalsePositives: true,
			showHandledRevisions: true,
			username: [],
			page: []
		},
		expectedFiltersForUrl: {
			wpShowFalsePositives: 1,
			wpShowHandledRevisions: 1,
			username: [],
			page: []
		}
	},
	'Usernames filter is set': {
		filterState: {
			showFalsePositives: true,
			showHandledRevisions: true,
			username: [ 'Test', 'Testing' ],
			page: []
		},
		expectedFiltersForUrl: {
			wpShowFalsePositives: 1,
			wpShowHandledRevisions: 1,
			username: [ 'Test', 'Testing' ],
			page: []
		}
	}
}, async function ( assert, options ) {
	const updateFiltersStub = this.sandbox.stub( utils, 'updateFiltersOnPage' );

	const wrapper = mountDialog();

	wrapper.find( 'input[name="filter-show-false-positives"]' ).setValue(
		options.filterState.showFalsePositives
	);
	wrapper.find( 'input[name="filter-show-handled-revisions"]' ).setValue(
		options.filterState.showHandledRevisions
	);

	const usernameFilter = wrapper.findComponent( FilterDialogUsernameFilter );
	usernameFilter.vm.$emit( 'update:selected-usernames', options.filterState.username );
	await wrapper.vm.$nextTick();

	const pageFilter = wrapper.findComponent( FilterDialogPageFilter );
	pageFilter.vm.$emit( 'update:selected-pages', options.filterState.page );
	await wrapper.vm.$nextTick();

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

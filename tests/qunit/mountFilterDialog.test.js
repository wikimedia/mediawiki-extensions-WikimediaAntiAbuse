'use strict';

const Vue = require( 'vue' );
const { mountFilterDialog } = require( 'ext.wikimediaAntiAbuse/mountFilterDialog.js' );

const BUTTON_CLASS = 'mw-wikimediaantiabuse-abuse-review-filter-button';
const APP_ID = 'mw-wikimediaantiabuse-abuse-review-filter-app';

QUnit.module( 'ext.wikimediaAntiAbuse.mountFilterDialog', QUnit.newMwEnvironment() );

QUnit.test( 'mounts app when filter button pressed', function ( assert ) {
	mw.config.set( 'wgWikimediaAntiAbuseActiveFilters', { showFalsePositives: false, showHandledRevisions: false } );

	// Mock Vue.createMwApp so we don't see the dialog appear in the QUnit test runner browser tab
	const app = {
		mount: this.sandbox.spy(),
		unmount: this.sandbox.spy()
	};
	const createMwApp = this.sandbox.stub( Vue, 'createMwApp' ).returns( app );

	const qunitFixture = document.getElementById( 'qunit-fixture' );

	const button = document.createElement( 'button' );
	button.className = BUTTON_CLASS;
	qunitFixture.appendChild( button );

	const appContainer = document.createElement( 'div' );
	appContainer.id = APP_ID;
	qunitFixture.appendChild( appContainer );

	mountFilterDialog();

	button.click();

	assert.true( createMwApp.calledOnce, 'createMwApp called once' );
	assert.strictEqual(
		createMwApp.firstCall.args[ 0 ].name,
		'FilterDialog',
		'The FilterDialog component is mounted'
	);
	assert.deepEqual(
		createMwApp.firstCall.args[ 1 ],
		{
			initialFilters: {
				showFalsePositives: false,
				showHandledRevisions: false
			}
		},
		'The active filters are passed to the app'
	);
	assert.strictEqual(
		app.mount.firstCall.args[ 0 ],
		'#' + APP_ID,
		'Filter dialog is mounted into the app container'
	);
} );

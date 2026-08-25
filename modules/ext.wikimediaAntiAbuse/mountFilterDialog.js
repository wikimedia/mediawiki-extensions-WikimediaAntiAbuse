'use strict';

const Vue = require( 'vue' );

/**
 * Mounts and displays a Vue app for the filters dialog when any of the filter buttons are clicked.
 */
function mountFilterDialog() {
	const FilterDialog = require( './components/FilterDialog.vue' );

	let filterApp = null;
	const activeFilters = mw.config.get( 'wgWikimediaAntiAbuseActiveFilters' );

	const filterButtons = document.getElementsByClassName(
		'mw-wikimediaantiabuse-abuse-review-filter-button'
	);

	Array.from( filterButtons ).forEach( ( button ) => {
		button.addEventListener( 'click', ( event ) => {
			event.preventDefault();

			// Unmount the previous instance of the filter dialog, if any
			if ( filterApp !== null ) {
				filterApp.unmount();
			}

			filterApp = Vue.createMwApp( FilterDialog, { initialFilters: activeFilters } );
			filterApp.mount( '#mw-wikimediaantiabuse-abuse-review-filter-app' );
		} );
	} );
}

module.exports = { mountFilterDialog };

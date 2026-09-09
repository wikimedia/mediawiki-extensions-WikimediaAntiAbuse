<template>
	<filter-dialog-multiselect-lookup
		v-model:selected-items="computedSelectedPages"
		:placeholder="$i18n(
			'wikimediaantiabuse-special-abuse-review-filter-page-placeholder'
		).text()"
		name="filter-page"
		class="mw-wikimediaantiabuse-abuse-review-filter-dialog-page-filter"
		:load-suggested-items-callback="loadSuggestedPages"
	>
		<template #label>
			{{ $i18n(
				'wikimediaantiabuse-special-abuse-review-filter-page-header'
			).text() }}
		</template>
	</filter-dialog-multiselect-lookup>
</template>

<script>
const { computed } = require( 'vue' ),
	FilterDialogMultiselectLookup = require( './FilterDialogMultiselectLookup.vue' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialogPageFilter',
	components: {
		FilterDialogMultiselectLookup
	},
	props: {
		/**
		 * A list of pages that should be already selected in the field
		 * Must be bound with `v-model:selected-pages`.
		 */
		selectedPages: {
			type: Array,
			required: true
		}
	},
	emits: [
		'update:selected-pages'
	],
	setup( props, ctx ) {
		const computedSelectedPages = computed( {
			get: () => props.selectedPages,
			set: ( value ) => ctx.emit( 'update:selected-pages', value )
		} );

		/**
		 * Load page suggestions for the pages lookup component using the
		 * 'prefixsearch' query API.
		 *
		 * @param {string} value The text the user has typed into the input field
		 * @return {Promise<Array<{ value: string }>>}
		 */
		function loadSuggestedPages( value ) {
			return new mw.Api().get( {
				action: 'query',
				list: 'prefixsearch',
				pssearch: value,
				pslimit: 10
			} ).then( ( data ) => {
				// If the return data structure is not expected or no
				// pages are found, then just display the typed item
				if (
					!data ||
					!data.query ||
					!data.query.prefixsearch ||
					!Array.isArray( data.query.prefixsearch )
				) {
					return [ { value: value } ];
				}

				// Always suggest the current typed value, even if it is not in
				// the prefixsearch results so users can specify deleted pages
				// (which won't be returned by prefixsearch)
				const returnArray = data.query.prefixsearch.map(
					( page ) => ( { value: page.title } )
				);
				if ( !returnArray.some( ( item ) => item.value === value ) ) {
					returnArray.push( { value: value } );
				}
				return returnArray;
			} ).catch( ( error ) => {
				mw.log.error( error );
				return [ { value: value } ];
			} );
		}

		return {
			computedSelectedPages,
			loadSuggestedPages
		};
	}
};
</script>

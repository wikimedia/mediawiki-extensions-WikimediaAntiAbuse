<template>
	<filter-dialog-multiselect-lookup
		v-model:selected-items="computedSelectedUsernames"
		:placeholder="$i18n(
			'wikimediaantiabuse-special-abuse-review-filter-username-placeholder'
		).text()"
		name="filter-username"
		class="mw-wikimediaantiabuse-abuse-review-filter-dialog-username-filter"
		:load-suggested-items-callback="loadSuggestedUsernames"
	>
		<template #label>
			{{ $i18n(
				'wikimediaantiabuse-special-abuse-review-filter-username-header'
			).text() }}
		</template>
	</filter-dialog-multiselect-lookup>
</template>

<script>
const { computed } = require( 'vue' ),
	FilterDialogMultiselectLookup = require( './FilterDialogMultiselectLookup.vue' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialogUsernameFilter',
	components: {
		FilterDialogMultiselectLookup
	},
	props: {
		/**
		 * A list of usernames that should be already selected in the field
		 * Must be bound with `v-model:selected-usernames`.
		 */
		selectedUsernames: {
			type: Array,
			required: true
		}
	},
	emits: [
		'update:selected-usernames'
	],
	setup( props, ctx ) {
		const computedSelectedUsernames = computed( {
			get: () => props.selectedUsernames,
			set: ( value ) => ctx.emit( 'update:selected-usernames', value )
		} );

		/**
		 * Load username suggestions for the username lookup component using the
		 * 'allusers' query API.
		 *
		 * @param {string} value The text the user has typed into the input field,
		 *   never an empty string
		 * @return {Promise<Array<{ value: string }>>}
		 */
		function loadSuggestedUsernames( value ) {
			return new mw.Api().get( {
				action: 'query',
				list: 'allusers',
				auprefix: value,
				aulimit: '10'
			} ).then( ( data ) => {
				// If the return data structure is not expected or no
				// users are found, then just display no suggestions.
				if (
					!data ||
					!data.query ||
					!data.query.allusers ||
					!Array.isArray( data.query.allusers )
				) {
					return [];
				}

				return data.query.allusers.map(
					( user ) => ( { value: user.name } )
				);
			} ).catch( ( error ) => {
				mw.log.error( error );
				return [];
			} );
		}

		return {
			computedSelectedUsernames,
			loadSuggestedUsernames
		};
	}
};
</script>

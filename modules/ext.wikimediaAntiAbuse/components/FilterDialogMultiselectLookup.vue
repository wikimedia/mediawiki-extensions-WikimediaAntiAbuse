<template>
	<cdx-field>
		<template #label>
			<slot name="label">
			</slot>
		</template>
		<cdx-multiselect-lookup
			v-model:input-chips="selectedItemChips"
			v-model:selected="computedSelectedItems"
			v-model:input-value="inputValue"
			:menu-items="suggestedItems"
			:menu-config="menuConfig"
			:placeholder="placeholder"
			:name="name"
			@update:input-value="loadSuggestedItems"
		>
		</cdx-multiselect-lookup>
	</cdx-field>
</template>

<script>
const { ref, computed, onMounted, onUnmounted } = require( 'vue' ),
	{ CdxField, CdxMultiselectLookup } = require( './../codex.js' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialogMultiselectLookup',
	components: {
		CdxField,
		CdxMultiselectLookup
	},
	props: {
		/**
		 * A list of items that should be selected in the field.
		 * Must be bound with `v-model:selected-items`.
		 */
		selectedItems: {
			type: Array,
			required: true
		},
		/**
		 * The placeholder text to display in the input field
		 */
		placeholder: {
			type: String,
			required: true
		},
		/**
		 * The name of the input field.
		 */
		name: {
			type: String,
			required: true
		},
		/**
		 * The function to call to load suggested items for the multi-lookup component.
		 *
		 * This function should return a Promise that resolves to an array of objects
		 * with a `value` property containing the item value.
		 *
		 * @param {string} value The text the user has typed into the input field. This will
		 *    never be an empty string, as empty input will show no suggestions.
		 * @return {Promise<Array<{ value: string }>>}
		 */
		loadSuggestedItemsCallback: {
			type: Function,
			required: true
		}
	},
	emits: [
		'update:selected-items'
	],
	setup( props, ctx ) {
		const windowHeight = ref( window.innerHeight );
		let lookupDebounce = null;

		const computedSelectedItems = computed( {
			get: () => props.selectedItems,
			set: ( value ) => ctx.emit( 'update:selected-items', value )
		} );
		const selectedItemChips = ref( props.selectedItems.map( ( item ) => ( {
			label: item, value: item
		} ) ) );
		const suggestedItems = ref( [] );
		const inputValue = ref( '' );

		/**
		 * Called when the browser window is resized.
		 *
		 * This function updates the reference containing the current height of the window
		 * to adjust the number of menu items shown in the suggestions dropdown.
		 */
		function onWindowResize() {
			windowHeight.value = window.innerHeight;
		}

		onMounted( () => {
			window.addEventListener( 'resize', onWindowResize );
		} );

		onUnmounted( () => {
			clearTimeout( lookupDebounce );
			window.removeEventListener( 'resize', onWindowResize );
		} );

		/**
		 * The configuration settings for the Codex MultiLookup component.
		 *
		 * This sets the visibleItemLimit to a proportion of the height such
		 * that the dropdown menu should not overflow the bottom of the dialog.
		 */
		const menuConfig = computed( () => ( {
			visibleItemLimit: Math.min(
				Math.max(
					Math.floor( windowHeight.value / 150 ),
					2
				),
				4
			)
		} ) );

		/**
		 * Load item suggestions for the multi-lookup component.
		 *
		 * Calling this method repeatedly is safe as updates are debounced using a 100ms delay.
		 *
		 * @param {string} value The text the user has typed into the input field
		 * @return {void}
		 */
		function loadSuggestedItems( value ) {
			// Clear any other pending requests to get items
			clearTimeout( lookupDebounce );

			if ( !value ) {
				suggestedItems.value = [];
				return;
			}

			// Debounce the API calls using a 100ms delay.
			lookupDebounce = setTimeout( () => {
				props.loadSuggestedItemsCallback( value )
					.then( ( items ) => {
						suggestedItems.value = items;
					} )
					.catch( () => {
						suggestedItems.value = [];
					} );
			}, 100 );
		}

		return {
			inputValue,
			selectedItemChips,
			computedSelectedItems,
			suggestedItems,
			menuConfig,
			windowHeight,
			loadSuggestedItems
		};
	},
	expose: [
		// Expose internal functions and variables used in tests in order
		// to prevent linter errors about unused properties
		'windowHeight'
	]
};
</script>

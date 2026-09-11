<template>
	<cdx-field
		v-if="computedSelectedRevisionIds.length > 0"
		class="mw-wikimediaantiabuse-abuse-review-filter-dialog-revision-filter"
		:status="status"
		:messages="messages"
	>
		<cdx-chip-input
			v-model:input-chips="computedSelectedRevisionIds"
			v-model:input-value="inputValue"
			name="filter-revision-ids"
			:chip-validator="chipValidator"
			:placeholder="$i18n(
				'wikimediaantiabuse-special-abuse-review-filter-revision-placeholder'
			).text()"
		>
		</cdx-chip-input>
		<template #label>
			{{ $i18n(
				'wikimediaantiabuse-special-abuse-review-filter-revision-header'
			).text() }}
		</template>
	</cdx-field>
</template>

<script>
const { computed, ref } = require( 'vue' ),
	{ CdxField, CdxChipInput } = require( './../codex.js' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialogRevisionFilter',
	components: {
		CdxField,
		CdxChipInput
	},
	props: {
		/**
		 * A list of revision IDs that should be already selected in the field
		 * Must be bound with `v-model:selected-revision-ids`.
		 */
		selectedRevisionIds: {
			type: Array,
			required: true
		}
	},
	emits: [
		'update:selected-revision-ids'
	],
	setup( props, ctx ) {
		const computedSelectedRevisionIds = computed( {
			get: () => props.selectedRevisionIds.map( ( id ) => ( { value: id } ) ),
			set: ( value ) => ctx.emit( 'update:selected-revision-ids', value.map( ( item ) => item.value ) )
		} );
		const inputValue = ref( '' );

		const chipValidator = ( value ) => value === '' || ( Number.isInteger( Number( value ) ) && Number( value ) > 0 );

		const status = computed( () => chipValidator( inputValue.value ) ? 'default' : 'error' );
		const messages = { error: mw.msg( 'wikimediaantiabuse-special-abuse-review-filter-revision-not-integer-error' ) };

		return {
			computedSelectedRevisionIds,
			inputValue,
			status,
			messages,
			chipValidator
		};
	}
};
</script>

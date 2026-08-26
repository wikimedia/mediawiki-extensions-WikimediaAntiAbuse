<template>
	<cdx-dialog
		v-model:open="open"
		:title="$i18n( 'wikimediaantiabuse-special-abuse-review-filter-legend' ).text()"
		:close-button-label="$i18n(
			'wikimediaantiabuse-special-abuse-review-filter-close'
		).text()"
		:use-close-button="true"
		class="mw-wikimediaantiabuse-abuse-review-filter-dialog"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="onShowResultsButtonClick"
		@default="onCloseButtonClick"
	>
		<cdx-field
			class="mw-wikimediaantiabuse-abuse-review-filter-dialog-checkbox-filters"
		>
			<cdx-checkbox
				v-model="showFalsePositivesCheckboxValue"
				name="filter-show-false-positives"
			>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-show-false-positives'
				).text() }}
			</cdx-checkbox>
			<cdx-checkbox
				v-model="showHandledRevisionsCheckboxValue"
				name="filter-show-handled-revisions"
			>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-show-handled-revisions'
				).text() }}
			</cdx-checkbox>
		</cdx-field>
	</cdx-dialog>
</template>

<script>
const { ref } = require( 'vue' ),
	{ CdxDialog, CdxField, CdxCheckbox } = require( './../codex.js' ),
	utils = require( './../utils.js' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialog',
	components: {
		CdxDialog,
		CdxField,
		CdxCheckbox
	},
	props: {
		/**
		 * A dictionary describing what filters are active on the current page.
		 *
		 * Requires the following keys:
		 *  - showFalsePositives: Boolean. If true, show revisions that have
		 *      been marked as false positives
		 *  - showHandledRevisions: Boolean. If true, show revisions that have
		 *      been marked as no further action
		 */
		initialFilters: {
			type: Object,
			required: true
		}
	},
	setup( props ) {
		const open = ref( true );

		const showFalsePositivesCheckboxValue = ref(
			props.initialFilters.showFalsePositives
		);

		const showHandledRevisionsCheckboxValue = ref(
			props.initialFilters.showHandledRevisions
		);

		function onCloseButtonClick() {
			open.value = false;
		}

		/**
		 * Handles a click of the "Show results" button which
		 * causes the page to be reloaded with the selected filters applied
		 */
		function onShowResultsButtonClick() {
			const filters = {};

			if ( showFalsePositivesCheckboxValue.value ) {
				filters.wpShowFalsePositives = 1;
			}

			if ( showHandledRevisionsCheckboxValue.value ) {
				filters.wpShowHandledRevisions = 1;
			}

			utils.updateFiltersOnPage( filters, window );
		}

		const primaryAction = {
			label: mw.msg( 'wikimediaantiabuse-special-abuse-review-filter-submit' ),
			actionType: 'progressive'
		};

		const defaultAction = {
			label: mw.msg( 'wikimediaantiabuse-special-abuse-review-filter-close' )
		};

		return {
			open,
			primaryAction,
			defaultAction,
			showFalsePositivesCheckboxValue,
			showHandledRevisionsCheckboxValue,
			onCloseButtonClick,
			onShowResultsButtonClick
		};
	}
};
</script>

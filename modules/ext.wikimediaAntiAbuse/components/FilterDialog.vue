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
		<filter-dialog-revision-filter v-model:selected-revision-ids="selectedRevisionIds">
		</filter-dialog-revision-filter>
		<cdx-field
			:is-fieldset="true"
			class="mw-wikimediaantiabuse-abuse-review-filter-dialog-show-additional-items"
		>
			<template #label>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-filter-verdicts-header'
				).text() }}
			</template>
			<cdx-checkbox
				v-model="showHandledRevisionsCheckboxValue"
				name="filter-show-handled-revisions"
			>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-show-handled-revisions'
				).text() }}
			</cdx-checkbox>
			<cdx-checkbox
				v-model="showFalsePositivesCheckboxValue"
				name="filter-show-false-positives"
			>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-show-false-positives'
				).text() }}
			</cdx-checkbox>
			<template #help-text>
				{{ $i18n(
					'wikimediaantiabuse-special-abuse-review-filter-show-additional-items-help'
				).text() }}
			</template>
		</cdx-field>
		<filter-dialog-username-filter v-model:selected-usernames="selectedUsernames">
		</filter-dialog-username-filter>
		<filter-dialog-page-filter v-model:selected-pages="selectedPages">
		</filter-dialog-page-filter>
	</cdx-dialog>
</template>

<script>
const { ref } = require( 'vue' ),
	{ CdxDialog, CdxField, CdxCheckbox } = require( './../codex.js' ),
	FilterDialogUsernameFilter = require( './FilterDialogUsernameFilter.vue' ),
	FilterDialogPageFilter = require( './FilterDialogPageFilter.vue' ),
	FilterDialogRevisionFilter = require( './FilterDialogRevisionFilter.vue' ),
	utils = require( './../utils.js' );

// @vue/component
module.exports = exports = {
	name: 'FilterDialog',
	components: {
		CdxDialog,
		CdxField,
		CdxCheckbox,
		FilterDialogUsernameFilter,
		FilterDialogPageFilter,
		FilterDialogRevisionFilter
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
		 *  - username: Array of strings. A list of usernames to filter by
		 *  - page: Array of strings. A list of page titles to filter by
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
		const selectedUsernames = ref( props.initialFilters.username );
		const selectedPages = ref( props.initialFilters.page );
		const selectedRevisionIds = ref( props.initialFilters.revision );

		function onCloseButtonClick() {
			open.value = false;
		}

		/**
		 * Handles a click of the "Show results" button which
		 * causes the page to be reloaded with the selected filters applied
		 */
		function onShowResultsButtonClick() {
			const filters = {
				username: selectedUsernames.value,
				page: selectedPages.value,
				revision: selectedRevisionIds.value
			};

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
			selectedUsernames,
			selectedPages,
			selectedRevisionIds,
			onCloseButtonClick,
			onShowResultsButtonClick
		};
	}
};
</script>

<template>
	<div class="mw-wikimediaantiabuse-abuse-review-actions-groups">
		<template v-if="tag || isSuppressed">
			<div class="mw-wikimediaantiabuse-abuse-review-actions-heading">
				<h4>
					{{ $i18n(
						'wikimediaantiabuse-special-abuse-review-verdicts-heading'
					).text() }}
				</h4>
				<cdx-progress-indicator v-if="busy">
					{{ $i18n(
						'wikimediaantiabuse-special-abuse-review-action-in-progress'
					).text() }}
				</cdx-progress-indicator>
			</div>
			<div
				class="mw-wikimediaantiabuse-abuse-review-actions
					mw-wikimediaantiabuse-abuse-review-actions--verdicts"
			>
				<cdx-button
					v-if="tag && verdict !== 'noFurtherAction'"
					type="button"
					:disabled="busy || suppressedBlocksMark"
					:aria-describedby="suppressedBlocksMark ? noteId : null"
					@click="setVerdict( verdict === 'falsePositive' ? null : 'falsePositive' )"
				>
					{{ falsePositiveLabel }}
				</cdx-button>

				<cdx-button
					v-if="tag && verdict !== 'falsePositive'"
					type="button"
					:disabled="busy || suppressedBlocksMark"
					:aria-describedby="suppressedBlocksMark ? noteId : null"
					@click="setVerdict( verdict === 'noFurtherAction' ? null : 'noFurtherAction' )"
				>
					{{ noFurtherActionLabel }}
				</cdx-button>

				<span
					v-if="isSuppressed"
					:id="noteId"
					class="mw-wikimediaantiabuse-abuse-review-suppressed-note"
				>
					{{ $i18n(
						'wikimediaantiabuse-special-abuse-review-already-suppressed-note'
					).text() }}
				</span>
			</div>
		</template>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
// CodexModule's codexComponents option injects this synthetic file; requiring
// '@wikimedia/codex' directly only works for a full-library dependency.
const { CdxButton, CdxProgressIndicator } = require( './codex.js' );
const {
	markAsFalsePositive,
	unmarkAsFalsePositive,
	markNoFurtherAction,
	unmarkNoFurtherAction
} = require( './rest.js' );
const { actionErrorMessage } = require( './utils.js' );

// Each verdict, and the calls that set and clear it.
const REQUESTS = {
	falsePositive: { mark: markAsFalsePositive, unmark: unmarkAsFalsePositive },
	noFurtherAction: { mark: markNoFurtherAction, unmark: unmarkNoFurtherAction }
};

// @vue/component
module.exports = exports = defineComponent( {
	name: 'RowActions',
	components: { CdxButton, CdxProgressIndicator },
	props: {
		revId: { type: Number, required: true },
		tag: { type: String, default: null },
		isFalsePositive: { type: Boolean, default: false },
		isNoFurtherAction: { type: Boolean, default: false },
		isSuppressed: { type: Boolean, default: false }
	},
	emits: [ 'verdict-changed' ],
	setup( props, { emit } ) {
		const busy = ref( false );
		// A row carries at most one verdict, so one ref holds it and null means none.
		const verdict = ref( null );
		if ( props.isFalsePositive ) {
			verdict.value = 'falsePositive';
		} else if ( props.isNoFurtherAction ) {
			verdict.value = 'noFurtherAction';
		}

		const suppressedBlocksMark = computed( () => props.isSuppressed && verdict.value === null );
		const falsePositiveLabel = computed( () => mw.msg(
			verdict.value === 'falsePositive' ?
				'wikimediaantiabuse-special-abuse-review-action-unmark-false-positive' :
				'wikimediaantiabuse-special-abuse-review-action-mark-false-positive'
		) );
		const noFurtherActionLabel = computed( () => mw.msg(
			verdict.value === 'noFurtherAction' ?
				'wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action' :
				'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action'
		) );

		/**
		 * @param {string|null} next The verdict to set, or null to clear the one held
		 */
		async function setVerdict( next ) {
			if ( busy.value ) {
				return;
			}

			// Clearing acts on the verdict the row holds; setting on the one asked for.
			const requests = REQUESTS[ next === null ? verdict.value : next ];
			const request = next === null ? requests.unmark : requests.mark;
			busy.value = true;
			let succeeded = false;
			let failure = null;
			try {
				await request( props.revId, props.tag );
				succeeded = true;
			} catch ( error ) {
				failure = error;
			} finally {
				busy.value = false;
			}

			if ( !succeeded ) {
				mw.notify( actionErrorMessage( failure ), { type: 'error' } );
				return;
			}
			verdict.value = next;
			// The tag chips are rendered above the island, so the row is told to flip them.
			emit( 'verdict-changed', next );
		}

		return {
			busy, verdict, suppressedBlocksMark, falsePositiveLabel, noFurtherActionLabel,
			noteId: 'mw-wikimediaantiabuse-abuse-review-suppressed-note-' + props.revId,
			setVerdict
		};
	}
} );
</script>

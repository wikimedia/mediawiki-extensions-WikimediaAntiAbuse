<template>
	<!-- A click reaching the row's summary element opens or closes it. -->
	<span class="mw-wikimediaantiabuse-abuse-review-verdicts" @click.stop.prevent>
		<cdx-toggle-button
			v-for="button in buttons"
			:key="button.verdict"
			size="small"
			:model-value="verdict === button.verdict"
			:disabled="busy || button.disabled"
			:aria-label="button.label"
			:title="button.title"
			:aria-describedby="button.note ? noteId : null"
			@update:model-value="setVerdict( verdict === button.verdict ? null : button.verdict )"
		>
			<cdx-icon :icon="button.icon"></cdx-icon>
		</cdx-toggle-button>

		<cdx-progress-indicator v-if="busy">
			{{ $i18n( 'wikimediaantiabuse-special-abuse-review-action-in-progress' ).text() }}
		</cdx-progress-indicator>

		<span
			v-if="disabledNote"
			:id="noteId"
			class="mw-wikimediaantiabuse-abuse-review-disabled-note"
		>
			{{ disabledNote }}
		</span>
	</span>
</template>

<script>
const { defineComponent, ref, computed, onMounted, onUnmounted } = require( 'vue' );
// CodexModule's codexComponents option injects this synthetic file; requiring
// '@wikimedia/codex' directly only works for a full-library dependency.
const { CdxIcon, CdxProgressIndicator, CdxToggleButton } = require( './../codex.js' );
const { cdxIconCheck, cdxIconClose } = require( './../icons.json' );
const {
	markAsFalsePositive,
	unmarkAsFalsePositive,
	markNoFurtherAction,
	unmarkNoFurtherAction
} = require( './../rest.js' );
const { actionErrorMessage } = require( './../utils.js' );

const REQUESTS = {
	falsePositive: { mark: markAsFalsePositive, unmark: unmarkAsFalsePositive },
	noFurtherAction: { mark: markNoFurtherAction, unmark: unmarkNoFurtherAction }
};

const LABEL_KEYS = {
	falsePositive: {
		mark: 'wikimediaantiabuse-special-abuse-review-action-mark-false-positive',
		unmark: 'wikimediaantiabuse-special-abuse-review-action-unmark-false-positive'
	},
	noFurtherAction: {
		mark: 'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action',
		unmark: 'wikimediaantiabuse-special-abuse-review-action-unmark-no-further-action'
	}
};

// @vue/component
module.exports = exports = defineComponent( {
	name: 'RowVerdicts',
	components: { CdxIcon, CdxProgressIndicator, CdxToggleButton },
	props: {
		revId: { type: Number, required: true },
		tag: { type: String, required: true },
		isFalsePositive: { type: Boolean, default: false },
		isNoFurtherAction: { type: Boolean, default: false },
		isSuppressed: { type: Boolean, default: false },
		detailsElement: { type: Object, default: null }
	},
	emits: [ 'verdict-changed' ],
	setup( props, { emit } ) {
		const busy = ref( false );
		const verdict = ref( null );
		if ( props.isFalsePositive ) {
			verdict.value = 'falsePositive';
		} else if ( props.isNoFurtherAction ) {
			verdict.value = 'noFurtherAction';
		}

		const isOpen = ref( !!props.detailsElement && props.detailsElement.open );
		if ( props.detailsElement ) {
			const followRow = () => {
				isOpen.value = props.detailsElement.open;
			};
			// The toggle event does not bubble, so it is taken from the element itself.
			onMounted( () => props.detailsElement.addEventListener( 'toggle', followRow ) );
			onUnmounted( () => props.detailsElement.removeEventListener( 'toggle', followRow ) );
		}

		const suppressedBlocksMark = computed(
			() => props.isSuppressed && verdict.value === null
		);

		const disabledNote = computed( () => {
			if ( suppressedBlocksMark.value ) {
				return mw.msg( 'wikimediaantiabuse-special-abuse-review-already-suppressed-note' );
			}
			return isOpen.value ?
				null :
				mw.msg( 'wikimediaantiabuse-special-abuse-review-closed-row-note' );
		} );

		// A reviewer judges an edit only after seeing it, so a closed row takes no verdict.
		const rowRefuses = computed(
			() => suppressedBlocksMark.value || !isOpen.value
		);

		/**
		 * The server refuses two verdicts on one flag, so holding one disables the
		 * other's button.
		 *
		 * @param {string} own
		 * @return {boolean}
		 */
		function isDisabled( own ) {
			return rowRefuses.value || ( verdict.value !== null && verdict.value !== own );
		}

		/**
		 * @param {string} own
		 * @param {Object} icon
		 * @return {Object}
		 */
		function toButton( own, icon ) {
			const held = verdict.value === own;
			const label = mw.msg( LABEL_KEYS[ own ][ held ? 'unmark' : 'mark' ] );

			return {
				verdict: own,
				icon,
				disabled: isDisabled( own ),
				note: disabledNote.value,
				label,
				title: disabledNote.value || label
			};
		}

		const buttons = computed( () => [
			toButton( 'noFurtherAction', cdxIconCheck ),
			toButton( 'falsePositive', cdxIconClose )
		] );

		/**
		 * @param {string|null} next The verdict to set, or null to clear the one held
		 */
		async function setVerdict( next ) {
			if ( busy.value ) {
				return;
			}

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
			emit( 'verdict-changed', next );
		}

		return {
			busy, verdict, buttons, disabledNote,
			noteId: 'mw-wikimediaantiabuse-abuse-review-disabled-note-' + props.revId,
			setVerdict
		};
	}
} );
</script>

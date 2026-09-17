<template>
	<!-- A click reaching the row's summary element opens or closes it. -->
	<span
		class="mw-wikimediaantiabuse-abuse-review-verdicts"
		:data-verdict-held="verdict"
		@click.stop.prevent
	>
		<template v-if="chip">
			<cdx-info-chip :status="chip.status">
				{{ chip.label }}
			</cdx-info-chip>

			<teleport :to="actionsElement" :disabled="!actionsElement">
				<cdx-button
					v-if="isOpen"
					:disabled="busy"
					:title="sendBackTooltip"
					@click="setVerdict( null )"
				>
					{{ sendBackLabel }}
				</cdx-button>
			</teleport>
		</template>

		<template v-else>
			<cdx-button
				v-for="button in buttons"
				:key="button.verdict"
				size="small"
				:disabled="busy || button.disabled"
				:aria-label="button.label"
				:title="button.title"
				:aria-describedby="button.note ? noteId : null"
				@click="setVerdict( button.verdict )"
			>
				<cdx-icon :icon="button.icon"></cdx-icon>
			</cdx-button>

			<span
				v-if="disabledNote"
				:id="noteId"
				class="mw-wikimediaantiabuse-abuse-review-disabled-note"
			>
				{{ disabledNote }}
			</span>
		</template>

		<cdx-progress-indicator v-if="busy">
			{{ $i18n( 'wikimediaantiabuse-special-abuse-review-action-in-progress' ).text() }}
		</cdx-progress-indicator>

		<!-- HTML derived from message parsed on server-side -->
		<!-- eslint-disable vue/no-v-html -->
		<span
			v-if="attribution"
			class="mw-wikimediaantiabuse-abuse-review-verdict-performer"
			v-html="attribution"
		></span>
		<!-- eslint-enable vue/no-v-html -->
	</span>
</template>

<script>
const { defineComponent, ref, computed, onMounted, onUnmounted } = require( 'vue' );
// CodexModule's codexComponents option injects this synthetic file; requiring
// '@wikimedia/codex' directly only works for a full-library dependency.
const { CdxButton, CdxIcon, CdxInfoChip, CdxProgressIndicator } = require( './../codex.js' );
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

const MARK_LABEL_KEYS = {
	falsePositive: 'wikimediaantiabuse-special-abuse-review-action-mark-false-positive',
	noFurtherAction: 'wikimediaantiabuse-special-abuse-review-action-mark-no-further-action'
};

const SEND_BACK_LABEL = 'wikimediaantiabuse-special-abuse-review-action-send-back-for-review';
const SEND_BACK_TOOLTIP =
	'wikimediaantiabuse-special-abuse-review-action-send-back-for-review-tooltip';

const CHIPS = {
	falsePositive: {
		status: 'warning',
		message: 'wikimediaantiabuse-special-abuse-review-verdict-chip-false-positive'
	},
	noFurtherAction: {
		status: 'success',
		message: 'wikimediaantiabuse-special-abuse-review-verdict-chip-no-further-action'
	}
};

// @vue/component
module.exports = exports = defineComponent( {
	name: 'RowVerdicts',
	components: { CdxButton, CdxIcon, CdxInfoChip, CdxProgressIndicator },
	props: {
		revId: { type: Number, required: true },
		tag: { type: String, required: true },
		isFalsePositive: { type: Boolean, default: false },
		isNoFurtherAction: { type: Boolean, default: false },
		isSuppressed: { type: Boolean, default: false },
		attributionHtml: { type: String, default: null },
		actionsElement: { type: Object, default: null },
		detailsElement: { type: Object, default: null },
		referrer: { type: String, default: '' }
	},
	emits: [ 'verdict-changed' ],
	setup( props, { emit } ) {
		const busy = ref( false );
		const verdict = ref( null );
		const attribution = ref( props.attributionHtml );
		const viewerBylines = mw.config.get( 'wgWikimediaAntiAbuseViewerBylines' ) || {};
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
		 * @param {string} own
		 * @param {Object} icon
		 * @return {Object}
		 */
		function toButton( own, icon ) {
			const label = mw.msg( MARK_LABEL_KEYS[ own ] );

			return {
				verdict: own,
				icon,
				disabled: rowRefuses.value,
				note: disabledNote.value,
				label,
				title: disabledNote.value || label
			};
		}

		const buttons = computed( () => [
			toButton( 'noFurtherAction', cdxIconCheck ),
			toButton( 'falsePositive', cdxIconClose )
		] );

		const chip = computed( () => {
			if ( verdict.value === null ) {
				return null;
			}

			return {
				status: CHIPS[ verdict.value ].status,
				label: mw.msg( CHIPS[ verdict.value ].message )
			};
		} );

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
				await request( props.revId, props.tag, props.referrer );
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
			attribution.value = viewerBylines[ next === null ? 'returned' : 'recorded' ] || null;
			emit( 'verdict-changed', next );
		}

		return {
			busy, verdict, buttons, chip, disabledNote, isOpen, attribution,
			sendBackLabel: mw.msg( SEND_BACK_LABEL ),
			sendBackTooltip: mw.msg( SEND_BACK_TOOLTIP ),
			noteId: 'mw-wikimediaantiabuse-abuse-review-disabled-note-' + props.revId,
			setVerdict
		};
	}
} );
</script>

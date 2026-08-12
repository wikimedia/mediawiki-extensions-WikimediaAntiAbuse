'use strict';

const { updateRowForFalsePositiveChange, actionErrorMessage } =
	require( '../../modules/ext.wikimediaAntiAbuse/utils.js' );

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle';
const MARK_BUTTON_CLASS = 'mw-wikimediaantiabuse-abuse-review-mark-button';
const UNMARK_BUTTON_CLASS = 'mw-wikimediaantiabuse-abuse-review-unmark-button';
const FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--false-positive';
const NOT_FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive';

QUnit.module( 'ext.wikimediaAntiAbuse.utils', QUnit.newMwEnvironment() );

/**
 * Build a review row with its two action buttons and two tag variants, hiding
 * the pair that does not match the initial state (as the pager renders it).
 *
 * @param {number} revId
 * @param {boolean} falsePositive Initial state of the row
 * @return {HTMLElement}
 */
function makeRow( revId, falsePositive ) {
	const row = document.createElement( 'tr' );
	// The following classes are used here:
	// * mw-wikimediaantiabuse-abuse-review-row
	row.className = 'mw-wikimediaantiabuse-abuse-review-row';
	row.setAttribute( 'data-rev-id', String( revId ) );

	const add = ( className, hidden ) => {
		const el = document.createElement( 'span' );
		// The following classes are used here:
		// * mw-wikimediaantiabuse-hidden
		// * mw-wikimediaantiabuse-abuse-review-toggle
		// * mw-wikimediaantiabuse-abuse-review-mark-button
		// * mw-wikimediaantiabuse-abuse-review-unmark-button
		// * mw-wikimediaantiabuse-abuse-review-tag--false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--not-false-positive
		el.className = className + ' ' + TOGGLE_CLASS + ( hidden ? ' ' + HIDDEN_CLASS : '' );
		row.appendChild( el );
	};
	add( MARK_BUTTON_CLASS, falsePositive );
	add( UNMARK_BUTTON_CLASS, !falsePositive );
	add( FALSE_POSITIVE_TAG_CLASS, !falsePositive );
	add( NOT_FALSE_POSITIVE_TAG_CLASS, falsePositive );

	document.getElementById( 'qunit-fixture' ).appendChild( row );
	return row;
}

/**
 * @param {HTMLElement} row
 * @param {string} className
 * @return {boolean} Whether the element with that class is hidden
 */
function isHidden( row, className ) {
	return row.querySelector( '.' + className ).classList.contains( HIDDEN_CLASS );
}

QUnit.test( 'marks a not-false-positive row as a false positive', ( assert ) => {
	const row = makeRow( 10, false );

	updateRowForFalsePositiveChange( row );
	assert.true( isHidden( row, MARK_BUTTON_CLASS ), 'mark button hidden' );
	assert.false( isHidden( row, UNMARK_BUTTON_CLASS ), 'unmark button shown' );
	assert.false( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag shown' );
	assert.true( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'not-false-positive tag hidden' );
} );

QUnit.test( 'unmarks a false-positive row', ( assert ) => {
	const row = makeRow( 11, true );

	updateRowForFalsePositiveChange( row );
	assert.false( isHidden( row, MARK_BUTTON_CLASS ), 'mark button shown' );
	assert.true( isHidden( row, UNMARK_BUTTON_CLASS ), 'unmark button hidden' );
	assert.true( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag hidden' );
	assert.false( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'not-false-positive tag shown' );
} );

QUnit.test( 'flips only the given row, leaving others untouched', ( assert ) => {
	const target = makeRow( 20, false );
	const other = makeRow( 21, false );

	updateRowForFalsePositiveChange( target );

	assert.true( isHidden( target, MARK_BUTTON_CLASS ), 'target row updated' );
	assert.false( isHidden( other, MARK_BUTTON_CLASS ), 'other row untouched' );
} );

QUnit.module( 'ext.wikimediaAntiAbuse.utils.actionErrorMessage', QUnit.newMwEnvironment( {
	beforeEach: function () {
		mw.config.set( 'wgUserLanguage', 'en' );
		mw.messages.set( 'wikimediaantiabuse-special-abuse-review-action-error', 'Generic failure.' );
	}
} ) );

QUnit.test( 'prefers the translation for the user language', ( assert ) => {
	assert.strictEqual(
		actionErrorMessage( { messageTranslations: { en: 'English error', fr: 'Erreur' } } ),
		'English error'
	);
} );

QUnit.test( 'falls back to the first translation when the user language is absent', ( assert ) => {
	assert.strictEqual(
		actionErrorMessage( { messageTranslations: { fr: 'Erreur' } } ),
		'Erreur'
	);
} );

QUnit.test( 'falls back to the generic message when there are no translations', ( assert ) => {
	assert.strictEqual( actionErrorMessage( null ), 'Generic failure.', 'null error' );
	assert.strictEqual( actionErrorMessage( {} ), 'Generic failure.', 'no messageTranslations key' );
	assert.strictEqual( actionErrorMessage( { messageTranslations: {} } ), 'Generic failure.', 'empty translations' );
} );

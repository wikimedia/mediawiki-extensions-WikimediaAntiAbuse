'use strict';

const { updateRowToggles, actionErrorMessage } =
	require( '../../modules/ext.wikimediaAntiAbuse/utils.js' );

const HIDDEN_CLASS = 'mw-wikimediaantiabuse-hidden';
const FALSE_POSITIVE_TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle-false-positive';
const NO_FURTHER_ACTION_TOGGLE_CLASS = 'mw-wikimediaantiabuse-abuse-review-toggle-no-further-action';
const MARK_BUTTON_CLASS = 'mw-wikimediaantiabuse-abuse-review-mark-button';
const UNMARK_BUTTON_CLASS = 'mw-wikimediaantiabuse-abuse-review-unmark-button';
const MARK_NO_FURTHER_ACTION_BUTTON_CLASS =
	'mw-wikimediaantiabuse-abuse-review-mark-no-further-action-button';
const UNMARK_NO_FURTHER_ACTION_BUTTON_CLASS =
	'mw-wikimediaantiabuse-abuse-review-unmark-no-further-action-button';
const FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--false-positive';
const NOT_FALSE_POSITIVE_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--not-false-positive';
const NO_FURTHER_ACTION_TAG_CLASS = 'mw-wikimediaantiabuse-abuse-review-tag--no-further-action';

QUnit.module( 'ext.wikimediaAntiAbuse.utils', QUnit.newMwEnvironment() );

/**
 * Build a review row as the pager renders it: a mark button belongs to both toggle
 * scopes, because a row holds one verdict at a time and either verdict changing
 * decides whether that button is offered. An unmark button follows its own verdict.
 *
 * @param {number} revId
 * @param {boolean} falsePositive Initial false-positive state of the row
 * @param {boolean} noFurtherAction Initial no-further-action state of the row
 * @return {HTMLElement}
 */
function makeRow( revId, falsePositive, noFurtherAction ) {
	const row = document.createElement( 'tr' );
	// The following classes are used here:
	// * mw-wikimediaantiabuse-abuse-review-row
	row.className = 'mw-wikimediaantiabuse-abuse-review-row';
	row.setAttribute( 'data-rev-id', String( revId ) );

	const hasVerdict = falsePositive || noFurtherAction;
	const bothScopes = FALSE_POSITIVE_TOGGLE_CLASS + ' ' + NO_FURTHER_ACTION_TOGGLE_CLASS;

	const add = ( className, toggleClasses, hidden ) => {
		const el = document.createElement( 'span' );
		// The following classes are used here:
		// * mw-wikimediaantiabuse-hidden
		// * mw-wikimediaantiabuse-abuse-review-toggle-false-positive
		// * mw-wikimediaantiabuse-abuse-review-toggle-no-further-action
		// * mw-wikimediaantiabuse-abuse-review-mark-button
		// * mw-wikimediaantiabuse-abuse-review-unmark-button
		// * mw-wikimediaantiabuse-abuse-review-mark-no-further-action-button
		// * mw-wikimediaantiabuse-abuse-review-unmark-no-further-action-button
		// * mw-wikimediaantiabuse-abuse-review-tag--false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--not-false-positive
		// * mw-wikimediaantiabuse-abuse-review-tag--no-further-action
		el.className = className + ' ' + toggleClasses + ( hidden ? ' ' + HIDDEN_CLASS : '' );
		row.appendChild( el );
	};
	add( MARK_BUTTON_CLASS, bothScopes, hasVerdict );
	add( MARK_NO_FURTHER_ACTION_BUTTON_CLASS, bothScopes, hasVerdict );
	add( UNMARK_BUTTON_CLASS, FALSE_POSITIVE_TOGGLE_CLASS, !falsePositive );
	add( UNMARK_NO_FURTHER_ACTION_BUTTON_CLASS, NO_FURTHER_ACTION_TOGGLE_CLASS, !noFurtherAction );
	add( FALSE_POSITIVE_TAG_CLASS, FALSE_POSITIVE_TOGGLE_CLASS, !falsePositive );
	add( NOT_FALSE_POSITIVE_TAG_CLASS, FALSE_POSITIVE_TOGGLE_CLASS, falsePositive );
	add( NO_FURTHER_ACTION_TAG_CLASS, NO_FURTHER_ACTION_TOGGLE_CLASS, !noFurtherAction );

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
	const row = makeRow( 10, false, false );

	updateRowToggles( row, FALSE_POSITIVE_TOGGLE_CLASS );
	assert.true( isHidden( row, MARK_BUTTON_CLASS ), 'mark button hidden' );
	assert.false( isHidden( row, UNMARK_BUTTON_CLASS ), 'unmark button shown' );
	assert.false( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag shown' );
	assert.true( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'not-false-positive tag hidden' );
	assert.true(
		isHidden( row, MARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'the row now has a verdict, so the other verdict is no longer offered'
	);
	assert.true( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag untouched' );
	assert.true(
		isHidden( row, UNMARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'the other verdict cannot be unmarked, it was never set'
	);
} );

QUnit.test( 'unmarks a false-positive row', ( assert ) => {
	const row = makeRow( 11, true, false );

	updateRowToggles( row, FALSE_POSITIVE_TOGGLE_CLASS );
	assert.false( isHidden( row, MARK_BUTTON_CLASS ), 'mark button shown' );
	assert.true( isHidden( row, UNMARK_BUTTON_CLASS ), 'unmark button hidden' );
	assert.true( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag hidden' );
	assert.false( isHidden( row, NOT_FALSE_POSITIVE_TAG_CLASS ), 'not-false-positive tag shown' );
	assert.false(
		isHidden( row, MARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'the verdict is gone, so the other verdict is offered again'
	);
} );

QUnit.test( 'marks a row as needing no further action', ( assert ) => {
	const row = makeRow( 12, false, false );

	updateRowToggles( row, NO_FURTHER_ACTION_TOGGLE_CLASS );
	assert.true(
		isHidden( row, MARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'mark no-further-action button hidden'
	);
	assert.false(
		isHidden( row, UNMARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'unmark no-further-action button shown'
	);
	assert.false( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag shown' );
	assert.true(
		isHidden( row, MARK_BUTTON_CLASS ),
		'the row now has a verdict, so the other verdict is no longer offered'
	);
	assert.true( isHidden( row, FALSE_POSITIVE_TAG_CLASS ), 'false-positive tag untouched' );
} );

QUnit.test( 'unmarks a no-further-action row', ( assert ) => {
	const row = makeRow( 13, false, true );

	updateRowToggles( row, NO_FURTHER_ACTION_TOGGLE_CLASS );
	assert.false(
		isHidden( row, MARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'mark no-further-action button shown'
	);
	assert.true(
		isHidden( row, UNMARK_NO_FURTHER_ACTION_BUTTON_CLASS ),
		'unmark no-further-action button hidden'
	);
	assert.true( isHidden( row, NO_FURTHER_ACTION_TAG_CLASS ), 'no-further-action tag hidden' );
	assert.false(
		isHidden( row, MARK_BUTTON_CLASS ),
		'the verdict is gone, so the other verdict is offered again'
	);
} );

QUnit.test( 'flips only the given row, leaving others untouched', ( assert ) => {
	const target = makeRow( 20, false, false );
	const other = makeRow( 21, false, false );

	updateRowToggles( target, FALSE_POSITIVE_TOGGLE_CLASS );

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

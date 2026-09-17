/**
 * Per-post-type TTL override select (audit maintainability).
 *
 * Dashboard carried 3 duplicated TTL-override selects (Posts/Pages/Products)
 * sharing an identical 7-option list and differing only by id/value/handler
 * — adding a duration or fixing toTtlOverride coercion in 2 of 3 silently
 * diverged per-type expiry. The option list and coercion live here once.
 *
 * @since NEXT
 */

// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering
import React from 'react';
import { __ } from '@wordpress/i18n';

/**
 * Shared TTL-override options (single source of truth).
 *
 * @since NEXT
 * @type {Array<{ value: string|number, label: string }>}
 */
export const TTL_OVERRIDE_OPTIONS = [
	{ value: '', label: __( 'Inherit global', 'performance-optimisation' ) },
	{ value: 0, label: __( 'Never expire', 'performance-optimisation' ) },
	{ value: 1, label: __( '1 hour', 'performance-optimisation' ) },
	{ value: 6, label: __( '6 hours', 'performance-optimisation' ) },
	{ value: 12, label: __( '12 hours', 'performance-optimisation' ) },
	{ value: 24, label: __( '24 hours', 'performance-optimisation' ) },
	{ value: 48, label: __( '48 hours', 'performance-optimisation' ) },
	{ value: 168, label: __( '1 week', 'performance-optimisation' ) },
];

/**
 * Coerce a TTL override select value to a finite number, or undefined when
 * the override should be omitted. Guards against tampered non-numeric option
 * values: Number('abc') is NaN and JSON.stringify(NaN) becomes null, which
 * the server could misread as an explicit clear / never-expire.
 *
 * @since NEXT
 * @param {*} value Raw select value ('' | number | string | null | undefined).
 * @return {number|undefined} Finite number, or undefined to omit.
 */
export const toTtlOverride = ( value ) => {
	if ( '' === value || null === value || undefined === value ) {
		return undefined;
	}
	const n = Number( value );
	return Number.isFinite( n ) ? n : undefined;
};

/**
 * Labeled TTL-override select bound to one post-type value.
 *
 * @since NEXT
 * @param {Object}        props               Props.
 * @param {string}        props.id            Select id.
 * @param {string}        props.name          Select name.
 * @param {string}        props.label         Field label.
 * @param {string|number} props.value         Current value ('' inherits).
 * @param {Function}      props.onChange      Change handler (receives the event).
 * @param {string}        [props.describedBy] aria-describedby target id.
 * @return {Element} Labeled select.
 */
const TtlOverrideSelect = ( {
	id,
	name,
	label,
	value,
	onChange,
	describedBy,
} ) => (
	<div className="wppo-field">
		<label className="wppo-field-label" htmlFor={ id }>
			{ label }
		</label>
		<select
			className="wppo-select"
			id={ id }
			name={ name }
			value={ '' === value ? '' : String( value ) }
			onChange={ onChange }
			aria-describedby={ describedBy }
		>
			{ TTL_OVERRIDE_OPTIONS.map( ( option ) => (
				<option key={ String( option.value ) } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	</div>
);

export default TtlOverrideSelect;

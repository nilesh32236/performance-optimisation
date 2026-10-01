/**
 * SettingField — the one field wrapper for every non-toggle control.
 *
 * The plugin hand-rolls its form controls: there is no `TextControl`,
 * `SelectControl` or `TextareaControl` from `@wordpress/components` anywhere in
 * `src/components/`. That was workable when each screen wrote its own markup,
 * but it meant five recurring mistakes, all of them visible on the live site:
 *
 * 1. **The inspector only worked for toggles.** `SettingRow` was wired up
 *    around `SwitchField` calls only, so a textarea — a field that can break a
 *    site in a way a toggle never can, by holding an invalid selector or a
 *    malformed URL — had no explanation at all. That is backwards.
 * 2. **Labels were not reliably associated.** A field with a visible `<label>`
 *    that forgot `htmlFor` announces as an unlabelled textbox.
 * 3. **Descriptions drifted.** The same control was described at three
 *    different sizes and margins depending on which screen it sat in.
 * 4. **Hand-written `id`s collide.** A field rendered once per post type or per
 *    rule repeats the same literal `id` in the document, so `htmlFor` resolves
 *    to the first one and every other copy is unlabelled. `useId` below makes
 *    that structurally impossible.
 * 5. **Nested fields had no grouping**, so a sub-field looked like a top-level
 *    setting with no indication of what it qualifies.
 *
 * This component fixes all five by construction rather than by convention, and
 * delegates the inspector wiring to `SettingRow` so there is exactly one
 * implementation of the hover/focus/pin precedence in the codebase.
 *
 * The props API is closed — unrecognised props are dropped rather than spread
 * onto the control, because forwarding something like `onBlur` blindly would
 * land it on a `<textarea>` as an unknown DOM attribute.
 *
 * ```jsx
 * <SettingField
 *   name="excludeUnusedCSS"
 *   label="Safelist Selectors"
 *   description="One selector per line — kept even if unused."
 *   type="textarea"
 *   rows={ 4 }
 *   mono
 *   subject={ subjectFor( 'excludeUnusedCSS', settings ) }
 *   value={ settings.excludeUnusedCSS }
 *   onChange={ onFieldChange }
 * />
 * ```
 *
 * @since x-release-please-version
 */

import { useId } from '@wordpress/element';

import SettingRow from './SettingRow';

/** The control types this component knows how to render. */
const TYPES = [
	'text',
	'number',
	'textarea',
	'select',
	'checkbox',
	'password',
];

/**
 * Render one setting: label, control, description, and inspector wiring.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.name          Form field name. Required — it is what
 *                                       `onFieldChange` reads to route the
 *                                       value, so a field without one cannot
 *                                       save.
 * @param {string}   props.label         Visible label.
 * @param {string}   [props.description] Help text, linked to the control.
 * @param {string}   [props.type]        One of `TYPES`. Defaults to `text`.
 * @param {*}        [props.value]       Current value.
 * @param {Function} props.onChange      Receives the native change event.
 * @param {Object}   [props.subject]     Inspector subject. See `InspectorPanel`.
 * @param {Array}    [props.options]     `[{ value, label }]` for `select`.
 * @param {string}   [props.placeholder] Placeholder text.
 * @param {number}   [props.rows]        Rows, for `textarea`.
 * @param {boolean}  [props.mono]        Use the monospace face, for code-ish
 *                                       values such as selectors and headers.
 * @param {boolean}  [props.nested]      Render as a sub-field of the setting
 *                                       above it, indented and connected.
 * @param {string}   [props.id]          Explicit DOM id. Rarely needed;
 *                                       generated when absent.
 * @param {string}   props.className     Extra class on the field wrapper.
 * @param {number}   [props.min]         Lower bound, for `number`. Forwarded
 *                                       only to a number input.
 * @param {number}   [props.max]         Upper bound, for `number`.
 * @param {number}   [props.step]        Step, for `number`.
 * @return {Element} The field.
 */
export default function SettingField( {
	name,
	label,
	description = '',
	type = 'text',
	value,
	onChange,
	subject,
	options = [],
	placeholder = '',
	rows = 4,
	mono = false,
	nested = false,
	min,
	max,
	step,
	id: idProp = '',
	className = '',
} ) {
	// A generated id, not a literal one, so the same field rendered inside a
	// loop still gets a distinct, associateable label. Prefixed by `name` to
	// stay readable in devtools.
	const generated = useId();
	const id = idProp || `${ name }-${ generated }`;
	const descriptionId = description ? `${ id }-desc` : undefined;

	if ( ! TYPES.includes( type ) ) {
		// Loud, not silent: a typo would otherwise render a bare text input and
		// save a value into the wrong shape of setting.
		throw new Error(
			`SettingField: unknown type "${ type }" for "${ name }". Expected one of: ${ TYPES.join(
				', '
			) }.`
		);
	}

	/** Shared attributes, so every type is described and named identically. */
	const controlProps = {
		id,
		name,
		onChange,
		'aria-describedby': descriptionId,
	};

	let control;

	switch ( type ) {
		case 'textarea':
			control = (
				<textarea
					{ ...controlProps }
					className={ `wppo-textarea${
						mono ? ' wppo-textarea--mono' : ''
					} ` }
					rows={ rows }
					placeholder={ placeholder }
					value={ value ?? '' }
				/>
			);
			break;

		case 'select':
			control = (
				<select
					{ ...controlProps }
					className="wppo-select"
					value={ value ?? '' }
				>
					{ options.map( ( opt ) => (
						<option key={ opt.value } value={ opt.value }>
							{ opt.label }
						</option>
					) ) }
				</select>
			);
			break;

		case 'checkbox':
			// Matches `CheckboxOption`'s own markup: the input is *inside* the
			// label, so the box and its text are one hit target and the label
			// does not depend on a `htmlFor` id staying unique. Class names
			// come from that component, not invented here.
			control = (
				<div
					className={ `wppo-checkbox-option${
						value ? ' wppo-is-checked' : ''
					}` }
				>
					<label htmlFor={ id }>
						<input
							{ ...controlProps }
							type="checkbox"
							checked={ Boolean( value ) }
						/>
						<span className="wppo-option-label-text">
							{ label }
						</span>
					</label>
				</div>
			);
			break;

		case 'number':
			// Only a number input takes these, and passing them to a <textarea>
			// would put invalid attributes on the element. Omitted entirely
			// when unset, rather than passed as undefined, so the attribute is
			// simply absent from the DOM.
			control = (
				<input
					{ ...controlProps }
					type="number"
					className={ `wppo-input${
						mono ? ' wppo-input--mono' : ''
					} ` }
					placeholder={ placeholder }
					value={ value ?? '' }
					{ ...( min !== undefined ? { min } : {} ) }
					{ ...( max !== undefined ? { max } : {} ) }
					{ ...( step !== undefined ? { step } : {} ) }
				/>
			);
			break;

		case 'password':
			// Kept distinct from `text` so the field is masked by default. The
			// inspector's "where you stand now" row reports only whether a
			// value is set (see the screen's `subjectFor`), so nothing here
			// displays the secret back to the reader.
			control = (
				<input
					{ ...controlProps }
					type="password"
					className="wppo-input"
					placeholder={ placeholder }
					autoComplete="off"
					value={ value ?? '' }
				/>
			);
			break;

		default:
			control = (
				<input
					{ ...controlProps }
					type="text"
					className={ `wppo-input${
						mono ? ' wppo-input--mono' : ''
					} ` }
					placeholder={ placeholder }
					value={ value ?? '' }
				/>
			);
	}

	// A checkbox supplies its own visible label, so a second one would repeat it.
	const showLabel = type !== 'checkbox' && Boolean( label );

	return (
		<SettingRow subject={ subject }>
			<div
				className={ [
					'wppo-field',
					nested ? 'wppo-field-nest' : '',
					className,
				]
					.filter( Boolean )
					.join( ' ' ) }
			>
				{ showLabel ? (
					<label className="wppo-field-label" htmlFor={ id }>
						{ label }
					</label>
				) : null }
				{ control }
				{ description ? (
					<p
						id={ descriptionId }
						className="wppo-text-muted wppo-text-small wppo-mt-8"
					>
						{ description }
					</p>
				) : null }
			</div>
		</SettingRow>
	);
}

export { TYPES as SETTING_FIELD_TYPES };

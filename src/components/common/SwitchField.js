import { useId } from '@wordpress/element';
import { ToggleControl } from '@wordpress/components';

/**
 * SwitchField — Accessible toggle switch with label and description.
 * Uses WordPress ToggleControl for native WP styling + accessibility.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.label         Visible heading for the switch.
 * @param {string}   [props.description] Subtitle text.
 * @param {string}   props.name          Input name attribute.
 * @param {boolean}  props.checked       Whether the switch is on.
 * @param {Function} props.onChange      Change handler (receives synthetic event).
 * @param {boolean}  [props.showLabel]   Whether to show the label.
 * @param {boolean}  [props.disabled]    Whether the switch is disabled.
 */
const SwitchField = ( {
	label,
	description,
	name,
	checked,
	onChange,
	showLabel = true,
	disabled = false,
} ) => {
	const handleToggle = ( newValue ) => {
		// Synthesize an event-like object so existing handleChange() util works unchanged.
		onChange( {
			target: {
				name,
				type: 'checkbox',
				checked: newValue,
			},
		} );
	};

	const labelId = useId();
	// Audit #1420: description programmatically associated with the toggle.
	const descriptionId = description ? `desc-${ labelId }` : undefined;

	return (
		<div className="wppo-switch-field">
			{ ( showLabel || description ) && (
				<div className="wppo-switch-field__info">
					{ showLabel && (
						<span
							className="wppo-switch-field__label"
							id={ labelId }
						>
							{ label }
						</span>
					) }
					{ description && (
						<p id={ descriptionId } className="wppo-text-muted">
							{ description }
						</p>
					) }
				</div>
			) }
			{ /* The visible label above is this component's own. ToggleControl also
			     renders one, because in @wordpress/components 29 it has **no**
			     `hideLabelFromVision` prop and always emits a visible `<label>`
			     for the input it generates an id for. Left alone that printed
			     the setting name twice in every row.

			     Its label is kept — it is the toggle's accessible name, bound to
			     the input via `htmlFor`, and that id is generated internally so
			     it cannot be referenced from here — and hidden visually instead
			     (see `_forms.scss`). Passing `label={ null }` would leave the
			     checkbox with an empty accessible name, which is the worse of
			     the two defects. */ }
			<ToggleControl
				__nextHasNoMarginBottom
				checked={ checked }
				onChange={ handleToggle }
				label={ label }
				disabled={ disabled }
			/>
		</div>
	);
};

export default SwitchField;

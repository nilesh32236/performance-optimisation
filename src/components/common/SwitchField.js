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
	const id = useId();

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

	return (
		<div className="wppo-switch-field" id={ `${ id }-wrapper` }>
			{ ( showLabel || description ) && (
				<div className="wppo-switch-field__info">
					{ showLabel && <strong>{ label }</strong> }
					{ description && (
						<p className="wppo-text-muted">{ description }</p>
					) }
				</div>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				checked={ checked }
				onChange={ handleToggle }
				label={ label }
				hideLabelFromVision={ true }
				disabled={ disabled }
			/>
		</div>
	);
};

export default SwitchField;

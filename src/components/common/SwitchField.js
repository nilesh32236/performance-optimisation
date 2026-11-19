import { useEffect, useId, useRef } from '@wordpress/element';
import { ToggleControl } from '@wordpress/components';

/**
 * SwitchField — Accessible toggle switch with label and description.
 * Uses WordPress ToggleControl for native WP styling + accessibility.
 *
 * @param {Object}                    props               Component props.
 * @param {string}                    props.label         Visible heading for the switch.
 * @param {string}                    [props.description] Subtitle text.
 * @param {string}                    props.name          Input name attribute.
 * @param {boolean}                   props.checked       Whether the switch is on.
 * @param {Function}                  props.onChange      Change handler (receives synthetic event).
 * @param {boolean}                   [props.showLabel]   Whether to show the label.
 * @param {boolean}                   [props.disabled]    Whether the switch is disabled.
 * @param {import('react').ReactNode} [props.badge]       Optional inline badge (e.g. RiskBadge) rendered next to the label text.
 */
const SwitchField = ( {
	label,
	description,
	name,
	checked,
	onChange,
	showLabel = true,
	disabled = false,
	badge = null,
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

	const inputRef = useRef( null );
	const labelId = useId();

	// Audit #1839: ToggleControl destructures only a fixed prop subset
	// (__nextHasNoMarginBottom, label, checked, help, className, onChange,
	// disabled) and forwards nothing else — so aria-labelledby/aria-label and
	// hideLabelFromVision were silently dropped. Apply the accessible name
	// directly to the input instead, and pass the description through `help`
	// (ToggleControl wires aria-describedby to the rendered hint itself).
	useEffect( () => {
		const input = inputRef.current;
		if ( ! input ) {
			return;
		}
		if ( showLabel ) {
			input.setAttribute( 'aria-labelledby', labelId );
			input.removeAttribute( 'aria-label' );
		} else {
			input.setAttribute( 'aria-label', label );
			input.removeAttribute( 'aria-labelledby' );
		}
	}, [ showLabel, label, labelId ] );

	return (
		<div className="wppo-switch-field">
			{ showLabel && (
				<div className="wppo-switch-field__info">
					<span className="wppo-switch-field__label" id={ labelId }>
						{ label }
						{ badge }
					</span>
				</div>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				checked={ checked }
				onChange={ handleToggle }
				help={ description }
				disabled={ disabled }
				ref={ inputRef }
			/>
		</div>
	);
};

export default SwitchField;

import { ToggleControl } from '@wordpress/components';
import { useId, useEffect, useRef } from '@wordpress/element';

/**
 * SwitchField — Accessible toggle switch with label and description.
 * Uses WordPress ToggleControl for native WP styling + accessibility.
 * Implements aria-describedby for description and associates visible label via htmlFor.
 * WP ToggleControl handles role=switch / checkbox semantics.
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
	const rawId = useId();
	const descriptionId = description
		? `wppo-switch-desc-${ rawId.replace( /:/g, '' ) }`
		: undefined;
	const containerRef = useRef( null );
	const visibleLabelRef = useRef( null );

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

	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) {
			return;
		}
		const input = container.querySelector( 'input[type="checkbox"]' );
		if ( ! input ) {
			return;
		}
		if ( descriptionId ) {
			input.setAttribute( 'aria-describedby', descriptionId );
		} else {
			input.removeAttribute( 'aria-describedby' );
		}
		if ( visibleLabelRef.current && input.id ) {
			visibleLabelRef.current.setAttribute( 'for', input.id );
		}
	}, [ descriptionId ] );

	return (
		<div className="wppo-switch-field" ref={ containerRef }>
			{ ( showLabel || description ) && (
				<div className="wppo-switch-field__info">
					{ showLabel && (
						// eslint-disable-next-line jsx-a11y/label-has-associated-control
						<label
							ref={ visibleLabelRef }
							className="wppo-switch-field__label"
							htmlFor={ undefined }
						>
							{ label }
						</label>
					) }
					{ description && (
						<p id={ descriptionId } className="wppo-text-muted">
							{ description }
						</p>
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

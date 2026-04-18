import { useId } from '@wordpress/element';

/**
 * SwitchField — Accessible toggle switch with label and description.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.label         Visible heading for the switch.
 * @param {string}   [props.description] Subtitle text.
 * @param {string}   props.name          Input name attribute.
 * @param {boolean}  props.checked       Whether the switch is on.
 * @param {Function} props.onChange      Change handler.
 */
const SwitchField = ( { label, description, name, checked, onChange } ) => {
	const id = useId();

	return (
		<div className="wppo-switch-field">
			<div id={ `${ id }-label` }>
				<strong>{ label }</strong>
				{ description && (
					<p className="wppo-text-muted">{ description }</p>
				) }
			</div>
			<label className="wppo-switch" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					name={ name }
					checked={ checked }
					onChange={ onChange }
					aria-labelledby={ `${ id }-label` }
				/>
				<span className="wppo-slider"></span>
			</label>
		</div>
	);
};

export default SwitchField;

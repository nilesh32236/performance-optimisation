import { useCallback, useId } from '@wordpress/element';
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useInspector } from '../../lib/InspectorContext';

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
 * @param {Object}   [props.subject]     Inspector subject. See `InspectorPanel`.
 *
 *                                       A switch is a setting like any other, so it carries the same inspector
 *                                       wiring `SettingRow` gives a row — hover, focus, and an explicit click that
 *                                       pins the explanation open. Precedence is unchanged: hover yields to focus,
 *                                       focus yields to a pin. Without this the panel stayed empty for every switch
 *                                       however good its copy was, because no subject was ever offered to it.
 */
const SwitchField = ( {
	label,
	description,
	name,
	checked,
	onChange,
	showLabel = true,
	disabled = false,
	subject,
} ) => {
	const { show, hide, setOpen } = useInspector();

	const enter = useCallback( () => {
		if ( subject ) {
			show( subject, 'hover' );
		}
	}, [ show, subject ] );
	const leave = useCallback( () => hide( 'hover' ), [ hide ] );
	const focus = useCallback( () => {
		if ( subject ) {
			show( subject, 'focus' );
		}
	}, [ show, subject ] );
	const blur = useCallback( () => hide( 'focus' ), [ hide ] );
	const pin = useCallback( () => {
		if ( subject ) {
			show( subject, 'pinned' );
			setOpen( true );
		}
	}, [ show, subject, setOpen ] );

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

	return (
		<div
			className="wppo-switch-field"
			onMouseEnter={ enter }
			onMouseLeave={ leave }
			onFocusCapture={ focus }
			onBlurCapture={ blur }
		>
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
						<p className="wppo-text-muted">{ description }</p>
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
				// `help` is the **only** ToggleControl prop that reaches the
				// input: it derives the `aria-describedby` target internally
				// from the id it generated. A previous version put an
				// `id`-keyed `<p>` on the description and passed
				// `aria-describedby` down as a prop, which landed on
				// BaseControl's wrapper `<div>` rather than the checkbox — so
				// the description was on screen and invisible to assistive
				// tech. The `<p>` above is the visible copy; this is the
				// programmatic one, and its own span is hidden in
				// `_forms.scss` for the same reason the label's is.
				help={ description }
				disabled={ disabled }
			/>
			{ subject && (
				<button
					type="button"
					className="wppo-switch-field__explain"
					onClick={ pin }
					aria-hidden="true"
					tabIndex={ -1 }
					title={ __(
						'Pin this explanation',
						'performance-optimisation'
					) }
				/>
			) }
		</div>
	);
};

export default SwitchField;

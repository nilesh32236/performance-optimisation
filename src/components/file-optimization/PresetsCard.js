/**
 * PresetsCard component.
 *
 * ARCH-015 decomposition step: presentational card for the Optimisation
 * Presets section. State + handlers stay in FileOptimization; this card
 * only renders props and forwards callbacks.
 *
 * @since 2.4.0
 * @param {Object}      props                       Component props.
 * @param {Object|null} props.presetNotice          Active preset notice ({type, message}) or null.
 * @param {Function}    props.onDismissPreset       Dismiss handler for the preset notice.
 * @param {Function}    props.onSafe                Apply-safe-preset handler.
 * @param {Function}    props.onAggressive          Open-aggressive-confirm handler.
 * @param {Function}    props.onConfirmAggressive   Confirm-aggressive handler.
 * @param {Function}    props.onCancelAggressive    Cancel-aggressive-confirm handler.
 * @param {Function}    props.onRevert              Revert-to-snapshot handler.
 * @param {boolean}     props.isApplyingPreset      Preset apply in-flight flag.
 * @param {boolean}     props.isRestoring           Snapshot restore in-flight flag.
 * @param {boolean}     props.isSaving              Form save in-flight flag.
 * @param {boolean}     props.optimizerDisabled     LiteSpeed owns optimisation flag.
 * @param {boolean}     props.showAggressiveConfirm Aggressive confirm dialog visibility.
 * @param {boolean}     props.isAggressiveActive    Computed isAggressiveDelay(settings) result.
 * @param {boolean}     props.isSafeActive          Computed isSafePresetActive(settings) result.
 * @return {Element} Card element.
 */

import { __ } from '@wordpress/i18n';
import { memo } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRocket } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from '../common/FeatureCard';
import ConfirmDialog from '../common/ConfirmDialog';
import NoticeBanner from '../common/NoticeBanner';

const PresetsCard = ( {
	presetNotice = null,
	onDismissPreset,
	onSafe,
	onAggressive,
	onConfirmAggressive,
	onCancelAggressive,
	onRevert,
	isApplyingPreset = false,
	isRestoring = false,
	isSaving = false,
	optimizerDisabled = false,
	showAggressiveConfirm = false,
	isAggressiveActive = false,
	isSafeActive = false,
} ) => (
	<FeatureCard
		title={ __( 'Optimisation Presets', 'performance-optimisation' ) }
		icon={ <FontAwesomeIcon icon={ faRocket } /> }
	>
		{ presetNotice && (
			<NoticeBanner
				type={ presetNotice.type }
				message={ presetNotice.message }
				className="wppo-mb-12"
				onDismiss={ onDismissPreset }
			/>
		) }
		<p className="wppo-text-muted wppo-text-small wppo-mb-12">
			{ __(
				'Safe enables minify + defer + delay with page-builder, jQuery and WooCommerce exclusions pre-applied (about 200ms render-block win without breakage). Aggressive drops the safe exclusions and combines CSS — only for sites with manual exclusions. Every apply snapshots your current settings first; Revert restores them in one click. Export/import and the Scripts-tab sandbox preview remain as extra safety nets.',
				'performance-optimisation'
			) }
		</p>
		<div className="wppo-field-group wppo-flex wppo-gap-12 wppo-flex-wrap">
			<button
				type="button"
				className="wppo-button wppo-button--primary"
				onClick={ onSafe }
				disabled={ isApplyingPreset || isSaving || optimizerDisabled }
			>
				{ isApplyingPreset
					? __( 'Applying…', 'performance-optimisation' )
					: __( 'Apply Safe Preset', 'performance-optimisation' ) }
			</button>
			<button
				type="button"
				className="wppo-button wppo-button--secondary"
				onClick={ onAggressive }
				disabled={ isApplyingPreset || isSaving || optimizerDisabled }
			>
				{ __( 'Enable Aggressive Mode', 'performance-optimisation' ) }
			</button>
			<button
				type="button"
				className="wppo-button wppo-button--secondary"
				onClick={ onRevert }
				disabled={ isRestoring || isApplyingPreset }
			>
				{ isRestoring
					? __( 'Reverting…', 'performance-optimisation' )
					: __( 'Revert to Previous', 'performance-optimisation' ) }
			</button>
		</div>
		<ConfirmDialog
			isOpen={ showAggressiveConfirm }
			onConfirm={ onConfirmAggressive }
			onCancel={ onCancelAggressive }
			title={ __(
				'Enable Aggressive Mode?',
				'performance-optimisation'
			) }
			message={ __(
				'Aggressive mode drops the builder, jQuery and WooCommerce exclusions and combines CSS — this can break layouts or checkout. Your current settings are snapshotted first, so you can revert in one click. Proceed?',
				'performance-optimisation'
			) }
			confirmLabel={ __( 'Enable Anyway', 'performance-optimisation' ) }
			variant="danger"
			isBusy={ isApplyingPreset }
		/>
		{ isAggressiveActive && (
			<NoticeBanner
				type="warning"
				message={ __(
					'Aggressive mode is on: builder, jQuery or WooCommerce scripts may be delayed. Re-enable the safe presets — or press Revert to Previous to restore your last settings in one click.',
					'performance-optimisation'
				) }
				className="wppo-mt-12"
			/>
		) }
		{ isSafeActive && (
			<NoticeBanner
				type="success"
				message={ __(
					'Safe preset is active: minify + defer + delay with builder, jQuery and WooCommerce exclusions.',
					'performance-optimisation'
				) }
				className="wppo-mt-12"
			/>
		) }
	</FeatureCard>
);

export default memo( PresetsCard );

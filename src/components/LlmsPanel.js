import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRobot } from '@fortawesome/free-solid-svg-icons';
import { getWppoSettings } from '../lib/apiRequest';
import { isSafeHttpUrl } from '../lib/urls';
import useNotice from '../lib/useNotice';
import useSaveSettings from '../lib/useSaveSettings';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * LLMs.txt panel for Dashboard (N8).
 *
 * @since 2.0.0
 */
const LlmsPanel = () => {
	const initial = getWppoSettings( 'settings.llms_txt', {} );

	const [ enabled, setEnabled ] = useState( !! initial.enabled );
	const [ source, setSource ] = useState( initial.source || 'both' );
	const { notice, notify, dismiss } = useNotice();
	const { saving, save } = useSaveSettings( 'llms_txt', {
		notify,
		dismiss,
		successMessage: __(
			'LLMs.txt settings saved.',
			'performance-optimisation'
		),
		errorMessage: __(
			'Failed to save LLMs.txt settings.',
			'performance-optimisation'
		),
	} );

	// Resync when the global settings arrive late or change after a save
	// elsewhere (see EdgeCachePanel for the snapshot-key pattern).
	const llmsKey = JSON.stringify(
		getWppoSettings( 'settings.llms_txt', null )
	);
	useEffect( () => {
		// Audit #1354: skip resync while saving (EdgeCachePanel pattern)
		// so a global change cannot clobber in-flight user edits.
		if ( saving ) {
			return;
		}
		const s = getWppoSettings( 'settings.llms_txt', {} );
		setEnabled( !! s.enabled );
		setSource( s.source || 'both' );
	}, [ llmsKey, saving ] );

	const homeUrl = getWppoSettings( 'homeUrl', '' );
	const llmsUrl = homeUrl
		? `${ homeUrl.replace( /\/$/, '' ) }/llms.txt`
		: '/llms.txt';

	const handleSave = async () => {
		try {
			await save( { enabled, source } );
		} catch {
			// Notify already handled inside useSaveSettings.
		}
	};

	return (
		<FeatureCard
			title={ __( 'LLMs.txt', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faRobot } aria-hidden="true" /> } // Audit #1420: decorative.
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<SwitchField
				label={ __( 'Enable LLMs.txt', 'performance-optimisation' ) }
				description={ __(
					'Generate /llms.txt and /llms-full.txt for AI crawlers from top URLs (trends + sitemap). Opt-in, local file only.',
					'performance-optimisation'
				) }
				name="llmsEnabled"
				checked={ enabled }
				onChange={ ( e ) => setEnabled( e.target.checked ) }
			/>
			<div className="wppo-field">
				<label className="wppo-field-label" htmlFor="wppoLlmsSource">
					{ __( 'Source', 'performance-optimisation' ) }
				</label>
				<select
					className="wppo-select"
					id="wppoLlmsSource"
					value={ source }
					onChange={ ( e ) => setSource( e.target.value ) }
				>
					<option value="both">
						{ __(
							'Both (Trends + Sitemap)',
							'performance-optimisation'
						) }
					</option>
					<option value="trends">
						{ __( 'Trends only', 'performance-optimisation' ) }
					</option>
					<option value="sitemap">
						{ __( 'Sitemap only', 'performance-optimisation' ) }
					</option>
				</select>
			</div>
			{ enabled && homeUrl && (
				<p className="wppo-text-muted wppo-text-small">
					{ __(
						'File will be available at:',
						'performance-optimisation'
					) }{ ' ' }
					{ isSafeHttpUrl( llmsUrl ) ? (
						<a
							href={ llmsUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ llmsUrl }
						</a>
					) : (
						<span>{ llmsUrl }</span>
					) }
				</p>
			) }
			<div className="wppo-feature-card__footer">
				<LoadingSubmitButton
					className="wppo-button wppo-button--primary"
					onClick={ handleSave }
					isLoading={ saving }
					label={ __(
						'Save LLMs.txt Settings',
						'performance-optimisation'
					) }
					loadingLabel={ __( 'Saving…', 'performance-optimisation' ) }
				/>
			</div>
		</FeatureCard>
	);
};

export default LlmsPanel;

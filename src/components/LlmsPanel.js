import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * LLMs.txt panel for Dashboard (N8).
 *
 * @since NEXT
 */
const LlmsPanel = () => {
	const initial =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.llms_txt || {}
			: {};

	const [ enabled, setEnabled ] = useState( !! initial.enabled );
	const [ source, setSource ] = useState( initial.source || 'both' );
	const [ saving, setSaving ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const homeUrl =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.homeUrl || '' : '';
	const llmsUrl = homeUrl
		? `${ homeUrl.replace( /\/$/, '' ) }/llms.txt`
		: '/llms.txt';

	const handleSave = async () => {
		setSaving( true );
		try {
			const response = await apiCall( 'update_settings', {
				tab: 'llms_txt',
				settings: { enabled, source },
			} );
			if ( response.success ) {
				// Mutate global for next mount.
				if (
					typeof wppoSettings !== 'undefined' &&
					wppoSettings.settings
				) {
					wppoSettings.settings.llms_txt = { enabled, source };
				}
				notify( {
					type: 'success',
					message: __(
						'LLMs.txt settings saved.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to save LLMs.txt settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Failed to save LLMs.txt settings.',
					'performance-optimisation'
				),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<FeatureCard
			title={ __( 'LLMs.txt', 'performance-optimisation' ) }
			icon={ <i className="fas fa-robot"></i> }
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
					<a href={ llmsUrl } target="_blank" rel="noreferrer">
						{ llmsUrl }
					</a>
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

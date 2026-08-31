import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * Edge Cache panel (N2).
 *
 * Host-agnostic Cloudflare Workers / Bunny Edge adapter.
 * - Generates wrangler.toml + cloudflare-worker.js semantics
 * - Purge via Edge_Purger alongside CDN_Purger on wppo_after_cache_clear
 * - Stale-while-revalidate <30ms global TTFB
 *
 * @since NEXT
 */
const EdgeCachePanel = () => {
	const initial =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.edge_cache || {}
			: {};

	const [ enabled, setEnabled ] = useState( !! initial.enabled );
	const [ provider, setProvider ] = useState(
		initial.provider || 'cloudflare'
	);
	const [ ttl, setTtl ] = useState( String( initial.ttl ?? 300 ) );
	const [ swr, setSwr ] = useState(
		String( initial.staleWhileRevalidate ?? 86400 )
	);
	const [ cfZone, setCfZone ] = useState( initial.cloudflareZoneId || '' );
	const [ bunnyZone, setBunnyZone ] = useState(
		initial.bunnyPullZoneId || ''
	);
	const [ saving, setSaving ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	useEffect( () => {
		const s =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.settings?.edge_cache || {}
				: {};
		setEnabled( !! s.enabled );
		setProvider( s.provider || 'cloudflare' );
		setTtl( String( s.ttl ?? 300 ) );
		setSwr( String( s.staleWhileRevalidate ?? 86400 ) );
		setCfZone( s.cloudflareZoneId || '' );
		setBunnyZone( s.bunnyPullZoneId || '' );
	}, [ wppoSettings?.settings?.edge_cache ] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		dismiss();
		try {
			const response = await apiCall( 'update_settings', {
				tab: 'edge_cache',
				settings: {
					enabled,
					provider,
					ttl: parseInt( ttl, 10 ) || 300,
					staleWhileRevalidate: parseInt( swr, 10 ) || 86400,
					cloudflareZoneId: cfZone,
					bunnyPullZoneId: bunnyZone,
				},
			} );
			if ( response.success ) {
				if (
					typeof wppoSettings !== 'undefined' &&
					wppoSettings.settings
				) {
					const nextEdgeCache = Object.freeze( {
						enabled,
						provider,
						ttl: parseInt( ttl, 10 ) || 300,
						staleWhileRevalidate: parseInt( swr, 10 ) || 86400,
						cloudflareZoneId: cfZone,
						bunnyPullZoneId: bunnyZone,
					} );
					wppoSettings.settings = Object.freeze( {
						...wppoSettings.settings,
						edge_cache: nextEdgeCache,
					} );
				}
				notify( {
					type: 'success',
					message: __(
						'Edge cache settings saved.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to save edge cache settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Failed to save edge cache settings.',
					'performance-optimisation'
				),
			} );
		} finally {
			setSaving( false );
		}
	}, [ enabled, provider, ttl, swr, cfZone, bunnyZone, notify, dismiss ] );

	return (
		<FeatureCard
			title={ __( 'Edge HTML Cache', 'performance-optimisation' ) }
			icon={ <i className="fas fa-globe"></i> }
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<SwitchField
				label={ __( 'Enable Edge Cache', 'performance-optimisation' ) }
				description={ __(
					'Deploy cache/wppo/{domain}/{path}/index.html semantics to Cloudflare Workers / Bunny Edge via stale-while-revalidate. TTFB <30ms global (edge) vs LS-local 90ms. Disabled by default — no behaviour change until enabled.',
					'performance-optimisation'
				) }
				name="edgeCacheEnabled"
				checked={ enabled }
				onChange={ ( e ) => setEnabled( e.target.checked ) }
			/>
			<p className="wppo-text-muted wppo-text-small">
				{ __(
					'Gated by wppo_edge_cache_enabled filter. Purge via Edge_Purger::purge_all on wppo_after_cache_clear (transient lock, multisite-safe).',
					'performance-optimisation'
				) }
			</p>

			<div className="wppo-field">
				<label className="wppo-field-label" htmlFor="wppoEdgeProvider">
					{ __( 'Provider', 'performance-optimisation' ) }
				</label>
				<select
					className="wppo-select"
					id="wppoEdgeProvider"
					value={ provider }
					onChange={ ( e ) => setProvider( e.target.value ) }
				>
					<option value="cloudflare">
						{ __(
							'Cloudflare Workers',
							'performance-optimisation'
						) }
					</option>
					<option value="bunny">
						{ __( 'Bunny Edge', 'performance-optimisation' ) }
					</option>
					<option value="both">
						{ __( 'Both', 'performance-optimisation' ) }
					</option>
				</select>
			</div>

			<div className="wppo-field">
				<label className="wppo-field-label" htmlFor="wppoEdgeTtl">
					{ __( 'Cache TTL (seconds)', 'performance-optimisation' ) }
				</label>
				<input
					className="wppo-input"
					id="wppoEdgeTtl"
					type="number"
					min="60"
					value={ ttl }
					onChange={ ( e ) => setTtl( e.target.value ) }
				/>
			</div>

			<div className="wppo-field">
				<label className="wppo-field-label" htmlFor="wppoEdgeSwr">
					{ __(
						'Stale-While-Revalidate (seconds)',
						'performance-optimisation'
					) }
				</label>
				<input
					className="wppo-input"
					id="wppoEdgeSwr"
					type="number"
					min="0"
					value={ swr }
					onChange={ ( e ) => setSwr( e.target.value ) }
				/>
			</div>

			{ ( provider === 'cloudflare' || provider === 'both' ) && (
				<div className="wppo-field">
					<label
						className="wppo-field-label"
						htmlFor="wppoEdgeCfZone"
					>
						{ __(
							'Cloudflare Zone ID',
							'performance-optimisation'
						) }
					</label>
					<input
						className="wppo-input"
						id="wppoEdgeCfZone"
						type="text"
						value={ cfZone }
						onChange={ ( e ) => setCfZone( e.target.value ) }
						placeholder="abc123"
					/>
					<p className="wppo-text-muted wppo-text-small">
						{ __(
							'Define WPPO_CLOUDFLARE_API_TOKEN in wp-config.php with Zone > Cache Purge permission.',
							'performance-optimisation'
						) }
					</p>
				</div>
			) }

			{ ( provider === 'bunny' || provider === 'both' ) && (
				<div className="wppo-field">
					<label
						className="wppo-field-label"
						htmlFor="wppoEdgeBunnyZone"
					>
						{ __(
							'Bunny Pull Zone ID',
							'performance-optimisation'
						) }
					</label>
					<input
						className="wppo-input"
						id="wppoEdgeBunnyZone"
						type="text"
						value={ bunnyZone }
						onChange={ ( e ) => setBunnyZone( e.target.value ) }
						placeholder="12345"
					/>
					<p className="wppo-text-muted wppo-text-small">
						{ __(
							'Define WPPO_BUNNY_API_KEY in wp-config.php with Pull Zone > Purge permission.',
							'performance-optimisation'
						) }
					</p>
				</div>
			) }

			<div className="wppo-feature-card__footer">
				<LoadingSubmitButton
					className="wppo-button wppo-button--primary"
					onClick={ handleSave }
					isLoading={ saving }
					label={ __(
						'Save Edge Cache',
						'performance-optimisation'
					) }
					loadingLabel={ __( 'Saving…', 'performance-optimisation' ) }
				/>
			</div>

			<p className="wppo-text-muted wppo-text-small wppo-mt-12">
				{ __(
					'Worker: templates/cloudflare-worker.js • Wrangler: generated via Edge_Cache::get_wrangler_toml() • Bunny: templates/bunny-edge.js',
					'performance-optimisation'
				) }
			</p>
		</FeatureCard>
	);
};

export default EdgeCachePanel;

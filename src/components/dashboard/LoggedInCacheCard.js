/**
 * LoggedInCacheCard component.
 *
 * First Dashboard decomposition step (REF-008): presentational card for the
 * logged-in user cache settings. State + save flow stay in Dashboard;
 * this card only renders props and forwards callbacks.
 *
 * @since 2.4.0
 * @param {Object}   props               Component props.
 * @param {boolean}  props.enabled       Whether logged-in cache is enabled.
 * @param {string[]} props.selectedRoles Selected role slugs.
 * @param {boolean}  props.saving        Save in-flight flag.
 * @param {Object}   props.userRoles     Role slug → label map.
 * @param {Function} props.onToggle      Toggle change handler.
 * @param {Function} props.onRoleChange  Role checkbox change handler.
 * @param {Function} props.onSave        Save button handler.
 * @return {Element} Card element.
 */

import { __ } from '@wordpress/i18n';
import { memo } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUserCheck } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from '../common/FeatureCard';
import SwitchField from '../common/SwitchField';
import CheckboxOption from '../common/CheckboxOption';
import LoadingSubmitButton from '../common/LoadingSubmitButton';

const LoggedInCacheCard = ( {
	enabled = false,
	selectedRoles = [],
	saving = false,
	userRoles = {},
	onToggle,
	onRoleChange,
	onSave,
} ) => (
	<FeatureCard
		title={ __( 'Cache for Logged-in Users', 'performance-optimisation' ) }
		icon={ <FontAwesomeIcon icon={ faUserCheck } aria-hidden="true" /> }
	>
		<SwitchField
			label={ __( 'Enable', 'performance-optimisation' ) }
			description={ __(
				'Serve cached pages to logged-in users based on their role(s). The admin bar and user-specific content are preserved per role group.',
				'performance-optimisation'
			) }
			name="enableLoggedInCache"
			checked={ enabled }
			onChange={ onToggle }
		/>
		{ enabled && (
			<div className="wppo-logged-in-cache-roles">
				<p className="wppo-text-muted">
					{ __(
						'Select which user roles should receive cached pages:',
						'performance-optimisation'
					) }
				</p>
				{ Object.entries( userRoles ).map( ( [ slug, name ] ) => (
					<CheckboxOption
						key={ slug }
						label={ name }
						name={ slug }
						checked={ selectedRoles.includes( slug ) }
						onChange={ onRoleChange }
					/>
				) ) }
				<p className="wppo-text-muted wppo-mt-10">
					{ __(
						'When no roles are selected, caching applies to all logged-in users.',
						'performance-optimisation'
					) }
				</p>
			</div>
		) }
		<div className="wppo-feature-card__footer">
			<LoadingSubmitButton
				className="wppo-button wppo-button--primary"
				onClick={ onSave }
				isLoading={ saving }
				label={ __( 'Save Settings', 'performance-optimisation' ) }
				loadingLabel={ __( 'Saving…', 'performance-optimisation' ) }
			/>
		</div>
	</FeatureCard>
);

export default memo( LoggedInCacheCard );

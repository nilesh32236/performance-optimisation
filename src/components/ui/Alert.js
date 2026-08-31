import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faInfoCircle,
	faCheckCircle,
	faExclamationTriangle,
	faExclamationCircle,
} from '@fortawesome/free-solid-svg-icons';

const toneMap = {
	info: {
		bg: 'tw-bg-[var(--wppo-info-bg)]',
		border: 'tw-border-[var(--wppo-info-border)]',
		icon: faInfoCircle,
		color: 'tw-text-[var(--wppo-info)]',
	},
	success: {
		bg: 'tw-bg-[var(--wppo-success-bg)]',
		border: 'tw-border-[var(--wppo-success-border)]',
		icon: faCheckCircle,
		color: 'tw-text-[var(--wppo-success)]',
	},
	warning: {
		bg: 'tw-bg-[var(--wppo-warning-bg)]',
		border: 'tw-border-[var(--wppo-warning-border)]',
		icon: faExclamationTriangle,
		color: 'tw-text-[var(--wppo-warning)]',
	},
	error: {
		bg: 'tw-bg-[var(--wppo-error-bg)]',
		border: 'tw-border-[var(--wppo-error-border)]',
		icon: faExclamationCircle,
		color: 'tw-text-[var(--wppo-error)]',
	},
};

const Alert = ( {
	tone = 'info',
	title,
	children,
	className = '',
	...props
} ) => {
	const t = toneMap[ tone ] || toneMap.info;
	return (
		<div
			role={ tone === 'error' ? 'alert' : 'status' }
			aria-live={ tone === 'error' ? 'assertive' : 'polite' }
			className={ `tw-flex tw-gap-3 tw-p-3.5 tw-rounded-[10px] tw-border ${ t.bg } ${ t.border } ${ className }`.trim() }
			{ ...props }
		>
			<FontAwesomeIcon
				icon={ t.icon }
				className={ `tw-mt-0.5 tw-flex-shrink-0 ${ t.color }` }
				aria-hidden="true"
			/>
			<div className="tw-flex-1 tw-min-w-0">
				{ title && (
					<p className="tw-font-semibold tw-text-[13.5px] tw-leading-5 tw-mb-1">
						{ title }
					</p>
				) }
				<div className="tw-text-[13.5px] tw-leading-5 tw-text-[var(--wppo-text-main)]">
					{ children }
				</div>
			</div>
		</div>
	);
};

export default Alert;

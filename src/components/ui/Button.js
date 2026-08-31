/* eslint-disable jsdoc/require-param-type, jsdoc/check-param-names */
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSpinner } from '@fortawesome/free-solid-svg-icons';

/**
 * Button — reusable Tailwind primitive for Overview and future pages.
 * Uses Tailwind prefix tw- with preflight false (WordPress admin safe).
 * Preserves focus-visible, hover, disabled, loading states per ACCESSIBILITY.md
 *
 * @param                                          props.children
 * @param {Object}                                 props
 * @param {'primary'|'secondary'|'danger'|'ghost'} props.variant
 * @param {'sm'|'md'|'lg'}                         props.size
 * @param {boolean}                                props.isLoading
 * @param {string}                                 props.loadingLabel
 * @param                                          props.disabled
 * @param                                          props.className
 * @param                                          props.type
 */
const Button = ( {
	children,
	variant = 'primary',
	size = 'md',
	isLoading = false,
	loadingLabel,
	disabled,
	className = '',
	type = 'button',
	...props
} ) => {
	const base =
		'tw-inline-flex tw-items-center tw-justify-center tw-font-semibold tw-rounded-[10px] tw-transition tw-duration-200 tw-focus-visible:tw-outline tw-focus-visible:tw-outline-2 tw-focus-visible:tw-outline-[var(--wppo-primary)] tw-focus-visible:tw-outline-offset-2 tw-disabled:tw-opacity-50 tw-disabled:tw-cursor-not-allowed';
	const sizes = {
		sm: 'tw-h-8 tw-px-3 tw-text-[13px] tw-gap-1.5',
		md: 'tw-h-9 tw-px-4 tw-text-[13.5px] tw-gap-2',
		lg: 'tw-h-10 tw-px-5 tw-text-[14px] tw-gap-2',
	};
	const variants = {
		primary:
			'tw-bg-[var(--wppo-primary)] tw-text-white tw-border tw-border-transparent hover:tw-bg-[var(--wppo-primary-hover)] tw-shadow-sm',
		secondary:
			'tw-bg-white tw-text-[var(--wppo-text-main)] tw-border tw-border-[var(--wppo-border)] hover:tw-bg-[#f8fafc] hover:tw-border-[var(--wppo-border-hover)]',
		danger: 'tw-bg-[var(--wppo-danger)] tw-text-white tw-border tw-border-transparent hover:tw-bg-[var(--wppo-danger-hover)]',
		ghost: 'tw-bg-transparent tw-text-[var(--wppo-text-muted)] tw-border tw-border-transparent hover:tw-bg-[var(--wppo-primary-soft)] hover:tw-text-[var(--wppo-primary)]',
	};
	return (
		<button
			type={ type }
			className={ `${ base } ${ sizes[ size ] } ${ variants[ variant ] } ${ className }`.trim() }
			disabled={ disabled || isLoading }
			aria-busy={ isLoading ? 'true' : undefined }
			{ ...props }
		>
			{ isLoading && (
				<FontAwesomeIcon icon={ faSpinner } spin aria-hidden="true" />
			) }
			{ isLoading && loadingLabel ? loadingLabel : children }
		</button>
	);
};

export default Button;

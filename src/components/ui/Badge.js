/**
 * Badge — semantic status badge for Overview health and metrics.
 */
const tones = {
	good: 'tw-bg-[var(--wppo-success-bg)] tw-text-[var(--wppo-success)] tw-border tw-border-[var(--wppo-success-border)]',
	info: 'tw-bg-[var(--wppo-info-bg)] tw-text-[var(--wppo-info)] tw-border tw-border-[var(--wppo-info-border)]',
	warning:
		'tw-bg-[var(--wppo-warning-bg)] tw-text-[var(--wppo-warning)] tw-border tw-border-[var(--wppo-warning-border)]',
	error: 'tw-bg-[var(--wppo-error-bg)] tw-text-[var(--wppo-error)] tw-border tw-border-[var(--wppo-error-border)]',
	neutral:
		'tw-bg-[#f8fafc] tw-text-[var(--wppo-text-muted)] tw-border tw-border-[var(--wppo-border)]',
};

const Badge = ( { children, tone = 'neutral', className = '', ...props } ) => (
	<span
		className={ `tw-inline-flex tw-items-center tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-bold tw-tracking-wide tw-leading-none ${ tones[ tone ] } ${ className }`.trim() }
		{ ...props }
	>
		{ children }
	</span>
);

export default Badge;

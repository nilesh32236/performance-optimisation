/* eslint-disable jsdoc/require-param-type */
/**
 * Card — Tailwind primitive for Overview metrics and sections.
 * Replaces .wppo-feature-card for Overview only (other pages keep SCSS FeatureCard).
 * @param root0
 * @param root0.children
 * @param root0.className
 * @param root0.padding
 * @param root0.hover
 */
const Card = ( {
	children,
	className = '',
	padding = 'p-5 sm:p-6',
	hover = false,
	...props
} ) => (
	<div
		className={ `tw-bg-[var(--wppo-bg-card)] tw-border tw-border-[var(--wppo-border)] tw-rounded-[16px] tw-shadow-[var(--wppo-shadow-card)] ${
			hover
				? 'hover:tw-shadow-[var(--wppo-shadow-card-hover)] hover:tw-border-[var(--wppo-border-hover)] hover:tw--translate-y-px tw-transition'
				: ''
		} tw-overflow-hidden tw-flex tw-flex-col ${ padding } ${ className }`.trim() }
		{ ...props }
	>
		{ children }
	</div>
);

export const CardHeader = ( { children, className = '', ...props } ) => (
	<div
		className={ `tw-flex tw-items-start tw-justify-between tw-gap-3 tw-pb-4 tw-mb-4 tw-border-b tw-border-[var(--wppo-border)] tw-bg-[var(--wppo-bg-card-surface)] tw--m-5 sm:tw--m-6 tw-px-5 sm:tw-px-6 tw-pt-5 sm:tw-pt-6 ${ className }`.trim() }
		{ ...props }
	>
		{ children }
	</div>
);

export const CardFooter = ( { children, className = '', ...props } ) => (
	<div
		className={ `tw-flex tw-items-center tw-justify-end tw-gap-2.5 tw-flex-wrap tw-pt-4 tw-mt-auto tw-border-t tw-border-[var(--wppo-border)] tw-bg-[rgba(248,250,252,0.5)] tw--mx-5 sm:tw--mx-6 tw--mb-5 sm:tw--mb-6 tw-px-5 sm:tw-px-6 tw-py-4 max-sm:tw-flex-col ${ className }`.trim() }
		{ ...props }
	>
		{ children }
	</div>
);

export default Card;

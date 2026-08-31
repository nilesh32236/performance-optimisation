/* eslint-disable jsdoc/require-param-type */
/**
 * SectionHeader — Tailwind primitive for Overview page header and section headers.
 * Mobile-first: stacks on small, side-by-side on md+.
 * @param root0
 * @param root0.title
 * @param root0.description
 * @param root0.actions
 * @param root0.eyebrow
 * @param root0.className
 */
const SectionHeader = ( {
	title,
	description,
	actions,
	eyebrow,
	className = '',
	...props
} ) => (
	<div
		className={ `tw-flex tw-flex-col tw-gap-4 md:tw-flex-row md:tw-items-start md:tw-justify-between ${ className }`.trim() }
		{ ...props }
	>
		<div className="tw-min-w-0 tw-flex-1">
			{ eyebrow && (
				<p className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-light)] tw-mb-1.5">
					{ eyebrow }
				</p>
			) }
			<h2 className="tw-text-[22px] sm:tw-text-[24px] tw-font-bold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-tight">
				{ title }
			</h2>
			{ description && (
				<p className="tw-mt-1.5 tw-text-[13.5px] sm:tw-text-[14px] tw-leading-6 tw-text-[var(--wppo-text-muted)] tw-max-w-[65ch]">
					{ description }
				</p>
			) }
		</div>
		{ actions && (
			<div className="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5 tw-shrink-0 max-sm:tw-w-full">
				{ actions }
			</div>
		) }
	</div>
);

export default SectionHeader;

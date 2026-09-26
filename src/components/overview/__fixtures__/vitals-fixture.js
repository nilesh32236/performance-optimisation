/**
 * The literal `web_vitals_trends` response shape, captured from the live site.
 *
 * This is not invented. The endpoint returns `trends` as an object keyed by
 * `<url-hash>_<strategy>`, each value an array of history rows — which is why an
 * implementation that assumed a flat array rendered no vitals rows at all.
 * Pinning the real shape is what stops that regressing.
 */

export const WEB_VITALS_TRENDS_RESPONSE = {
	success: true,
	message: 'Success',
	data: {
		trends: {
			'8e745f1b2c3d4e5f6a7b8c9d0e1f2a3b_desktop': [
				{
					date: '2026-09-01',
					performance: 0.42,
					lcp: 3120,
					cls: 0.14,
					tbt: 480,
					fcp: 1900,
				},
				{
					date: '2026-09-15',
					performance: 0.51,
					lcp: 2410,
					cls: 0.06,
					tbt: 390,
					fcp: 1600,
				},
				{
					date: '2026-09-24',
					performance: 0.38,
					lcp: 4100,
					cls: 0.21,
					tbt: 620,
					fcp: 2400,
				},
			],
			'8e745f1b2c3d4e5f6a7b8c9d0e1f2a3b_mobile': [
				{
					date: '2026-09-24',
					performance: 0.29,
					lcp: 5300,
					cls: 0.18,
					tbt: 910,
					fcp: 3100,
				},
			],
		},
	},
};

/** A response whose history rows carry no usable measurement at all. */
export const WEB_VITALS_ALL_NULL = {
	success: true,
	data: {
		trends: {
			abc123_mobile: [
				{
					date: '2026-09-24',
					performance: null,
					lcp: null,
					cls: null,
					tbt: null,
				},
			],
		},
	},
};

/**
 * A payload where one metric is absent everywhere and another is present.
 *
 * This is the case that distinguishes an explicit "absent" check from a
 * `> 0` filter: with `Number( null ) === 0`, dropping the explicit check turns
 * a missing LCP into a real `0`, which the status model then reports as a
 * perfect score.
 */
export const WEB_VITALS_PARTIAL = {
	success: true,
	data: {
		trends: {
			def456_mobile: [
				{ date: '2026-09-24', lcp: null, cls: 0.04, tbt: 300 },
				{ date: '2026-09-23', lcp: null, cls: 0.06, tbt: 350 },
			],
		},
	},
};

/**
 * The real stored-history row shape, and the case the first fixture missed.
 *
 * `Class_Pagespeed` writes `fetched_at` and a 0-100 `performance`, not `date`
 * and 0-1. On the live site 7 of 37 stored rows are a genuine `cls: 0`, so a
 * fixture with no zero-valued metric could not have caught the `> 0` filter
 * discarding them.
 */
export const WEB_VITALS_WITH_REAL_ZEROS = {
	success: true,
	data: {
		trends: {
			'8e745f1b2c3d4e5f6a7b8c9d0e1f2a3b_desktop': [
				{
					fetched_at: '2026-09-20T00:00:00Z',
					performance: 100,
					lcp: 900,
					cls: 0,
					tbt: 120,
				},
				{
					fetched_at: '2026-09-21T00:00:00Z',
					performance: 100,
					lcp: 1100,
					cls: 0,
					tbt: 140,
				},
				{
					fetched_at: '2026-09-22T00:00:00Z',
					performance: 100,
					lcp: 1000,
					cls: 0,
					tbt: 130,
				},
			],
		},
	},
};

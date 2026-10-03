/**
 * axe-core accessibility run against the real admin SPA.
 *
 * Why this file exists: the `probe-*.mjs` scripts in this directory and
 * `.a11y-check.js` all report accessibility *conclusions* from bespoke in-page
 * assertions. None of them is axe-core, and none of them leaves a committed
 * result artefact behind. Every accessibility number quoted in a session log or
 * a commit message therefore came from console output nobody else could
 * re-derive. That is the defect this run fixes.
 *
 * What it does:
 *   - logs into the real WordPress admin and drives the real shipped bundle.
 *     This plugin directory IS the live site, so the `build/` output committed
 *     on this branch is what gets measured.
 *   - walks every screen x every UI state.
 *   - runs axe-core over each combination and writes the results to
 *     `evidence/a11y/axe-<head>-<date>.json` as a committed artefact.
 *
 * Deliberate configuration choices -- all of them narrow *less*, never more:
 *   - NO `disableRules()`. No rule is switched off to clear a count.
 *   - NO `withTags()`. axe-core's full default rule set runs.
 *   - NO `exclude()`. The plugin mount point is used as the axe *context*, not
 *     as an exclusion, and the full document is measured as a second context.
 *
 * Two contexts exist because "which violations are ours" is a real question:
 * this plugin's SPA versus the WordPress core admin chrome the branch never
 * touched. Both are recorded so neither can be hidden by picking the flattering
 * one.
 *
 * Honesty guards, which matter more than the counts themselves:
 *   - A screen that fails to mount is recorded `rendered: false` and counted
 *     separately. It is never reported as "0 violations" -- an unrendered screen
 *     is missing evidence, not a pass.
 *   - A state that does not actually change what is on screen is recorded
 *     `stateAchieved: false` with the markers that were looked for. States are
 *     screen-specific in this app; a blanket API stub does not produce an error
 *     notice on a screen that has no error path, and the artefact says so
 *     instead of implying six distinct states were measured.
 *
 * Run: npm run a11y:axe
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { chromium } = require( 'playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const SITE = process.env.WPPO_SITE || 'https://nileshportfolio.duckdns.org';
const USER = process.env.WPPO_USER || 'admin';
const PASS = process.env.WPPO_PASS || 'tempPass123!';

const API_GLOB = '**/wp-json/performance-optimisation/v1/**';

/** The mount point React renders into, per `src/index.js`. */
const MOUNT = '#performance-optimisation';

/** Screens, as the admin nav actually resolves them (each one verified mounted). */
const SCREENS = [
	{ slug: 'overview', query: 'section=overview' },
	{ slug: 'speed', query: 'section=speed&view=fileOptimization' },
	{ slug: 'preload', query: 'section=preload' },
	{ slug: 'media', query: 'section=media&view=imageOptimization' },
	{ slug: 'data-system', query: 'section=data-system&view=databaseCleanup' },
	{ slug: 'object-cache', query: 'section=data-system&view=objectCache' },
	{ slug: 'manage', query: 'section=manage&view=pluginSettings' },
	{ slug: 'tools', query: 'section=tools' },
];

/** `WPPO_SCREENS` narrows the matrix for a smoke run or a single re-check. */
const SELECTED_SCREENS = process.env.WPPO_SCREENS
	? SCREENS.filter( ( s ) =>
			process.env.WPPO_SCREENS.split( ',' )
				.map( ( v ) => v.trim() )
				.includes( s.slug )
		)
	: SCREENS;

const STATES = [
	'default',
	'loading',
	'error',
	'empty',
	'disabled',
	'success',
];

/**
 * What each state is supposed to put on screen, and the DOM marker that proves
 * it happened. `stateAchieved` in the artefact is exactly this test, so a state
 * that silently failed to materialise is visible in the committed evidence.
 */
const STATE_MARKER = {
	default: () => true,
	loading: ( d ) => d.statusRegions > 0,
	error: ( d ) => d.notices.error > 0,
	empty: ( d ) => d.emptyStates > 0,
	disabled: ( d ) => d.disabledControls > 0,
	success: ( d ) => d.notices.success > 0,
};

/** Endpoints whose responses drive list, empty and disabled presentations. */
const LIST_ENDPOINTS = [
	'recent_activities',
	'database_cleanup_counts',
	'suggestions',
	'web_vitals_trends',
	'pagespeed_results',
];

/** Empty payload, shaped the way `src/App.js` and the cards actually read it. */
const EMPTY_PAYLOAD = {
	recent_activities: { activities: [], total: 0, page: 1 },
	database_cleanup_counts: { counts: {}, items: [], total: 0 },
	suggestions: { suggestions: [] },
	web_vitals_trends: { trends: [] },
	pagespeed_results: { results: null, total: 0 },
};

/**
 * Read an installed package's version off disk.
 *
 * `require('<pkg>/package.json')` is unavailable for packages whose `exports`
 * map omits that subpath, so the manifest is read directly.
 *
 * @param {string} name Package directory name.
 * @return {string} Semver string, or 'unknown'.
 */
const pkgVersion = ( name ) => {
	try {
		return JSON.parse(
			fs.readFileSync(
				path.join( __dirname, '..', 'node_modules', name, 'package.json' ),
				'utf8'
			)
		).version;
	} catch {
		return 'unknown';
	}
};

/**
 * The commit currently checked out, plus a fingerprint of the shipped bundle.
 *
 * This plugin directory IS the live site and `build/` is committed per branch,
 * so another agent checking out a different branch in this same working
 * directory silently changes what the browser loads. Recording the commit and
 * the bundle fingerprint per run is what makes a run attributable: a reader can
 * tell which commit produced which number instead of having to trust it.
 *
 * @return {{head: string, branch: string, bundle: string}} Identity snapshot.
 */
const identity = () => {
	let head = 'unknown';
	let branch = 'unknown';
	try {
		head = execSync( 'git rev-parse HEAD', { encoding: 'utf8' } ).trim();
		branch = execSync( 'git rev-parse --abbrev-ref HEAD', {
			encoding: 'utf8',
		} ).trim();
	} catch {
		// Not a git checkout; the bundle fingerprint below still applies.
	}
	let bundle = 'unknown';
	try {
		bundle = execSync(
			`git hash-object "${ path.join( __dirname, '..', 'build', 'index.js' ) }"`,
			{ encoding: 'utf8' }
		).trim();
	} catch {
		// build/index.js absent; identity is still recorded from HEAD.
	}
	return { head, branch, bundle };
};

/**
 * Build the route handler for one state.
 *
 * `loading` never resolves the request. That is the honest way to hold the
 * app's own loading presentation on screen for the length of an axe run, rather
 * than injecting markup that only looks like a spinner.
 *
 * @param {string} state      State name.
 * @param {Array}  heldRoutes Route handles, released in teardown.
 * @return {Function} Playwright route handler.
 */
const makeHandler = ( state, heldRoutes ) => async ( route ) => {
	const action = ( route.request().url().split( '/v1/' )[ 1 ] || '' ).split(
		'?'
	)[ 0 ];

	if ( state === 'loading' ) {
		heldRoutes.push( route );
		return;
	}

	if ( state === 'error' ) {
		await route.fulfill( {
			status: 500,
			contentType: 'application/json',
			body: JSON.stringify( {
				code: 'wppo_forced_failure',
				message: 'Forced failure for the axe accessibility run.',
				data: { status: 500 },
			} ),
		} );
		return;
	}

	if (
		( state === 'empty' || state === 'disabled' ) &&
		LIST_ENDPOINTS.includes( action )
	) {
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( EMPTY_PAYLOAD[ action ] ?? { data: [] } ),
		} );
		return;
	}

	await route.fallback();
};

/**
 * Log in and return an authenticated page.
 *
 * @param {import('playwright').Browser} browser Browser instance.
 * @return {Promise<import('playwright').Page>} Authenticated page.
 */
const login = async ( browser ) => {
	const page = await (
		await browser.newContext( { viewport: { width: 1600, height: 1000 } } )
	).newPage();

	await page.goto( `${ SITE }/wp-login.php`, {
		waitUntil: 'commit',
		timeout: 90000,
	} );
	await page.waitForSelector( '#user_login', { timeout: 60000 } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'commit', timeout: 90000 } ),
		page.click( '#wp-submit' ),
	] );
	return page;
};

/** Snapshot of what is actually on screen, used for state verification. */
const inspect = ( page ) =>
	page.evaluate( ( mount ) => {
		const root = document.querySelector( mount );
		if ( ! root ) {
			return { present: false };
		}
		const visible = ( el ) => {
			const r = el.getBoundingClientRect();
			if ( r.width < 2 || r.height < 2 ) {
				return false;
			}
			const cs = getComputedStyle( el );
			return (
				cs.visibility !== 'hidden' &&
				cs.display !== 'none' &&
				Number( cs.opacity ) > 0.01
			);
		};
		const controls = [
			...root.querySelectorAll( 'input, select, textarea' ),
		].filter( ( el ) => el.type !== 'hidden' );
		const emptyStates = [
			...root.querySelectorAll( '.wppo-empty-state' ),
		].filter( visible );
		const statusRegions = [
			...root.querySelectorAll(
				'[role="status"], [aria-busy="true"], .wppo-loading-placeholder'
			),
		].filter( visible );

		return {
			present: true,
			painted: root.innerHTML.trim().length > 0,
			htmlLength: root.innerHTML.length,
			controls: controls.length,
			buttons: root.querySelectorAll( 'button' ).length,
			links: root.querySelectorAll( 'a' ).length,
			images: root.querySelectorAll( 'img' ).length,
			disabledControls: [
				...root.querySelectorAll(
					'[disabled], [aria-disabled="true"]'
				),
			].filter( visible ).length,
			emptyStates: emptyStates.length,
			statusRegions: statusRegions.length,
			notices: {
				error: root.querySelectorAll( '.wppo-notice--error' ).length,
				success: root.querySelectorAll( '.wppo-notice--success' )
					.length,
				warning: root.querySelectorAll( '.wppo-notice--warning' )
					.length,
				info: root.querySelectorAll( '.wppo-notice--info' ).length,
			},
			heading: (
				root.querySelector( 'h1, h2' )?.textContent?.trim() ?? ''
			).slice( 0, 80 ),
		};
	}, MOUNT );

/**
 * Run axe and return the raw `AxeResults`, untouched.
 *
 * No rule configuration is applied here. That is the point: the artefact must
 * reflect axe's defaults so the counts can be re-derived by anyone running the
 * same command.
 *
 * @param {import('playwright').Page} page   Page under test.
 * @param {string|null}            context Axe context, or null for full doc.
 * @return {Promise<Object>} Raw axe results.
 */
const runAxe = ( page, context ) => {
	const builder = new AxeBuilder( { page } );
	if ( context ) {
		builder.include( context );
	}
	return builder.analyze();
};

/**
 * Compact the full-document context.
 *
 * Violations are kept verbatim because they are the evidence. The passing and
 * inapplicable rule lists are reduced to ids and node counts, which still
 * records every rule that ran and keeps the artefact committable. This is the
 * one place the artefact is not byte-for-byte raw, and it is declared as such in
 * `meta.rawDataNote`.
 *
 * @param {Object} raw Raw axe results.
 * @return {Object} Compacted results.
 */
const compact = ( raw ) => ( {
	testEngine: raw.testEngine,
	timestamp: raw.timestamp,
	url: raw.url,
	violations: raw.violations,
	incomplete: raw.incomplete,
	passCount: raw.passes.length,
	passRules: raw.passes.map( ( p ) => ( {
		id: p.id,
		nodes: p.nodes.length,
	} ) ),
	inapplicableCount: raw.inapplicable.length,
	inapplicableRules: raw.inapplicable.map( ( p ) => p.id ),
} );

( async () => {
	const startedAt = new Date().toISOString();

	// Prove the disk state before writing anything large. A full /tmp has
	// silently prevented Chromium from launching on this host before, which
	// makes every browser probe return nothing.
	const tmp = fs.statfsSync( '/tmp' );
	const tmpFreeMb = Math.round( ( tmp.bavail * tmp.bsize ) / 1024 / 1024 );
	process.stderr.write( `/tmp free before run: ${ tmpFreeMb } MB\n` );
	if ( tmpFreeMb < 200 ) {
		throw new Error(
			`/tmp has only ${ tmpFreeMb } MB free; refusing to start a browser run.`
		);
	}

	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const page = await login( browser );

	const results = [];
	/** Baseline DOM per screen, so a state can be shown to differ from default. */
	const baselines = {};

	for ( const screen of SELECTED_SCREENS ) {
		for ( const state of STATES ) {
			const heldRoutes = [];

			await page.unroute( API_GLOB ).catch( () => null );
			await page.route( API_GLOB, makeHandler( state, heldRoutes ) );

			const record = { screen: screen.slug, query: screen.query, state };
			record.measured = identity();

			try {
				await page.goto(
					`${ SITE }/wp-admin/admin.php?page=performance-optimisation&${ screen.query }`,
					{ waitUntil: 'commit', timeout: 90000 }
				);

				const mounted = await page
					.waitForSelector( `${ MOUNT } .wppo-container`, {
						timeout: 30000,
					} )
					.then( () => true )
					.catch( () => false );

				// Let lazy chunks resolve and the first data fetch settle.
				await page.waitForTimeout( 3000 );

				if ( state === 'success' ) {
					// Drive a genuine save so the app renders its own success
					// notice. Stubbed to succeed so the live site's settings are
					// never actually mutated by an accessibility run.
					await page
						.route( API_GLOB, async ( route ) => {
							const action = (
								route.request().url().split( '/v1/' )[ 1 ] || ''
							).split( '?' )[ 0 ];
							if ( action === 'update_settings' ) {
								return route.fulfill( {
									status: 200,
									contentType: 'application/json',
									body: JSON.stringify( {
										success: true,
										data: { settings: {} },
									} ),
								} );
							}
							return route.fallback();
						} )
						.catch( () => null );

					await page
						.locator( `${ MOUNT } button:has-text("Save Settings")` )
						.first()
						.click( { timeout: 15000 } )
						.catch( () => null );
					await page
						.waitForSelector( `${ MOUNT } .wppo-notice--success`, {
							timeout: 20000,
						} )
						.catch( () => null );
					await page.waitForTimeout( 800 );
				}

				record.dom = await inspect( page );
				record.rendered = Boolean( mounted && record.dom?.painted );
				record.stateAchieved = record.rendered
					? Boolean( STATE_MARKER[ state ]( record.dom ) )
					: false;

				if ( state === 'default' && record.rendered ) {
					baselines[ screen.slug ] = record.dom;
				}
				record.differsFromDefault = ( () => {
					const base = baselines[ screen.slug ];
					if ( ! base || ! record.dom ) {
						return null;
					}
					return ! (
						base.htmlLength === record.dom.htmlLength &&
						base.controls === record.dom.controls &&
						base.notices.error === record.dom.notices.error &&
						base.notices.success === record.dom.notices.success &&
						base.emptyStates === record.dom.emptyStates &&
						base.disabledControls === record.dom.disabledControls
					);
				} )();

				if ( ! record.rendered ) {
					// Missing evidence, explicitly not a pass.
					record.axe = {
						scoped: null,
						fullPage: null,
						skipped: 'screen did not mount or painted nothing',
					};
				} else {
					record.axe = {
						scoped: await runAxe( page, MOUNT ),
						fullPage: compact( await runAxe( page, null ) ),
					};
				}
			} catch ( error ) {
				record.rendered = false;
				record.stateAchieved = false;
				record.error = error.message;
				record.axe = { scoped: null, fullPage: null };
			} finally {
				for ( const route of heldRoutes ) {
					await route.abort().catch( () => null );
				}
			}

			results.push( record );

			const v = record.axe?.scoped?.violations?.length ?? null;
			const imp = record.axe?.scoped?.violations?.filter(
				( x ) => x.impact === 'critical' || x.impact === 'serious'
			).length;
			process.stderr.write(
				`${ ( screen.slug + '/' + state ).padEnd( 26 ) } rendered=${
					record.rendered
				} state=${ record.stateAchieved } violations=${ v } serious+critical=${ imp }\n`
			);
		}
	}

	await browser.close();

	// ---- summary -------------------------------------------------------
	// Counted from the scoped (plugin-owned) context, because that is the
	// surface this branch is responsible for. Full-document numbers are kept
	// alongside so the scoping choice stays auditable.
	const IMPACTS = [ 'critical', 'serious', 'moderate', 'minor' ];

	const tally = ( runs ) => {
		const byImpact = Object.fromEntries( IMPACTS.map( ( i ) => [ i, 0 ] ) );
		const byRule = {};
		const runsAffected = [];
		let rendered = 0;
		let notRendered = 0;
		let statesAchieved = 0;

		for ( const run of runs ) {
			if ( ! run.rendered ) {
				notRendered++;
				continue;
			}
			rendered++;
			if ( run.stateAchieved ) {
				statesAchieved++;
			}
			if ( ! run.axe?.scoped ) {
				continue;
			}
			const hit = [];
			for ( const violation of run.axe.scoped.violations ) {
				byImpact[ violation.impact ] =
					( byImpact[ violation.impact ] ?? 0 ) + 1;
				hit.push( violation.id );
				byRule[ violation.id ] = byRule[ violation.id ] ?? {
					impact: violation.impact,
					help: violation.help,
					description: violation.description,
					tags: violation.tags,
					helpUrl: violation.helpUrl,
					runs: [],
					nodeCount: 0,
					nodes: [],
				};
				byRule[ violation.id ].runs.push(
					`${ run.screen }/${ run.state }`
				);
				byRule[ violation.id ].nodeCount += violation.nodes.length;
				for ( const node of violation.nodes ) {
					byRule[ violation.id ].nodes.push( {
						run: `${ run.screen }/${ run.state }`,
						target: node.target,
						failureSummary: node.failureSummary,
						html: node.html,
					} );
				}
			}
			if ( hit.length ) {
				runsAffected.push( {
					run: `${ run.screen }/${ run.state }`,
					rules: hit,
				} );
			}
		}
		return {
			runsRendered: rendered,
			runsNotRendered: notRendered,
			statesAchieved,
			violationsByImpact: byImpact,
			seriousOrCritical: byImpact.serious + byImpact.critical,
			runsAffected,
			byRule,
		};
	};

	const scoped = tally( results );

	const fullDoc = {
		violationsByImpact: Object.fromEntries( IMPACTS.map( ( i ) => [ i, 0 ] ) ),
		byRule: {},
		runsWithViolations: 0,
	};
	for ( const run of results ) {
		if ( ! run.rendered || ! run.axe?.fullPage ) {
			continue;
		}
		const hit = [];
		for ( const violation of run.axe.fullPage.violations ) {
			fullDoc.violationsByImpact[ violation.impact ] =
				( fullDoc.violationsByImpact[ violation.impact ] ?? 0 ) + 1;
			fullDoc.byRule[ violation.id ] =
				( fullDoc.byRule[ violation.id ] ?? 0 ) + 1;
			hit.push( violation.id );
		}
		if ( hit.length ) {
			fullDoc.runsWithViolations++;
		}
	}

	const identityAtStart = identity();
	const branch = identityAtStart.head;

	// A run is only attributable to one commit if the working tree stayed put.
	// This plugin directory is also the live site, so a concurrent branch
	// checkout by another agent changes the bundle mid-run.
	const identityAtEnd = identity();
	const distinctCommits = [
		...new Set( results.map( ( r ) => r.measured?.head ?? 'unknown' ) ),
	];
	const distinctBundles = [
		...new Set( results.map( ( r ) => r.measured?.bundle ?? 'unknown' ) ),
	];
	const branchStable =
		identityAtStart.head === identityAtEnd.head &&
		distinctCommits.length === 1 &&
		distinctBundles.length === 1;

	const artefact = {
		meta: {
			description:
				'axe-core results for the plugin admin SPA, per screen and per UI state.',
			generatedAt: startedAt,
			completedAt: new Date().toISOString(),
			gitBranch: process.env.WPPO_BRANCH || identityAtStart.branch,
			gitHead: branch,
			attribution: {
				branchStable,
				distinctCommitsMeasured: distinctCommits,
				distinctBundlesMeasured: distinctBundles,
				identityAtStart,
				identityAtEnd,
				note:
					'branchStable is false when the checked-out commit or the build/index.js fingerprint changed during the run. That means the numbers below span more than one bundle and must not be quoted as a single result. The plugin directory is the live site, so a concurrent checkout by another process changes what the browser loads.',
			},
			site: SITE,
			tooling: {
				'@axe-core/playwright': pkgVersion( '@axe-core/playwright' ),
				'axe-core': pkgVersion( 'axe-core' ),
				playwright: pkgVersion( 'playwright' ),
			},
			axeConfiguration: {
				rulesDisabled: [],
				rulesExcluded: [],
				tagsFilter: null,
				note:
					'axe-core default rule set. No disableRules(), no withTags(), no exclude(). The plugin mount point is used as the axe context for the "scoped" run; the full document is measured separately as "fullPage". Context scoping is not a rule exclusion.',
			},
			contexts: {
				scoped: `${ MOUNT } -- the React SPA this plugin owns`,
				fullPage:
					'entire document, including WordPress core admin chrome this branch never touched',
			},
			states: {
				default: 'real API responses',
				loading:
					'API requests held open and never fulfilled, so the app renders its own loading state',
				error: 'API returns 500, so the app renders its own error notice',
				empty:
					'list-returning API endpoints return empty collections, so the app renders its own empty state',
				disabled:
					'list-returning API endpoints return zero counts, so the app renders its own disabled controls through its own disabled={...} logic',
				success:
					'update_settings stubbed to succeed and Save Settings clicked, so the app renders its own success notice',
			},
			stateVerification: {
				note:
					'stateAchieved is the DOM marker for that state actually being present. States are screen-specific in this app: a blanket API stub does not produce an error notice on a screen with no error path, so runs where the marker was absent are recorded as false rather than counted as a measured state.',
				markers: {
					default: 'screen rendered',
					loading: 'visible role=status / aria-busy / loading placeholder',
					error: '>=1 .wppo-notice--error',
					empty: '>=1 visible .wppo-empty-state',
					disabled: '>=1 visible disabled control',
					success: '>=1 .wppo-notice--success',
				},
			},
			rawDataNote:
				'The "scoped" axe result for every run is stored verbatim and complete. The "fullPage" axe result keeps its violations and incomplete arrays verbatim but reduces passes/inapplicable to rule-id plus node-count lists, to keep the artefact committable. The scoped context -- the one the reported counts come from -- is unmodified raw axe output.',
			wcagNote:
				'This artefact records what axe-core reported and which rules ran. It is NOT a conformance claim. axe-core does not test every WCAG success criterion and cannot judge criteria such as 2.4.7 Focus Visible (AA) or 2.4.11 Focus Not Obscured (Minimum) (AA) reliably in every context; 2.4.13 Focus Appearance is AAA, not AA.',
			tmpFreeMbBeforeRun: tmpFreeMb,
		},
		summary: {
			screens: SELECTED_SCREENS.length,
			states: STATES.length,
			runs: results.length,
			scoped,
			fullPage: fullDoc,
		},
		results,
	};

	const outDir = path.join( __dirname, '..', 'evidence', 'a11y' );
	fs.mkdirSync( outDir, { recursive: true } );
	const stamp = startedAt.slice( 0, 10 );
	const outFile = path.join(
		outDir,
		`axe-${ branch.slice( 0, 7 ) }-${ stamp }.json`
	);
	fs.writeFileSync( outFile, JSON.stringify( artefact, null, '\t' ) );

	const sizeMb = ( fs.statSync( outFile ).size / 1024 / 1024 ).toFixed( 2 );
	process.stderr.write(
		`\nArtefact: ${ path.relative( process.cwd(), outFile ) } (${ sizeMb } MB)\n` +
			`Scoped, serious+critical: ${ scoped.seriousOrCritical } across ` +
			`${ scoped.runsAffected.length } run(s)\n` +
			`Runs rendered: ${ scoped.runsRendered } / ${ results.length }` +
			`  |  states achieved: ${ scoped.statesAchieved }\n` +
			( branchStable
				? `Branch stable: yes (${ identityAtStart.head })\n`
				: `BRANCH UNSTABLE DURING RUN: commits=${ distinctCommits.join(
						','
				  ) } bundles=${ distinctBundles.length }. Do not quote these\n` +
						`counts as a single result; re-run on a quiet working tree.\n` )
	);

	// Non-zero exit on a genuine serious/critical finding, so this works as a
	// gate. It is not written to green by suppression. A run whose commit moved
	// underneath it also fails: unattributable numbers are not a pass.
	process.exit( scoped.seriousOrCritical > 0 || ! branchStable ? 1 : 0 );
} )().catch( ( error ) => {
	process.stderr.write( 'FAILED: ' + error.stack + '\n' );
	process.exit( 2 );
} );
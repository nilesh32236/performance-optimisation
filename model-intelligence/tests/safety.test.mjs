import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { redactResult } from '../lib/redact.mjs';

const run = promisify(execFile);
const here = dirname(fileURLToPath(import.meta.url));
const root = dirname(dirname(here));
const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

test('redacts credential-shaped fields recursively', () => {
  const value = redactResult({ model_id: 'free-model', nested: { apiKey: 'secret', cookie: 'secret', authorization: 'secret', password: 'secret' }, safe: 'ok' });
  assert.deepEqual(value, { model_id: 'free-model', nested: { apiKey: '[REDACTED]', cookie: '[REDACTED]', authorization: '[REDACTED]', password: '[REDACTED]' }, safe: 'ok' });
});

test('champion configuration declares Muse Spark under a free-only policy', async () => {
  const config = JSON.parse(await readFile(new URL('../config/champion.json', import.meta.url)));
  assert.equal(config.policy, 'free-only');
  assert.equal(config.champion.model_ref, CHAMPION);
  assert.equal(config.promotion.minimum_samples >= 5, true);
});

// The check-config.mjs guard is the only executable proof that the workflows
// and AGENTS.md still carry the vars.OPENCODE_MODEL fallback and that no other
// opencode/<model> literal has appeared. The test below used to be named as if
// it covered that, but it only asserted three champion.json fields and never
// ran the guard — so the guarantee existed as prose alone.
test('check-config.mjs passes and reports Muse Spark as the sole fallback', async () => {
  const { stdout } = await run(process.execPath, [join(root, 'model-intelligence/check-config.mjs')], { cwd: root });
  const result = JSON.parse(stdout);
  assert.equal(result.ok, true, `model authority check failed: ${stdout}`);
  assert.equal(result.champion, CHAMPION);
  assert.equal(result.explicit_selection_preserved, true);
  assert.ok(result.occurrences > 0, 'the guard must actually inspect the declared fallback locations');
});

// Pins the wiring itself. The guard was written, passed, and had no caller at
// all, so nothing failed when the invariant lapsed; these assertions fail if the
// script is un-referenced again.
test('the model authority guard is wired into package scripts and CI', async () => {
  const pkg = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
  assert.equal(pkg.scripts['model:check'], 'node model-intelligence/check-config.mjs');

  const workflowDir = join(root, '.github/workflows');
  const files = (await readdir(workflowDir)).filter((name) => name.endsWith('.yml'));
  const callers = [];
  for (const name of files) {
    const text = await readFile(join(workflowDir, name), 'utf8');
    if (text.includes('model:check')) callers.push(name);
  }
  assert.ok(
    callers.includes('webpack.yml'),
    `webpack.yml must run the model authority check on pull requests; found callers: ${callers.join(', ') || 'none'}`
  );
});

// An independent review demonstrated two ways to neuter the guard while every
// other test stayed green: rewriting check-config.mjs to always print ok:true,
// and dropping a covered workflow from fallback-locations.json. Neither file's
// CONTENT was pinned — only the wiring.
//
// This case runs the REAL guard script against a temporary fixture containing a
// planted rogue model literal. If the script is gutted, or stops covering that
// file, the rogue literal is accepted and this test fails.
test( 'the guard rejects a rogue model literal planted in a covered file', async ( t ) => {
	const { mkdtemp, mkdir, writeFile, readFile, rm, cp } = await import(
		'node:fs/promises'
	);
	const { tmpdir } = await import( 'node:os' );
	const { join } = await import( 'node:path' );
	const fixture = await mkdtemp( join( tmpdir(), 'wppo-model-guard-' ) );
	t.after( async () => {
		await rm( fixture, { recursive: true, force: true } );
	} );

	// check-config.mjs resolves its root as dirname(dirname(script)), so laying
	// out model-intelligence/{check-config.mjs,config/} plus the covered files
	// gives the guard a complete, self-contained world to scan.
	await mkdir( join( fixture, 'model-intelligence', 'config' ), {
		recursive: true,
	} );
	await mkdir( join( fixture, 'model-intelligence', 'registry' ), {
		recursive: true,
	} );
	await mkdir( join( fixture, '.github', 'workflows' ), { recursive: true } );
	await cp(
		join( root, 'model-intelligence', 'check-config.mjs' ),
		join( fixture, 'model-intelligence', 'check-config.mjs' )
	);
	for ( const file of [
		'config/champion.json',
		'config/fallback-locations.json',
		'registry/free-models.json',
	] ) {
		await writeFile(
			join( fixture, 'model-intelligence', file ),
			await readFile( join( root, 'model-intelligence', file ) )
		);
	}
	// Copy every covered path, then plant the rogue literal in one of them.
	const locations = JSON.parse(
		await readFile(
			join( fixture, 'model-intelligence', 'config', 'fallback-locations.json' ),
			'utf8'
		)
	).fallback_locations;
	for ( const location of locations ) {
		const dest = join( fixture, location.path );
		await mkdir( dest.replace( /[^/]+$/, '' ), { recursive: true } );
		try {
			await writeFile( dest, await readFile( join( root, location.path ) ) );
		} catch {
			await writeFile( dest, '' );
		}
	}
	const victim = join( fixture, '.github', 'workflows', 'wppo-ai-review.yml' );
	await writeFile(
		victim,
		'          model: ${{ vars.OPENCODE_MODEL || \'opencode/attacker-model\' }}\n'
	);

	let rejected = null;
	try {
		await run(
			process.execPath,
			[ join( fixture, 'model-intelligence', 'check-config.mjs' ) ],
			{ cwd: fixture }
		);
	} catch ( error ) {
		rejected = error;
	}

	assert.notEqual(
		rejected,
		null,
		'a gutted check-config.mjs, or one that stopped covering this file, would let the rogue literal through silently'
	);
	// Prove it failed for the RIGHT reason: it must name the rogue model.
	const diagnostic = `${ rejected.stdout || '' }${ rejected.stderr || '' }${ rejected.message || '' }`;
	assert.match(
		diagnostic,
		/attacker-model/,
		'the guard must reject because of the planted rogue literal, not an unrelated crash'
	);
} );

// The second neutering vector: shrinking the coverage manifest. Pin the exact
// set of covered paths so a workflow cannot be dropped from the scan quietly.
test( 'every workflow holding an opencode literal is listed in the coverage manifest', async () => {
	const { readdir, readFile: read } = await import( 'node:fs/promises' );
	const repo = root;
	const manifest = JSON.parse(
		await read( join( repo, 'model-intelligence', 'config', 'fallback-locations.json' ), 'utf8' )
	);
	const covered = new Set( manifest.fallback_locations.map( ( l ) => l.path ) );

	const dir = join( repo, '.github', 'workflows' );
	const files = ( await readdir( dir ) ).filter( ( n ) => n.endsWith( '.yml' ) );
	const missing = [];
	for ( const name of files ) {
		const rel = `.github/workflows/${ name }`;
		if ( covered.has( rel ) ) {
			continue;
		}
		const text = await read( join( dir, name ), 'utf8' );
		// Any real model reference, not a prose mention in a comment.
		if ( /opencode\/[A-Za-z0-9._-]+/.test( text ) ) {
			missing.push( rel );
		}
	}
	assert.deepEqual(
		missing,
		[],
		'these workflows hold an opencode/<model> literal but are not covered by check-config.mjs. Add them to model-intelligence/config/fallback-locations.json or their drift is invisible.'
	);
} );

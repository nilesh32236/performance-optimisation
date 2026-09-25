# WordPress documentation source

`docs/site/` contains the maintained, editor-facing prose pages for the Performance Optimisation documentation tree. The pages lead with the user task, safe default, enablement, verification, compatibility, and rollback before the technical reference. The fragments are classic HTML so the Boltfolio docs pipeline can convert them into native Gutenberg blocks while preserving headings, tables, code, and callouts.

## Publishing flow

1. Regenerate the source-derived API reference from the theme root with `php wp-content/themes/boltfolio/tools/generate-api-reference.php --out=/tmp/performance-docs/api`.
2. Convert generated and prose fragments to Gutenberg blocks with the theme's `html-to-blocks.mjs` tool.
3. Merge the generated manifest with this directory's `manifest.json` into a staging bundle.
4. Run `php wp-content/themes/boltfolio/tools/sync-plugin-docs.php --bundle=... --product=performance-optimisation`.
5. Verify the public routes and the WordPress block editor before publishing a source change.

The importer matches product, slug, and parent, updates existing pages in place, and does not read connector credentials or unrelated options. The generated reference intentionally scans the complete recursive `includes/` tree so moved classes cannot silently disappear from the published reference.

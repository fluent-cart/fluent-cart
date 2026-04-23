# WordPress Gutenberg Block Reference (External)

> Trimmed reference from `vapvarun/claude-backup/wp-gutenberg-blocks` skill.
> Only includes patterns NOT already covered in FluentCart's own CLAUDE.md or codebase.

## apiVersion 3 Compliance

WordPress 6.9+ enforces `apiVersion: 3` in block schemas for compatibility with the iframed editor (WordPress 7.0+). FluentCart already uses apiVersion 3 for all blocks.

## Safe Update Rules

- **Never** change a block's registered name after release
- **Never** change saved markup without adding a deprecation entry
- **Never** change attribute serialization without a migration path
- Always add `deprecated` array when modifying `save()` output

## Block Model Selection

| Model | When to Use | FluentCart Pattern |
|---|---|---|
| Dynamic (`render` callback) | Server-rendered content | **Default** — ~90% of FC blocks |
| Static (`save()`) | Simple, no server data | Rare in FC |
| Interactive (`viewScriptModule`) | Client-side interactivity | Not used in FC |

## Verification Checklist (Standard WP)

- Block appears in inserter and inserts successfully
- Saving/reloading does not trigger "Invalid block" errors
- Frontend output matches expectations
- Assets load in correct contexts (editor vs. frontend)

## Common Issues

| Problem | Cause | Fix |
|---|---|---|
| "Invalid block content" | Missing deprecation or markup change | Add deprecation entry |
| Attributes not saving | Missing source definition | Check attribute serialization |
| Block absent from inserter | Registration failure | Check PHP error log |
| Styles missing in editor | Incomplete style references | Verify `getStyles()` return |

## Official Resources

- [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [Block API Reference](https://developer.wordpress.org/block-editor/reference-guides/block-api/)
- [Gutenberg Storybook](https://wordpress.github.io/gutenberg/)
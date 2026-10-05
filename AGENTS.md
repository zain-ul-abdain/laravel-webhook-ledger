# AGENTS.md

Instructions for any AI coding agent working here (Claude Code, Codex, Cursor, Gemini CLI, Copilot).

## What this is
Exactly-once webhook processing for Laravel: signature verification, atomic deduplication via a DB unique constraint on (provider, event_id), and a durable event log.

## Rules
- Public MIT package on Packagist — every commit is public. No secrets, client names or personal paths.
- Namespace `Zain\WebhookLedger\` (PSR-4 → `src/`). Supports PHP ^8.2 and Laravel 12 | 13; keep both green.
- Tests: `vendor/bin/pest`, or per database: `docker compose run --rm test` (sqlite), `test-pgsql`, `test-mysql`. Run all three before claiming a DB-related fix works.
- Style: Laravel Pint. Keep the public API backward compatible within a major version; new behaviour behind config.
- Releases are git tags (vX.Y.Z) — Packagist reads tags; there is no `version` field in composer.json. Update README claims (test counts, supported versions) in the same change.
- Commit or tag only when the owner asks.

## Integration suite
- A consumer-side suite (fresh Laravel app + `composer require` from Packagist) lives outside this repo in `../ledger-integration-test`. Keep the README's integration test count in sync with it.

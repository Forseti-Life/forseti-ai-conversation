<!-- AUTOMATION VALIDATION: 2026-04-23 - automation of development confirmed for this repo -->
# AI Conversation Module (Shared)

This repository is the **documentation-facing shared-module stub** for `ai_conversation`.

It currently does **not** ship the Drupal module source tree. Instead, it documents where the canonical implementation lives in the active Forseti workspace and what capabilities that implementation currently supports.

## Repository reality

- **Tracked files in this repo:** `README.md` only
- **Purpose:** shared-module documentation and coordination pointer
- **Current state:** no standalone module code is mirrored here yet

## Canonical implementation locations

The active `ai_conversation` implementation currently lives in the broader Forseti workspace:

- **Canonical implementation:** `forseti.life/sites/forseti/web/modules/custom/ai_conversation`
- **Job Hunter consumer path:** `forseti-job-hunter/web/modules/custom/ai_conversation`
- **DungeonCrawler consumer path:** `dungeoncrawler-pf2e/web/modules/custom/ai_conversation`

When this shared module changes, the source of truth is the live implementation in the workspace above, not this repository.

## Current capabilities

The active implementation supports:

1. **AWS Bedrock** for primary hosted model execution
2. **Ollama** for self-hosted/local LLM execution via provider settings
3. **Rolling conversation summaries** to keep long chats within context limits
4. **Drupal node-based conversation storage** for persistent chat history
5. **Provider-level configuration** for Bedrock and Ollama runtime selection

## Documentation scope for this repository

This repository should document:

- what the shared module is
- where the canonical implementation lives
- which runtimes/providers are currently supported
- which product/workspace copies consume the module

This repository should **not** claim to contain:

- the full module source tree
- test files that are not actually tracked here
- release/status snapshots that are no longer valid

## Documentation hygiene result

For the current pilot pass:

- authoritative documentation file reviewed: `README.md`
- redundant stale status Markdown files found: **none**

## If this repo is expanded later

If the shared module is promoted into a true standalone source repository, update this README to include:

- actual file layout
- real installation steps for the exported package
- test commands that exist in the standalone repo
- release/versioning policy for keeping downstream copies in sync

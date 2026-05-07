# Changelog

All notable changes to `lyre/ai-agents` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-05-06

### Added
- Prompt template inheritance via a new `extends_template_id` foreign key on `ai_agents_prompt_templates`. Templates compose root → leaf and substitute variables at the leaf. Depth-capped (default 3) and cycle-safe; on detection the resolver logs a warning and falls back to the leaf alone.
- Multi-variable prompt resolution. The resolver now substitutes any `{{variable}}` token, not only `{{assistant_name}}`. Variables are merged from (lowest-to-highest priority): each template's `variables` JSON, system defaults (`assistant_name`, `agent_id`, `model`), `agent.metadata.template_variables`, and a caller-supplied map.
- `Lyre\AiAgents\Contracts\PromptSectionContributor` — a tagged interface (tag: `lyre.prompt_section_contributors`) for host apps to inject extra sections into the resolved prompt without forking the resolver.
- `Lyre\AiAgents\Exceptions\PromptCompositionException` for inheritance failures.
- `AgentRunner` now forwards OpenAI Responses API `text.format` from `agent.metadata.response_format`. Existing agents without it are unaffected.
- `AgentKnowledgeService::ensureHandoverTool($agent, $endpoint)` and `ensureHandoverToolForAllAgents($endpoint)` — register a generic `request_human_handover` tool whose handler is a host-configured webhook.
- New config keys under `prompts` (`max_inheritance_depth`, `section_separator`) and `tools` (`lead.*`, `handover.*`).

### Changed
- The lead-collection tool is now config-driven (`config('ai-agents.tools.lead.tool_name')`, default `submit_lead`). When `ensureLeadCollectionTool` runs, any existing `submit_lead_to_axis` row on the agent is migrated idempotently to the canonical name and removed from `agent.tools`.
- Tool registration metadata for built-in tools now uses `managed_by: 'lyre.ai_agents'` and `lyre_builtin_tool` instead of the old `axis`-specific markers.

### Backwards compatibility
- All changes are additive at the schema and API level. Agents without `extends_template_id`, without `metadata.response_format`, and without contributors registered behave exactly as in 1.0.x.
- The lead-tool rename is automatic on next sync and does not require manual data migration.

### Notes
- A proper PHPUnit + Orchestra Testbench suite for the new resolver / runner code paths is tracked as a follow-up — this release is verified via host-app smoke tests only.

## [1.0.2] - prior

Initial baseline (no changelog kept before this release).

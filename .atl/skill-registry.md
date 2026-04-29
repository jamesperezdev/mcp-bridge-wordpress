# Skill Registry

**Project**: mcp-bridge-wordpress
**Detected**: 2026-04-28
**Mode**: openspec

## Project Context

This is a WordPress MCP bridge plugin written in PHP. It provides REST API endpoints for controlling WordPress from Claude MCP, with WP-CLI fallback when available.

## Available Skills

### SDD (Spec-Driven Development) Skills

| Skill | Source | Purpose |
|-------|--------|---------|
| sdd-init | claude | Initialize SDD context in a project |
| sdd-explore | claude | Explore and investigate ideas before committing |
| sdd-propose | claude | Create change proposals with intent and scope |
| sdd-spec | claude | Write specifications with requirements/scenarios |
| sdd-design | claude | Create technical design documents |
| sdd-tasks | claude | Break down changes into implementation tasks |
| sdd-apply | claude | Implement tasks from changes |
| sdd-verify | claude | Validate implementation against specs |
| sdd-archive | claude | Sync delta specs and archive completed changes |
| sdd-onboard | claude | Guided end-to-end SDD workflow walkthrough |

### Supporting Skills

| Skill | Source | Purpose |
|-------|--------|---------|
| judgment-day | claude/opencode | Parallel adversarial review protocol |
| branch-pr | claude/opencode | PR creation workflow |
| issue-creation | claude/opencode | Issue creation workflow |
| skill-creator | claude/opencode | Create new AI agent skills |
| skill-registry | claude/opencode | Create/update skill registry |
| go-testing | claude/opencode | Go testing patterns (not applicable) |

### Project-Level Skills

None detected.

## Project Conventions

- **WordPress Coding Standards**: Single PHP class (`WP_MCP_Bridge`) with procedural-style helper methods
- **REST API Patterns**: WordPress REST API with `register_rest_route()`, `WP_REST_Request`, `WP_REST_Response`
- **Authentication**: X-MCP-Secret header + admin capability check
- **Error Handling**: `WP_Error` objects with status codes
- **Fallback Strategy**: WP-CLI preferred, PHP fallback for core operations
- **No formal test suite** — manual verification only

## Quality Tools

| Tool | Available |
|------|-----------|
| Linter (PHP CodeSniffer) | ❌ Not found |
| Type Checker (PHPStan) | ❌ Not found |
| Formatter (PHP-CS-Fixer) | ❌ Not found |
| Test Runner (PHPUnit) | ❌ Not found |

## Next Steps

- `/sdd-explore <topic>` — investigate an idea
- `/sdd-propose <change-name>` — create a change proposal
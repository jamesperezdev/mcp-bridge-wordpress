# Proposal: divi-mcp-engine

## Intent

Extend `wp-mcp-bridge.php` with a Divi page-building engine that transforms natural language prompts into complete Divi shortcodes, enabling autonomous WordPress page construction from MCP clients (e.g., Claude).

**Why**: WordPress site management via MCP currently requires manual page building. This change enables AI-driven page generation, reducing manual effort from hours to seconds.

## Scope

### In Scope
- 4-layer internal engine (NLP → Layout → Divi → CRO)
- 4 new REST endpoints (page creation, theme settings, optimize, analyze)
- Divi shortcode generation for all major module types
- Design system presets (modern, corporate, minimal, bold)
- CRO/UX optimization engine with before/after scoring
- Full backward compatibility with existing endpoints

### Out of Scope
- Visual page editor integration (Divi Builder plugin required but not installed)
- Gutenberg block generation
- Theme option presets beyond Divi
- Multi-language / i18n support
- A/B testing infrastructure

## Capabilities

### New Capabilities
- `divi-page-creation`: Generate complete Divi-powered pages from natural language prompts
- `divi-theme-control`: Update Divi global theme settings via API
- `divi-page-optimization`: Apply CRO/SEO/performance optimizations to existing pages
- `divi-page-analysis`: Score existing pages on UX, SEO, and conversion metrics

### Modified Capabilities
- None (existing endpoints unchanged)

## Approach

**Single-file extension** to `wp-mcp-bridge.php`. Add `WP_MCP_Divi_Engine` class with 4 sub-engines:

| Engine | Responsibility |
|--------|---------------|
| `WP_MCP_NLP_Interpreter` | Parse natural language → structured layout spec |
| `WP_MCP_Divi_Generator` | Convert layout spec → Divi shortcodes |
| `WP_MCP_Design_System` | Apply color palettes, typography, spacing |
| `WP_MCP_CRO_Engine` | Score and optimize for conversion |

**NLP Prompt Parsing** (for `"landing moderna con hero impactante, 3 servicios con iconos, testimonios y formulario de contacto"`):

```
INTENT   → "landing"         (page type)
STYLE    → "moderna"         (design preset)
HERO     → "impactante"      (full-width, bold typography, CTA)
SECTIONS → [
    { type: "services", count: 3, icons: true },
    { type: "testimonials", layout: "carousel" },
    { type: "contact_form" }
]
```

**Output**: Array of section objects with module types, content, and visual params → passed to Divi Generator.

**REST Endpoints**:

| Method | Endpoint | Callback | Purpose |
|--------|----------|----------|---------|
| POST | `/divi/page` | `create_divi_page` | Create page from prompt |
| POST | `/divi/theme-settings` | `update_divi_theme_settings` | Update global Divi settings |
| POST | `/divi/page/optimize` | `optimize_divi_page` | Optimize existing page |
| POST | `/divi/page/analyze` | `analyze_divi_page` | Score page metrics |

**Authentication**: Existing `check_permission()` (X-MCP-Secret + admin capability).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `wp-mcp-bridge.php` | Modified | Add `WP_MCP_Divi_Engine` class + 4 sub-engines, register 4 routes |
| `openspec/specs/` | New | 4 new capability specs (divi-page-creation, divi-theme-control, divi-page-optimization, divi-page-analysis) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Divi not active on target site | Med | Return clear error: "Divi not active — please activate theme first" |
| Complex prompts generate invalid shortcodes | Med | Validate generated shortcodes before insert; fallback to simpler layout |
| NLP parsing ambiguity | Low | Log parsed structure to debug log for refinement |

## Rollback Plan

1. Remove `WP_MCP_Divi_Engine` class from `wp-mcp-bridge.php`
2. Remove the 4 `register_rest_route` calls for `/divi/*` endpoints
3. File restored to pre-change state — no data migration needed

## Dependencies

- Divi theme (elegant-themes/divi) installed and active on target WordPress
- WordPress 5.6+ with REST API enabled
- Existing auth mechanism (X-MCP-Secret header)

## Success Criteria

- [ ] `POST /divi/page` with prompt creates a published WordPress page with valid Divi shortcodes
- [ ] Generated page renders correctly in Divi builder preview
- [ ] `POST /divi/page/analyze` returns scores for UX, SEO, performance (0-100 scale)
- [ ] `POST /divi/theme-settings` successfully updates Divi global options
- [ ] `POST /divi/page/optimize` modifies existing page and returns before/after scores
- [ ] All 4 endpoints use existing `check_permission()` auth
- [ ] No breaking changes to existing `/health`, `/plugins/*`, `/themes/*` endpoints
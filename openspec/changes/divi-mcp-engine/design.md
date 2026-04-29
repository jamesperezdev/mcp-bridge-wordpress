# Design: divi-mcp-engine

## Technical Approach

Extend `wp-mcp-bridge.php` with a single new class `WP_MCP_Divi_Engine` containing 4 sub-engines. The class registers 4 REST endpoints and provides methods that transform natural language prompts into Divi shortcodes, update theme settings, optimize and analyze pages.

**Key principle**: Follow existing code patterns — single class with public REST callbacks and private helper methods. No additional PHP files.

## Architecture Decisions

### Decision: 4-Layer Engine Architecture

**Choice**: Separate the engine into 4 distinct layers (NLP Interpreter → Divi Generator → Design System → CRO Engine) rather than a monolithic class.

**Alternatives considered**:
- Single class with all logic in one file (rejected: too large, harder to test)
- Separate files per engine (rejected: constraint says single-file extension to wp-mcp-bridge.php)

**Rationale**: Clean separation of concerns while maintaining single-file constraint. Each engine has a single responsibility.

### Decision: Pattern-Matched NLP Parsing

**Choice**: Regex + keyword matching for prompt interpretation (no external NLP library dependency).

**Alternatives considered**:
- External AI/NLP API (rejected: adds external dependency, latency, cost)
- Full parser combinator (rejected: over-engineering for MVP)

**Rationale**: WordPress/PHP environment — external API calls introduce failure modes. Keyword matching covers 90% of use cases with zero dependencies.

### Decision: Shortcode Validation Before Insert

**Choice**: Validate generated Divi shortcodes syntax before inserting into post_content.

**Alternatives considered**:
- Insert first, validate after (rejected: could corrupt posts)
- No validation (rejected: malformed shortcodes break page rendering)

**Rationale**: Safety-first. Fallback to minimal valid shortcode on parse failure.

### Decision: Settings Allowlist for Divi Theme Control

**Choice**: Hardcoded allowlist of Divi setting keys that may be updated via API.

**Alternatives considered**:
- Allow any et_divi key (rejected: security risk — could set unexpected values)
- Dynamic schema from Divi (rejected: Divi doesn't expose a public API for this)

**Rationale**: Security. Only known-safe settings can be modified remotely.

## Data Flow

```
┌─────────────────────────────────────────────────────────┐
│  MCP Client (Claude)                                     │
│  POST /divi/page {"prompt": "...", "title": "..."}      │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│  WP_MCP_Divi_Engine::create_divi_page()                  │
│  - check_permission() via parent class                   │
│  - is_divi_active() check                                │
└────────────────────┬────────────────────────────────────┘
                     │
          ┌──────────┴──────────┐
          ▼                     ▼
┌─────────────────┐   ┌─────────────────────┐
│ NLP Interpreter │   │  Divi Generator     │
│ parse_prompt()  │──▶│  generate_shortcodes()│
│ Returns:        │   │  Returns: string     │
│ $layout_spec    │   │  $shortcodes        │
└─────────────────┘   └──────────┬──────────┘
                                │
                     ┌──────────┴──────────┐
                     ▼                       ▼
          ┌─────────────────┐    ┌────────────────────┐
          │Design System    │    │  CRO Engine        │
          │apply_presets()  │    │  (used by optimize/│
          │Returns: $ctx    │    │   analyze)         │
          └─────────────────┘    └────────────────────┘
                     │                       │
                     └───────────┬───────────┘
                                 ▼
          ┌─────────────────────────────────────────┐
          │ wp_insert_post() with shortcodes         │
          │ Returns: {page_id, post_url}            │
          └─────────────────────────────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `wp-mcp-bridge.php` | Modify | Add WP_MCP_Divi_Engine class (~500 lines), register 4 REST routes |

## Class Structure

```php
class WP_MCP_Divi_Engine {

    // Sub-engines
    private $nlp;        // WP_MCP_NLP_Interpreter
    private $divi;       // WP_MCP_Divi_Generator
    private $design;     // WP_MCP_Design_System
    private $cro;        // WP_MCP_CRO_Engine

    // REST Callbacks
    public function create_divi_page( WP_REST_Request $req ): WP_REST_Response {}
    public function update_divi_theme_settings( WP_REST_Request $req ): WP_REST_Response {}
    public function optimize_divi_page( WP_REST_Request $req ): WP_REST_Response {}
    public function analyze_divi_page( WP_REST_Request $req ): WP_REST_Response {}

    // Shared helpers
    private function is_divi_active(): bool {}
    private function parse_prompt( string $prompt ): array {}
    private function validate_shortcodes( string $shortcodes ): bool {}
}
```

### Sub-Engine: WP_MCP_NLP_Interpreter

```php
class WP_MCP_NLP_Interpreter {
    public function parse( string $prompt ): array {
        // Returns: [
        //   'page_type' => 'landing',
        //   'style'     => 'modern',
        //   'sections'  => [
        //     ['type' => 'hero', 'fullwidth' => true, ...],
        //     ['type' => 'services', 'count' => 3, 'icons' => true, ...],
        //   ]
        // ]
    }
}
```

**Pattern matching rules**:
- Page types: landing | about | contact | pricing | services | portfolio → `page_type`
- Styles: moderna | corporativa | minimal | bold | elegant → `style`
- Keywords: hero, servicios, testimonios, formulario → section types

### Sub-Engine: WP_MCP_Divi_Generator

Generates shortcodes for: hero, services, testimonials, pricing tiers, FAQ, CTA, contact form, about, footer.

**Design presets** (from Design System):
- `modern`: Primary #0d6efd, white text, Inter font
- `corporate`: Primary #1a1a2e, white text, Roboto font
- `minimal`: Primary #333, white text, Open Sans font
- `bold`: Primary #e63946, white text, Montserrat font

### Sub-Engine: WP_MCP_Design_System

Holds color palettes, typography, spacing defaults. Methods:
- `get_preset( string $name ): array`
- `apply_preset( array $context, string $preset ): array`

### Sub-Engine: WP_MCP_CRO_Engine

Scoring dimensions:
- **UX** (0-100): CTA count, navigation clarity, spacing ratio, button density
- **SEO** (0-100): H1 count, heading hierarchy, image alt coverage, content length
- **Performance** (0-100): image count estimate, lazy-load presence, external resources

## REST Endpoint Contracts

### POST /divi/page
**Request**: `{"prompt": "string", "title": "string", "style": "string (optional)"}`
**Response**: `{"success": true, "page_id": int, "url": "string"}`

### POST /divi/theme-settings
**Request**: `{"setting": "string", "value": "mixed"}` OR `{"settings": [{"setting":"string","value":"mixed"},...]}`
**Response**: `{"success": true, "setting": "string", "value": "mixed"}` or `{"success": true, "updated": int}`

### POST /divi/page/optimize
**Request**: `{"post_id": int, "optimization_targets": ["speed","seo","accessibility"], "preview": bool}`
**Response**: `{"success": true, "speed_score_before": int, "speed_score_after": int, ...}`

### POST /divi/page/analyze
**Request**: `{"post_id": int, "force_refresh": bool}`
**Response**: `{"success": true, "scores": {"ux": int, "seo": int, "performance": int, "overall": int}, "issues": [...]}`

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | NLP interpreter patterns | Mock prompts, assert parsed structure |
| Unit | Shortcode generation | Assert valid shortcode string output |
| Unit | Score calculation | Feed known content, assert expected scores |
| Integration | Full page creation flow | POST /divi/page, verify post created |
| Integration | Settings update | POST /divi/theme-settings, verify wp_options updated |

**No E2E**: WordPress plugin with no test infrastructure.

## Migration / Rollback

**No migration required** — adds new functionality only.

**Rollback**:
1. Remove `WP_MCP_Divi_Engine` class block (~500 lines) from wp-mcp-bridge.php
2. Remove the 4 `register_rest_route` calls for `/divi/*`
3. No data loss — existing posts remain intact

## Open Questions

- [ ] Should we support additional languages beyond English/Spanish for NLP?
- [ ] Divi library load timing — should `is_divi_active()` check be memoized?
- [ ] CRO score thresholds — are the 80/50/0 boundaries correct for production use?
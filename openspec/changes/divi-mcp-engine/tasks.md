# Tasks: divi-mcp-engine

## Phase 1: Infrastructure (Foundation)

- [x] 1.1 Add `WP_MCP_Divi_Engine` class skeleton to `wp-mcp-bridge.php` after `WP_MCP_Bridge` class (line 506+)
- [x] 1.2 Add `private $nlp, $divi, $design, $cro` sub-engine properties with lazy initialization
- [x] 1.3 Register 4 REST routes in `WP_MCP_Bridge::register_routes()`: `/divi/page`, `/divi/theme-settings`, `/divi/page/optimize`, `/divi/page/analyze`
- [x] 1.4 Add `private function is_divi_active(): bool` helper using `get_template() === 'Divi'`

## Phase 2: Sub-Engines Implementation

- [x] 2.1 Implement `WP_MCP_NLP_Interpreter::parse(string $prompt): array` — pattern-match page type, style, sections from prompt keywords (English + Spanish)
- [x] 2.2 Implement `WP_MCP_Divi_Generator` — `generate_section(array $spec): string` for each section type (hero, services, testimonials, contact_form, pricing, faq, cta)
- [x] 2.3 Implement `WP_MCP_Divi_Generator::generate_page(array $layout_spec): string` — concatenate all sections into full shortcode string
- [x] 2.4 Implement `WP_MCP_Design_System::get_preset(string $name): array` — return color/font/spacing preset (modern, corporate, minimal, bold)
- [x] 2.5 Implement `WP_MCP_Design_System::apply_preset(array $context, string $preset): array` — inject preset values into layout context
- [x] 2.6 Implement `WP_MCP_CRO_Engine::calculate_scores(string $content): array` — return ux/seo/performance scores (0-100)
- [x] 2.7 Implement `WP_MCP_CRO_Engine::apply_optimizations(int $post_id, array $targets): array` — modify post_content, return before/after scores

## Phase 3: REST Callbacks

- [x] 3.1 Implement `create_divi_page(WP_REST_Request $req): WP_REST_Response` — validate input, call NLP → Divi Generator → validate → wp_insert_post
- [x] 3.2 Implement `update_divi_theme_settings(WP_REST_Request $req): WP_REST_Response` — handle single setting or batch, validate against allowlist
- [x] 3.3 Implement `optimize_divi_page(WP_REST_Request $req): WP_REST_Response` — handle preview mode, apply optimizations, log to post meta
- [x] 3.4 Implement `analyze_divi_page(WP_REST_Request $req): WP_REST_Response` — check Divi shortcodes, calculate scores, handle cache or force_refresh

## Phase 4: Validation & Helpers

- [x] 4.1 Implement `validate_shortcodes(string $shortcodes): bool` — check all opening tags have closing tags
- [x] 4.2 Add fallback shortcode generation on invalid parse (returns minimal valid Divi page)
- [x] 4.3 Add allowlist constant for Divi theme settings keys (et_divi[accent_color], et_divi[body_font], etc.)

## Phase 5: Verification

- [x] 5.1 Verify all 4 endpoints use existing `check_permission()` authentication
- [x] 5.2 Verify existing endpoints (`/health`, `/plugins/*`, `/themes/*`) unaffected
- [x] 5.3 Test NLP with Spanish prompt: "landing moderna con hero impactante, 3 servicios con iconos, testimonios y formulario de contacto"
- [x] 5.4 Test NLP with English prompt: "pricing page with bold header, 4 pricing tiers, faq section, and call to action"
- [x] 5.5 Test page creation flow end-to-end with Divi active site
- [x] 5.6 Test Divi not active error case returns HTTP 400
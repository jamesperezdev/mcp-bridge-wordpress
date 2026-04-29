# divi-page-analysis Specification

## Purpose

Provide diagnostic scoring for existing Divi pages across UX quality, SEO completeness, and performance metrics. The system parses page content, evaluates against defined benchmarks, and returns actionable scores from 0-100 with improvement suggestions.

## Requirements

### Requirement: Page Analysis Endpoint

The system SHALL expose POST /divi/page/analyze which accepts a post_id and returns a comprehensive scoring report across multiple dimensions.

#### Scenario: Complete page analysis

- GIVEN the request body contains {"post_id":123}
- WHEN the page exists and contains Divi shortcodes
- THEN the system SHALL return {success:true, post_id:123, scores:{overall:78, ux:82, seo:71, performance:81}, issues:[{severity:"warning", element:"missing_alt_images", suggestion:"Add descriptive alt text to all images"},{severity:"info", element:"heading_structure", suggestion:"Consider splitting H2 content into smaller sections"}], summary:"Page scores above average. Primary improvement opportunity: SEO meta elements."}

#### Scenario: Empty page

- GIVEN the request body contains {"post_id":124}
- WHEN the page has no content (empty post_content)
- THEN the system SHALL return {success:true, post_id:124, scores:{overall:0, ux:0, seo:0, performance:0}, issues:[{severity:"critical", element:"empty_content", suggestion:"Page has no content to analyze"}], summary:"Cannot analyze empty page."}

### Requirement: UX Score Calculation

The system SHALL calculate UX score based on: CTA presence, navigation flow, mobile responsiveness indicators, color contrast patterns, and interactive element density. Score MUST be 0-100.

#### Scenario: High UX score page

- GIVEN a page contains multiple CTAs, clear navigation sections, proper button spacing
- WHEN the UX scorer evaluates the page
- THEN it SHALL calculate a score >= 80 and return {ux_score:85, positive_signals:["multiple_ctas","clear_navigation","proper_spacing"]}

#### Scenario: Low UX score page

- GIVEN a page contains no CTA buttons and has dense unbroken text blocks
- WHEN the UX scorer evaluates the page
- THEN it SHALL calculate a score < 50 and return {ux_score:38, negative_signals:["no_cta_found","dense_text_blocks","missing_whitespace"]}

### Requirement: SEO Score Calculation

The system SHALL calculate SEO score based on: heading hierarchy (H1 count, H2-H6 distribution), image alt attribute coverage, internal/external link ratio, content length, and keyword density signals. Score MUST be 0-100.

#### Scenario: Well-optimized SEO page

- GIVEN a page has single H1, logical heading hierarchy, all images with alt text, sufficient content length
- WHEN the SEO scorer evaluates the page
- THEN it SHALL calculate a score >= 75 and return {seo_score:82, positive_signals:["single_h1","proper_hierarchy","all_alts_present","content_length_ok"]}

### Requirement: Performance Score Estimation

The system SHALL estimate performance score from: estimated page weight based on content analysis, presence of lazy-load attributes, CSS complexity indicators, and external resource requests. Score MUST be 0-100.

#### Scenario: Lightweight page

- GIVEN a page contains minimal images, lazy-load attributes present, no heavy external scripts
- WHEN the performance scorer estimates page load
- THEN it SHALL calculate a score >= 70 and return {performance_score:78, estimated_load_time:"1.8s", factors:["lazy_load_present","minimal_images","no_external_scripts"]}

#### Scenario: Heavy page

- GIVEN a page contains many large images without lazy-load and multiple external font requests
- WHEN the performance scorer estimates page load
- THEN it SHALL calculate a score < 50 and return {performance_score:34, estimated_load_time:"4.2s", factors:["no_lazy_load","large_images","multiple_external_fonts"]}

### Requirement: Divi Shortcode Detection

The system SHALL verify that the target post contains Divi shortcodes before performing analysis. Non-Divi pages MUST be rejected with a clear error.

#### Scenario: Non-Divi page

- GIVEN the request body contains {"post_id":125}
- WHEN the page post_content contains no et_pb_ shortcodes
- THEN the system SHALL return HTTP 400 with {success:false, error:"Post 125 does not appear to be a Divi page (no et_pb_ shortcodes found)"}

### Requirement: Analysis Cache

The system SHALL cache analysis results in a post meta with key _divi_analysis_cache for 24 hours. Subsequent requests within the cache window SHALL return cached results unless force_refresh=true is passed.

#### Scenario: Cached result returned

- GIVEN a page was analyzed within the last 24 hours with cached results existing
- WHEN a new analyze request arrives without force_refresh
- THEN the system SHALL return the cached result and include {cached:true, cache_age_hours:X}

#### Scenario: Force refresh requested

- GIVEN the request body contains {"post_id":123, "force_refresh":true}
- WHEN the system processes the request
- THEN it SHALL bypass the cache, perform fresh analysis, and update the cache
# divi-page-optimization Specification

## Purpose

Enable automated optimization of existing Divi pages for Conversion Rate Optimization (CRO), SEO, and performance. The system analyzes page content and applies transformations that improve page speed, accessibility, and conversion metrics, returning before/after scores.

## Requirements

### Requirement: Page Optimization Endpoint

The system SHALL expose POST /divi/page/optimize which accepts a post_id and optimization_targets array (e.g., ["speed", "seo", "accessibility"]). The endpoint MUST analyze the page content and apply targeted improvements.

#### Scenario: Speed optimization

- GIVEN the request body contains {"post_id":123, "optimization_targets":["speed"]}
- WHEN the page contains large unoptimized images or missing lazy-load attributes
- THEN the system SHALL add loading="lazy" to img tags, minify inline CSS, and return {success:true, optimizations_applied:["lazy_load_images","minify_css"], speed_score_before:45, speed_score_after:72}

#### Scenario: SEO optimization

- GIVEN the request body contains {"post_id":123, "optimization_targets":["seo"]}
- WHEN the page content has missing alt attributes on images or low heading hierarchy density
- THEN the system SHALL add alt attributes, ensure heading hierarchy (single H1, logical H2-H6 flow), and return {success:true, optimizations_applied:["add_missing_alts","heading_hierarchy"], seo_score_before:55, seo_score_after:81}

#### Scenario: Multi-target optimization

- GIVEN the request body contains {"post_id":123, "optimization_targets":["speed","seo","accessibility"]}
- WHEN the page requires all three types of optimization
- THEN the system SHALL apply all valid optimizations and return a map with before/after scores for each target

### Requirement: Optimization Preview

The system SHALL support a dry-run mode where optimization_targets include "preview":true, returning what WOULD be changed without applying them.

#### Scenario: Preview mode

- GIVEN the request body contains {"post_id":123, "optimization_targets":["speed"], "preview":true}
- WHEN the system processes the request in preview mode
- THEN it SHALL return {success:true, preview:true, proposed_changes:["lazy_load_images","minify_css"], estimated_speed_improvement:"+27 points"} without modifying the post

### Requirement: Optimization Logging

The system SHALL log all applied optimizations to post meta with key _divi_optimization_log, storing timestamp, target, before_score, after_score, and changes_applied as a JSON array.

#### Scenario: Optimization log creation

- GIVEN a successful optimization was applied to post 123
- WHEN the optimization completes
- THEN the system SHALL store in post_meta: {timestamp:"ISO8601", target:"speed", before_score:45, after_score:72, changes:["lazy_load_images","minify_css"]}

### Requirement: Invalid Post ID

The system SHALL return HTTP 400 when the provided post_id does not exist or is not a published Divi page.

#### Scenario: Non-existent post

- GIVEN the request body contains {"post_id":99999, "optimization_targets":["speed"]}
- WHEN the post with ID 99999 does not exist
- THEN the system SHALL return {success:false, error:"Post ID 99999 not found or not a Divi page"}
# divi-page-creation Specification

## Purpose

Enable autonomous generation of complete Divi-powered WordPress pages from natural language prompts via REST API. The NLP interpreter parses the user's intent and outputs a structured layout spec; the Divi generator converts that spec into WordPress shortcodes that render correctly in the Divi theme.

## Requirements

### Requirement: NLP Prompt Interpretation

The system SHALL parse natural language prompts and extract: page type (landing, about, contact), style descriptor (moderna, corporativa, minimal), and structural components (hero, services, testimonials, contact form, etc.). The parser MUST handle prompts in English and Spanish.

#### Scenario: Spanish landing page prompt

- GIVEN the Divi engine receives the prompt "landing moderna con hero impactante, 3 servicios con iconos, testimonios y formulario de contacto"
- WHEN the NLP interpreter processes the prompt
- THEN it SHALL output a structure with: page_type="landing", style="modern", sections=[{type:"hero", fullwidth:true}, {type:"services", count:3, icons:true}, {type:"testimonials", layout:"carousel"}, {type:"contact_form"}]

#### Scenario: English pricing page prompt

- GIVEN the Divi engine receives the prompt "pricing page with bold header, 4 pricing tiers, faq section, and call to action"
- WHEN the NLP interpreter processes the prompt
- THEN it SHALL output a structure with: page_type="pricing", style="bold", sections=[{type:"hero", centered:true}, {type:"pricing_tiers", count:4}, {type:"faq"}, {type:"cta"}]

#### Scenario: Ambiguous prompt

- GIVEN the Divi engine receives a prompt with no recognizable page type
- WHEN the NLP interpreter cannot determine page type
- THEN it SHALL default to "landing" page type with empty sections array and log the ambiguity

### Requirement: Divi Shortcode Generation

The system SHALL convert the structured layout spec into valid WordPress shortcodes using Divi module syntax. Each section MUST generate properly nested `[et_pb_section]`, `[et_pb_row]`, and module shortcodes.

#### Scenario: Hero section generation

- GIVEN the NLP interpreter outputs sections=[{type:"hero", fullwidth:true}]
- WHEN the Divi generator processes the hero section
- THEN it SHALL generate: et_pb_section with fullwidth=true, et_pb_row containing et_pb_text with heading and et_pb_button

#### Scenario: Services section with icons

- GIVEN the NLP interpreter outputs sections=[{type:"services", count:3, icons:true}]
- WHEN the Divi generator processes the services section
- THEN it SHALL generate 3 et_pb_row entries, each containing an et_pb_blurb with icon, title, and description

#### Scenario: Full page generation

- GIVEN the NLP interpreter outputs a complete layout spec with 4 sections
- WHEN the Divi generator processes all sections
- THEN it SHALL concatenate all shortcodes and wrap them in a single divi_container shortcode

### Requirement: Page Creation via REST

The system SHALL accept a prompt via POST /divi/page, generate the shortcodes, and create a published WordPress page. The endpoint MUST validate that Divi is active before proceeding.

#### Scenario: Successful page creation

- GIVEN the request body contains {"prompt": "landing moderna con hero impactante", "title": "Mi Landing"}
- WHEN the user has admin capability and Divi is active
- THEN the endpoint SHALL create a published post with type=page, title="Mi Landing", content=generated_shortcodes, and return {success:true, page_id:X, url:Y}

#### Scenario: Divi not active

- GIVEN the request body contains {"prompt": "landing moderna", "title": "Test"}
- WHEN Divi theme is not active on the target site
- THEN the endpoint SHALL return HTTP 400 with {success:false, error:"Divi not active — please activate Elegant Themes Divi first"}

#### Scenario: Missing prompt parameter

- GIVEN the request body is missing the "prompt" field
- WHEN the endpoint receives the request
- THEN it SHALL return HTTP 400 with {success:false, error:"Missing required parameter: prompt"}

### Requirement: Shortcode Validation

The system SHALL validate generated shortcodes for syntactic correctness before inserting into the post content. Malformed shortcodes MUST be rejected with a fallback to a minimal valid page.

#### Scenario: Valid shortcode output

- GIVEN the Divi generator produces shortcode output
- WHEN the validator checks the output
- THEN it SHALL confirm all required closing tags are present and return {valid:true}

#### Scenario: Invalid shortcode detection

- GIVEN the Divi generator produces an incomplete shortcode structure
- WHEN the validator detects missing closing tags
- THEN it SHALL log the error and return a minimal fallback: [et_pb_section][et_pb_row][et_pb_text]Contenido no disponible[/et_pb_text][/et_pb_row][/et_pb_section]
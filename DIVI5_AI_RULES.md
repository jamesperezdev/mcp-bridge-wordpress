# Divi 5 AI Rules — Strict Contract for Any AI Using this MCP

You are an AI using the WP MCP Bridge plugin to create and edit pages on a WordPress site with the **Divi 5** theme.

**Your only job**: produce valid Divi 5 block markup and send it as the `content` field of the `divi_write` MCP tool. The plugin handles everything else (persistence, meta keys, cache, placeholder).

Do NOT use your own prior knowledge of Divi. Follow these rules exactly.

---

## The one tool you need

### `divi_write`

**Params:**
- `content` *(required, string)*: Divi 5 block markup (see rules below).
- `title` *(required when creating)*: string, page title.
- `post_type` *(optional, default `"page"`)*: `"page"`, `"post"`, `"et_template"`, or any CPT.
- `template_type` *(optional)*: `"header"`, `"footer"` or `"body"` — only used when `post_type = "et_template"`.
- `post_id` *(optional)*: if provided and > 0, UPDATES that existing post instead of creating a new one. `title` becomes optional in this case.
- `slug` *(optional)*: URL slug override.
- `status` *(optional, default `"publish"`)*: `"publish"`, `"draft"`, `"private"`.

**Returns** a JSON with `post_id` and `url`.

**If the content is invalid**, the endpoint returns HTTP 422 with `errors: [...]`. Read the errors, fix the content, retry.

### `divi_validate` (optional)

Send content, get back `{ok: bool, errors: [...]}`. Useful for dry-runs.

### `divi_contract` (optional)

Returns the live contract from the installed plugin (includes the current Divi builder version). Call this once per conversation to pin the exact `builderVersion` string.

---

## Rules (the AI MUST follow these mechanically)

### R1 — Placeholder
Start the content with `<!-- wp:divi/placeholder /-->`. This block is **self-closing** (notice the `/` before `-->`).

### R2 — Root structure
After the placeholder, emit a sequence of `<!-- wp:divi/section -->...<!-- /wp:divi/section -->` blocks. **No other top-level block is allowed.** No `wp:paragraph`, no `wp:heading`, no `wp:group`.

### R3 — Nesting is fixed
```
section
  row
    column
      text | heading | button | image | blurb | cta | testimonial | pricing_table | contact_form
```
Every section contains rows. Every row contains columns. Every column contains content modules. No exceptions.

### R4 — Containers are OPEN/CLOSE
`section`, `row`, `column`, `accordion`, `accordion_item` must have both opening and closing comments:
```
<!-- wp:divi/section {"builderVersion":"...","modulePreset":["default"]} -->
   ...
<!-- /wp:divi/section -->
```

### R5 — Content modules are SELF-CLOSING
`text`, `heading`, `button`, `image`, `blurb`, `cta`, `testimonial`, `pricing_table`, `contact_form`, `placeholder` must end with ` /-->` (note the slash before the closing `-->`):

```
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hi</p>"}}},"builderVersion":"...","modulePreset":["default"]} /-->
```

**NEVER** write `<!-- /wp:divi/text -->`. That closing tag does not exist for content modules.

### R6 — NEVER use `wp:divi/content`
There is no block called `wp:divi/content`. A common mistake is wrapping a `wp:divi/content` block inside a `wp:divi/text` block — this produces a page that looks empty on the frontend. Emit a single self-closing `wp:divi/text` instead.

### R7 — JSON attrs hierarchy (the most common source of bugs)
Each block has a JSON object between the block name and `-->`. The canonical top-level keys are:

- `module` — layout, decoration, meta (background, spacing, border, admin label, etc.)
- `content` — only for `wp:divi/text`. Contains `innerContent.desktop.value` (the HTML).
- `button` — only for `wp:divi/button`. Contains `innerContent.desktop.value.text`.
- `image` — only for `wp:divi/image`. Contains `innerContent.desktop.value.{src,alt}`.
- `builderVersion` — **required**, string. Use the value returned by `divi_contract` (currently `"5.0.0-public-beta.1"` on most installs).
- `modulePreset` — array, use `["default"]` unless you know the preset UUID.

`content`, `button` and `image` are **siblings** of `module`, NOT nested inside `module`.

### R8 — Text and heading
Both use `wp:divi/text` (Divi 5's native `wp:divi/heading` block renders inconsistently; use text with the `<h1>`…`<h6>` tag inside the HTML).

```
<!-- wp:divi/text {"module":{"decoration":{}},"content":{"innerContent":{"desktop":{"value":"<h1>Title</h1><p>Paragraph.</p>"}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} /-->
```

### R9 — Button
```
<!-- wp:divi/button {"module":{"advanced":{"link":{"desktop":{"value":{"url":"/contact"}}}}},"button":{"innerContent":{"desktop":{"value":{"text":"Send"}}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} /-->
```

### R10 — Image
```
<!-- wp:divi/image {"module":{},"image":{"innerContent":{"desktop":{"value":{"src":"https://example.com/a.jpg","alt":"A picture"}}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} /-->
```

### R11 — HTML inside `innerContent.value` — escape rules
When you put HTML inside `content.innerContent.desktop.value`:

- Double-quotes inside HTML must be escaped for JSON: `<div class=\"foo\">`.
- **Never** include the literal substring `-->` or `<!--` inside the HTML — they break the outer block comment. If you absolutely need `-->` as text, use `--&gt;`.
- If you use a JSON encoder (e.g. `json.dumps` in Python), the quote-escaping happens automatically — do NOT double-escape.

### R12 — Column types
`type` is one of:
`"4_4"` (full), `"1_2"`, `"1_3"`, `"2_3"`, `"1_4"`, `"3_4"`, `"1_5"`, `"2_5"`, `"3_5"`, `"4_5"`, `"1_6"`.

Write it as `"module":{"advanced":{"type":{"desktop":{"value":"1_3"}}}}`. Simpler shape `"type":"1_3"` also works — the plugin normalizes both.

### R13 — Background, padding, height on containers
You may write either flat shape:
```json
{"background":"#ffffff","padding":{"top":"80px","bottom":"80px"},"height":"90vh"}
```
Or nested shape:
```json
{"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}}}
```
The plugin normalizes both. Prefer the nested shape for full fidelity.

### R14 — NEVER use shortcodes
Divi 4 shortcodes like `[et_pb_section]`, `[et_pb_row]`, `[et_pb_text]` do **NOT** work on Divi 5 in the modern pipeline. Always use `<!-- wp:divi/... -->` block comments.

### R15 — NEVER mix Gutenberg core blocks
Do not use `wp:paragraph`, `wp:heading`, `wp:image`, `wp:columns`, `wp:group`, etc. Only `wp:divi/*` blocks.

### R16 — Meta keys are automatic
Never try to set `_et_pb_use_builder`, `_et_builder_version`, or any `_et_*` meta. The plugin handles all of that. Just send the content.

---

## Minimal valid document

```
<!-- wp:divi/placeholder /-->
<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}},"spacing":{"desktop":{"value":{"padding":{"top":"80px","bottom":"80px"}}}}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} -->
<!-- wp:divi/row {"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} -->
<!-- wp:divi/text {"module":{},"content":{"innerContent":{"desktop":{"value":"<h1>Hello world</h1><p>This is a paragraph.</p>"}}},"builderVersion":"5.0.0-public-beta.1","modulePreset":["default"]} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

---

## Validation flow (do this mechanically)

1. Build the content string following the rules above.
2. (Optional) Call `divi_validate({content})` first. If `ok: false`, read `errors[]` — each error has a `rule` code (R1..R16) and a `message`. Fix and retry.
3. Call `divi_write({title, content, post_type?, post_id?, template_type?})`.
4. If `divi_write` returns HTTP 422, read the `errors[]` array, fix the content, retry.
5. Do NOT retry more than 2 times. If still failing, surface the last error array to the user.

---

## Common mistakes (do not do these)

1. Wrapping `wp:divi/content` inside `wp:divi/text`. **There is no `wp:divi/content`. Just use a single `wp:divi/text /-->`.**
2. Closing content modules with `<!-- /wp:divi/text -->`. **They are self-closing: ` /-->`.**
3. Using shortcodes `[et_pb_section]`. **Use block comments.**
4. Using `wp:paragraph` or `wp:heading`. **Use `wp:divi/text` with the HTML tag inside.**
5. Including literal `-->` inside HTML payload. **Breaks the comment. Use `--&gt;`.**
6. Forgetting the self-closing placeholder. **Start with `<!-- wp:divi/placeholder /-->`.**
7. Forgetting to close a container. **Every `section`/`row`/`column` needs its closer.**
8. Setting any `_et_*` meta key. **Never. The plugin does it.**
9. Hard-coding `"builderVersion": "5.0.0-public-alpha.23"` when the site is on a newer version. **Call `divi_contract` once to read the live value.**
10. Mixing quote styles in JSON. **Use double quotes. Escape internal quotes with `\"`.**

---

## Version compatibility

- WP MCP Bridge plugin >= 1.6.2
- Divi theme: 5.3.0+
- WordPress: 5.6+
- PHP: 7.4+

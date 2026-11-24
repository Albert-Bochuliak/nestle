# Glossary Tooltip — Architecture

A Drupal 11 module that scans rendered content for terms from the `glossary`
taxonomy vocabulary and wraps each match in an accessible tooltip containing
the term description. Descriptions longer than 100 characters are truncated
and get a "Read more" link to the term page.

## Overview

Classes are organized by responsibility into `Service`, `Dom` and `Model`
namespaces; the filter plugin and settings form are thin integration layers:

```
Text format filter (GlossaryTooltipFilter)
        │
        ▼
Service\GlossaryHighlighter ──► Service\GlossaryTermProvider (terms, cached)
        │          │
        │          ├──► Model\HighlightContext (per-run state)
        │          └──► Service\GlossaryMatcher (compile, normalize)
        ▼
Dom\GlossaryDomProcessor ──► Service\GlossaryMatcher (findMatches)
        └──► Dom\TooltipElementBuilder (tooltip DOM markup)
```

| Class | Layer | Responsibility |
|---|---|---|
| `Plugin\Filter\GlossaryTooltipFilter` | Integration | Drupal filter plugin; entry point per rendered field |
| `Service\GlossaryHighlighter` | Coordination | Pipeline: terms, settings, context, result + cache metadata |
| `Service\GlossaryTermProvider` | Data | Loads and caches published glossary terms |
| `Service\GlossaryMatcher` | Matching | Stateless, pure-PHP pattern compilation and matching |
| `Dom\GlossaryDomProcessor` | DOM | Parse/traverse/serialize HTML, wrap matches |
| `Dom\TooltipElementBuilder` | DOM | Accessible tooltip markup |
| `Model\HighlightContext` | State | Per-run index + first-occurrence bookkeeping |
| `Form\GlossaryTooltipSettingsForm` | Integration | Admin toggle: first vs every occurrence |

Data flow on each filtered field:

1. The filter calls `GlossaryHighlighter::highlight($html)`.
2. The highlighter gets published terms from `GlossaryTermProvider` and
   builds a `HighlightContext` (normalized index, first-only flag).
3. `GlossaryDomProcessor` compiles the matcher pattern once, walks DOM text
   nodes, and delegates each match to `GlossaryMatcher::findMatches()` +
   `TooltipElementBuilder`.
4. The processor returns the serialized document or NULL when nothing
   changed; the filter attaches the library only on matches.
5. Cache tags `taxonomy_term_list:glossary` + `config:glossary_tooltip.settings`
   keep filtered markup in sync with term and settings changes.

## Backend reference

### `GlossaryTooltipFilter` — `src/Plugin/Filter/GlossaryTooltipFilter.php`

Drupal filter plugin (`#[Filter(id: 'glossary_tooltip')]`) enabled on text
formats such as Basic HTML and Full HTML.

- `create()` — pulls `glossary_tooltip.highlighter` and the module's logger
  channel from the container (`ContainerFactoryPluginInterface`).
- `process($text, $langcode)` — runs the highlighter with the field's
  langcode, sets the processed text, bubbles the full `CacheableMetadata`
  (tags, contexts, max-age) onto the result, and attaches the
  `glossary_tooltip/glossary_tooltip` library only on matches. This is the
  single fail-safe layer: failures are logged with the langcode and the
  result gets `max-age 0`, so a transient error never poisons the cache.

### `GlossaryHighlighter` — `src/Service/GlossaryHighlighter.php`

Pipeline coordinator; contains no regex or DOM logic.

- `highlight(string $html, ?string $langcode): array` — returns
  `{text, matched, cacheability}`; metadata merges the provider entry
  with the settings tag. Short-circuits on empty input before loading
  terms. Exceptions propagate to the filter's fail-safe layer.
- `isFirstOccurrenceOnly(): bool` — reads the module setting.

### `GlossaryTermProvider` — `src/Service/GlossaryTermProvider.php`

Term source; results are memoized per request and language. No dedicated
cache bin: loaded terms are already served by Drupal's entity cache.

- `getTerms(?string $langcode): array` — single contract returning
  `{terms, cacheability}`; memoized per request and language. One entity
  query (`accessCheck(FALSE)` because access is checked explicitly below)
  sorted by weight then tid for deterministic order, plus one
  `loadMultiple()` batch — no N+1 queries and no dedicated cache bin:
  Drupal's entity cache already covers loaded terms.
- Access: `$term->access('view', $anonymous)` and field-level
  `description` access as cacheable access results, so only publicly
  viewable data is highlighted and the verdicts' cacheability is bubbled.
- `cacheability` — `taxonomy_term_list:glossary` tag +
  `languages:language_content` context + generated-URL metadata.

### `GlossaryMatcher` — `src/Service/GlossaryMatcher.php`

Stateless pure-PHP matcher — no Drupal dependencies, unit-testable.

- `compile(array $names): ?string` — builds the alternation pattern once
  per run: deduplicated, longest-first (so `Wet food` beats `Food`),
  inner whitespace becomes `\s+`, wrapped in Unicode word boundaries
  `(?<![\p{L}\p{N}_]) ... (?![\p{L}\p{N}_])` with `/iu`. Returns NULL for
  empty input. Documented scaling note: extreme term counts may need
  chunking — a local change that does not affect callers.
- `findMatches(string $text, string $pattern): array` — `preg_match_all`
  with `PREG_OFFSET_CAPTURE`; returns byte `offset`/`length`/`text`
  consistent with `substr()`. A pattern failure throws `RuntimeException`,
  distinguishing errors from "no matches".
- `normalize(string $text): string` — trim, collapse Unicode whitespace,
  lowercase; used for both names and matches so `wet\nfood` resolves to
  `wet food`.

### `GlossaryDomProcessor` — `src/Dom/GlossaryDomProcessor.php`

All low-level DOM work: parse once, traverse `//text()` once, serialize
only if changed.

- `process(string $html, HighlightContext): ?string` — compiles the pattern,
  wraps matches, returns the serialized document or NULL when untouched.
- `isSkipped(\DOMText): bool` — text inside `a`, `button`, `code`, `option`,
  `pre`, `script`, `select`, `style`, `textarea` or an element with
  `data-glossary-term` is never scanned (links stay intact; idempotent
  re-runs).
- `wrapMatches(\DOMDocument, \DOMText, array, HighlightContext): bool` —
  splits the text node around matches and inserts builder-generated spans;
  leaves the node untouched when every match is skipped.
- Known limitation: a multi-word term split by inline markup
  (`wet <em>food</em>`) is not matched — matching is per text node.

### `HighlightContext` — `src/Model/HighlightContext.php`

Compact per-run state object.

- `__construct(array $terms, bool $firstOnly, GlossaryMatcher)` — builds a
  single normalized-name → term-data index; on duplicate normalized names
  the first term wins (terms arrive weight-sorted, so it is deterministic).
- `names(): array` — index keys for the matcher.
- `termFor(string $matched): ?array` — resolves matched text to term data
  `{id, name, description, url}`; returns NULL for unknown terms and for
  already-seen terms in first-occurrence mode.

### `TooltipElementBuilder` — `src/Dom/TooltipElementBuilder.php`

Owns all generated markup and the 100-character limit.

- `buildElement(\DOMDocument, string $matchedText, array $term): \DOMElement`
  — disclosure pattern: `.glossary-term` wrapper holding a native
  `<button class="glossary-term__trigger">` (keyboard/focus for free)
  with `aria-expanded`, plus the tooltip as its sibling. No `role="tooltip"`
  — the panel contains an interactive link, which tooltips forbid.
  No IDs are emitted: JS assigns unique IDs + `aria-controls` at init so
  repeated cached fragments never collide.
- `buildTooltip()` — `.glossary-tooltip` with `.glossary-tooltip__content`
  and the "Read more" link shown only when truncated.
- Truncation: `mb_substr` to `DESCRIPTION_LIMIT - 1` chars + `…`, so text
  plus ellipsis never exceeds 100 characters.

### `GlossaryTooltipSettingsForm` — `src/Form/GlossaryTooltipSettingsForm.php`

Config form at `/admin/config/content/glossary-tooltip`; the
`first_occurrence_only` checkbox uses `#config_target`, so Drupal 11
handles the default value and persistence — no custom submitForm.

### `glossary_tooltip_install()` — `glossary_tooltip.install`

`hook_install()`: creates the `glossary` vocabulary (name + long-text
description fields) unless it already exists.

## Frontend

The `glossary_tooltip` library ships a vanilla-JS Drupal behavior, module
CSS, two SVG assets and the Roboto / Roboto Slab webfonts — no build step.

### `js/glossary-tooltip.js`

- `attach(context)` — `once()`-guarded init of every `.glossary-term`;
  one delegated `document` `keydown`/`click` pair for Escape-close
  (restoring focus) and outside-click-close.
- `initTerm(term)` — assigns `glossary-tooltip-N` ID + `aria-controls`;
  `show()`/`hide()` enforcing a single-open-tooltip invariant;
  `mouseenter`/`mouseleave`, `focusin`/`focusout` (keeps open while focus
  moves inside, e.g. to "Read more"); the native button's click (Enter/
  Space included) toggles on touch devices.
- `positionTooltip(term, tooltip)` — measures hidden, picks `--above`/
  `--below` by free space, clamps horizontally to the text block ∩
  viewport, counter-shifts the arrow via `--arrow-offset`.

### `css/glossary-tooltip.css`

Figma styles: `.glossary-term` inline-block wrapper (multi-word terms
can't split, keeping anchoring correct); `.glossary-term__trigger` button
reset + 10% teal background and underline;
`.glossary-tooltip` `#0D7978`, `13px 24px`, radius 8px, Roboto 14/20 white;
`::before` hover bridge; `::after` SVG arrow flipped for `--above`;
`.glossary-tooltip__link` Roboto Slab 500 with theme-hover resets.

### Accessibility

Native `<button>` trigger (focusable, Enter/Space activation),
`aria-expanded` + `aria-controls` → tooltip panel; Tab opens, Escape
closes with focus restore, Tab again reaches "Read more"; tap toggles on
touch.

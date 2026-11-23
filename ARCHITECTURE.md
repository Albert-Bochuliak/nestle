# Glossary Tooltip — Architecture

A Drupal 11 module that scans rendered content for terms from the `glossary`
taxonomy vocabulary and wraps each match in an accessible tooltip containing
the term description. Descriptions longer than 100 characters are truncated
and get a "Read more" link to the term page.

## Overview

The module is split into four single-responsibility backend services plus a
thin filter plugin and a small settings form:

```
Text format filter (GlossaryTooltipFilter)
        │
        ▼
GlossaryHighlighter ──► GlossaryTermProvider   (terms, cached)
        │                     │
        ├──► GlossaryMatcher  (pure regex matching)
        ├──► HighlightContext (per-run state)
        └──► TooltipElementBuilder (tooltip DOM markup)
```

| Class | Responsibility |
|---|---|
| `GlossaryTooltipFilter` | Drupal filter plugin; entry point per rendered field |
| `GlossaryHighlighter` | Parses HTML, walks text nodes, wraps matches |
| `GlossaryTermProvider` | Loads and caches published glossary terms |
| `GlossaryMatcher` | Finds term occurrences in plain text (no Drupal deps) |
| `HighlightContext` | Per-run state: lookup index, first-occurrence set, tooltip counter |
| `TooltipElementBuilder` | Builds the accessible tooltip DOM markup |
| `GlossaryTooltipSettingsForm` | Admin toggle: first occurrence vs every occurrence |

Data flow on each filtered field:

1. The filter calls `GlossaryHighlighter::highlight($html)`.
2. The highlighter asks `GlossaryTermProvider` for published terms.
3. `HighlightContext::create()` builds a normalized lookup index.
4. Each DOM text node is passed to `GlossaryMatcher::findMatches()`, which
   returns byte offsets of term occurrences (longest match wins, Unicode word
   boundaries, multi-word whitespace tolerance).
5. `wrapMatches()` splits the text node and inserts elements built by
   `TooltipElementBuilder`.
6. The filter attaches the `glossary_tooltip` library (JS/CSS) only when at
   least one term matched, and adds `taxonomy_term_list` cache tags so term
   edits invalidate filtered markup automatically.

## Backend reference

### `GlossaryTooltipFilter` — `src/Plugin/Filter/GlossaryTooltipFilter.php`

Drupal filter plugin (`#[Filter(id: 'glossary_tooltip')]`) enabled on text
formats such as Basic HTML and Full HTML.

- `create()` — pulls `glossary_tooltip.highlighter` and the module's logger
  channel from the container (`ContainerFactoryPluginInterface`).
- `process($text, $langcode)` — runs the highlighter, sets the processed text,
  adds cache tags, and attaches the `glossary_tooltip/glossary_tooltip`
  library only when a term matched. Any failure is logged and the original
  text is returned unchanged so rendering never breaks.

### `GlossaryHighlighter` — `src/GlossaryHighlighter.php`

DOM-level orchestrator. Parses the field HTML and rewrites text nodes.

- `highlight(string $html): array` — main entry point. Returns
  `{text, cache_tags, matched}`. Short-circuits on empty input or no terms;
  wraps the whole pipeline in try/catch with error logging.
- `processTextNodes(\DOMDocument, HighlightContext)` — XPath `//text()` walk;
  skips non-text nodes and skipped subtrees, delegates matches to
  `wrapMatches()`.
- `isSkipped(\DOMText): bool` — true when the node sits inside `a`, `button`,
  `code`, `option`, `pre`, `script`, `select`, `style`, `textarea` or an
  element already carrying `data-glossary-term` (idempotent re-runs).
- `wrapMatches(\DOMDocument, \DOMText, array, HighlightContext)` — splits the
  text node around each match: inserts plain-text nodes before/after and a
  `TooltipElementBuilder` element at each hit. Honors first-occurrence mode
  via the context and leaves the node untouched when nothing was inserted.

### `GlossaryTermProvider` — `src/GlossaryTermProvider.php`

Term source with two-level caching (request memoization + Drupal cache bin).

- `getTerms(): array` — returns term data `{id: {name, description, url}}`;
  memoized per request, then cache bin, then storage.
- `readCache(): ?array` — cache-bin read; returns NULL on miss or backend
  failure (logged as warning).
- `loadAndCache(): array` — loads terms and writes them to the cache with
  the module's cache tags.
- `getCacheTags(): array` — `['taxonomy_term_list']`; invalidated on every
  term save/unpublish/delete, so edits appear without a manual cache clear.
- `loadTerms(): array` — entity query for published terms (`status = 1`) of
  the `glossary` vocabulary, sorted by weight; returns normalized term data.
- `extractDescription(object $term): string` — reads the built-in
  `description` field, prefers `processed` over `value`, strips tags, returns
  plain text.

### `GlossaryMatcher` — `src/GlossaryMatcher.php`

Pure PHP matcher — no Drupal dependencies, fully unit-testable.

- `findMatches(string $text, array $names): array` — runs `preg_match_all`
  with `PREG_OFFSET_CAPTURE`; returns ordered matches with byte `offset`,
  `length` and `text`. Empty inputs and regex failures yield `[]`.
- `normalize(string $text): string` — lookup key normalization: trims,
  collapses any Unicode whitespace to single spaces, lowercases. Used both
  for term names and matched text so `wet\nfood` resolves to `wet food`.
- `buildPattern(array $names): ?string` — compiles the alternation pattern:
  deduplicates/trims names, sorts longest-first (so `Wet food` beats `Food`
  at the same position), converts inner whitespace to `\s+`, wraps the
  alternation in Unicode word boundaries
  `(?<![\p{L}\p{N}_]) ... (?![\p{L}\p{N}_])` with `/iu` flags. The compiled
  pattern is memoized per names set so it is built once per document, not
  once per text node.

### `HighlightContext` — `src/HighlightContext.php`

Typed per-call state object, replacing what used to be a loose array.

- `create(array $terms, bool $firstOnly, GlossaryMatcher): self` — factory;
  builds the names list and the normalized-name → term-ID lookup index.
- `names(): array` — term names for the matcher.
- `termFor(string $matched): ?array` — resolves matched text to
  `{id, data}`; returns NULL for unknown matches or already-seen terms in
  first-occurrence mode, and marks the term seen otherwise.
- `nextCounter(): int` / `counter(): int` — tooltip numbering used for unique
  `glossary-tooltip-{tid}-{n}` element IDs.

### `TooltipElementBuilder` — `src/TooltipElementBuilder.php`

Owns all generated markup and the 100-character limit.

- `buildElement(\DOMDocument, string $matchedText, array $term, int $termId, int $counter): \DOMElement`
  — the `.glossary-term` span with `tabindex="0"`, `aria-expanded`,
  `aria-describedby`, `data-glossary-term` and the nested tooltip.
- `buildTooltip(\DOMDocument, array $term, string $tooltip_id): \DOMElement`
  — `.glossary-tooltip` span (`role="tooltip"`, unique ID), the
  `.glossary-tooltip__content` description and, for truncated descriptions,
  the "Read more" link (`glossary-tooltip__link`) pointing at the term URL.
- `truncateDescription(string): array` — returns
  `[text, isTruncated]`; cuts at `DESCRIPTION_LIMIT` (100) and appends `…`.

### `GlossaryTooltipSettingsForm` — `src/Form/GlossaryTooltipSettingsForm.php`

Config form at `/admin/config/content/glossary-tooltip`.

- `getEditableConfigNames()` — `glossary_tooltip.settings`.
- `getFormId()` — `glossary_tooltip_settings`.
- `buildForm()` — single checkbox `first_occurrence_only`.
- `submitForm()` — persists the flag; logs and shows an error message on
  failure instead of throwing.

### `glossary_tooltip_install()` — `glossary_tooltip.install`

`hook_install()`: creates the `glossary` vocabulary (machine name + title +
long-text description fields) unless it already exists; logs and rethrows on
failure.

## Frontend

The `glossary_tooltip` library (`glossary_tooltip.libraries.yml`) ships a
vanilla-JS Drupal behavior, module CSS, two SVG assets and the Roboto /
Roboto Slab webfonts — no build step, no framework dependencies.

### `js/glossary-tooltip.js`

A `Drupal.behaviors.glossaryTooltip` behavior using `once()`, so it works
with AJAX re-rendering and never double-binds.

- `attach(context)` — initializes every `.glossary-term` in the context and,
  exactly once per page, registers two delegated `document` listeners:
  `Escape` closes the open tooltip and returns focus to the term, and an
  outside click closes it.
- `initTerm(term)` — per-term wiring:
  - `show()` — closes any previously open term (single-tooltip invariant),
    calls `positionTooltip()`, adds `is-open`, sets `aria-expanded="true"`
    and registers itself as `openTerm`.
  - `hide()` — removes `is-open`, resets `aria-expanded`, clears `openTerm`.
  - `mouseenter`/`mouseleave` — hover open/close.
  - `focusin`/`focusout` — keyboard open/close; closes only when focus
    leaves the whole term element (so tabbing to "Read more" keeps it open).
  - `click` — touch support: taps toggle the tooltip; taps on the "Read
    more" link pass through as normal navigation.
- `positionTooltip(term, tooltip)` — measures the tooltip while hidden
  (`is-measuring` class), chooses `--above` vs `--below` placement by free
  viewport space, then clamps the tooltip horizontally to the surrounding
  text block intersected with the viewport (8px margin). When the box is
  shifted, `--arrow-offset` counter-shifts the arrow so it stays pointed at
  the term.

### `css/glossary-tooltip.css`

Figma-driven styles:

- `.glossary-term` — `inline-block` (multi-word terms can't split across
  lines, which keeps tooltip anchoring correct), 10%-opacity teal background
  `rgba(13, 121, 120, .1)`, teal underline, `cursor: help`.
- `.glossary-tooltip` — absolutely positioned, teal `#0D7978`, `13px 24px`
  padding, `8px` radius, `0 4px 8px rgba(0,0,0,.4)` shadow, Roboto 400
  `14px/20px` white text, hidden until `is-open`.
- `::before` bridge — an invisible strip above/below the tooltip so the
  pointer can travel from the term to the tooltip without a `mouseleave`.
- `::after` arrow — `img/read_more_icon_top.svg`, flipped with `scaleY(-1)`
  for `--above` placement, shifted via `--arrow-offset`.
- `.glossary-tooltip__link` — Roboto Slab 500 `14px/16px`, white in every
  state, hover/focus resets for background/box-shadow/border/decoration
  (Olivero link styles leak otherwise), with `img/read_more_icon.svg` as a
  trailing icon.

### Accessibility

- Terms are focusable (`tabindex="0"`) and expose state via
  `aria-expanded` + `aria-describedby` pointing at `role="tooltip"`.
- Keyboard: Tab to focus opens the tooltip, Escape closes and restores
  focus, Tab again reaches "Read more".
- Touch: tap toggles the tooltip; the link inside remains tappable.

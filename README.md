# Glossary Tooltip

Drupal 11 module that highlights words matching items in the glossary
vocabulary and shows their descriptions in a tooltip.

## Features

- Creates a **Glossary** taxonomy vocabulary on install (terms have a Title
  and a long-text Description by default).
- Scans text inside any content type via a configurable **text filter**.
- Highlights matches with an accessible tooltip containing the term
  description (up to **100 characters**; longer descriptions end with
  `…` and a **Read more** link to the term page).
- Multi-word terms and non-Latin languages supported; when one term is
  part of another, the **longest match wins**.
- Words that are already links stay unchanged.
- Term edits and unpublishing take effect **without a cache clear**
  (entity list cache tags invalidate filtered output).
- Tooltip works on **mobile** (tap) and from the **keyboard** (focus /
  Escape).
- Optional setting to highlight only the **first occurrence** of each
  term or **every occurrence**.

## Requirements

- Drupal 11
- Core Taxonomy and Filter modules

## Installation

1. Copy the module to `modules/custom/glossary_tooltip`.
2. Enable it: `drush en glossary_tooltip` (or via *Extend*).
3. Enable the **Glossary Tooltip** filter on the text formats you use
   (*Configuration → Text formats and editors*, e.g. *Basic HTML*).
4. Add terms under *Structure → Taxonomy → Glossary*.

## Configuration

*Configuration → Content authoring → Glossary Tooltip* — switch between
highlighting the first occurrence of each term or every occurrence.

## How it works

- `GlossaryTermProvider` loads published `glossary` terms in one entity
  query + one `loadMultiple()` batch, memoized per request. Access is
  checked against the anonymous user on the entity and the `name` /
  `description` fields via cacheable access results — only publicly
  viewable terms are highlighted.
- `GlossaryTooltipFilter` (a text filter) runs the highlighter over
  filtered markup and attaches the tooltip library only when a term
  matches. It is the single fail-safe layer: load failures are logged
  and render with `max-age 0`.
- `GlossaryHighlighter` coordinates the pipeline; `GlossaryDomProcessor`
  walks `DOMDocument` text nodes skipping links and non-content
  elements; `GlossaryMatcher` is a dependency-free Unicode-safe matcher
  (case-insensitive, word-boundary aware, longest-match-first);
  `TooltipElementBuilder` owns the accessible disclosure markup.
- `js/glossary-tooltip.js` positions the tooltip above or below the term
  and supports hover, keyboard focus, Escape and touch taps.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full per-class and
per-function reference (backend and frontend).

## Caching

Filtered output carries cache tags `taxonomy_term_list:glossary`,
per-term `taxonomy_term:N` tags and `config:glossary_tooltip.settings`,
plus `languages:language_content` and `user.permissions` contexts — so
term edits, unpublishing, new terms and settings changes take effect
without a cache clear.

## Accessibility

Native `<button>` trigger with `aria-expanded` / `aria-controls`; Tab
opens, Escape closes and restores focus, "Read more" is a plain link;
tap toggles on touch.

## Known limitations

- A multi-word term split by inline markup (`wet <em>food</em>`) is not
  matched — matching happens per text node.
- Only published terms viewable by anonymous users are highlighted.

## Tests

```
phpunit modules/custom/glossary_tooltip/tests/src/Unit
```

38 unit tests, 60 assertions (matcher, DOM processor, run context).

Optional static analysis (requires `drupal/coder` and `phpstan`):

```
vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/custom/glossary_tooltip
vendor/bin/phpstan analyse modules/custom/glossary_tooltip
```

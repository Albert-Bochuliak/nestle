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

- `GlossaryTermProvider` loads published `glossary` terms and caches them
  with the `taxonomy_term_list` cache tag.
- `GlossaryTooltipFilter` (a text filter) runs the highlighter over
  filtered markup and attaches the tooltip library only when a term
  matches. Its cache metadata includes the term list tag, so term
  changes show up immediately.
- `GlossaryHighlighter` walks the DOM with `DOMDocument`, skipping links
  and non-content elements, and delegates matching to `GlossaryMatcher`.
- `GlossaryMatcher` is a dependency-free Unicode-safe matcher:
  case-insensitive, word-boundary aware, longest-match-first.
- `js/glossary-tooltip.js` positions the tooltip above or below the term
  and supports hover, keyboard focus, Escape and touch taps.

## Tests

```
phpunit modules/custom/glossary_tooltip/tests/src/Unit
```

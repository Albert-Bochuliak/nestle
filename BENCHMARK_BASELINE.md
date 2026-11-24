# Baseline Benchmark — glossary_tooltip

Date: 2026-07 (post-PHPCS/PHPStan state, HEAD 8b75513).
Reproduce: `php -d memory_limit=1G modules/custom/glossary_tooltip/benchmark.php [--tier::N | --components | --cleanup]` from docroot. Raw data: `benchmark-results.json` (docroot).

## Environment

| Param | Value |
|---|---|
| PHP | 8.5.0 (CLI), memory_limit 256M (5k/10k runs used `-d memory_limit=1G`) |
| PCRE | backtrack_limit 1 000 000, recursion_limit 100 000, pcre.jit 1 |
| OPcache (CLI) | disabled |
| Drupal | 11.4.13, MySQL (OpenServer), Windows |
| Iterations | provider cold x3, warm x10, pattern x10, context x20, process x5, regex/parse x10 |

Synthetic terms (`Benchmark synthetic term NNNNN` + Unicode `слово NNNNN`, `plain_text` descriptions) were created per tier and removed after the run. 11 real terms remained; their `description` uses `basic_html`, which contains the `glossary_tooltip` filter — this matters (see Recursion bug).

## Results

### Term provider (getTerms: query + loadMultiple + access + description + URL)

| Terms | Cold p50 | Warm p50 | DB queries | Access est | Desc est | URL est | Peak mem |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | 44.2 ms | 10.4 ms | 2 / 20.2 ms | 1.6 ms | 0.3 ms | 1.6 ms | 23.5 MB |
| 1 000 | 1 091 ms | 591 ms | 15 / 518 ms | 8.1 ms | 45.6 ms | 3.1 ms | 74.2 MB |
| 5 000 | — | — | — | — | — | — | **OOM (256M, 1G, 4G)** |
| 10 000 | — | — | — | — | — | — | **OOM** |

The 1 000-tier figures already include re-entrant `getTerms()` calls triggered by the recursion bug below (15 queries vs 2 at tier 100).

### Regex pattern (compile only, GlossaryMatcher)

| Terms | Compile p50 | Pattern length |
|---:|---:|---:|
| 100 | 0.16 ms | 1.4 KB |
| 1 000 | 2.5 ms | 13.4 KB |
| 5 000 | 14.5 ms | 66.7 KB |
| 10 000 | 30.2 ms | 133.4 KB |

### DOM pipeline (`highlight()`: parse + preg_match_all + wrap + serialize)

Terms 100–1 000, ms (p50):

| HTML | Density | Parse | Regex | Process total | Serialize | Peak mem |
|---:|---|---:|---:|---:|---:|---:|
| 5 KB | none | 0.6 | 0.03 | 1.1 | 1.2 | 23 MB |
| 5 KB | many | 0.6–1.0 | 0.04–0.07 | 3.7–6.8 | 1.1–2.1 | 23 MB |
| 50 KB | few | 6.1–8.7 | 0.3–0.5 | 15–16 | 11.8–17.7 | 23.5 MB |
| 100 KB | none | 11.6 | 0.6 | 17–23 | 27–31 | 24 MB |
| 100 KB | many | 11.5–16.6 | 0.7–1.9 | 40–52 | 22.7–32.8 | 24 MB |

- Regex matching is never the bottleneck (<= ~10 ms even at 1 000 terms x 100 KB).
- DOM serialization (`saveHTML`) is the single most expensive stage at large HTML (~25–33 ms at 100 KB), then parsing (~12–17 ms).
- Match density barely changes cost: DOM walk and serialization dominate.

### Multi-field scaling (same request)

| Terms | 1 field | 5 fields | 10 fields |
|---:|---:|---:|---:|
| 100 | 13.3 ms | 46.9 ms | 89.7 ms |
| 1 000 | 9.9 ms | 42.3 ms | 99.5 ms |

Linear scaling, as expected.

### HTTP TTFB (real node page, `basic_html` field)

| Terms | Cold | Warm p50 | Warm p95 | Warm p99 |
|---:|---:|---:|---:|---:|
| 100 | ~1.0–1.3 s | 52.9 ms | 63.8 ms | 64.8 ms |
| 1 000 | ~6.6 s | **6 110 ms** | 6 466 ms | 6 708 ms |
| 10 000 | ~9.7 s | **~5 200 ms** (n=3, 5.14–5.32 s) | — | — |

At 1 000+ terms every request pays the provider recursion; at 10 000 the page still returns 200 but highlighting is dead (regex fails → fail-safe, max-age 0 → uncacheable).

## Finding 1 — PCRE alternation hard limit (critical)

`preg_match_all` on the single longest-first alternation returns `FALSE` with `PREG_INTERNAL_ERROR` at **>= 2 333 alternatives (~31 KB pattern)**. Bisected: 2 332 passes, 2 333 fails. Independent of `pcre.jit` and `pcre.backtrack_limit`/`recursion_limit`.

Consequence: with ~2 300+ glossary terms the module throws `RuntimeException` on every field; the filter fail-safe returns text unmodified with max-age 0 — highlighting silently stops working **and** the render becomes uncacheable.

Fix direction (next stage): chunk the pattern (<= ~2 000 alternatives per `preg_match_all`, merge match sets) or switch to a non-alternation matcher.

## Finding 2 — Infinite recursion via `$field->processed` (critical)

`GlossaryTermProvider::extractDescription()` reads `description.0.processed` → `TextProcessed::getValue()` → `renderInIsolation(processed_text)` → runs the format's filters → `GlossaryTooltipFilter` → highlighter → `getTerms()` → `extractDescription()` → recursion. Core has no re-entrancy guard (`processed` is still NULL during re-entry).

Any term whose description format enables `glossary_tooltip` triggers it — `basic_html` and `full_html` both do; 11 real terms use `basic_html`. Each nested level performs a full `loadMultiple()`:

- 5 000 / 10 000 terms: OOM even at `memory_limit=4G` (~900+ frames, ~30+ cycles).
- 1 000 terms: provider warm 591 ms; HTTP TTFB ~6.1 s per request.
- 10 000 terms: HTTP TTFB ~5.2 s per request (provider still pays recursion before regex failure).

Fix direction (next stage): use raw `description.value` + `strip_tags` (provider already strips tags) or add a re-entrancy guard in the filter/provider.

## Finding 3 — Memory ceiling on entity loading (moderate)

Even without recursion, `loadMultiple()` of 5 000 terms approaches the 256M ceiling (components peak at 10 000 names = 22 MB, but full entities are far heavier). Combined with Finding 2 it is currently unreachable at 256M. After the recursion fix, chunked loading or a lean field-query may be needed for the 5k+ tier.

## Bottleneck summary (priority order)

1. **PCRE alternation limit ~2 333 terms** — module silently stops highlighting; fix = pattern chunking.
2. **Provider recursion via `processed`** — OOM at 5k+, ~5–6 s/page at 1k+; fix = raw `value` + strip_tags or re-entrancy guard.
3. **DOM serialization** — largest DOM stage (~30 ms/100 KB); possible optimization: `saveHTML($node)` per node or cheaper markup builder.
4. **DOM parsing** — ~12–17 ms/100 KB; inherent to DOMDocument, acceptable.
5. **Provider at scale** — after fixes, watch `loadMultiple` memory and per-term `toUrl()`/access cost.

No optimization implemented at this stage — measurements only.

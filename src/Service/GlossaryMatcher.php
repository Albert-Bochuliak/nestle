<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

/**
 * Finds glossary term occurrences in plain text.
 *
 * Pure, stateless string-matching service with no Drupal dependencies, kept
 * separate so the logic is unit-testable without a bootstrap. Patterns are
 * compiled once per processing run and reused for every text node.
 */
final class GlossaryMatcher {

  /**
   * Characters considered part of a word for boundary checks.
   *
   * \p{M} covers combining marks so accented and decomposed characters
   * still count as word characters.
   */
  private const WORD_CHARS = '\p{L}\p{N}\p{M}_';

  /**
   * Maximum alternatives per compiled pattern.
   *
   * A single alternation matching thousands of terms exhausts PCRE
   * internals (preg_match_all() fails with PREG_INTERNAL_ERROR around
   * ~2 300 alternatives / ~31 KB on typical builds). Both bounds are kept
   * well below the observed failure point.
   */
  private const MAX_ALTERNATIVES = 1500;

  /**
   * Maximum length of a compiled pattern body in bytes.
   */
  private const MAX_PATTERN_BYTES = 16384;

  /**
   * Compiles the term alternation pattern(s), longest terms first.
   *
   * Multi-word terms tolerate any whitespace between words. Matches cannot
   * start or end inside a word, so "cat" does not match in "catalogue" and
   * the rule holds for non-Latin scripts.
   *
   * Small vocabularies produce a single pattern — the fastest path. When
   * the alternation would exceed the limits above, it is split into
   * sequential chunks (each preserving the longest-first order) compiled
   * as zero-width lookahead patterns; findMatches() merges their
   * candidates into exactly the result a single pattern would give.
   *
   * @param string[] $names
   *   Term names.
   *
   * @return string[]|null
   *   Ready-to-run PCRE patterns, or NULL when there is nothing to match.
   *   A single-element list is a consuming pattern; a multi-element list
   *   contains lookahead patterns whose matches live in capture group 1.
   */
  public function compile(array $names): ?array {
    $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn(string $name): bool => $name !== '')));

    if ($names === []) {
      return NULL;
    }

    // Longest first: at the same position PCRE tries alternatives in order.
    usort($names, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    $alternatives = array_map(static function (string $name): string {
      $words = preg_split('/\s+/u', $name) ?: [$name];
      return implode('\\s+', array_map(
        static fn(string $word): string => preg_quote($word, '/'),
        $words,
      ));
    }, $names);

    // Single-pattern fast path for vocabularies that fit PCRE limits.
    $total_bytes = -1;
    foreach ($alternatives as $alternative) {
      $total_bytes += strlen($alternative) + 1;
    }
    if (count($alternatives) <= self::MAX_ALTERNATIVES && $total_bytes <= self::MAX_PATTERN_BYTES) {
      return [$this->buildPattern($alternatives, FALSE)];
    }

    // Sequential chunks keep the longest-first order inside each chunk.
    $patterns = [];
    $chunk = [];
    $chunk_bytes = 0;
    foreach ($alternatives as $alternative) {
      $bytes = strlen($alternative);
      if ($chunk_bytes > 0
        && (count($chunk) >= self::MAX_ALTERNATIVES
        || $chunk_bytes + 1 + $bytes > self::MAX_PATTERN_BYTES)) {
        $patterns[] = $this->buildPattern($chunk, TRUE);
        $chunk = [];
        $chunk_bytes = 0;
      }
      $chunk[] = $alternative;
      $chunk_bytes += ($chunk_bytes > 0 ? 1 : 0) + $bytes;
    }
    $patterns[] = $this->buildPattern($chunk, TRUE);

    return $patterns;
  }

  /**
   * Finds all non-overlapping term occurrences in the given text.
   *
   * Matching is case-insensitive and Unicode-safe. When several terms can
   * match at the same position, the longest term wins; a match starting
   * earlier always wins over a match starting later.
   *
   * @param string $text
   *   Plain text (not HTML) to scan.
   * @param string[] $patterns
   *   Patterns from self::compile().
   *
   * @return array<int, array{offset: int, length: int, text: string}>
   *   Matches ordered by position. "offset" and "length" are in bytes and
   *   consistent with substr().
   *
   * @throws \RuntimeException
   *   When the pattern fails (distinct from "no matches found").
   */
  public function findMatches(string $text, array $patterns): array {
    if ($text === '') {
      return [];
    }

    // Fast path: one consuming pattern, matches straight from group 0.
    if (count($patterns) === 1) {
      return $this->runPattern($text, $patterns[0], FALSE);
    }

    // Chunked: each lookahead pattern yields the longest match its chunk
    // offers at every position — including positions overlapping another
    // candidate. Consuming matches instead would drop candidates that the
    // global resolution may still need.
    $candidates = [];
    foreach ($patterns as $pattern) {
      $candidates = [...$candidates, ...$this->runPattern($text, $pattern, TRUE)];
    }
    if ($candidates === []) {
      return [];
    }

    // Leftmost wins; for equal offsets the longest wins. This reproduces
    // the single-pattern semantics exactly: PCRE's alternation picks the
    // longest-first alternative at each position, then scanning resumes
    // past the match — a greedy non-overlap pass does the same.
    usort($candidates, static fn(array $a, array $b): int => $a['offset'] <=> $b['offset'] ?: $b['length'] <=> $a['length']);
    $matches = [];
    $end = 0;
    foreach ($candidates as $candidate) {
      if ($candidate['offset'] < $end) {
        continue;
      }
      $matches[] = $candidate;
      $end = $candidate['offset'] + $candidate['length'];
    }

    return $matches;
  }

  /**
   * Builds a single PCRE pattern from prepared alternatives.
   *
   * @param string[] $alternatives
   *   Escaped, longest-first alternatives.
   * @param bool $lookahead
   *   TRUE wraps the alternation in a zero-width lookahead so matches at
   *   every position are reported (chunked mode); FALSE builds a normal
   *   consuming pattern.
   */
  private function buildPattern(array $alternatives, bool $lookahead): string {
    $start = '/(?<![' . self::WORD_CHARS . '])';
    $alternation = '(?:' . implode('|', $alternatives) . ')';
    $end = '(?![' . self::WORD_CHARS . '])';

    return $lookahead
      ? $start . '(?=(' . $alternation . $end . '))/iu'
      : $start . $alternation . $end . '/iu';
  }

  /**
   * Runs one pattern and returns matches as offset/length/text rows.
   *
   * @param string $text
   *   Plain text to scan.
   * @param string $pattern
   *   A ready-to-run PCRE pattern from compile().
   * @param bool $lookahead
   *   TRUE reads capture group 1 (lookahead patterns), FALSE reads the
   *   whole match.
   *
   * @return array<int, array{offset: int, length: int, text: string}>
   *   Matches in scan order.
   */
  private function runPattern(string $text, string $pattern, bool $lookahead): array {
    $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    if ($count === FALSE) {
      throw new \RuntimeException('Glossary term matching failed: ' . preg_last_error_msg());
    }

    return array_map(
      static fn(array $match): array => [
        'offset' => $match[1],
        'length' => strlen($match[0]),
        'text' => $match[0],
      ],
      $lookahead ? $matches[1] : $matches[0],
    );
  }

  /**
   * Normalizes a term name or matched text into a lookup key.
   *
   * Lowercases the value and collapses whitespace so a multi-word match
   * like "wet\nfood" still resolves to the "wet food" term.
   */
  public function normalize(string $text): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

/**
 * Finds glossary term occurrences in plain text.
 *
 * Pure string-matching service with no Drupal dependencies, kept separate
 * so the logic is unit-testable without a bootstrap.
 */
final class GlossaryMatcher {

  /**
   * Characters considered part of a word for boundary checks.
   */
  private const WORD_CHARS = '\p{L}\p{N}_';

  /**
   * Finds all non-overlapping term occurrences in the given text.
   *
   * Matching is case-insensitive and Unicode-safe. When several terms can
   * match at the same position, the longest term wins; a match starting
   * earlier always wins over a match starting later.
   *
   * @param string $text
   *   Plain text (not HTML) to scan.
   * @param string[] $names
   *   Term names to look for.
   *
   * @return array<int, array{offset: int, length: int, text: string}>
   *   Matches ordered by position. "offset" and "length" are in bytes.
   */
  public function findMatches(string $text, array $names): array {
    $pattern = $this->buildPattern($names);

    if ($pattern === NULL || $text === '') {
      return [];
    }

    try {
      $result = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    }
    catch (\Throwable) {
      return [];
    }

    if ($result === FALSE || $result === 0) {
      return [];
    }

    $found = [];
    foreach ($matches[0] as [$matched, $offset]) {
      $found[] = [
        'offset' => $offset,
        'length' => strlen($matched),
        'text' => $matched,
      ];
    }

    return $found;
  }

  /**
   * Normalizes a term name or matched text into a lookup key.
   *
   * Lowercases the value and collapses whitespace so a multi-word match
   * like "wet\nfood" still resolves to the "wet food" term.
   */
  public function normalize(string $text): string {
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    return mb_strtolower($text);
  }

  /**
   * Builds the term alternation pattern, longest terms first.
   *
   * @param string[] $names
   *   Term names.
   *
   * @return string|null
   *   A PCRE pattern, or NULL when there is nothing to match.
   */
  private function buildPattern(array $names): ?string {
    $names = array_values(array_unique(array_filter(
      array_map(static fn(string $name): string => trim($name), $names),
      static fn(string $name): bool => $name !== '',
    )));

    if ($names === []) {
      return NULL;
    }

    // Longest first: at the same position PCRE tries alternatives in order.
    usort($names, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    $alternatives = array_map(static function (string $name): string {
      // Multi-word terms tolerate any whitespace between words.
      $words = preg_split('/\s+/u', $name) ?: [$name];
      return implode('\\s+', array_map(
        static fn(string $word): string => preg_quote($word, '/'),
        $words,
      ));
    }, $names);

    return '/(?<![' . self::WORD_CHARS . '])(?:' . implode('|', $alternatives) . ')(?![' . self::WORD_CHARS . '])/iu';
  }

}

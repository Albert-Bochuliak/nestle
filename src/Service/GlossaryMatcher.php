<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

/**
 * Finds glossary term occurrences in plain text.
 *
 * Pure, stateless string-matching service with no Drupal dependencies, kept
 * separate so the logic is unit-testable without a bootstrap. The pattern is
 * compiled once per processing run and reused for every text node.
 */
final class GlossaryMatcher {

  /**
   * Characters considered part of a word for boundary checks.
   */
  private const WORD_CHARS = '\p{L}\p{N}_';

  /**
   * Compiles the term alternation pattern, longest terms first.
   *
   * Multi-word terms tolerate any whitespace between words. Matches cannot
   * start or end inside a word, so "cat" does not match in "catalogue" and
   * the rule holds for non-Latin scripts.
   *
   * @param string[] $names
   *   Term names.
   *
   * @return string|null
   *   A PCRE pattern, or NULL when there is nothing to match. With an extreme
   *   number of terms the combined pattern may exceed PCRE limits; a chunking
   *   strategy can be added here without changing callers.
   */
  public function compile(array $names): ?string {
    $names = array_values(array_unique(array_filter(array_map('trim', $names), 'strlen')));

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

    return '/(?<![' . self::WORD_CHARS . '])(?:' . implode('|', $alternatives) . ')(?![' . self::WORD_CHARS . '])/iu';
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
   * @param string $pattern
   *   Pattern from self::compile().
   *
   * @return array<int, array{offset: int, length: int, text: string}>
   *   Matches ordered by position. "offset" and "length" are in bytes and
   *   consistent with substr().
   *
   * @throws \RuntimeException
   *   When the pattern fails (distinct from "no matches found").
   */
  public function findMatches(string $text, string $pattern): array {
    if ($text === '') {
      return [];
    }

    $count = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
    if ($count === FALSE) {
      throw new \RuntimeException('Glossary term matching failed.');
    }

    return array_map(
      static fn(array $match): array => [
        'offset' => $match[1],
        'length' => strlen($match[0]),
        'text' => $match[0],
      ],
      $matches[0],
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

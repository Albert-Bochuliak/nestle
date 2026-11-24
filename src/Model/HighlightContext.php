<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Model;

use Drupal\glossary_tooltip\Service\GlossaryMatcher;

/**
 * Per-run state of a single highlighting pass.
 *
 * A compact value object holding the normalized term index plus the
 * first-occurrence bookkeeping. A fresh instance per call keeps runs
 * isolated from each other.
 */
final class HighlightContext {

  /**
   * Term IDs already highlighted (first-occurrence mode).
   *
   * @var array<int, bool>
   */
  private array $seen = [];

  /**
   * Term data indexed by normalized term name.
   *
   * When two terms share a normalized name, the first one wins; terms arrive
   * sorted by weight, so the result is deterministic.
   *
   * @var array<string, array{id: int, name: string, description: string, url: string}>
   */
  private array $index = [];

  /**
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   * @param bool $firstOnly
   *   Whether only the first occurrence of each term is highlighted.
   * @param \Drupal\glossary_tooltip\Service\GlossaryMatcher $matcher
   *   Matcher used to normalize names and matched text into index keys.
   */
  public function __construct(
    array $terms,
    private readonly bool $firstOnly,
    private readonly GlossaryMatcher $matcher,
  ) {
    foreach ($terms as $term_id => $term) {
      $this->index[$matcher->normalize($term['name'])] ??= ['id' => $term_id] + $term;
    }
  }

  /**
   * Normalized term names for the matcher pattern.
   *
   * @return string[]
   *   Term names.
   */
  public function names(): array {
    return array_keys($this->index);
  }

  /**
   * Resolves matched text to a term that may be wrapped.
   *
   * Returns NULL for unknown matches and for terms already highlighted
   * in first-occurrence mode; otherwise marks the term as used.
   *
   * @return array{id: int, name: string, description: string, url: string}|null
   *   Term data with ID, or NULL when the match must be skipped.
   */
  public function termFor(string $matched): ?array {
    $entry = $this->index[$this->matcher->normalize($matched)] ?? NULL;

    if ($entry === NULL || ($this->firstOnly && isset($this->seen[$entry['id']]))) {
      return NULL;
    }

    $this->seen[$entry['id']] = TRUE;
    return $entry;
  }

}

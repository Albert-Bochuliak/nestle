<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

/**
 * Per-call state of a single GlossaryHighlighter::highlight() run.
 *
 * Groups the term data, lookup index, first-occurrence bookkeeping and
 * the tooltip counter that used to travel as separate parameters.
 */
final class HighlightContext {

  /**
   * Term IDs already highlighted (for first-occurrence mode).
   *
   * @var array<int, bool>
   */
  private array $seen = [];

  /**
   * Number of tooltips created so far; used for unique IDs.
   */
  private int $counter = 0;

  /**
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   * @param string[] $names
   *   Term names to look for.
   * @param array<string, int> $lookup
   *   Normalized term name to term ID.
   * @param bool $firstOnly
   *   Whether only the first occurrence of each term is highlighted.
   * @param \Drupal\glossary_tooltip\GlossaryMatcher $matcher
   *   Matcher used to normalize matched text into lookup keys.
   */
  private function __construct(
    private readonly array $terms,
    private readonly array $names,
    private readonly array $lookup,
    private readonly bool $firstOnly,
    private readonly GlossaryMatcher $matcher,
  ) {}

  /**
   * Builds the context from term data and the module setting.
   *
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   */
  public static function create(array $terms, bool $firstOnly, GlossaryMatcher $matcher): self {
    $names = [];
    $lookup = [];
    foreach ($terms as $term_id => $term) {
      $names[] = $term['name'];
      $lookup[$matcher->normalize($term['name'])] = $term_id;
    }

    return new self($terms, $names, $lookup, $firstOnly, $matcher);
  }

  /**
   * Term names to look for.
   *
   * @return string[]
   *   Term names.
   */
  public function names(): array {
    return $this->names;
  }

  /**
   * Resolves matched text to a term that may be wrapped.
   *
   * Returns NULL for unknown matches and for terms already highlighted
   * in first-occurrence mode; otherwise marks the term as used.
   *
   * @return array{id: int, data: array{name: string, description: string, url: string}}|null
   *   Term ID and data, or NULL when the match must be skipped.
   */
  public function termFor(string $matched): ?array {
    $term_id = $this->lookup[$this->matcher->normalize($matched)] ?? NULL;

    if ($term_id === NULL || ($this->firstOnly && isset($this->seen[$term_id]))) {
      return NULL;
    }

    $this->seen[$term_id] = TRUE;
    return ['id' => $term_id, 'data' => $this->terms[$term_id]];
  }

  /**
   * Increments and returns the tooltip counter.
   */
  public function nextCounter(): int {
    return ++$this->counter;
  }

  /**
   * Number of tooltips created so far.
   */
  public function counter(): int {
    return $this->counter;
  }

}

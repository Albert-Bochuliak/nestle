<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\glossary_tooltip\Dom\GlossaryDomProcessor;
use Drupal\glossary_tooltip\Model\HighlightContext;

/**
 * Coordinates glossary term highlighting in HTML markup.
 *
 * Owns the pipeline — fetching terms, reading settings, building the run
 * context and calling the DOM processor — and knows nothing about regexes
 * or low-level DOM manipulation. Fail-safe handling lives one level up, in
 * the filter plugin; exceptions propagate there.
 */
final class GlossaryHighlighter {

  /**
   * Constructs the glossary highlighter.
   *
   * @param \Drupal\glossary_tooltip\Service\GlossaryTermProvider $termProvider
   *   Loads and caches published glossary terms.
   * @param \Drupal\glossary_tooltip\Service\GlossaryMatcher $matcher
   *   Normalizes term names for the run context.
   * @param \Drupal\glossary_tooltip\Dom\GlossaryDomProcessor $domProcessor
   *   Rewrites the markup, wrapping matched terms.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads the module settings (first-occurrence mode).
   */
  public function __construct(
    private readonly GlossaryTermProvider $termProvider,
    private readonly GlossaryMatcher $matcher,
    private readonly GlossaryDomProcessor $domProcessor,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Highlights glossary terms in the given HTML markup.
   *
   * @param string $html
   *   The markup to scan.
   * @param string|null $langcode
   *   Language code of the content, used for translated terms and URLs.
   *
   * @return array{text: string, matched: bool, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   Processed markup, whether any term was matched and cache metadata.
   */
  public function highlight(string $html, ?string $langcode = NULL): array {
    $result = [
      'text' => $html,
      'matched' => FALSE,
    ];

    if (trim($html) !== '') {
      // Throws on load failure: the filter's fail-safe layer handles it.
      $terms = $this->termProvider->getTerms($langcode);
      if ($terms !== []) {
        $context = new HighlightContext($terms, $this->isFirstOccurrenceOnly(), $this->matcher);
        $processed = $this->domProcessor->process($html, $context);

        if ($processed !== NULL) {
          $result['text'] = $processed;
          $result['matched'] = TRUE;
        }
      }
    }

    // Built after getTerms() so restored/loaded metadata is complete;
    // on empty input getCacheMetadata() returns the base metadata without
    // triggering a load. The settings tag keeps filtered markup in sync
    // with the "first occurrence only" toggle.
    $result['cacheability'] = (new CacheableMetadata())
      ->merge($this->termProvider->getCacheMetadata($langcode))
      ->addCacheTags(['config:glossary_tooltip.settings']);

    return $result;
  }

  /**
   * Whether only the first occurrence of each term is highlighted.
   */
  private function isFirstOccurrenceOnly(): bool {
    return (bool) $this->configFactory
      ->get('glossary_tooltip.settings')
      ->get('first_occurrence_only');
  }

}

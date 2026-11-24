<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\glossary_tooltip\Dom\GlossaryDomProcessor;
use Drupal\glossary_tooltip\Model\HighlightContext;
use Psr\Log\LoggerInterface;

/**
 * Coordinates glossary term highlighting in HTML markup.
 *
 * Owns the pipeline — fetching terms, reading settings, building the run
 * context and calling the DOM processor — and knows nothing about regexes
 * or low-level DOM manipulation.
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
   * @param \Psr\Log\LoggerInterface $logger
   *   Logs highlighting failures.
   */
  public function __construct(
    private readonly GlossaryTermProvider $termProvider,
    private readonly GlossaryMatcher $matcher,
    private readonly GlossaryDomProcessor $domProcessor,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Highlights glossary terms in the given HTML markup.
   *
   * @param string $html
   *   The markup to scan.
   *
   * @return array{text: string, cache_tags: string[], matched: bool}
   *   Processed markup, cache tags and whether any term was matched.
   */
  public function highlight(string $html): array {
    $result = [
      'text' => $html,
      'cache_tags' => $this->termProvider->getCacheTags(),
      'matched' => FALSE,
    ];

    $terms = $this->termProvider->getTerms();
    if ($terms === [] || trim($html) === '') {
      return $result;
    }

    try {
      $context = new HighlightContext($terms, $this->isFirstOccurrenceOnly(), $this->matcher);
      $processed = $this->domProcessor->process($html, $context);

      if ($processed !== NULL) {
        $result['text'] = $processed;
        $result['matched'] = TRUE;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Glossary highlighting failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

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

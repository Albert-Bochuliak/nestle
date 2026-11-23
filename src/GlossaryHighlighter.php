<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps glossary terms found in HTML with tooltip markup.
 */
final class GlossaryHighlighter {

  /**
   * Elements whose text content is never scanned.
   *
   * Includes "a" so words that are already links stay unchanged.
   */
  private const SKIP_TAGS = [
    'a',
    'button',
    'code',
    'option',
    'pre',
    'script',
    'select',
    'style',
    'textarea',
  ];

  public function __construct(
    private readonly GlossaryTermProvider $termProvider,
    private readonly GlossaryMatcher $matcher,
    private readonly TooltipElementBuilder $elementBuilder,
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
    $terms = $this->termProvider->getTerms();

    $result = [
      'text' => $html,
      'cache_tags' => $this->termProvider->getCacheTags(),
      'matched' => FALSE,
    ];

    if ($terms === [] || trim($html) === '') {
      return $result;
    }

    try {
      $document = Html::load($html);
      $context = HighlightContext::create(
        $terms,
        (bool) $this->configFactory
          ->get('glossary_tooltip.settings')
          ->get('first_occurrence_only'),
        $this->matcher,
      );

      $this->processTextNodes($document, $context);

      if ($context->counter() > 0) {
        $result['text'] = Html::serialize($document);
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
   * Scans every text node of the document and wraps found terms.
   */
  private function processTextNodes(\DOMDocument $document, HighlightContext $context): void {
    $xpath = new \DOMXPath($document);

    foreach ($xpath->query('//text()') ?: [] as $text_node) {
      if (!$text_node instanceof \DOMText || $this->isSkipped($text_node)) {
        continue;
      }

      $matches = $this->matcher->findMatches($text_node->nodeValue ?? '', $context->names());
      if ($matches === []) {
        continue;
      }

      $this->wrapMatches($document, $text_node, $matches, $context);
    }
  }

  /**
   * Checks whether a text node sits inside a skipped element.
   */
  private function isSkipped(\DOMText $node): bool {
    for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
      if (in_array(strtolower($parent->nodeName), self::SKIP_TAGS, TRUE)
        || $parent->hasAttribute('data-glossary-term')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Replaces a text node with plain text and highlighted term spans.
   *
   * @param array<int, array{offset: int, length: int, text: string}> $matches
   *   Matches inside the text node.
   * @param \Drupal\glossary_tooltip\HighlightContext $context
   *   Highlighting run state.
   */
  private function wrapMatches(
    \DOMDocument $document,
    \DOMText $text_node,
    array $matches,
    HighlightContext $context,
  ): void {
    $parent = $text_node->parentNode;
    if (!$parent instanceof \DOMNode) {
      return;
    }

    $text = $text_node->nodeValue ?? '';
    $position = 0;
    $inserted = FALSE;

    foreach ($matches as $match) {
      $hit = $context->termFor($match['text']);
      if ($hit === NULL) {
        continue;
      }

      if ($match['offset'] > $position) {
        $parent->insertBefore(
          $document->createTextNode(substr($text, $position, $match['offset'] - $position)),
          $text_node,
        );
      }

      $parent->insertBefore(
        $this->elementBuilder->buildElement($document, $match['text'], $hit['data'], $hit['id'], $context->nextCounter()),
        $text_node,
      );

      $inserted = TRUE;
      $position = $match['offset'] + $match['length'];
    }

    if (!$inserted) {
      return;
    }

    if ($position < strlen($text)) {
      $parent->insertBefore($document->createTextNode(substr($text, $position)), $text_node);
    }

    $parent->removeChild($text_node);
  }

}

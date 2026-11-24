<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Dom;

use Drupal\Component\Utility\Html;
use Drupal\glossary_tooltip\Model\HighlightContext;
use Drupal\glossary_tooltip\Service\GlossaryMatcher;

/**
 * Rewrites HTML markup by wrapping glossary term matches in tooltips.
 *
 * Owns all low-level DOM work: parsing, serialization, text-node traversal
 * and node replacement. Multi-word terms spanning inline elements are a
 * known limitation: matching happens per text node, so "wet <em>food</em>"
 * is not highlighted.
 */
final class GlossaryDomProcessor {

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
    private readonly GlossaryMatcher $matcher,
    private readonly TooltipElementBuilder $elementBuilder,
  ) {}

  /**
   * Wraps matched terms in the given HTML markup.
   *
   * @param string $html
   *   The markup to scan.
   * @param \Drupal\glossary_tooltip\Model\HighlightContext $context
   *   Highlighting run state.
   *
   * @return string|null
   *   Processed markup, or NULL when nothing was highlighted. The document
   *   is parsed once and serialized only when it changed.
   */
  public function process(string $html, HighlightContext $context): ?string {
    $pattern = $this->matcher->compile($context->names());
    if ($pattern === NULL) {
      return NULL;
    }

    $document = Html::load($html);
    $xpath = new \DOMXPath($document);
    $changed = FALSE;

    // Snapshot: DOMNodeList is live and would skip/revisit nodes while
    // wrapMatches() modifies the document.
    foreach (iterator_to_array($xpath->query('//text()') ?: []) as $text_node) {
      if (!$text_node instanceof \DOMText || $this->isSkipped($text_node)) {
        continue;
      }

      $matches = $this->matcher->findMatches($text_node->nodeValue ?? '', $pattern);
      if ($matches !== []) {
        $changed = $this->wrapMatches($document, $text_node, $matches, $context) || $changed;
      }
    }

    return $changed ? Html::serialize($document) : NULL;
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
   *
   * @return bool
   *   Whether the node was rewritten. When every match is skipped (for
   *   example in first-occurrence mode) the node is left untouched.
   */
  private function wrapMatches(
    \DOMDocument $document,
    \DOMText $text_node,
    array $matches,
    HighlightContext $context,
  ): bool {
    $parent = $text_node->parentNode;
    if (!$parent instanceof \DOMNode) {
      return FALSE;
    }

    $text = $text_node->nodeValue ?? '';
    $position = 0;
    $inserted = FALSE;

    foreach ($matches as $match) {
      $term = $context->termFor($match['text']);
      if ($term === NULL) {
        continue;
      }

      if ($match['offset'] > $position) {
        $parent->insertBefore(
          $document->createTextNode(substr($text, $position, $match['offset'] - $position)),
          $text_node,
        );
      }

      $parent->insertBefore(
        $this->elementBuilder->buildElement($document, $match['text'], $term),
        $text_node,
      );

      $inserted = TRUE;
      $position = $match['offset'] + $match['length'];
    }

    if (!$inserted) {
      return FALSE;
    }

    if ($position < strlen($text)) {
      $parent->insertBefore($document->createTextNode(substr($text, $position)), $text_node);
    }

    $parent->removeChild($text_node);
    return TRUE;
  }

}

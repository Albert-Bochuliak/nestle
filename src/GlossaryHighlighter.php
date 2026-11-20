<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps glossary terms found in HTML with tooltip markup.
 */
final class GlossaryHighlighter {

  use StringTranslationTrait;

  /**
   * Maximum length of the description shown in the tooltip.
   */
  public const DESCRIPTION_LIMIT = 100;

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

  private readonly GlossaryMatcher $matcher;

  public function __construct(
    private readonly GlossaryTermProvider $termProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    ?TranslationInterface $translation = NULL,
  ) {
    $this->matcher = new GlossaryMatcher();
    if ($translation !== NULL) {
      $this->setStringTranslation($translation);
    }
  }

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
      $xpath = new \DOMXPath($document);
      $text_nodes = iterator_to_array($xpath->query('//text()') ?: []);

      $names = array_column($terms, 'name');
      $lookup = [];
      foreach ($terms as $term_id => $term) {
        $lookup[mb_strtolower($term['name'])] = $term_id;
      }

      $first_only = (bool) $this->configFactory
        ->get('glossary_tooltip.settings')
        ->get('first_occurrence_only');

      $seen = [];
      $counter = 0;

      foreach ($text_nodes as $text_node) {
        if (!$text_node instanceof \DOMText || $this->isSkipped($text_node)) {
          continue;
        }

        $matches = $this->matcher->findMatches($text_node->nodeValue ?? '', $names);
        if ($matches === []) {
          continue;
        }

        $this->wrapMatches($document, $text_node, $matches, $terms, $lookup, $seen, $counter, $first_only);
      }

      if ($counter > 0) {
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
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   * @param array<string, int> $lookup
   *   Lowercase term name to term ID.
   * @param array<int, bool> $seen
   *   Term IDs already highlighted (for first-occurrence mode).
   * @param int $counter
   *   Running count of created tooltips; used for unique IDs.
   */
  private function wrapMatches(
    \DOMDocument $document,
    \DOMText $text_node,
    array $matches,
    array $terms,
    array $lookup,
    array &$seen,
    int &$counter,
    bool $first_only,
  ): void {
    $parent = $text_node->parentNode;
    if (!$parent instanceof \DOMNode) {
      return;
    }

    $text = $text_node->nodeValue ?? '';
    $position = 0;
    $inserted = FALSE;

    foreach ($matches as $match) {
      $term_id = $lookup[mb_strtolower($match['text'])] ?? NULL;

      if ($term_id === NULL) {
        // Whitespace-flexible matching can differ in case/spacing; fall back
        // to a name lookup via the raw match.
        continue;
      }

      if ($first_only && isset($seen[$term_id])) {
        continue;
      }

      if ($match['offset'] > $position) {
        $parent->insertBefore(
          $document->createTextNode(substr($text, $position, $match['offset'] - $position)),
          $text_node,
        );
      }

      $parent->insertBefore(
        $this->buildTermElement($document, $match['text'], $terms[$term_id], $term_id, ++$counter),
        $text_node,
      );

      $seen[$term_id] = TRUE;
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

  /**
   * Builds the highlight span with its tooltip.
   *
   * @param array{name: string, description: string, url: string} $term
   *   Term data.
   */
  private function buildTermElement(
    \DOMDocument $document,
    string $matched_text,
    array $term,
    int $term_id,
    int $counter,
  ): \DOMElement {
    $tooltip_id = 'glossary-tooltip-' . $term_id . '-' . $counter;

    $wrapper = $document->createElement('span');
    $wrapper->setAttribute('class', 'glossary-term');
    $wrapper->setAttribute('tabindex', '0');
    $wrapper->setAttribute('aria-expanded', 'false');
    $wrapper->setAttribute('aria-describedby', $tooltip_id);
    $wrapper->setAttribute('data-glossary-term', (string) $term_id);
    $wrapper->appendChild($document->createTextNode($matched_text));

    $tooltip = $document->createElement('span');
    $tooltip->setAttribute('class', 'glossary-tooltip');
    $tooltip->setAttribute('id', $tooltip_id);
    $tooltip->setAttribute('role', 'tooltip');

    $content = $document->createElement('span');
    $content->setAttribute('class', 'glossary-tooltip__content');

    $description = $term['description'];
    $is_truncated = mb_strlen($description) > self::DESCRIPTION_LIMIT;
    if ($is_truncated) {
      $description = rtrim(mb_strimwidth($description, 0, self::DESCRIPTION_LIMIT, '')) . '…';
    }
    $content->appendChild($document->createTextNode($description));
    $tooltip->appendChild($content);

    if ($is_truncated) {
      $link = $document->createElement('a', $this->t('Read more'));
      $link->setAttribute('class', 'glossary-tooltip__link');
      $link->setAttribute('href', $term['url']);
      $tooltip->appendChild($link);
    }

    $wrapper->appendChild($tooltip);

    return $wrapper;
  }

}

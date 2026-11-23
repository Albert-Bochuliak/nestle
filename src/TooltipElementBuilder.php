<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds the accessible tooltip markup for a matched glossary term.
 */
final class TooltipElementBuilder {

  use StringTranslationTrait;

  /**
   * Maximum length of the description shown in the tooltip.
   */
  public const DESCRIPTION_LIMIT = 100;

  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * Builds the highlight span with its tooltip.
   *
   * @param string $matchedText
   *   The matched term text as it appears in the content.
   * @param array{name: string, description: string, url: string} $term
   *   Term data.
   * @param int $termId
   *   The term ID.
   * @param int $counter
   *   Ordinal number of this tooltip; used for unique element IDs.
   */
  public function buildElement(
    \DOMDocument $document,
    string $matchedText,
    array $term,
    int $termId,
    int $counter,
  ): \DOMElement {
    $tooltip_id = 'glossary-tooltip-' . $termId . '-' . $counter;

    $wrapper = $document->createElement('span');
    $wrapper->setAttribute('class', 'glossary-term');
    $wrapper->setAttribute('tabindex', '0');
    $wrapper->setAttribute('aria-expanded', 'false');
    $wrapper->setAttribute('aria-describedby', $tooltip_id);
    $wrapper->setAttribute('data-glossary-term', (string) $termId);
    $wrapper->appendChild($document->createTextNode($matchedText));

    $wrapper->appendChild($this->buildTooltip($document, $term, $tooltip_id));

    return $wrapper;
  }

  /**
   * Builds the tooltip element with description and optional link.
   *
   * @param array{name: string, description: string, url: string} $term
   *   Term data.
   */
  private function buildTooltip(\DOMDocument $document, array $term, string $tooltip_id): \DOMElement {
    $tooltip = $document->createElement('span');
    $tooltip->setAttribute('class', 'glossary-tooltip');
    $tooltip->setAttribute('id', $tooltip_id);
    $tooltip->setAttribute('role', 'tooltip');

    [$description, $is_truncated] = $this->truncateDescription($term['description']);

    $content = $document->createElement('span');
    $content->setAttribute('class', 'glossary-tooltip__content');
    $content->appendChild($document->createTextNode($description));
    $tooltip->appendChild($content);

    if ($is_truncated) {
      $link = $document->createElement('a', (string) $this->t('Read more'));
      $link->setAttribute('class', 'glossary-tooltip__link');
      $link->setAttribute('href', $term['url']);
      $tooltip->appendChild($link);
    }

    return $tooltip;
  }

  /**
   * Truncates a description to DESCRIPTION_LIMIT characters.
   *
   * @return array{0: string, 1: bool}
   *   The (possibly truncated) description and whether truncation happened.
   */
  private function truncateDescription(string $description): array {
    if (mb_strlen($description) <= self::DESCRIPTION_LIMIT) {
      return [$description, FALSE];
    }

    return [rtrim(mb_strimwidth($description, 0, self::DESCRIPTION_LIMIT, '')) . '…', TRUE];
  }

}

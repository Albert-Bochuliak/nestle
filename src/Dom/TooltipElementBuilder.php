<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Dom;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds the accessible tooltip markup for a matched glossary term.
 */
final class TooltipElementBuilder {

  use StringTranslationTrait;

  /**
   * Maximum length of the description shown in the tooltip.
   *
   * The limit includes the ellipsis: truncated text is cut to LIMIT - 1
   * characters so text plus "…" never exceeds the limit.
   */
  public const DESCRIPTION_LIMIT = 100;

  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * Builds the highlight span with its tooltip.
   *
   * The tooltip uses a disclosure pattern: the term is focusable and exposes
   * state through aria-expanded, while aria-details points at the tooltip.
   * aria-details (rather than aria-describedby) is used because the tooltip
   * contains an interactive "Read more" link, which plain tooltips forbid.
   *
   * The tooltip ID contains uniqid(), so identical terms in separately
   * cached fragments on the same page never produce duplicate HTML IDs.
   *
   * @param string $matchedText
   *   The matched term text as it appears in the content.
   * @param array{name: string, description: string, url: string} $term
   *   Term data.
   * @param int $termId
   *   The term ID.
   */
  public function buildElement(
    \DOMDocument $document,
    string $matchedText,
    array $term,
    int $termId,
  ): \DOMElement {
    $tooltip_id = sprintf('glossary-tooltip-%d-%s', $termId, uniqid());

    $wrapper = $document->createElement('span');
    $wrapper->setAttribute('class', 'glossary-term');
    $wrapper->setAttribute('tabindex', '0');
    $wrapper->setAttribute('aria-expanded', 'false');
    $wrapper->setAttribute('aria-details', $tooltip_id);
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

    $description = $term['description'];
    $is_truncated = mb_strlen($description) > self::DESCRIPTION_LIMIT;

    $content = $document->createElement('span');
    $content->setAttribute('class', 'glossary-tooltip__content');
    $content->appendChild($document->createTextNode(
      $is_truncated
        ? rtrim(mb_substr($description, 0, self::DESCRIPTION_LIMIT - 1)) . '…'
        : $description,
    ));
    $tooltip->appendChild($content);

    if ($is_truncated) {
      $link = $document->createElement('a', (string) $this->t('Read more'));
      $link->setAttribute('class', 'glossary-tooltip__link');
      $link->setAttribute('href', $term['url']);
      $tooltip->appendChild($link);
    }

    return $tooltip;
  }

}

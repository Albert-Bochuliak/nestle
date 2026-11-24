<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Dom;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Builds the accessible tooltip markup for a matched glossary term.
 *
 * Markup follows the disclosure pattern: a native <button> trigger exposes
 * state through aria-expanded and points at the tooltip via aria-controls
 * (assigned in JS together with a unique ID). role="tooltip" is not used —
 * the panel contains an interactive link, which tooltips forbid.
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
   * @param \DOMDocument $document
   *   The document the element is built for.
   * @param string $matchedText
   *   The matched term text as it appears in the content.
   * @param array{id: int, name: string, description: string, url: string} $term
   *   Term data, including the term ID.
   */
  public function buildElement(
    \DOMDocument $document,
    string $matchedText,
    array $term,
  ): \DOMElement {
    $wrapper = $document->createElement('span');
    $wrapper->setAttribute('class', 'glossary-term');
    $wrapper->setAttribute('data-glossary-term', (string) $term['id']);

    // The trigger is a native button: keyboard activation and focusability
    // come for free. No ID here — JS assigns a unique one per render so
    // repeated cached fragments never collide, and links aria-controls.
    $trigger = $document->createElement('button');
    $trigger->setAttribute('type', 'button');
    $trigger->setAttribute('class', 'glossary-term__trigger');
    $trigger->setAttribute('aria-expanded', 'false');
    $trigger->appendChild($document->createTextNode($matchedText));
    $wrapper->appendChild($trigger);

    $wrapper->appendChild($this->buildTooltip($document, $term));

    return $wrapper;
  }

  /**
   * Builds the tooltip element with description and optional link.
   *
   * @param \DOMDocument $document
   *   The document the element is built for.
   * @param array{id: int, name: string, description: string, url: string} $term
   *   Term data, including the term ID.
   */
  private function buildTooltip(\DOMDocument $document, array $term): \DOMElement {
    $tooltip = $document->createElement('span');
    $tooltip->setAttribute('class', 'glossary-tooltip');

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
      $link = $document->createElement('a');
      $link->setAttribute('class', 'glossary-tooltip__link');
      $link->setAttribute('href', $term['url']);
      $link->appendChild($document->createTextNode((string) $this->t('Read more')));
      $tooltip->appendChild($link);
    }

    return $tooltip;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;

/**
 * Loads published glossary terms.
 *
 * One entity query plus one batched loadMultiple() — no N+1 queries.
 * A dedicated persistent cache is deliberately absent: loaded entities are
 * already served by Drupal's entity cache, so a second cache would
 * duplicate core behaviour. Results are memoized per request and language.
 *
 * Access is checked against the anonymous user with cacheable access
 * results: only publicly viewable terms and fields are highlighted, the
 * shared memoization never mixes per-user data, and the access verdicts'
 * own cacheability is bubbled into the returned metadata.
 *
 * Load failures propagate to the caller: an empty "terms" list means
 * "the vocabulary is empty", never "loading failed".
 */
final class GlossaryTermProvider {

  private const VOCABULARY = 'glossary';

  /**
   * Matches tags that delimit block-level content in HTML.
   *
   * Used to substitute block boundaries with a space when flattening
   * markup to plain text.
   */
  private const BLOCK_BOUNDARY_PATTERN = '/<\/?(?:address|article|aside|blockquote|br|dd|details|dialog|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hgroup|hr|li|main|menu|nav|ol|p|pre|section|summary|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>/i';

  /**
   * Request-memoized results per language.
   *
   * @var array<string, array{terms: array<int, array{name: string, description: string, url: string}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}>
   */
  private array $entries = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Gets published glossary terms and their cache metadata.
   *
   * @param string|null $langcode
   *   Language code; names, descriptions and URLs are language-dependent.
   *
   * @return array{terms: array<int, array{name: string, description: string, url: string}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   Term data keyed by term ID, plus tags/contexts to bubble onto the
   *   rendered output — the vocabulary list tag keeps filtered markup in
   *   sync with term edits, unpublishing and deletions automatically.
   *
   * @throws \Throwable
   *   When term loading fails.
   */
  public function getTerms(?string $langcode = NULL): array {
    return $this->entries[$langcode ?? ''] ??= $this->load($langcode);
  }

  /**
   * Loads terms and accumulates their cache metadata.
   *
   * @return array{terms: array<int, array{name: string, description: string, url: string}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   Term data and cache metadata.
   */
  private function load(?string $langcode): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->condition('status', 1)
      // Secondary sort keeps the order deterministic for equal weights.
      ->sort('weight')
      ->sort('tid')
      ->execute();

    $cacheability = (new CacheableMetadata())
      ->setCacheTags(['taxonomy_term_list:' . self::VOCABULARY])
      ->addCacheContexts(['languages:language_content']);
    $terms = [];

    if ($term_ids !== []) {
      $anonymous = User::getAnonymousUser();

      foreach ($storage->loadMultiple($term_ids) as $term) {
        if ($langcode !== NULL) {
          $term = $this->entityRepository->getTranslationFromContext($term, $langcode);
        }

        // TRUE asks for cacheable access results: forbidden verdicts are
        // bubbled into the metadata exactly like allowed ones.
        $access = $term->access('view', $anonymous, TRUE);
        $name_access = $term->get('name')->access('view', $anonymous, TRUE);
        $cacheability->addCacheableDependency($term)
          ->addCacheableDependency($access)
          ->addCacheableDependency($name_access);

        $name = $name_access->isAllowed() ? trim($term->getName()) : '';
        if (!$access->isAllowed() || $name === '') {
          continue;
        }

        // Generated URLs carry cacheability (language, domain) — bubble it.
        $url = $term->toUrl()->toString(TRUE);
        $cacheability->addCacheableDependency($url);

        $terms[(int) $term->id()] = [
          'name' => $name,
          'description' => $this->extractDescription($term, $anonymous, $cacheability),
          'url' => $url->getGeneratedUrl(),
        ];
      }
    }

    return ['terms' => $terms, 'cacheability' => $cacheability];
  }

  /**
   * Extracts the description as plain text, or '' when not viewable.
   */
  private function extractDescription(TermInterface $term, AccountInterface $account, CacheableMetadata $cacheability): string {
    $field = $term->get('description');
    $access = $field->access('view', $account, TRUE);
    $cacheability->addCacheableDependency($access);

    if (!$access->isAllowed()) {
      return '';
    }

    // The raw value is read deliberately, never ::$processed: rendering
    // the processed text runs the format's filters, and a format with
    // glossary_tooltip enabled would re-enter this provider recursively.
    $items = $field->getValue();
    $text = (string) ($items[0]['value'] ?? '');
    if ($text === '') {
      return '';
    }

    return $this->htmlToPlainText($text);
  }

  /**
   * Converts formatted text to plain text without running text filters.
   *
   * @param string $html
   *   Raw markup from the field value.
   *
   * @return string
   *   Plain text safe for a DOM text node.
   */
  private function htmlToPlainText(string $html): string {
    // Script and style contents are not displayable text: the whole element
    // is removed so its source cannot leak into the tooltip. An unclosed
    // tag consumes the rest of the value, matching browser behaviour.
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', ' ', $html);
    // Block-level tag boundaries are word separators: without them text
    // nodes of adjacent blocks would glue into a single word.
    $html = (string) preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $html);
    $text = Html::decodeEntities(strip_tags($html));
    // Collapse whitespace, including non-breaking spaces from entities.
    return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
  }

}

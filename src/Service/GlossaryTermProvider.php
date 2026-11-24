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

    $text = (string) ($field->processed ?? $field->value ?? '');
    return trim(Html::decodeEntities(strip_tags($text)));
  }

}

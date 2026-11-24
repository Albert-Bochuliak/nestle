<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Loads and caches published glossary terms.
 *
 * Term data and its cache metadata travel as one entry
 * `{terms, metadata}`: the metadata — the vocabulary list tag, language
 * context and cacheability of generated term URLs — is stored in the cache
 * item together with the data, so a cache hit restores exactly what a
 * fresh load would produce.
 *
 * Access is checked against the anonymous user, so only publicly viewable
 * terms and fields are ever highlighted and the shared cache can never
 * leak per-user results. Cache entries are keyed per language because
 * names, descriptions and term URLs are language-dependent.
 *
 * Load failures propagate to the caller (the filter's fail-safe layer):
 * an empty term list in a result means "the vocabulary is empty", never
 * "loading failed", and failed results are never stored.
 */
final class GlossaryTermProvider {

  private const CACHE_ID = 'glossary_tooltip:terms';
  private const VOCABULARY = 'glossary';

  /**
   * Request-memoized entries per language.
   *
   * @var array<string, array{terms: array<int, array{name: string, description: string, url: string}>, metadata: \Drupal\Core\Cache\CacheableMetadata}>
   */
  private array $entries = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets all published glossary terms for the given language.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   *
   * @throws \Throwable
   *   When term loading fails.
   */
  public function getTerms(?string $langcode = NULL): array {
    return $this->getEntry($langcode)['terms'];
  }

  /**
   * Cache metadata of the term data for the given language.
   *
   * Falls back to the base metadata when nothing was loaded yet — relevant
   * only for empty input, where term data is never requested.
   */
  public function getCacheMetadata(?string $langcode = NULL): CacheableMetadata {
    $cid = self::cid($langcode);
    return $this->entries[$cid]['metadata'] ?? self::baseMetadata();
  }

  /**
   * Returns the cached or freshly loaded entry for the given language.
   *
   * @return array{terms: array<int, array{name: string, description: string, url: string}>, metadata: \Drupal\Core\Cache\CacheableMetadata}
   *   Term data and its cache metadata.
   */
  private function getEntry(?string $langcode): array {
    $cid = self::cid($langcode);
    return $this->entries[$cid] ??= $this->readCache($cid) ?? $this->loadAndCache($cid, $langcode);
  }

  /**
   * Metadata every entry shares: vocabulary tag + language context.
   */
  private static function baseMetadata(): CacheableMetadata {
    return (new CacheableMetadata())
      ->setCacheTags(['taxonomy_term_list:' . self::VOCABULARY])
      ->addCacheContexts(['languages:language_content']);
  }

  /**
   * Per-language cache item ID.
   */
  private static function cid(?string $langcode): string {
    return self::CACHE_ID . ($langcode === NULL ? '' : ":$langcode");
  }

  /**
   * Returns a cached entry, or NULL on a miss or a cache backend failure.
   *
   * @return array{terms: array, metadata: \Drupal\Core\Cache\CacheableMetadata}|null
   *   The stored entry, or NULL.
   */
  private function readCache(string $cid): ?array {
    try {
      $cached = $this->cache->get($cid);
      return $cached !== FALSE && is_array($cached->data) && isset($cached->data['terms'], $cached->data['metadata'])
        ? $cached->data
        : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not read glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Loads terms from storage and stores the entry in the cache.
   *
   * @return array{terms: array, metadata: \Drupal\Core\Cache\CacheableMetadata}
   *   The loaded entry.
   *
   * @throws \Throwable
   *   When term loading fails; nothing is stored.
   */
  private function loadAndCache(string $cid, ?string $langcode): array {
    $entry = $this->loadEntry($langcode);

    try {
      $this->cache->set($cid, $entry, Cache::PERMANENT, $entry['metadata']->getCacheTags());
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not write glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return $entry;
  }

  /**
   * Loads published terms from the glossary vocabulary.
   *
   * @return array{terms: array<int, array{name: string, description: string, url: string}>, metadata: \Drupal\Core\Cache\CacheableMetadata}
   *   Term data and its cache metadata.
   */
  private function loadEntry(?string $langcode): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->condition('status', 1)
      ->sort('weight')
      // Secondary sort keeps the order deterministic for equal weights.
      ->sort('tid')
      ->execute();

    $metadata = self::baseMetadata();
    $terms = [];

    if ($term_ids !== []) {
      // loadMultiple() fetches all terms in one batch — no N+1 queries.
      $anonymous = User::getAnonymousUser();

      foreach ($storage->loadMultiple($term_ids) as $term) {
        if ($langcode !== NULL) {
          $term = $this->entityRepository->getTranslationFromContext($term, $langcode);
        }

        $name = trim($term->getName());
        if ($name === '' || !$term->access('view', $anonymous)) {
          continue;
        }

        // Generated URLs carry cacheability (language, domain) — bubble it.
        $generated_url = $term->toUrl()->toString(TRUE);
        $metadata = $metadata->merge($generated_url);

        $terms[(int) $term->id()] = [
          'name' => $name,
          'description' => $this->extractDescription($term, $anonymous),
          'url' => $generated_url->getGeneratedUrl(),
        ];
      }
    }

    return ['terms' => $terms, 'metadata' => $metadata];
  }

  /**
   * Extracts the term description as plain text, or '' when the field is
   * not viewable for anonymous users.
   */
  private function extractDescription(TermInterface $term, AccountInterface $anonymous): string {
    $field = $term->get('description');
    if (!$field->access('view', $anonymous)) {
      return '';
    }

    $text = (string) ($field->processed ?? $field->value ?? '');
    return trim(Html::decodeEntities(strip_tags($text)));
  }

}

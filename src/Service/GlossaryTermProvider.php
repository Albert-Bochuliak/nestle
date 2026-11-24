<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Loads and caches published glossary terms.
 *
 * Two cache levels: a per-request memoization and the Drupal cache backend
 * tagged with the vocabulary list cache tag, so term edits, unpublishing
 * and deletions invalidate it automatically.
 *
 * Access is checked against the anonymous user, so only terms publicly
 * viewable are ever highlighted and the shared cache can never leak
 * per-user results. Cache entries are keyed per language because names,
 * descriptions and term URLs are language-dependent.
 */
final class GlossaryTermProvider {

  private const CACHE_ID = 'glossary_tooltip:terms';
  private const VOCABULARY = 'glossary';

  /**
   * Terms keyed by term ID, per language; NULL entries mean "not loaded".
   *
   * @var array<string, array<int, array{name: string, description: string, url: string}>>
   */
  private array $terms = [];

  /**
   * Cache metadata accumulated while loading terms.
   */
  private CacheableMetadata $metadata;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {
    $this->metadata = (new CacheableMetadata())
      ->setCacheTags(['taxonomy_term_list:' . self::VOCABULARY])
      ->addCacheContexts(['languages:language_content']);
  }

  /**
   * Gets all published glossary terms for the given language.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   */
  public function getTerms(?string $langcode = NULL): array {
    $cid = self::CACHE_ID . ($langcode === NULL ? '' : ":$langcode");

    if (isset($this->terms[$cid])) {
      return $this->terms[$cid];
    }

    // NULL means "loading failed": neither the request memoization nor the
    // persistent cache stores failed results.
    $terms = $this->readCache($cid) ?? $this->loadAndCache($cid, $langcode);
    if ($terms === NULL) {
      return [];
    }

    return $this->terms[$cid] = $terms;
  }

  /**
   * Cache metadata of the term data: tags, contexts and URL cacheability.
   */
  public function getCacheMetadata(): CacheableMetadata {
    return $this->metadata;
  }

  /**
   * Returns cached terms, or NULL on a miss or a cache backend failure.
   *
   * @return array<int, array{name: string, description: string, url: string}>|null
   *   Term data keyed by term ID, or NULL.
   */
  private function readCache(string $cid): ?array {
    try {
      $cached = $this->cache->get($cid);
      return $cached !== FALSE && is_array($cached->data) ? $cached->data : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not read glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Loads terms from storage and stores them in the cache.
   *
   * @return array<int, array{name: string, description: string, url: string}>|null
   *   Term data, or NULL when loading failed; failures are never cached.
   */
  private function loadAndCache(string $cid, ?string $langcode): ?array {
    try {
      $terms = $this->loadTerms($langcode);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to load glossary terms: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    try {
      $this->cache->set($cid, $terms, Cache::PERMANENT, $this->metadata->getCacheTags());
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not write glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return $terms;
  }

  /**
   * Loads published terms from the glossary vocabulary.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   */
  private function loadTerms(?string $langcode): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->condition('status', 1)
      ->sort('weight')
      // Secondary sort keeps the order deterministic for equal weights.
      ->sort('tid')
      ->execute();

    if (empty($term_ids)) {
      return [];
    }

    // loadMultiple() fetches all terms in one batch — no N+1 queries.
    $anonymous = User::getAnonymousUser();
    $terms = [];

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
      $this->metadata = $this->metadata->merge($generated_url);

      $terms[(int) $term->id()] = [
        'name' => $name,
        'description' => $this->extractDescription($term),
        'url' => $generated_url->getGeneratedUrl(),
      ];
    }

    return $terms;
  }

  /**
   * Extracts the term description as plain text.
   */
  private function extractDescription(TermInterface $term): string {
    $field = $term->get('description');
    $text = (string) ($field->processed ?? $field->value ?? '');
    return trim(Html::decodeEntities(strip_tags($text)));
  }

}

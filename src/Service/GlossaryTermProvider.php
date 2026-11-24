<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads and caches published terms of the glossary vocabulary.
 *
 * Two cache levels: a per-request memoization and the Drupal cache backend
 * tagged with the vocabulary list cache tag, so term edits, unpublishing
 * and deletions invalidate it automatically.
 *
 * The term query skips access checks deliberately: term data is identical
 * for every viewer, so a shared cache entry cannot leak per-user results
 * and the query cannot depend on the current user's permissions.
 */
final class GlossaryTermProvider {

  private const CACHE_ID = 'glossary_tooltip:terms';
  private const VOCABULARY = 'glossary';

  /**
   * Terms keyed by term ID, NULL when not loaded yet.
   *
   * @var array<int, array{name: string, description: string, url: string}>|null
   */
  private ?array $terms = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets all published glossary terms.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   */
  public function getTerms(): array {
    return $this->terms ??= $this->readCache() ?? $this->loadAndCache() ?? [];
  }

  /**
   * Cache tags that keep filtered text in sync with term edits.
   *
   * The bundle-level list tag is invalidated by core whenever a term of
   * this vocabulary is saved, unpublished or deleted, which rebuilds both
   * this cache and the filtered markup — without touching other
   * vocabularies or requiring a manual cache clear.
   *
   * @return string[]
   *   Cache tags.
   */
  public function getCacheTags(): array {
    return ['taxonomy_term_list:' . self::VOCABULARY];
  }

  /**
   * Returns cached terms, or NULL on a miss or a cache backend failure.
   *
   * @return array<int, array{name: string, description: string, url: string}>|null
   *   Term data keyed by term ID, or NULL.
   */
  private function readCache(): ?array {
    try {
      $cached = $this->cache->get(self::CACHE_ID);
      if ($cached !== FALSE && is_array($cached->data)) {
        return $cached->data;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not read glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Loads terms from storage and stores them in the cache.
   *
   * @return array<int, array{name: string, description: string, url: string}>|null
   *   Term data, or NULL when loading failed; failures are never cached.
   */
  private function loadAndCache(): ?array {
    try {
      $terms = $this->loadTerms();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to load glossary terms: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    try {
      $this->cache->set(self::CACHE_ID, $terms, Cache::PERMANENT, $this->getCacheTags());
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
  private function loadTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $term_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->condition('status', 1)
      ->sort('weight')
      ->execute();

    if (empty($term_ids)) {
      return [];
    }

    // loadMultiple() fetches all terms in one batch — no N+1 queries.
    $terms = [];
    foreach ($storage->loadMultiple($term_ids) as $term) {
      $name = trim($term->getName());
      if ($name !== '') {
        $terms[(int) $term->id()] = [
          'name' => $name,
          'description' => $this->extractDescription($term),
          'url' => $term->toUrl()->toString(),
        ];
      }
    }

    return $terms;
  }

  /**
   * Extracts the term description as plain text.
   */
  private function extractDescription(TermInterface $term): string {
    $field = $term->get('description');
    return trim(strip_tags((string) ($field->processed ?? $field->value ?? '')));
  }

}

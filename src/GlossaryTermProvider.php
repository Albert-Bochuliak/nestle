<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads and caches published terms of the glossary vocabulary.
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
    if ($this->terms !== NULL) {
      return $this->terms;
    }

    try {
      $cached = $this->cache->get(self::CACHE_ID);
      if ($cached !== FALSE && is_array($cached->data)) {
        return $this->terms = $cached->data;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not read glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    $this->terms = $this->loadTerms();

    try {
      $this->cache->set(self::CACHE_ID, $this->terms, Cache::PERMANENT, $this->getCacheTags());
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not write glossary term cache: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return $this->terms;
  }

  /**
   * Cache tags that keep filtered text in sync with term edits.
   *
   * The entity list tag is invalidated on every term save, unpublish or
   * delete, which rebuilds both this cache and the filtered markup —
   * without a manual cache clear.
   *
   * @return string[]
   *   Cache tags.
   */
  public function getCacheTags(): array {
    return ['taxonomy_term_list'];
  }

  /**
   * Loads published terms from the glossary vocabulary.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   */
  private function loadTerms(): array {
    try {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $term_ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('vid', self::VOCABULARY)
        ->condition('status', 1)
        ->sort('weight')
        ->execute();

      if (empty($term_ids)) {
        return [];
      }

      $terms = [];
      foreach ($storage->loadMultiple($term_ids) as $term) {
        $name = trim((string) $term->getName());
        if ($name === '') {
          continue;
        }

        $terms[(int) $term->id()] = [
          'name' => $name,
          'description' => $this->extractDescription($term),
          'url' => $term->toUrl()->toString(),
        ];
      }

      return $terms;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to load glossary terms: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Extracts the term description as plain text.
   */
  private function extractDescription(object $term): string {
    try {
      if (method_exists($term, 'hasField') && $term->hasField('description')) {
        $field = $term->get('description');
        $text = (string) ($field->processed ?? $field->value ?? '');
        return trim((string) strip_tags($text));
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not read a term description: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return '';
  }

}

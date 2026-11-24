<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit\Model;

use Drupal\glossary_tooltip\Model\HighlightContext;
use Drupal\glossary_tooltip\Service\GlossaryMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the per-run highlight context.
 */
#[CoversClass(HighlightContext::class)]
#[Group('glossary_tooltip')]
final class HighlightContextTest extends TestCase {

  /**
   * Builds a context for the given terms.
   *
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   * @param bool $firstOnly
   *   Whether only the first occurrence of each term is highlighted.
   */
  private function context(array $terms, bool $firstOnly): HighlightContext {
    return new HighlightContext($terms, $firstOnly, new GlossaryMatcher());
  }

  /**
   * Shared term fixture: a single-word and a multi-word term.
   *
   * @return array<int, array{name: string, description: string, url: string}>
   *   Term data keyed by term ID.
   */
  private static function terms(): array {
    return [
      1 => ['name' => 'Food', 'description' => 'd1', 'url' => '/taxonomy/term/1'],
      2 => ['name' => 'Wet food', 'description' => 'd2', 'url' => '/taxonomy/term/2'],
    ];
  }

  /**
   * Names are indexed once under their normalized form.
   */
  public function testNamesAreIndexedOnce(): void {
    $this->assertSame(['food', 'wet food'], $this->context(self::terms(), FALSE)->names());
  }

  /**
   * A match resolves to term data regardless of case/whitespace.
   */
  public function testTermForResolvesNormalizedMatch(): void {
    $term = $this->context(self::terms(), FALSE)->termFor("wet\nFOOD");
    $this->assertSame(2, $term['id']);
    $this->assertSame('Wet food', $term['name']);
  }

  /**
   * Text that is not a term resolves to NULL.
   */
  public function testUnknownMatchReturnsNull(): void {
    $this->assertNull($this->context(self::terms(), FALSE)->termFor('dry food'));
  }

  /**
   * First-occurrence mode returns each term once.
   */
  public function testFirstOccurrenceOnly(): void {
    $context = $this->context(self::terms(), TRUE);
    $this->assertNotNull($context->termFor('food'));
    $this->assertNull($context->termFor('food'));
    // Other terms are unaffected.
    $this->assertNotNull($context->termFor('wet food'));
  }

  /**
   * Every-occurrence mode returns a term for each match.
   */
  public function testEveryOccurrenceMode(): void {
    $context = $this->context(self::terms(), FALSE);
    $this->assertNotNull($context->termFor('food'));
    $this->assertNotNull($context->termFor('food'));
  }

  /**
   * Terms sharing a normalized name resolve to the first (weight order).
   */
  public function testDuplicateNormalizedNameFirstWins(): void {
    $terms = [
      3 => ['name' => 'PM', 'description' => 'a', 'url' => '/t/3'],
      4 => ['name' => ' pm ', 'description' => 'b', 'url' => '/t/4'],
    ];
    $term = $this->context($terms, FALSE)->termFor('pm');
    $this->assertSame(3, $term['id']);
  }

}

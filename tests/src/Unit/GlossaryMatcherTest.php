<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit;

use Drupal\glossary_tooltip\GlossaryMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\glossary_tooltip\GlossaryMatcher
 * @group glossary_tooltip
 */
final class GlossaryMatcherTest extends TestCase {

  private GlossaryMatcher $matcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->matcher = new GlossaryMatcher();
  }

  public function testEmptyInput(): void {
    $this->assertSame([], $this->matcher->findMatches('', ['term']));
    $this->assertSame([], $this->matcher->findMatches('text', []));
  }

  public function testSimpleMatch(): void {
    $matches = $this->matcher->findMatches('Diabetes is common.', ['Diabetes']);
    $this->assertCount(1, $matches);
    $this->assertSame('Diabetes', $matches[0]['text']);
  }

  public function testWordBoundaries(): void {
    // "cat" must not match inside "catalogue".
    $this->assertSame([], $this->matcher->findMatches('A catalogue entry.', ['cat']));
    $this->assertCount(1, $this->matcher->findMatches('A cat sleeps.', ['cat']));
  }

  public function testLongestTermWins(): void {
    // "whole food" starts earlier, so it wins; "food" inside it never matches.
    $matches = $this->matcher->findMatches('I like whole food.', ['food', 'whole food']);
    $this->assertCount(1, $matches);
    $this->assertSame('whole food', $matches[0]['text']);
  }

  public function testEarlierMatchWinsOverLater(): void {
    $matches = $this->matcher->findMatches('I like wet food and food.', ['food', 'wet food']);
    $this->assertCount(2, $matches);
    $this->assertSame('wet food', $matches[0]['text']);
    $this->assertSame('food', $matches[1]['text']);
  }

  public function testMultiWordWhitespace(): void {
    $matches = $this->matcher->findMatches("A wet\nfood dish.", ['wet food']);
    $this->assertCount(1, $matches);
  }

  public function testNonLatinTerms(): void {
    $matches = $this->matcher->findMatches('Слово здоровʼя у тексті.', ["здоровʼя"]);
    $this->assertCount(1, $matches);
    $this->assertSame('здоровʼя', $matches[0]['text']);
  }

  public function testCaseInsensitive(): void {
    $matches = $this->matcher->findMatches('ARTHRITIS and arthritis.', ['Arthritis']);
    $this->assertCount(2, $matches);
  }

}

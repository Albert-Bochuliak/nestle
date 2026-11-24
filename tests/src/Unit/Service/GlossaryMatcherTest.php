<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit\Service;

use Drupal\glossary_tooltip\Service\GlossaryMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the stateless glossary matcher.
 */
#[CoversClass(GlossaryMatcher::class)]
#[Group('glossary_tooltip')]
final class GlossaryMatcherTest extends TestCase {

  /**
   * The matcher under test.
   */
  private GlossaryMatcher $matcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->matcher = new GlossaryMatcher();
  }

  /**
   * Shorthand for compile() + findMatches() in single-scenario tests.
   *
   * @return array<int, array{offset: int, length: int, text: string}>
   *   Matches, or an empty array when there is no pattern.
   */
  private function find(string $text, array $names): array {
    $pattern = $this->matcher->compile($names);
    return $pattern === NULL ? [] : $this->matcher->findMatches($text, $pattern);
  }

  /**
   * Empty names produce no pattern; empty text produces no matches.
   */
  public function testEmptyInput(): void {
    $this->assertNull($this->matcher->compile([]));
    $this->assertNull($this->matcher->compile(['', '  ']));
    $this->assertSame([], $this->matcher->findMatches('', '(?i)x'));
  }

  /**
   * Matched texts are returned in order of appearance.
   */
  #[DataProvider('matchProvider')]
  public function testMatches(string $text, array $names, array $expected): void {
    $this->assertSame($expected, array_column($this->find($text, $names), 'text'));
  }

  /**
   * Scenarios for matched texts in order of appearance.
   */
  public static function matchProvider(): array {
    return [
      'simple match' => ['Diabetes is common.', ['Diabetes'], ['Diabetes']],
      'word boundary' => ['A cat sleeps, a catalogue too.', ['cat'], ['cat']],
      'longest term wins' => ['I like whole food.', ['food', 'whole food'], ['whole food']],
      'earlier match first' => ['I like wet food and food.', ['food', 'wet food'], ['wet food', 'food']],
      'multi-word whitespace' => ["A wet\nfood dish.", ['wet food'], ["wet\nfood"]],
      'non-Latin' => ['Слово здоровʼя у тексті.', ["здоровʼя"], ["здоровʼя"]],
      'case-insensitive' => ['ARTHRITIS and arthritis.', ['Arthritis'], ['ARTHRITIS', 'arthritis']],
      'no partial word match' => ['A catalogue entry.', ['cat'], []],
      'numeric term name' => ['Error 404 found.', ['404'], ['404']],
      'numeric boundary' => ['Error 4044 found.', ['404'], []],
      // "é" as e + combining acute accent (U+0301) is a word character.
      'combining mark' => ["un cafe\u{0301} s'il vous plaît", ["cafe\u{0301}"], ["cafe\u{0301}"]],
    ];
  }

  /**
   * Byte offsets must stay consistent with substr().
   */
  public function testOffsetsAreByteConsistent(): void {
    $text = 'Привіт, diabetes тут.';
    [$match] = $this->find($text, ['diabetes']);
    $this->assertSame('diabetes', substr($text, $match['offset'], $match['length']));
  }

  /**
   * One compiled pattern must be reusable across many texts.
   */
  public function testPatternReuse(): void {
    $pattern = $this->matcher->compile(['PM', 'Project Manager']);
    $this->assertNotNull($pattern);
    $this->assertCount(2, $this->matcher->findMatches('The PM is a Project Manager', $pattern));
    $this->assertSame([], $this->matcher->findMatches('Nothing here', $pattern));
  }

  /**
   * Normalization collapses whitespace and lowercases.
   */
  #[DataProvider('normalizeProvider')]
  public function testNormalize(string $input, string $expected): void {
    $this->assertSame($expected, $this->matcher->normalize($input));
  }

  /**
   * Scenarios for normalize().
   *
   * @return array<string, array{string, string}>
   *   Input text and expected normalized form.
   */
  public static function normalizeProvider(): array {
    return [
      'multi-space' => ['wet   food', 'wet food'],
      'newline' => ["wet\nfood", 'wet food'],
      'trim + case' => ['  Diabetes  ', 'diabetes'],
      'non-Latin' => ['ЗДОРОВʼЯ', "здоровʼя"],
    ];
  }

}

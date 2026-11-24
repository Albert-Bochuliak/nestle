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
    $patterns = $this->matcher->compile($names);
    return $patterns === NULL ? [] : $this->matcher->findMatches($text, $patterns);
  }

  /**
   * Empty names produce no pattern; empty text produces no matches.
   */
  public function testEmptyInput(): void {
    $this->assertNull($this->matcher->compile([]));
    $this->assertNull($this->matcher->compile(['', '  ']));
    $this->assertSame([], $this->matcher->findMatches('', ['/(?i)x/']));
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
    $patterns = $this->matcher->compile(['PM', 'Project Manager']);
    $this->assertNotNull($patterns);
    $this->assertCount(2, $this->matcher->findMatches('The PM is a Project Manager', $patterns));
    $this->assertSame([], $this->matcher->findMatches('Nothing here', $patterns));
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

  /**
   * Filler names that push compile() past the chunking threshold.
   *
   * @param int $count
   *   How many fillers to generate.
   *
   * @return string[]
   *   Names that never match the test texts.
   */
  private static function fillers(int $count): array {
    $fillers = [];
    for ($i = 0; $i < $count; $i++) {
      $fillers[] = "xfill{$i}q";
    }
    return $fillers;
  }

  /**
   * Vocabularies past the single-pattern PCRE limit still match.
   */
  public function testLargeVocabularyChunks(): void {
    // Past the ~2 333-alternative point where a single pattern fails.
    $names = array_merge(['wet food'], self::fillers(2600), ['food', '0']);
    $patterns = $this->matcher->compile($names);
    $this->assertNotNull($patterns);
    $this->assertGreaterThan(1, count($patterns));

    $matches = $this->matcher->findMatches('A wet food dish, plain food and 0 errors.', $patterns);
    $this->assertSame(['wet food', 'food', '0'], array_column($matches, 'text'));
  }

  /**
   * Terms split across chunks keep longest-first and earliest-position wins.
   */
  public function testCrossChunkPriority(): void {
    // "wet food" lands in an earlier chunk than "food" (longest-first sort).
    $names = array_merge(['wet food'], self::fillers(1600), ['food']);
    $this->assertGreaterThan(1, count($this->matcher->compile($names) ?? []));

    $this->assertSame(['wet food', 'food'], array_column(
      $this->find('I like wet food and food.', $names),
      'text',
    ));
  }

  /**
   * A candidate suppressed inside its chunk must survive global resolution.
   *
   * In "a.x yz", chunk 1's consuming match "x yz" at offset 2 suppresses
   * "yz" at offset 4 in the same chunk. Chunk 2's "a.x" at offset 0 beats
   * "x yz" globally, freeing the "yz" position — a result the suppressed
   * candidate is required for. Lookahead patterns report every position's
   * best candidate, so the merged result equals a single pattern's.
   */
  public function testSuppressedCrossChunkCandidateSurvives(): void {
    $word = '\p{L}\p{N}\p{M}_';
    $chunk1 = '/(?<![' . $word . '])(?=((?:x\s+yz|yz)(?![' . $word . '])))/iu';
    $chunk2 = '/(?<![' . $word . '])(?=((?:a\.x)(?![' . $word . '])))/iu';

    $matches = $this->matcher->findMatches('a.x yz', [$chunk1, $chunk2]);
    $this->assertSame(['a.x', 'yz'], array_column($matches, 'text'));

    // Same term set through a single pattern: identical result.
    $this->assertSame(['a.x', 'yz'], array_column(
      $this->find('a.x yz', ['x yz', 'yz', 'a.x']),
      'text',
    ));
  }

  /**
   * Chunked matching is equivalent to single-pattern matching.
   */
  #[DataProvider('chunkEquivalenceProvider')]
  public function testChunkedEquivalentToSingle(string $text, array $names, array $expected): void {
    $single = array_column($this->find($text, $names), 'text');
    $this->assertSame($expected, $single);

    $chunked_names = array_merge($names, self::fillers(1600));
    $this->assertGreaterThan(1, count($this->matcher->compile($chunked_names) ?? []));
    $this->assertSame($single, array_column($this->find($text, $chunked_names), 'text'));
  }

  /**
   * Scenarios shared by the single-pattern and chunked assertions.
   */
  public static function chunkEquivalenceProvider(): array {
    return [
      'mixed positions' => ['a cat and a dog', ['cat', 'dog', 'a'], ['a', 'cat', 'a', 'dog']],
      'multiple terms' => ['Cats and dogs eat fish.', ['cats', 'dogs', 'fish'], ['Cats', 'dogs', 'fish']],
      'long names' => [
        'The very long multi word glossary term appears.',
        ['very long multi word glossary term', 'word'],
        ['very long multi word glossary term'],
      ],
      'numeric and special' => ['v1.2 beats v1 and 0 errors.', ['v1.2', 'v1', '0'], ['v1.2', 'v1', '0']],
    ];
  }

  /**
   * Byte offsets stay substr()-consistent in chunked mode with Unicode.
   */
  public function testChunkedUnicodeByteOffsets(): void {
    $names = array_merge(['здоровʼя', 'diabetes'], self::fillers(1600));
    $text = 'Привіт, diabetes та здоровʼя тут.';
    $matches = $this->find($text, $names);
    $this->assertCount(2, $matches);
    foreach ($matches as $match) {
      $this->assertSame($match['text'], substr($text, $match['offset'], $match['length']));
    }
  }

}

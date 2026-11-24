<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit\Dom;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\glossary_tooltip\Dom\GlossaryDomProcessor;
use Drupal\glossary_tooltip\Dom\TooltipElementBuilder;
use Drupal\glossary_tooltip\Model\HighlightContext;
use Drupal\glossary_tooltip\Service\GlossaryMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlossaryDomProcessor::class)]
#[Group('glossary_tooltip')]
final class GlossaryDomProcessorTest extends TestCase {

  private GlossaryDomProcessor $processor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn(TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $this->processor = new GlossaryDomProcessor(
      new GlossaryMatcher(),
      new TooltipElementBuilder($translation),
    );
  }

  /**
   * @param array<int, array{name: string, description: string, url: string}> $terms
   *   Term data keyed by term ID.
   */
  private function process(string $html, array $terms, bool $firstOnly = FALSE): ?string {
    $context = new HighlightContext($terms, $firstOnly, new GlossaryMatcher());
    return $this->processor->process($html, $context);
  }

  private static function terms(): array {
    return [
      1 => ['name' => 'Food', 'description' => 'Things you eat', 'url' => '/taxonomy/term/1'],
      2 => ['name' => 'Wet food', 'description' => 'Canned pet food', 'url' => '/taxonomy/term/2'],
    ];
  }

  public function testReturnsNullWhenNothingMatches(): void {
    $this->assertNull($this->process('<p>No matches here.</p>', self::terms()));
  }

  public function testWrapsMatchAndPreservesStructure(): void {
    $html = $this->process('<p>Cats like <strong>wet food</strong> today.</p>', self::terms());
    $this->assertStringContainsString('<strong>', $html);
    $this->assertStringContainsString('glossary-term__trigger', $html);
    $this->assertStringContainsString('data-glossary-term="2"', $html);
    $this->assertStringContainsString('Canned pet food', $html);
    $this->assertStringContainsString('aria-expanded="false"', $html);
    $this->assertStringContainsString('<button', $html);
  }

  /**
   * The trigger is a button; the interactive link must not sit inside it.
   */
  public function testLinkIsOutsideTriggerButton(): void {
    $html = $this->process('<p>food</p>', [
      1 => ['name' => 'Food', 'description' => str_repeat('a', 120), 'url' => '/t/1'],
    ]);
    $this->assertLessThan(strpos($html, '<a '), strpos($html, '</button>'));
  }

  /**
   * Tooltip IDs are assigned by JS at init so repeated cached fragments
   * can never collide — PHP must not emit one.
   */
  public function testNoTooltipIdInServerMarkup(): void {
    $html = $this->process('<p>food</p>', self::terms());
    $this->assertStringNotContainsString('id="glossary-tooltip', $html);
    $this->assertStringNotContainsString('aria-controls', $html);
  }

  public function testLongestTermWins(): void {
    $html = $this->process('<p>wet food</p>', self::terms());
    $this->assertStringContainsString('data-glossary-term="2"', $html);
    $this->assertStringNotContainsString('data-glossary-term="1"', $html);
  }

  public function testLinksStayUntouched(): void {
    $html = $this->process('<p><a href="/x">wet food</a> and wet food</p>', self::terms());
    // The linked text keeps the original anchor; only the plain occurrence
    // is wrapped.
    $this->assertSame(1, substr_count($html, 'data-glossary-term="'));
    $this->assertStringContainsString('<a href="/x">wet food</a>', $html);
  }

  public function testScriptAndCodeAreSkipped(): void {
    $html = $this->process('<p><code>wet food</code><script>var x = "wet food";</script></p>', self::terms());
    $this->assertNull($html);
  }

  public function testAlreadyProcessedTermNotWrappedAgain(): void {
    $once = $this->process('<p>wet food</p>', self::terms());
    $this->assertNotNull($once);
    $this->assertNull($this->process($once, self::terms()));
  }

  public function testFirstOccurrenceOnly(): void {
    $html = $this->process('<p>food, food, food</p>', self::terms(), TRUE);
    $this->assertSame(1, substr_count($html, 'data-glossary-term="'));
  }

  public function testEveryOccurrence(): void {
    $html = $this->process('<p>food, food, food</p>', self::terms(), FALSE);
    $this->assertSame(3, substr_count($html, 'data-glossary-term="'));
  }

  public function testNonLatinTerm(): void {
    $html = $this->process('<p>це здоровʼя дуже важливо</p>', [
      5 => ['name' => "здоровʼя", 'description' => 'Опис', 'url' => '/t/5'],
    ]);
    $this->assertStringContainsString('data-glossary-term="5"', $html);
  }

  #[DataProvider('descriptionProvider')]
  public function testDescriptionHandling(string $description, bool $expectLink): void {
    $html = $this->process('<p>food</p>', [
      1 => ['name' => 'Food', 'description' => $description, 'url' => '/taxonomy/term/1'],
    ]);
    $this->assertStringContainsString('aria-expanded="false"', $html);
    $this->assertSame($expectLink, str_contains($html, 'glossary-tooltip__link'));
    $this->assertSame($expectLink, str_contains($html, 'href="/taxonomy/term/1"'));
  }

  public static function descriptionProvider(): array {
    return [
      'short description, no link' => ['Short text', FALSE],
      'long description, read more' => [str_repeat('a', 120), TRUE],
    ];
  }

  /**
   * Truncated text plus the ellipsis stays within the 100-char limit.
   */
  public function testTruncationLength(): void {
    $html = $this->process('<p>food</p>', [
      1 => ['name' => 'Food', 'description' => str_repeat('a', 120), 'url' => '/t/1'],
    ]);
    preg_match('/glossary-tooltip__content[^>]*>([^<]+)</', $html, $m);
    $this->assertSame(100, mb_strlen($m[1]));
    $this->assertStringEndsWith('…', $m[1]);
  }

}

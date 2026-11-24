<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Unit\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\glossary_tooltip\Service\GlossaryTermProvider;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the term provider's description extraction.
 */
#[CoversClass(GlossaryTermProvider::class)]
#[Group('glossary_tooltip')]
final class GlossaryTermProviderTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // User::getAnonymousUser() resolves the entity definition through the
    // container; seed its memoized value directly so the unit test needs
    // no kernel.
    $anonymous = $this->createMock(UserInterface::class);
    $anonymous->method('id')->willReturn(0);
    $property = new \ReflectionProperty(User::class, 'anonymousUser');
    $property->setValue(NULL, $anonymous);
  }

  /**
   * Returns the provider result for a stubbed single-term glossary.
   *
   * @param array<int, array<string, string>> $description_items
   *   Raw field item values for the description field.
   * @param bool $description_allowed
   *   Whether view access to the description field is granted.
   * @param bool $assert_no_magic_get
   *   When TRUE, asserts magic property access is never used, proving the
   *   processed value (and thus text filters) is never touched.
   *
   * @return array{terms: array<int, array{name: string, description: string, url: string}>, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The provider result.
   */
  private function getTerms(array $description_items, bool $description_allowed = TRUE, bool $assert_no_magic_get = TRUE): array {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1]);

    $description = $this->createMock(FieldItemListInterface::class);
    $description->method('access')->willReturn(
      $description_allowed ? AccessResult::allowed() : AccessResult::forbidden(),
    );
    $description->method('getValue')->willReturn($description_items);
    if ($assert_no_magic_get) {
      // Regression guard: any magic property read (::processed, ::value)
      // on the field list is forbidden. Reading ::processed renders the
      // text format's filters, and a format containing glossary_tooltip
      // re-enters this provider — the infinite recursion root cause.
      $description->expects($this->never())->method('__get');
    }

    $name = $this->createMock(FieldItemListInterface::class);
    $name->method('access')->willReturn(AccessResult::allowed());

    $generated = (new GeneratedUrl())->setGeneratedUrl('/taxonomy/term/1');
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn($generated);

    $term = $this->createMock(TermInterface::class);
    $term->method('access')->willReturn(AccessResult::allowed());
    $term->method('get')->willReturnMap([
      ['name', $name],
      ['description', $description],
    ]);
    $term->method('getName')->willReturn('Test term');
    $term->method('toUrl')->willReturn($url);
    $term->method('id')->willReturn(1);
    $term->method('getCacheTags')->willReturn(['taxonomy_term:1']);
    $term->method('getCacheContexts')->willReturn([]);
    $term->method('getCacheMaxAge')->willReturn(-1);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([1 => $term]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturn($storage);

    $provider = new GlossaryTermProvider(
      $entity_type_manager,
      $this->createMock(EntityRepositoryInterface::class),
    );
    return $provider->getTerms();
  }

  /**
   * Descriptions are flattened from the raw value without filters.
   *
   * @param array<int, array<string, string>> $items
   *   Raw field item values.
   * @param string $expected
   *   Expected plain-text description.
   */
  #[DataProvider('descriptionCases')]
  public function testPlainTextExtraction(array $items, string $expected): void {
    $result = $this->getTerms($items);
    $this->assertSame($expected, $result['terms'][1]['description']);
  }

  /**
   * Provides raw description markup and its plain-text equivalent.
   *
   * @return array<string, array{items: array<int, array<string, string>>, expected: string}>
   *   Test cases keyed by scenario name.
   */
  public static function descriptionCases(): array {
    return [
      'plain text' => [
        'items' => [['value' => 'Simple description', 'format' => 'basic_html']],
        'expected' => 'Simple description',
      ],
      'basic html' => [
        'items' => [['value' => '<p>First</p><p>Second <b>term</b></p>', 'format' => 'basic_html']],
        'expected' => 'First Second term',
      ],
      'format with glossary filter' => [
        // basic_html has glossary_tooltip enabled on the test site; the
        // raw value is used, so the filter never runs here.
        'items' => [['value' => '<p>A <a href="/x">linked</a> term</p>', 'format' => 'basic_html']],
        'expected' => 'A linked term',
      ],
      'adjacent blocks not glued' => [
        'items' => [[
          'value' => '<div>Alpha</div><div>Beta</div><ul><li>One</li><li>Two</li></ul>',
          'format' => 'full_html',
        ]],
        'expected' => 'Alpha Beta One Two',
      ],
      'br and headings' => [
        'items' => [['value' => "<h2>Title</h2>A<br>B\n<hr>C", 'format' => 'full_html']],
        'expected' => 'Title A B C',
      ],
      'html entities' => [
        'items' => [['value' => 'P&eacute;riode &amp; &#1057;&#1083;&#1086;&#1074;&#1086;', 'format' => 'basic_html']],
        'expected' => 'Période & Слово',
      ],
      'nbsp collapsed' => [
        'items' => [['value' => '<p>a&nbsp;&nbsp;&nbsp;b</p>', 'format' => 'basic_html']],
        'expected' => 'a b',
      ],
      'script removed' => [
        'items' => [['value' => '<p>Safe</p><script>alert("x")</script>', 'format' => 'full_html']],
        'expected' => 'Safe',
      ],
      'style removed' => [
        'items' => [['value' => '<style>body{display:none}</style><p>Text</p>', 'format' => 'full_html']],
        'expected' => 'Text',
      ],
      'unclosed script swallowed' => [
        'items' => [['value' => '<p>Keep</p><script>never()', 'format' => 'full_html']],
        'expected' => 'Keep',
      ],
      'comment removed' => [
        'items' => [['value' => '<p>Visible</p><!-- hidden note -->', 'format' => 'full_html']],
        'expected' => 'Visible',
      ],
      'empty items' => [
        'items' => [],
        'expected' => '',
      ],
      'whitespace only' => [
        'items' => [['value' => '<p> &nbsp; </p>', 'format' => 'basic_html']],
        'expected' => '',
      ],
    ];
  }

  /**
   * The processed property is never read, so no filter recursion occurs.
   */
  public function testProcessedPropertyIsNeverRead(): void {
    // The __get() expectation inside getTerms() fails the test if the
    // provider ever touches a magic field property (i.e. ::processed).
    $result = $this->getTerms([['value' => '<p>Desc</p>', 'format' => 'basic_html']]);
    $this->assertSame('Desc', $result['terms'][1]['description']);
  }

  /**
   * A forbidden description field yields an empty description.
   */
  public function testDescriptionAccessDenied(): void {
    $result = $this->getTerms(
      [['value' => '<p>Hidden</p>', 'format' => 'basic_html']],
      description_allowed: FALSE,
    );
    $this->assertSame('', $result['terms'][1]['description']);
  }

  /**
   * The vocabulary list tag is bubbled into the result metadata.
   */
  public function testCacheabilityContainsListTag(): void {
    $result = $this->getTerms([['value' => 'Desc', 'format' => 'basic_html']]);
    $this->assertContains('taxonomy_term_list:glossary', $result['cacheability']->getCacheTags());
  }

}

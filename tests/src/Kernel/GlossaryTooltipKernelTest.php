<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\filter\FilterProcessResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kernel tests for the glossary_tooltip module.
 *
 * Exercises the real Drupal integration: hook_install(), real taxonomy
 * entities, the Filter API, Config API, entity access and the fail-safe
 * layer — none of which the unit suite can reach.
 */
#[Group('glossary_tooltip')]
final class GlossaryTooltipKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['user', 'filter']);
    // Anonymous term access requires the 'access content' permission.
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('access content')
      ->save();
    // Full install (not just enable) so hook_install() runs and creates the
    // vocabulary, and config/install defaults are imported.
    $this->container->get('module_installer')->install(['glossary_tooltip']);
  }

  /**
   * Hook_install() must create the glossary vocabulary.
   */
  #[Test]
  public function vocabularyCreatedOnInstall(): void {
    $vocabulary = Vocabulary::load('glossary');
    $this->assertInstanceOf(Vocabulary::class, $vocabulary);
    $this->assertSame('Glossary', $vocabulary->label());

    $definitions = $this->container->get('entity_field.manager')
      ->getBaseFieldDefinitions('taxonomy_term');
    $this->assertArrayHasKey('description', $definitions);
  }

  /**
   * The provider loads real terms and skips unpublished/inaccessible ones.
   */
  #[Test]
  public function providerLoadsPublishedTerms(): void {
    $term = $this->createTerm('Kernel Alpha', '<p>Alpha <strong>desc</strong></p>');
    $this->createTerm('Kernel Beta', 'Beta desc');
    $this->createTerm('Kernel Hidden', 'Hidden', FALSE);

    $entry = $this->container->get('glossary_tooltip.term_provider')->getTerms();

    $this->assertCount(2, $entry['terms']);
    $this->assertSame('Kernel Alpha', $entry['terms'][$term->id()]['name']);
    $this->assertSame('Alpha desc', $entry['terms'][$term->id()]['description']);
    $this->assertStringContainsString('taxonomy/term/' . $term->id(), $entry['terms'][$term->id()]['url']);
    $this->assertContains('taxonomy_term_list:glossary', $entry['cacheability']->getCacheTags());
  }

  /**
   * The filter highlights and bubbles cacheability + library attachments.
   */
  #[Test]
  public function filterHighlightsAndBubblesMetadata(): void {
    $this->createTerm('Filter Term', 'Filter description');

    $result = $this->glossaryFilter()->process('<p>Text with Filter Term inside.</p>', 'en');

    $this->assertInstanceOf(FilterProcessResult::class, $result);
    $processed = $result->getProcessedText();
    $this->assertStringContainsString('glossary-term', $processed);
    $this->assertStringContainsString('Filter description', $processed);
    $this->assertContains('taxonomy_term_list:glossary', $result->getCacheTags());
    $this->assertContains('config:glossary_tooltip.settings', $result->getCacheTags());
    $this->assertContains('glossary_tooltip/glossary_tooltip', $result->getAttachments()['library'] ?? []);
  }

  /**
   * No match: text passes through unchanged, no library attached.
   */
  #[Test]
  public function filterWithoutMatchAttachesNoLibrary(): void {
    $this->createTerm('Absent Term', 'Desc');

    $result = $this->glossaryFilter()->process('<p>No glossary words here.</p>', 'en');

    $this->assertSame('<p>No glossary words here.</p>', $result->getProcessedText());
    $this->assertSame([], $result->getAttachments());
  }

  /**
   * Config API toggles first-occurrence vs every-occurrence highlighting.
   */
  #[Test]
  public function configControlsOccurrenceMode(): void {
    $this->createTerm('Toggle', 'Toggle desc');
    $filter = $this->glossaryFilter();

    $result = $filter->process('<p>Toggle and Toggle.</p>', 'en');
    $this->assertSame(1, substr_count($result->getProcessedText(), 'glossary-term__trigger'));

    $this->config('glossary_tooltip.settings')
      ->set('first_occurrence_only', FALSE)
      ->save();

    $result = $filter->process('<p>Toggle and Toggle.</p>', 'en');
    $this->assertSame(2, substr_count($result->getProcessedText(), 'glossary-term__trigger'));
  }

  /**
   * Fail-safe: a load failure returns the original text with max-age 0.
   *
   * Dropping the data table makes the provider's entity query throw a real
   * database exception — the only Throwable seam reachable without touching
   * the final production services.
   */
  #[Test]
  public function failSafeReturnsOriginalText(): void {
    $this->createTerm('Boom', 'Boom desc');

    $this->container->get('database')->query('DROP TABLE {taxonomy_term_field_data}');

    $result = $this->glossaryFilter()->process('<p>Mention Boom.</p>', 'en');

    $this->assertSame('<p>Mention Boom.</p>', $result->getProcessedText());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * The glossary filter plugin as configured by a real text format.
   */
  private function glossaryFilter() {
    $format = FilterFormat::load('glossary_test') ?? FilterFormat::create([
      'format' => 'glossary_test',
      'name' => 'Glossary test',
      'filters' => [
        'glossary_tooltip' => ['status' => 1, 'weight' => 0, 'settings' => []],
      ],
    ]);
    if ($format->isNew()) {
      $format->save();
    }
    return $format->filters('glossary_tooltip');
  }

  /**
   * Creates a glossary term.
   */
  private function createTerm(string $name, string $description, bool $published = TRUE): TermInterface {
    $term = Term::create([
      'vid' => 'glossary',
      'name' => $name,
      'description' => ['value' => $description, 'format' => 'basic_html'],
      'status' => (int) $published,
    ]);
    $term->save();
    return $term;
  }

}

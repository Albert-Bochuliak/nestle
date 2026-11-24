<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional test for automatic cache invalidation of glossary tooltips.
 *
 * One end-to-end lifecycle: every entity change must be reflected in the
 * rendered HTML on the next request, with no manual cache clears — the
 * provider's cache tags have to do the work.
 */
#[Group('glossary_tooltip')]
final class GlossaryTooltipCacheInvalidationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['glossary_tooltip'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FilterFormat::load('basic_html')
      ->setFilterConfig('glossary_tooltip', ['status' => TRUE, 'weight' => 10])
      ->save();
    // Drupal 11 standard profile no longer ships node types, and
    // node_add_body_field() is deprecated — create the type and its body
    // field storage + instance explicitly.
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    FieldStorageConfig::loadByName('node', 'body') ?? FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Body',
    ])->save();
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'page')
      ->setComponent('body', ['type' => 'text_default', 'settings' => [], 'weight' => 0])
      ->save();
  }

  /**
   * Full lifecycle: create → edit → rename → unpublish → republish → delete.
   */
  #[Test]
  public function cacheInvalidationLifecycle(): void {
    $term = Term::create([
      'vid' => 'glossary',
      'name' => 'Lifecycle Term',
      'description' => ['value' => '<p>Description version one</p>', 'format' => 'basic_html'],
      'status' => 1,
    ]);
    $term->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'Glossary page',
      'body' => ['value' => '<p>This mentions Lifecycle Term inline.</p>', 'format' => 'basic_html'],
      'status' => 1,
    ]);
    $node->save();
    $url = 'node/' . $node->id();
    $assert = $this->assertSession();

    // Term created: tooltip rendered. Second hit is served from the page
    // cache — it must show the same markup.
    $this->drupalGet($url);
    $assert->elementExists('css', 'span.glossary-term');
    $assert->pageTextContains('Description version one');
    $this->drupalGet($url);
    $assert->elementExists('css', 'span.glossary-term');

    // Description edited: new text appears without a cache clear.
    $term->set('description', ['value' => '<p>Description version two</p>', 'format' => 'basic_html'])->save();
    $this->drupalGet($url);
    $assert->elementExists('css', 'span.glossary-term');
    $assert->pageTextContains('Description version two');
    $assert->pageTextNotContains('Description version one');

    // Term renamed away from the body text: highlight disappears.
    $term->setName('Renamed Term')->save();
    $this->drupalGet($url);
    $assert->elementNotExists('css', 'span.glossary-term');
    $assert->pageTextContains('Lifecycle Term');

    // Renamed back: highlight returns.
    $term->setName('Lifecycle Term')->save();
    $this->drupalGet($url);
    $assert->elementExists('css', 'span.glossary-term');

    // Unpublished: highlight disappears.
    $term->set('status', 0)->save();
    $this->drupalGet($url);
    $assert->elementNotExists('css', 'span.glossary-term');

    // Republished: highlight returns.
    $term->set('status', 1)->save();
    $this->drupalGet($url);
    $assert->elementExists('css', 'span.glossary-term');

    // Deleted: highlight disappears for good.
    $term->delete();
    $this->drupalGet($url);
    $assert->elementNotExists('css', 'span.glossary-term');
    $assert->pageTextContains('Lifecycle Term');
  }

}

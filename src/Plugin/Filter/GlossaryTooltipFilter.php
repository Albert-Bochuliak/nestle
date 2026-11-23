<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\glossary_tooltip\GlossaryHighlighter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Highlights glossary terms in filtered text fields.
 *
 * Enable this filter on any text format (for example "Basic HTML") to scan
 * that format's fields inside every content type.
 */
#[Filter(
  id: 'glossary_tooltip',
  title: new TranslatableMarkup('Glossary Tooltip'),
  description: new TranslatableMarkup('Highlights terms from the glossary vocabulary and shows their descriptions in a tooltip.'),
  type: FilterInterface::TYPE_MARKUP_LANGUAGE,
)]
final class GlossaryTooltipFilter extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly GlossaryHighlighter $highlighter,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      (string) $plugin_id,
      (array) $plugin_definition,
      $container->get('glossary_tooltip.highlighter'),
      $container->get('logger.channel.glossary_tooltip'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult((string) $text);

    try {
      $highlighted = $this->highlighter->highlight((string) $text);

      $result->setProcessedText($highlighted['text']);
      $result->addCacheTags($highlighted['cache_tags']);

      if ($highlighted['matched']) {
        $result->addAttachments([
          'library' => ['glossary_tooltip/glossary_tooltip'],
        ]);
      }
    }
    catch (\Throwable $exception) {
      // Never break rendering: on failure return the text unchanged.
      $this->logger->error('Glossary Tooltip filter failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return $result;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Glossary Tooltip module.
 */
final class GlossaryTooltipSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['glossary_tooltip.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'glossary_tooltip_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // #config_target handles the default value and persistence natively;
    // no custom submitForm is needed.
    $form['first_occurrence_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Highlight only the first occurrence of each term'),
      '#description' => $this->t('When disabled, every occurrence of a glossary term is highlighted.'),
      '#config_target' => 'glossary_tooltip.settings:first_occurrence_only',
    ];

    return parent::buildForm($form, $form_state);
  }

}

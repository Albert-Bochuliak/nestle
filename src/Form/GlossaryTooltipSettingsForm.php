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
    $config = $this->config('glossary_tooltip.settings');

    $form['first_occurrence_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Highlight only the first occurrence of each term'),
      '#description' => $this->t('When disabled, every occurrence of a glossary term is highlighted.'),
      '#default_value' => (bool) $config->get('first_occurrence_only'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->config('glossary_tooltip.settings')
        ->set('first_occurrence_only', (bool) $form_state->getValue('first_occurrence_only'))
        ->save();
    }
    catch (\Throwable $exception) {
      $this->logger('glossary_tooltip')->error('Failed to save Glossary Tooltip settings: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('The settings could not be saved.'));
      return;
    }

    parent::submitForm($form, $form_state);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Tuition rates, fees, and financial hold settings.
 */
final class BillingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['mc_college_billing.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mc_college_billing_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('mc_college_billing.settings');
    $form['notice'] = [
      '#markup' => '<div class="messages messages--warning">' . $this->t('Changing rates does not re-bill existing terms automatically. Bills are recalculated when a registration or residency changes, or by running <code>drush php:script scripts/mc_seed_billing.php</code>.') . '</div>',
    ];
    $form['tuition'] = ['#type' => 'details', '#title' => $this->t('Tuition per credit hour'), '#open' => TRUE, '#tree' => TRUE];
    foreach (['in_district' => $this->t('In-District'), 'out_of_district' => $this->t('Out-of-District'), 'out_of_state' => $this->t('Out-of-State')] as $key => $label) {
      $form['tuition'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#field_prefix' => '$',
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $config->get("tuition_per_credit.$key"),
        '#required' => TRUE,
      ];
    }
    $form['fees'] = ['#type' => 'details', '#title' => $this->t('Fees'), '#open' => TRUE];
    $form['fees']['technology_fee_per_credit'] = [
      '#type' => 'number',
      '#title' => $this->t('Technology fee per credit hour'),
      '#field_prefix' => '$',
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $config->get('technology_fee_per_credit'),
    ];
    $form['fees']['term_fees'] = ['#type' => 'container', '#tree' => TRUE];
    foreach ((array) $config->get('term_fees') as $id => $fee) {
      $form['fees']['term_fees'][$id] = [
        '#type' => 'number',
        '#title' => $this->t('@label (per term)', ['@label' => $fee['label']]),
        '#field_prefix' => '$',
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $fee['amount'],
      ];
    }
    $form['payment_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Payment instructions shown to students'),
      '#default_value' => $config->get('payment_instructions'),
      '#rows' => 3,
    ];
    $form['hold'] = ['#type' => 'details', '#title' => $this->t('Financial hold'), '#open' => TRUE];
    $form['hold']['financial_hold_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Block new registrations while a balance from an earlier semester is unpaid'),
      '#default_value' => $config->get('financial_hold_enabled'),
    ];
    $form['hold']['financial_hold_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Allowed past-due balance before the hold applies'),
      '#field_prefix' => '$',
      '#min' => 0,
      '#step' => 0.01,
      '#default_value' => $config->get('financial_hold_threshold'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('mc_college_billing.settings');
    foreach ($form_state->getValue('tuition') as $key => $value) {
      $config->set("tuition_per_credit.$key", number_format((float) $value, 2, '.', ''));
    }
    $fees = (array) $config->get('term_fees');
    foreach ((array) $form_state->getValue('term_fees') as $id => $amount) {
      $fees[$id]['amount'] = number_format((float) $amount, 2, '.', '');
    }
    $config
      ->set('term_fees', $fees)
      ->set('technology_fee_per_credit', number_format((float) $form_state->getValue('technology_fee_per_credit'), 2, '.', ''))
      ->set('payment_instructions', $form_state->getValue('payment_instructions'))
      ->set('financial_hold_enabled', (bool) $form_state->getValue('financial_hold_enabled'))
      ->set('financial_hold_threshold', number_format((float) $form_state->getValue('financial_hold_threshold'), 2, '.', ''))
      ->save();
    parent::submitForm($form, $form_state);
  }

}

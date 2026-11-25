<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;

/**
 * Academic policy settings.
 */
final class SettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mc_college_core_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['standing'] = [
      '#type' => 'details',
      '#title' => $this->t('Academic standing'),
      '#open' => TRUE,
    ];
    $form['standing']['probation_gpa'] = [
      '#type' => 'number',
      '#title' => $this->t('Probation below cumulative GPA'),
      '#min' => 0, '#max' => 4, '#step' => 0.01,
      '#config_target' => 'mc_college_core.settings:probation_gpa',
    ];
    $form['standing']['deans_list_gpa'] = [
      '#type' => 'number',
      '#title' => $this->t("Dean's List minimum term GPA"),
      '#min' => 0, '#max' => 4, '#step' => 0.01,
      '#config_target' => 'mc_college_core.settings:deans_list_gpa',
    ];
    $form['standing']['deans_list_min_credits'] = [
      '#type' => 'number',
      '#title' => $this->t("Dean's List minimum graded credits in the term"),
      '#min' => 0,
      '#config_target' => 'mc_college_core.settings:deans_list_min_credits',
    ];
    $form['matriculation'] = [
      '#type' => 'details',
      '#title' => $this->t('Non-matriculated students'),
      '#open' => TRUE,
    ];
    $form['matriculation']['non_matriculated_credit_cap'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum credits per semester'),
      '#min' => 1,
      '#config_target' => 'mc_college_core.settings:non_matriculated_credit_cap',
    ];
    $form['matriculation']['matriculation_credit_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Must matriculate after earning this many credits'),
      '#min' => 1,
      '#config_target' => 'mc_college_core.settings:matriculation_credit_threshold',
    ];
    $form['matriculation']['enforce_matriculation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Block registration once the threshold is reached'),
      '#config_target' => 'mc_college_core.settings:enforce_matriculation',
    ];
    return parent::buildForm($form, $form_state);
  }

}

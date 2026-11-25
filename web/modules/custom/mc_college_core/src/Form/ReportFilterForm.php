<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mc_college_core\Academic\AcademicRecords;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET filter form shared by the analytics and student reports.
 */
final class ReportFilterForm extends FormBase {

  public function __construct(
    private readonly AcademicRecords $records,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('mc_college_core.academic_records'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mc_college_core_report_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string[] $filters
   *   Which filters to show: semester, student_type, department, standing,
   *   must_matriculate.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $filters = []): array {
    $query = $this->getRequest()->query;
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [self::class, 'removeFormBuildElements'];
    $form['#attributes']['class'][] = 'mc-filters';
    $form['filters'] = ['#type' => 'container', '#attributes' => ['class' => ['form--inline', 'clearfix']]];

    if (in_array('semester', $filters, TRUE)) {
      $form['filters']['semester'] = [
        '#type' => 'select',
        '#title' => $this->t('Semester'),
        '#options' => ['' => $this->t('- All -')] + $this->records->getSemesterOptions(),
        '#default_value' => $query->get('semester', ''),
      ];
    }
    if (in_array('department', $filters, TRUE)) {
      $form['filters']['department'] = [
        '#type' => 'select',
        '#title' => $this->t('Department'),
        '#options' => ['' => $this->t('- All -')] + $this->records->getDepartmentOptions(),
        '#default_value' => $query->get('department', ''),
      ];
    }
    if (in_array('student_type', $filters, TRUE)) {
      $form['filters']['student_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Student type'),
        '#options' => [
          '' => $this->t('- All -'),
          'matriculated' => $this->t('Matriculated'),
          'non_matriculated' => $this->t('Non-matriculated'),
        ],
        '#default_value' => $query->get('student_type', ''),
      ];
    }
    if (in_array('standing', $filters, TRUE)) {
      $form['filters']['standing'] = [
        '#type' => 'select',
        '#title' => $this->t('Standing'),
        '#options' => [
          '' => $this->t('- All -'),
          'good' => $this->t('Good Standing'),
          'probation' => $this->t('Academic Probation'),
          'none' => $this->t('No GPA yet'),
        ],
        '#default_value' => $query->get('standing', ''),
      ];
    }
    if (in_array('must_matriculate', $filters, TRUE)) {
      $form['filters']['must_matriculate'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Must matriculate'),
        '#default_value' => (bool) $query->get('must_matriculate'),
      ];
    }
    $form['filters']['actions'] = ['#type' => 'actions'];
    $form['filters']['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Apply')];
    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('<current>'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * Keeps GET URLs clean of form_build_id/form_id/op.
   */
  public static function removeFormBuildElements(array $form): array {
    unset($form['form_build_id'], $form['form_id'], $form['filters']['actions']['submit']['#name']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET form: values arrive as query parameters.
  }

}

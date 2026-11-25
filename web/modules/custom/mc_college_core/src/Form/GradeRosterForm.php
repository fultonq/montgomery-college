<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mc_college_core\Enrollment\EnrollmentManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Roster with bulk grade entry for a course session.
 *
 * Entering a grade marks the registration Completed. Withdrawn and waitlisted
 * students are listed read-only.
 */
final class GradeRosterForm extends FormBase {

  private const GRADES = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'F' => 'F', 'P' => 'P (Pass)', 'I' => 'I (Incomplete)'];

  public function __construct(
    private readonly EnrollmentManager $enrollment,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('mc_college_core.enrollment'));
  }

  /**
   * Route access: the session's professor or registration staff.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if ($node->bundle() !== 'course_session') {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }
    $teaches = $account->hasPermission('grade own course sessions')
      && (int) $node->get('field_professor')->target_id === (int) $account->id();
    return AccessResult::allowedIf($teaches || $account->hasPermission('administer course_session registration'))
      ->cachePerPermissions()->cachePerUser()->addCacheableDependency($node);
  }

  /**
   * Title callback.
   */
  public static function title(NodeInterface $node): string {
    return (string) t('Roster and grades: @session', ['@session' => $node->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mc_college_core_grade_roster';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('session_nid', $node->id());
    $semester = $node->get('field_semester')->entity;
    $form['#attached']['library'][] = 'mc_college_core/academic';
    $form['info'] = [
      '#markup' => '<p class="mc-muted">' . $this->t('@course · @meets · @location. Entering a grade marks the student Completed for @term. Only A-F count toward GPA.', [
        '@course' => $node->get('field_course')->entity?->label(),
        '@meets' => $this->enrollment->formatMeeting($node),
        '@location' => $node->get('field_location')->value,
        '@term' => $semester?->label(),
      ]) . '</p>',
    ];
    $form['roster'] = [
      '#type' => 'table',
      '#header' => [$this->t('Student'), $this->t('Student ID'), $this->t('Type'), $this->t('Status'), $this->t('Grade')],
      '#empty' => $this->t('No students registered.'),
      '#attributes' => ['class' => ['mc-table']],
    ];
    foreach ($this->enrollment->getRoster($node) as $id => $registration) {
      $user = $registration->getUser();
      $state = $registration->get('state')->value;
      $gradable = in_array($state, ['enrolled', 'completed'], TRUE);
      $form['roster'][$id]['name'] = ['#plain_text' => $user?->getDisplayName() ?? $registration->getEmail()];
      $form['roster'][$id]['sid'] = ['#plain_text' => $user?->get('field_student_id')->value ?? ''];
      $form['roster'][$id]['type'] = ['#plain_text' => $user?->get('field_student_type')->value === 'matriculated' ? $this->t('Matriculated') : $this->t('Non-matriculated')];
      $form['roster'][$id]['state'] = ['#plain_text' => ucfirst($state)];
      $form['roster'][$id]['grade'] = $gradable
        ? [
          '#type' => 'select',
          '#title' => $this->t('Grade for @name', ['@name' => $user?->getDisplayName()]),
          '#title_display' => 'invisible',
          '#options' => self::GRADES,
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $registration->get('field_grade')->value,
        ]
        : ['#plain_text' => $state === 'withdrawn' ? 'W' : '—'];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save grades'), '#button_type' => 'primary'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = \Drupal::entityTypeManager()->getStorage('registration');
    $changed = 0;
    foreach ($form_state->getValue('roster') ?: [] as $id => $values) {
      if (!isset($values['grade'])) {
        continue;
      }
      $registration = $storage->load($id);
      if (!$registration || !in_array($registration->get('state')->value, ['enrolled', 'completed'], TRUE)) {
        continue;
      }
      $grade = $values['grade'] === '' ? NULL : $values['grade'];
      if ($registration->get('field_grade')->value === $grade) {
        continue;
      }
      $registration->set('field_grade', $grade);
      if ($grade !== NULL) {
        $registration->set('state', 'completed');
      }
      $registration->save();
      $changed++;
    }
    $this->messenger()->addStatus($this->formatPlural($changed, 'Saved 1 grade.', 'Saved @count grades.'));
  }

}

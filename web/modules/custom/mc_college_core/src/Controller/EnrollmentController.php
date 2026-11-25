<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mc_college_core\Academic\AcademicRecords;
use Drupal\mc_college_core\Enrollment\EnrollmentManager;
use Drupal\mc_college_core\Form\ReportFilterForm;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Student schedule, course search, and professor teaching pages.
 */
final class EnrollmentController extends ControllerBase {

  private const STATE_LABELS = [
    'enrolled' => 'Enrolled',
    'waitlisted' => 'Waitlisted',
    'completed' => 'Completed',
    'withdrawn' => 'Withdrawn (W)',
    'dropped' => 'Dropped',
  ];

  public function __construct(
    private readonly EnrollmentManager $enrollment,
    private readonly AcademicRecords $records,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mc_college_core.enrollment'),
      $container->get('mc_college_core.academic_records'),
    );
  }

  /**
   * Access to a student's schedule: the student or registration staff.
   */
  public function scheduleAccess(UserInterface $user, AccountInterface $account): AccessResultInterface {
    $own = $account->isAuthenticated() && (int) $account->id() === (int) $user->id()
      && $account->hasPermission('manage own course registrations');
    return AccessResult::allowedIf($own || $account->hasPermission('view any course_session registration'))
      ->cachePerPermissions()->cachePerUser();
  }

  /**
   * Access to a teaching page: the professor or registration staff.
   */
  public function teachingAccess(UserInterface $user, AccountInterface $account): AccessResultInterface {
    $own = $account->isAuthenticated() && (int) $account->id() === (int) $user->id()
      && $account->hasPermission('grade own course sessions');
    return AccessResult::allowedIf($own || $account->hasPermission('administer course_session registration'))
      ->cachePerPermissions()->cachePerUser();
  }

  /**
   * A student's current and upcoming schedule with add/drop/withdraw.
   */
  public function schedule(UserInterface $user): array {
    $account = $this->currentUser();
    $build = [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => ['max-age' => 0],
      'intro' => [
        '#markup' => '<p class="mc-muted">' . $this->t('Drop a course through the semester Drop Deadline (no transcript entry, no tuition). After that, you may Withdraw through the Withdraw Deadline (W on transcript, tuition still owed).') . '</p>',
      ],
      'find' => [
        '#type' => 'link',
        '#title' => $this->t('Find and add courses'),
        '#url' => Url::fromRoute('mc_college_core.course_schedule'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    $type = $user->hasField('field_student_type') ? $user->get('field_student_type')->value : NULL;
    if ($type === 'non_matriculated') {
      $build['policy'] = [
        '#markup' => '<div class="mc-alert mc-alert--info">' . $this->t('Non-matriculated students may take up to @cap credits per semester and must matriculate after earning @n credits.', [
          '@cap' => $this->records->setting('non_matriculated_credit_cap', 11),
          '@n' => $this->records->setting('matriculation_credit_threshold', 12),
        ]) . '</div>',
      ];
    }

    $terms = $this->enrollment->getCurrentSchedule((int) $user->id());
    foreach ($terms as $nid => $term) {
      /** @var \Drupal\node\NodeInterface $semester */
      $semester = $term['semester'];
      $rows = [];
      $credits = 0;
      foreach ($term['registrations'] as $registration) {
        /** @var \Drupal\registration\Entity\RegistrationInterface $registration */
        $session = $registration->getHostEntity()?->getEntity();
        if (!$session instanceof NodeInterface) {
          continue;
        }
        $course = $session->get('field_course')->entity;
        $state = $registration->get('state')->value;
        $course_credits = (int) $course?->get('field_credits')->value;
        if (in_array($state, ['enrolled', 'completed'], TRUE)) {
          $credits += $course_credits;
        }
        $actions = [];
        foreach (['drop' => $this->t('Drop'), 'withdraw' => $this->t('Withdraw')] as $action => $label) {
          if ($this->enrollment->canPerform($registration, $action, $account)) {
            $actions[$action] = [
              '#type' => 'link',
              '#title' => $state === 'waitlisted' ? $this->t('Leave waitlist') : $label,
              '#url' => Url::fromRoute('mc_college_core.registration_action', ['registration' => $registration->id(), 'action' => $action]),
              '#attributes' => ['class' => ['button', 'button--small', $action === 'withdraw' ? 'button--danger' : '']],
            ];
          }
        }
        $rows[] = [
          ['data' => ['#type' => 'link', '#title' => $session->label(), '#url' => $session->toUrl()]],
          $course?->label() ?? '',
          $this->enrollment->formatMeeting($session),
          $session->get('field_location')->value ?? '',
          $session->get('field_professor')->entity?->getDisplayName() ?? $this->t('TBA'),
          (string) $course_credits,
          $this->t(self::STATE_LABELS[$state] ?? $state),
          ['data' => $actions ?: ['#markup' => '—']],
        ];
      }
      $build['term_' . $nid] = [
        '#type' => 'table',
        '#caption' => $this->t('@term — @credits credits (Drop by @drop, Withdraw by @withdraw)', [
          '@term' => $semester->label(),
          '@credits' => $credits,
          '@drop' => $this->formatDate($semester->get('field_drop_deadline')->value),
          '@withdraw' => $this->formatDate($semester->get('field_withdraw_deadline')->value),
        ]),
        '#header' => [
          $this->t('Session'), $this->t('Course'), $this->t('Meets'), $this->t('Location'),
          $this->t('Professor'), $this->t('Credits'), $this->t('Status'), $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['mc-table']],
      ];
    }
    if (!$terms) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No current or upcoming registrations.') . '</p>'];
    }
    return $build;
  }

  /**
   * Searchable course schedule with registration links.
   */
  public function courseSchedule(Request $request): array {
    $semesters = $this->records->getSemesterOptions();
    $semester = (int) ($request->query->get('semester') ?: $this->defaultSemester($semesters));
    $department = (int) $request->query->get('department');

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'course_session')
      ->condition('status', 1)
      ->sort('title')
      ->pager(50);
    if ($semester) {
      $query->condition('field_semester', $semester);
    }
    if ($department) {
      $query->condition('field_course.entity:node.field_department', $department);
    }
    $sessions = $this->entityTypeManager()->getStorage('node')->loadMultiple($query->execute());

    $rows = [];
    foreach ($sessions as $session) {
      /** @var \Drupal\node\NodeInterface $session */
      $course = $session->get('field_course')->entity;
      $host = $session->get('field_registration')->createHostEntity();
      $remaining = $host->getSpacesRemaining();
      $open = $host->isOpenForRegistration();
      $register = $open && $host->access('register self', $this->currentUser())
        ? ['#type' => 'link', '#title' => $remaining === 0 ? $this->t('Join waitlist') : $this->t('Register'), '#url' => Url::fromRoute('entity.node.registration.register', ['node' => $session->id()]), '#attributes' => ['class' => ['button', 'button--small', 'button--primary']]]
        : ['#markup' => $open ? $this->t('Log in as a student') : $this->t('Closed')];
      $rows[] = [
        ['data' => ['#type' => 'link', '#title' => $session->label(), '#url' => $session->toUrl()]],
        $course?->label() ?? '',
        $course?->get('field_department')->entity?->label() ?? '',
        (string) $course?->get('field_credits')->value,
        $this->enrollment->formatMeeting($session),
        $session->get('field_location')->value ?? '',
        $session->get('field_professor')->entity?->getDisplayName() ?? $this->t('TBA'),
        $remaining === NULL ? '—' : (string) $remaining,
        $session->get('field_requires_matriculation')->value ? $this->t('Matriculated only') : '',
        ['data' => $register],
      ];
    }

    return [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => ['contexts' => ['url.query_args', 'user'], 'tags' => ['node_list', 'registration_list']],
      'filters' => $this->formBuilder()->getForm(ReportFilterForm::class, ['semester', 'department']),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Session'), $this->t('Course'), $this->t('Department'), $this->t('Credits'),
          $this->t('Meets'), $this->t('Location'), $this->t('Professor'), $this->t('Seats left'),
          $this->t('Restrictions'), '',
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No course sessions match.'),
        '#attributes' => ['class' => ['mc-table']],
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  /**
   * A professor's course sessions with roster counts and grading links.
   */
  public function teaching(UserInterface $user): array {
    $build = ['#attached' => ['library' => ['mc_college_core/academic']], '#cache' => ['max-age' => 0]];
    foreach ($this->enrollment->getTeachingSessions((int) $user->id()) as $nid => $term) {
      $rows = [];
      foreach ($term['sessions'] as $session) {
        $roster = $this->enrollment->getRoster($session);
        $counts = array_count_values(array_map(fn($r) => $r->get('state')->value, $roster));
        $graded = count(array_filter($roster, fn($r) => !$r->get('field_grade')->isEmpty()));
        $seated = ($counts['enrolled'] ?? 0) + ($counts['completed'] ?? 0);
        $rows[] = [
          ['data' => ['#type' => 'link', '#title' => $session->label(), '#url' => $session->toUrl()]],
          $this->enrollment->formatMeeting($session),
          $session->get('field_location')->value ?? '',
          $seated . ' / ' . (int) $session->get('field_capacity')->value,
          (string) ($counts['waitlisted'] ?? 0),
          (string) ($counts['withdrawn'] ?? 0),
          $graded . ' / ' . $seated,
          ['data' => ['#type' => 'link', '#title' => $this->t('Roster and grades'), '#url' => Url::fromRoute('mc_college_core.grade_roster', ['node' => $session->id()]), '#attributes' => ['class' => ['button', 'button--small']]]],
        ];
      }
      $build['term_' . $nid] = [
        '#type' => 'table',
        '#caption' => $term['semester']->label(),
        '#header' => [
          $this->t('Session'), $this->t('Meets'), $this->t('Location'), $this->t('Enrolled'),
          $this->t('Waitlisted'), $this->t('Withdrawn'), $this->t('Graded'), '',
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['mc-table']],
      ];
    }
    if (count($build) === 2) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No course sessions assigned.') . '</p>'];
    }
    return $build;
  }

  /**
   * The current term (contains today), else the next upcoming one.
   */
  private function defaultSemester(array $semesters): int {
    $today = $this->enrollment->today();
    $best = 0;
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple(array_keys($semesters)) as $semester) {
      $start = $semester->get('field_start_date')->value;
      $end = $semester->get('field_end_date')->value;
      if ($start <= $today && $today <= $end) {
        return (int) $semester->id();
      }
      if ($start > $today && (!$best || $start < $this->entityTypeManager()->getStorage('node')->load($best)->get('field_start_date')->value)) {
        $best = (int) $semester->id();
      }
    }
    return $best;
  }

  private function formatDate(?string $date): string {
    return $date ? date('M j, Y', strtotime($date)) : '—';
  }

}

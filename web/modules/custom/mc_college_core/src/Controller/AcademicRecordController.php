<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Session\AccountInterface;
use Drupal\mc_college_core\Academic\AcademicRecords;
use Drupal\mc_college_core\Academic\CreditSummary;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Student performance report and transcript pages.
 */
final class AcademicRecordController extends ControllerBase {

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
   * Access: the student themself, or staff who may view academic records.
   */
  public function access(UserInterface $user, AccountInterface $account): AccessResultInterface {
    $own = $account->isAuthenticated()
      && (int) $account->id() === (int) $user->id()
      && $account->hasPermission('view own academic record');
    return AccessResult::allowedIf($own || $account->hasPermission('view student academic records'))
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($user);
  }

  /**
   * Title callback.
   */
  public function recordTitle(UserInterface $user): string {
    return (string) $this->t('Academic record: @name', ['@name' => $user->getDisplayName()]);
  }

  /**
   * Title callback.
   */
  public function transcriptTitle(UserInterface $user): string {
    return (string) $this->t('Unofficial transcript: @name', ['@name' => $user->getDisplayName()]);
  }

  /**
   * Student performance report.
   */
  public function record(UserInterface $user): array {
    $transcript = $this->records->getTranscript((int) $user->id());
    /** @var \Drupal\mc_college_core\Academic\CreditSummary $cumulative */
    $cumulative = $transcript['cumulative'];
    $standing = $this->records->getStanding($cumulative);
    $matric = $this->records->getMatriculationStatus($user, $cumulative);

    $build = $this->baseBuild($user);
    $build['profile'] = $this->profileTable($user);

    $build['alerts'] = ['#weight' => -10];
    if ($standing['key'] === 'probation') {
      $build['alerts']['probation'] = $this->alert('warning', $this->t('Cumulative GPA is below @gpa. The student is on academic probation and should meet with an advisor.', [
        '@gpa' => number_format((float) $this->records->setting('probation_gpa', 2.0), 2),
      ]));
    }
    if ($matric['must_matriculate']) {
      $build['alerts']['matriculate'] = $this->alert('error', $this->t('Non-matriculated students must matriculate after earning @n credits. This student has earned @earned and cannot register for more courses until admitted to a program.', [
        '@n' => $matric['threshold'],
        '@earned' => $matric['earned'],
      ]));
    }
    elseif (!$matric['matriculated'] && $matric['type'] !== NULL) {
      $build['alerts']['nonmatric'] = $this->alert('info', $this->t('Non-matriculated: limited to @cap credits per semester. @left more credits can be earned before matriculation is required.', [
        '@cap' => $matric['credit_cap'],
        '@left' => $matric['credits_until_threshold'],
      ]));
    }

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mc-stats']],
      'gpa' => $this->stat($this->t('Cumulative GPA'), $cumulative->formattedGpa()),
      'standing' => $this->stat($this->t('Academic standing'), $standing['label'], 'mc-standing--' . $standing['key']),
      'earned' => $this->stat($this->t('Credits earned'), (string) $cumulative->earned),
      'attempted' => $this->stat($this->t('Credits attempted'), (string) $cumulative->attempted),
      'completion' => $this->stat($this->t('Completion rate'), $cumulative->completionRate() === NULL ? '—' : $cumulative->completionRate() . '%'),
      'progress' => $this->stat($this->t('Credits in progress'), (string) $cumulative->inProgress),
    ];

    $rows = [];
    foreach ($transcript['terms'] as $term) {
      /** @var \Drupal\mc_college_core\Academic\CreditSummary $s */
      $s = $term['summary'];
      $gpa = $s->gpa();
      $rows[] = [
        $term['title'],
        (string) $s->attempted,
        (string) $s->earned,
        (string) $s->inProgress,
        $s->formattedGpa(),
        $term['cumulative']->formattedGpa(),
        [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span class="mc-bar" role="img" aria-label="{{ label }}"><span style="width: {{ width }}%"></span></span>',
            '#context' => [
              'width' => $gpa === NULL ? 0 : round($gpa / 4 * 100),
              'label' => $this->t('Term GPA @gpa of 4.00', ['@gpa' => $s->formattedGpa()]),
            ],
          ],
        ],
        $term['deans_list'] ? $this->t("Dean's List") : '',
      ];
    }
    $build['terms'] = [
      '#type' => 'table',
      '#caption' => $this->t('Performance by semester'),
      '#header' => [
        $this->t('Semester'),
        $this->t('Attempted'),
        $this->t('Earned'),
        $this->t('In progress'),
        $this->t('Term GPA'),
        $this->t('Cumulative GPA'),
        $this->t('Trend'),
        $this->t('Honors'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No course history yet.'),
      '#attributes' => ['class' => ['mc-table']],
    ];

    $build['links'] = [
      '#type' => 'link',
      '#title' => $this->t('View transcript by semester'),
      '#url' => \Drupal\Core\Url::fromRoute('mc_college_core.transcript', ['user' => $user->id()]),
      '#attributes' => ['class' => ['button']],
    ];
    return $build;
  }

  /**
   * Unofficial transcript grouped by semester.
   */
  public function transcript(UserInterface $user): array {
    $transcript = $this->records->getTranscript((int) $user->id());
    $build = $this->baseBuild($user);
    $build['#attached']['library'][] = 'mc_college_core/print';

    $build['print'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Print transcript'),
      '#attributes' => ['type' => 'button', 'class' => ['button', 'mc-print', 'no-print']],
    ];
    $build['profile'] = $this->profileTable($user);
    $build['generated'] = [
      '#markup' => '<p class="mc-muted">' . $this->t('Unofficial transcript generated @date. Grades: A=4, B=3, C=2, D=1, F=0; P = pass (credit, not in GPA); I = incomplete; W = withdrawn; IP = in progress.', [
        '@date' => (new DrupalDateTime())->format('F j, Y'),
      ]) . '</p>',
    ];

    foreach ($transcript['terms'] as $nid => $term) {
      $rows = [];
      foreach ($term['rows'] as $row) {
        $rows[] = [
          $row['course_code'],
          $row['course_title'],
          $row['department'],
          (string) $row['credits'],
          ['data' => $row['mark'], 'class' => ['mc-mark', 'mc-mark--' . strtolower($row['mark'])]],
          $row['points'] === NULL ? '' : number_format($row['points'], 2),
        ];
      }
      /** @var \Drupal\mc_college_core\Academic\CreditSummary $s */
      $s = $term['summary'];
      /** @var \Drupal\mc_college_core\Academic\CreditSummary $c */
      $c = $term['cumulative'];
      $build['term_' . $nid] = [
        '#type' => 'table',
        '#caption' => $term['title']
          . ($term['academic_year'] ? ' · ' . $this->t('Academic year @year', ['@year' => $term['academic_year']]) : '')
          . ($term['deans_list'] ? ' — ' . $this->t("Dean's List") : ''),
        '#header' => [
          $this->t('Course'),
          $this->t('Title'),
          $this->t('Department'),
          $this->t('Credits'),
          $this->t('Grade'),
          $this->t('Quality points'),
        ],
        '#rows' => $rows,
        '#footer' => [
          [
            ['data' => $this->t('Term: attempted @a, earned @e, GPA credits @g, quality points @q, GPA @gpa', [
              '@a' => $s->attempted, '@e' => $s->earned, '@g' => $s->gpaCredits,
              '@q' => number_format($s->qualityPoints, 2), '@gpa' => $s->formattedGpa(),
            ]), 'colspan' => 6],
          ],
          [
            ['data' => $this->t('Cumulative: attempted @a, earned @e, GPA @gpa', [
              '@a' => $c->attempted, '@e' => $c->earned, '@gpa' => $c->formattedGpa(),
            ]), 'colspan' => 6],
          ],
        ],
        '#attributes' => ['class' => ['mc-table', 'mc-transcript-term']],
      ];
    }
    if (!$transcript['terms']) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No course history yet.') . '</p>'];
    }

    /** @var \Drupal\mc_college_core\Academic\CreditSummary $cum */
    $cum = $transcript['cumulative'];
    $standing = $this->records->getStanding($cum);
    $build['totals'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mc-stats']],
      'gpa' => $this->stat($this->t('Cumulative GPA'), $cum->formattedGpa()),
      'earned' => $this->stat($this->t('Total credits earned'), (string) $cum->earned),
      'standing' => $this->stat($this->t('Academic standing'), $standing['label'], 'mc-standing--' . $standing['key']),
    ];
    return $build;
  }

  /**
   * Common render array wrapper with libraries and cache metadata.
   */
  private function baseBuild(UserInterface $user): array {
    return [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => [
        'tags' => ['registration_list', 'node_list', 'config:mc_college_core.settings', 'user:' . $user->id()],
        'contexts' => ['user.permissions', 'user'],
      ],
    ];
  }

  /**
   * Student identity table.
   */
  private function profileTable(UserInterface $user): array {
    $value = fn(string $field) => $user->hasField($field) && !$user->get($field)->isEmpty() ? $user->get($field) : NULL;
    $type = $value('field_student_type');
    $program = $value('field_program')?->entity;
    $residency = $value('field_residency');
    $type_label = $type ? ($type->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values')[$type->value] ?? $type->value) : $this->t('Not set');
    $residency_label = $residency ? ($residency->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values')[$residency->value] ?? $residency->value) : $this->t('Not set');
    return [
      '#type' => 'table',
      '#attributes' => ['class' => ['mc-profile']],
      '#rows' => [
        [['header' => TRUE, 'data' => $this->t('Student')], $user->getDisplayName()],
        [['header' => TRUE, 'data' => $this->t('Student ID')], $value('field_student_id')?->value ?? $this->t('Not set')],
        [['header' => TRUE, 'data' => $this->t('Student type')], $type_label],
        [['header' => TRUE, 'data' => $this->t('Program')], $program ? $program->label() : $this->t('Undeclared')],
        [['header' => TRUE, 'data' => $this->t('Residency')], $residency_label],
      ],
    ];
  }

  /**
   * A headline statistic.
   */
  private function stat($label, string $value, string $modifier = ''): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="mc-stat {{ modifier }}"><span class="mc-stat__value">{{ value }}</span><span class="mc-stat__label">{{ label }}</span></div>',
      '#context' => ['label' => $label, 'value' => $value, 'modifier' => $modifier],
    ];
  }

  /**
   * A status message box.
   */
  private function alert(string $type, $message): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="mc-alert mc-alert--{{ type }}" role="{{ type == "error" ? "alert" : "status" }}">{{ message }}</div>',
      '#context' => ['type' => $type, 'message' => $message],
    ];
  }

}

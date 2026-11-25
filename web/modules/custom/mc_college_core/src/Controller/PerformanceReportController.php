<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\mc_college_core\Academic\AcademicRecords;
use Drupal\mc_college_core\Academic\GradeScale;
use Drupal\mc_college_core\Form\ReportFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Course/department analytics and the student performance report.
 */
final class PerformanceReportController extends ControllerBase {

  public function __construct(
    private readonly AcademicRecords $records,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mc_college_core.academic_records'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Filters from the query string.
   */
  private function filters(Request $request): array {
    return array_filter([
      'semester' => $request->query->get('semester'),
      'student_type' => $request->query->get('student_type'),
      'department' => $request->query->get('department'),
      'standing' => $request->query->get('standing'),
      'must_matriculate' => $request->query->get('must_matriculate'),
    ]);
  }

  /**
   * Performance analytics per department and per course.
   */
  public function analytics(Request $request): array {
    $filters = $this->filters($request);
    $rows = $this->records->getOutcomeCounts($filters);

    $overall = $this->records->aggregate($rows, fn() => ['all', 'All']);
    $by_type = $this->records->aggregate($rows, fn($r) => [
      $r['student_type'] ?? 'unknown',
      match ($r['student_type']) {
        'matriculated' => (string) $this->t('Matriculated'),
        'non_matriculated' => (string) $this->t('Non-matriculated'),
        default => (string) $this->t('Not set'),
      },
    ]);
    $by_department = $this->records->aggregate($rows, fn($r) => [$r['department_tid'] ?? 0, $r['department'] ?? (string) $this->t('No department')]);
    $by_course = $this->records->aggregate($rows, fn($r) => [$r['course_nid'], trim(($r['course_code'] ?? '') . ' ' . preg_replace('/^[A-Z]{3} \d+: /', '', (string) $r['course_title']))]);

    $total = $overall['all'] ?? NULL;
    $build = [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => [
        'tags' => ['registration_list', 'node_list', 'taxonomy_term_list', 'user_list'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
      'filters' => $this->formBuilder()->getForm(ReportFilterForm::class, ['semester', 'department', 'student_type']),
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mc-stats']],
        'registrations' => $this->stat($this->t('Registrations'), (string) ($total['registrations'] ?? 0)),
        'success' => $this->stat($this->t('Success rate (A, B, C, P)'), $this->pct($total['success_rate'] ?? NULL)),
        'pass' => $this->stat($this->t('Pass rate (A-D, P)'), $this->pct($total['pass_rate'] ?? NULL)),
        'withdrawal' => $this->stat($this->t('Withdrawal rate'), $this->pct($total['withdrawal_rate'] ?? NULL)),
        'gpa' => $this->stat($this->t('Average grade points'), $this->num($total['avg_gpa'] ?? NULL)),
      ],
      'help' => [
        '#markup' => '<p class="mc-muted">' . $this->t('Rates are out of finished registrations (completed or withdrawn). In-progress and dropped registrations are counted but excluded from rates. Average grade points use A-F grades only.') . '</p>',
      ],
      'by_type' => $this->metricsTable($this->t('Matriculated vs. non-matriculated students'), $this->t('Student type'), $by_type),
      'by_department' => $this->metricsTable($this->t('Performance by department'), $this->t('Department'), $by_department, 'department', $request),
      'by_course' => $this->metricsTable($this->t('Performance by course'), $this->t('Course'), $by_course, 'course', $request),
    ];
    return $build;
  }

  /**
   * Student performance report: GPA, credits, and standing for every student.
   */
  public function students(Request $request): array {
    $filters = $this->filters($request);
    $students = $this->records->getStudentSummaries($filters);
    $limit = 50;
    $pager = $this->pagerManager->createPager(count($students), $limit);
    $page = array_slice($students, $pager->getCurrentPage() * $limit, $limit, TRUE);

    $probation = count(array_filter($students, fn($s) => $s['standing']['key'] === 'probation'));
    $must = count(array_filter($students, fn($s) => $s['must_matriculate']));
    $gpas = array_filter(array_map(fn($s) => $s['summary']->gpa(), $students), fn($g) => $g !== NULL);

    $rows = [];
    foreach ($page as $uid => $s) {
      /** @var \Drupal\mc_college_core\Academic\CreditSummary $sum */
      $sum = $s['summary'];
      $rows[] = [
        ['data' => ['#type' => 'link', '#title' => $s['name'], '#url' => Url::fromRoute('mc_college_core.academic_record', ['user' => $uid])]],
        $s['student_id'] ?? '',
        $s['student_type'] === 'matriculated' ? $this->t('Matriculated') : ($s['student_type'] === 'non_matriculated' ? $this->t('Non-matriculated') : ''),
        $s['program'] ?? $this->t('Undeclared'),
        (string) $sum->earned,
        (string) $sum->attempted,
        (string) $sum->inProgress,
        $sum->formattedGpa(),
        ['data' => $s['standing']['label'], 'class' => ['mc-standing--' . $s['standing']['key']]],
        $s['must_matriculate'] ? ['data' => $this->t('Must matriculate'), 'class' => ['mc-standing--probation']] : '',
        ['data' => ['#type' => 'link', '#title' => $this->t('Transcript'), '#url' => Url::fromRoute('mc_college_core.transcript', ['user' => $uid])]],
      ];
    }

    return [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => [
        'tags' => ['registration_list', 'node_list', 'user_list', 'config:mc_college_core.settings'],
        'contexts' => ['url.query_args', 'user.permissions'],
      ],
      'filters' => $this->formBuilder()->getForm(ReportFilterForm::class, ['student_type', 'standing', 'must_matriculate']),
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mc-stats']],
        'students' => $this->stat($this->t('Students'), (string) count($students)),
        'avg' => $this->stat($this->t('Average cumulative GPA'), $gpas ? number_format(array_sum($gpas) / count($gpas), 2) : '—'),
        'probation' => $this->stat($this->t('On probation'), (string) $probation),
        'must' => $this->stat($this->t('Must matriculate'), (string) $must),
      ],
      'export' => $this->exportLink('students', $request),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Student'), $this->t('ID'), $this->t('Type'), $this->t('Program'),
          $this->t('Earned'), $this->t('Attempted'), $this->t('In progress'), $this->t('GPA'),
          $this->t('Standing'), $this->t('Matriculation'), '',
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No students match these filters.'),
        '#attributes' => ['class' => ['mc-table']],
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  /**
   * CSV export of the department, course, or student report.
   */
  public function export(string $report, Request $request): Response {
    $filters = $this->filters($request);
    $lines = [];
    if ($report === 'students') {
      $lines[] = ['Student', 'Student ID', 'Type', 'Program', 'Earned', 'Attempted', 'In progress', 'GPA', 'Standing', 'Must matriculate'];
      foreach ($this->records->getStudentSummaries($filters) as $s) {
        $lines[] = [$s['name'], $s['student_id'], $s['student_type'], $s['program'], $s['summary']->earned, $s['summary']->attempted, $s['summary']->inProgress, $s['summary']->gpa(), $s['standing']['label'], $s['must_matriculate'] ? 'yes' : 'no'];
      }
    }
    else {
      $rows = $this->records->getOutcomeCounts($filters);
      $groups = $report === 'department'
        ? $this->records->aggregate($rows, fn($r) => [$r['department_tid'] ?? 0, $r['department'] ?? 'No department'])
        : $this->records->aggregate($rows, fn($r) => [$r['course_nid'], $r['course_code'] . ' ' . $r['course_title']]);
      $lines[] = array_merge([ucfirst($report), 'Registrations', 'In progress', 'Completed', 'Withdrawn', 'Dropped', 'Success rate %', 'Pass rate %', 'Withdrawal rate %', 'Avg grade points'], GradeScale::MARKS);
      foreach ($groups as $g) {
        $lines[] = array_merge([$g['label'], $g['registrations'], $g['in_progress'], $g['completed'], $g['withdrawn'], $g['dropped'], $g['success_rate'], $g['pass_rate'], $g['withdrawal_rate'], $g['avg_gpa']], array_values($g['marks']));
      }
    }
    $response = new StreamedResponse(function () use ($lines) {
      $out = fopen('php://output', 'w');
      foreach ($lines as $line) {
        fputcsv($out, $line, ',', '"', '\\');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="mc-college-' . $report . '-report.csv"');
    return $response;
  }

  /**
   * A metrics table with grade distribution bars.
   */
  private function metricsTable($caption, $group_label, array $groups, ?string $export = NULL, ?Request $request = NULL): array {
    $rows = [];
    foreach ($groups as $g) {
      $rows[] = [
        $g['label'],
        (string) $g['registrations'],
        (string) $g['in_progress'],
        (string) $g['completed'],
        (string) $g['withdrawn'],
        $this->pct($g['success_rate']),
        $this->pct($g['withdrawal_rate']),
        $this->num($g['avg_gpa']),
        ['data' => $this->distribution($g['marks'])],
      ];
    }
    return [
      'export' => $export && $request ? $this->exportLink($export, $request) : [],
      'table' => [
        '#type' => 'table',
        '#caption' => $caption,
        '#header' => [
          $group_label, $this->t('Registrations'), $this->t('In progress'), $this->t('Completed'),
          $this->t('Withdrawn'), $this->t('Success'), $this->t('Withdrawal'), $this->t('Avg GP'),
          $this->t('Grade distribution'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No registrations match these filters.'),
        '#attributes' => ['class' => ['mc-table']],
      ],
    ];
  }

  /**
   * Stacked bar of transcript marks.
   */
  private function distribution(array $marks): array {
    $total = array_sum($marks);
    $segments = [];
    foreach ($marks as $mark => $n) {
      if ($n > 0) {
        $segments[] = ['mark' => $mark, 'n' => $n, 'width' => round(100 * $n / $total, 2)];
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{% if segments %}<span class="mc-dist" role="img" aria-label="{{ label }}">{% for s in segments %}<span class="mc-dist__seg mc-mark--{{ s.mark|lower }}" style="width: {{ s.width }}%" title="{{ s.mark }}: {{ s.n }}">{{ s.width >= 8 ? s.mark }}</span>{% endfor %}</span>{% else %}—{% endif %}',
      '#context' => [
        'segments' => $segments,
        'label' => implode(', ', array_map(fn($s) => $s['mark'] . ': ' . $s['n'], $segments)),
      ],
    ];
  }

  /**
   * "Download CSV" link carrying the current filters.
   */
  private function exportLink(string $report, Request $request): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Download CSV'),
      '#url' => Url::fromRoute('mc_college_core.performance_export', ['report' => $report], ['query' => $this->filters($request)]),
      '#attributes' => ['class' => ['button', 'button--small', 'mc-export']],
    ];
  }

  private function stat($label, string $value): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="mc-stat"><span class="mc-stat__value">{{ value }}</span><span class="mc-stat__label">{{ label }}</span></div>',
      '#context' => ['label' => $label, 'value' => $value],
    ];
  }

  private function pct(?float $v): string {
    return $v === NULL ? '—' : number_format($v, 1) . '%';
  }

  private function num(?float $v): string {
    return $v === NULL ? '—' : number_format($v, 2);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Academic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Builds transcripts, GPA, standing, and analytics from registration data.
 *
 * Reads with SQL aggregates instead of loading entities so reports stay fast
 * with tens of thousands of registrations. Callers are responsible for access
 * checks; this service returns data for any student.
 */
final class AcademicRecords {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * A configuration value from mc_college_core.settings with a fallback.
   */
  public function setting(string $key, mixed $default = NULL): mixed {
    return $this->configFactory->get('mc_college_core.settings')->get($key) ?? $default;
  }

  /**
   * Base query: course_session registrations joined to session, course, semester.
   */
  private function baseQuery(): SelectInterface {
    $q = $this->database->select('registration', 'r');
    $q->join('node_field_data', 'sess', "[sess].[nid] = [r].[entity_id] AND [r].[entity_type_id] = 'node' AND [sess].[default_langcode] = 1");
    $q->join('node__field_course', 'sc', '[sc].[entity_id] = [sess].[nid]');
    $q->join('node_field_data', 'c', '[c].[nid] = [sc].[field_course_target_id] AND [c].[default_langcode] = 1');
    $q->join('node__field_semester', 'ss', '[ss].[entity_id] = [sess].[nid]');
    $q->join('node_field_data', 'sem', '[sem].[nid] = [ss].[field_semester_target_id] AND [sem].[default_langcode] = 1');
    $q->leftJoin('node__field_start_date', 'sd', '[sd].[entity_id] = [sem].[nid]');
    $q->leftJoin('node__field_course_code', 'cc', '[cc].[entity_id] = [c].[nid]');
    $q->leftJoin('node__field_credits', 'cr', '[cr].[entity_id] = [c].[nid]');
    $q->leftJoin('node__field_department', 'cd', '[cd].[entity_id] = [c].[nid]');
    $q->leftJoin('taxonomy_term_field_data', 'd', '[d].[tid] = [cd].[field_department_target_id] AND [d].[default_langcode] = 1');
    $q->leftJoin('registration__field_grade', 'g', '[g].[entity_id] = [r].[registration_id]');
    $q->condition('r.type', 'course_session');
    return $q;
  }

  /**
   * All transcript-relevant registrations for one student, oldest term first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows keyed by registration ID.
   */
  public function getStudentRows(int $uid): array {
    $q = $this->baseQuery();
    $q->addField('r', 'registration_id', 'id');
    $q->addField('r', 'state');
    $q->addField('g', 'field_grade_value', 'grade');
    $q->addField('sess', 'nid', 'session_nid');
    $q->addField('sess', 'title', 'session_title');
    $q->addField('c', 'nid', 'course_nid');
    $q->addField('c', 'title', 'course_title');
    $q->addField('cc', 'field_course_code_value', 'course_code');
    $q->addField('cr', 'field_credits_value', 'credits');
    $q->addField('d', 'name', 'department');
    $q->addField('sem', 'nid', 'semester_nid');
    $q->addField('sem', 'title', 'semester');
    $q->addField('sd', 'field_start_date_value', 'semester_start');
    if ($this->database->schema()->tableExists('node__field_academic_year')) {
      $q->leftJoin('node__field_academic_year', 'ay', '[ay].[entity_id] = [sem].[nid]');
      $q->leftJoin('taxonomy_term_field_data', 'ayt', '[ayt].[tid] = [ay].[field_academic_year_target_id] AND [ayt].[default_langcode] = 1');
      $q->addField('ayt', 'name', 'academic_year');
    }
    $q->condition('r.user_uid', $uid);
    $q->condition('r.state', GradeScale::TRANSCRIPT_STATES, 'IN');
    $q->orderBy('sd.field_start_date_value');
    $q->orderBy('cc.field_course_code_value');
    return $q->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Transcript grouped by semester with term and cumulative summaries.
   *
   * @return array{terms: array<int, array<string, mixed>>, cumulative: CreditSummary}
   *   Terms in chronological order. Each term has: title, start, rows,
   *   summary (CreditSummary), cumulative (CreditSummary as of that term),
   *   deans_list (bool).
   */
  public function getTranscript(int $uid): array {
    $terms = [];
    foreach ($this->getStudentRows($uid) as $row) {
      $nid = (int) $row['semester_nid'];
      $terms[$nid] ??= [
        'title' => $row['semester'],
        'academic_year' => $row['academic_year'] ?? NULL,
        'start' => $row['semester_start'],
        'rows' => [],
        'summary' => new CreditSummary(),
      ];
      $row['credits'] = (int) $row['credits'];
      $row['mark'] = GradeScale::mark($row['state'], $row['grade']);
      $row['points'] = GradeScale::countsTowardGpa($row['state'], $row['grade'])
        ? GradeScale::POINTS[$row['grade']] * $row['credits']
        : NULL;
      $terms[$nid]['rows'][] = $row;
      $terms[$nid]['summary']->add($row['state'], $row['grade'], $row['credits']);
    }

    $cumulative = new CreditSummary();
    $deans_gpa = (float) $this->setting('deans_list_gpa', 3.5);
    $deans_credits = (int) $this->setting('deans_list_min_credits', 6);
    foreach ($terms as &$term) {
      $cumulative->merge($term['summary']);
      $term['cumulative'] = clone $cumulative;
      $gpa = $term['summary']->gpa();
      $term['deans_list'] = $gpa !== NULL && $gpa >= $deans_gpa && $term['summary']->gpaCredits >= $deans_credits;
    }
    unset($term);

    return ['terms' => $terms, 'cumulative' => $cumulative];
  }

  /**
   * Cumulative GPA for a student, or NULL with no graded credits.
   */
  public function getCumulativeGpa(int $uid): ?float {
    return $this->getTranscript($uid)['cumulative']->gpa();
  }

  /**
   * Academic standing for a cumulative summary.
   *
   * @return array{key: string, label: string}
   *   Machine key (none, good, probation) and label.
   */
  public function getStanding(CreditSummary $cumulative): array {
    $gpa = $cumulative->gpa();
    if ($gpa === NULL) {
      return ['key' => 'none', 'label' => 'No GPA yet'];
    }
    if ($gpa < (float) $this->setting('probation_gpa', 2.0)) {
      return ['key' => 'probation', 'label' => 'Academic Probation'];
    }
    return ['key' => 'good', 'label' => 'Good Standing'];
  }

  /**
   * Matriculation status and policy limits for a student.
   *
   * @return array<string, mixed>
   *   type, matriculated, credit_cap, threshold, earned, must_matriculate,
   *   credits_until_threshold.
   */
  public function getMatriculationStatus(UserInterface $user, CreditSummary $cumulative): array {
    $type = $user->hasField('field_student_type') ? $user->get('field_student_type')->value : NULL;
    $matriculated = $type === 'matriculated';
    $threshold = (int) $this->setting('matriculation_credit_threshold', 12);
    return [
      'type' => $type,
      'matriculated' => $matriculated,
      'credit_cap' => $matriculated ? NULL : (int) $this->setting('non_matriculated_credit_cap', 11),
      'threshold' => $threshold,
      'earned' => $cumulative->earned,
      'must_matriculate' => !$matriculated && $type !== NULL && $cumulative->earned >= $threshold,
      'credits_until_threshold' => $matriculated ? NULL : max(0, $threshold - $cumulative->earned),
    ];
  }

  /**
   * Credits a student holds (enrolled or completed) in a semester.
   */
  public function getTermLoad(int $uid, int $semester_nid, ?int $exclude_registration = NULL): int {
    $q = $this->baseQuery();
    $q->addExpression('COALESCE(SUM([cr].[field_credits_value]), 0)', 'credits');
    $q->condition('r.user_uid', $uid);
    $q->condition('sem.nid', $semester_nid);
    $q->condition('r.state', GradeScale::ACTIVE_STATES, 'IN');
    if ($exclude_registration) {
      $q->condition('r.registration_id', $exclude_registration, '<>');
    }
    return (int) $q->execute()->fetchField();
  }

  /**
   * Credits earned by a student across all terms.
   */
  public function getEarnedCredits(int $uid): int {
    return $this->getTranscript($uid)['cumulative']->earned;
  }

  /**
   * Semester options (nid => title), newest first.
   *
   * @return array<int, string>
   *   Semester titles keyed by node ID.
   */
  public function getSemesterOptions(): array {
    $q = $this->database->select('node_field_data', 'n');
    $q->leftJoin('node__field_start_date', 'sd', '[sd].[entity_id] = [n].[nid]');
    $q->fields('n', ['nid', 'title']);
    $q->condition('n.type', 'semester');
    $q->condition('n.default_langcode', 1);
    $q->orderBy('sd.field_start_date_value', 'DESC');
    return $q->execute()->fetchAllKeyed();
  }

  /**
   * Department options (tid => name).
   *
   * @return array<int, string>
   *   Department names keyed by term ID.
   */
  public function getDepartmentOptions(): array {
    return $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('t.vid', 'department')
      ->condition('t.default_langcode', 1)
      ->orderBy('t.name')
      ->execute()->fetchAllKeyed();
  }

  /**
   * Aggregated outcome counts for analytics.
   *
   * @param array $filters
   *   Optional keys: semester (nid), student_type, department (tid).
   *
   * @return array<int, array<string, mixed>>
   *   Rows with department_tid, department, course_nid, course_code,
   *   course_title, credits, student_type, state, grade, n.
   */
  public function getOutcomeCounts(array $filters = []): array {
    $q = $this->baseQuery();
    $q->leftJoin('user__field_student_type', 'st', '[st].[entity_id] = [r].[user_uid]');
    $q->addField('d', 'tid', 'department_tid');
    $q->addField('d', 'name', 'department');
    $q->addField('c', 'nid', 'course_nid');
    $q->addField('cc', 'field_course_code_value', 'course_code');
    $q->addField('c', 'title', 'course_title');
    $q->addField('cr', 'field_credits_value', 'credits');
    $q->addField('st', 'field_student_type_value', 'student_type');
    $q->addField('r', 'state');
    $q->addField('g', 'field_grade_value', 'grade');
    $q->addExpression('COUNT(*)', 'n');
    foreach (['department_tid', 'department', 'course_nid', 'course_code', 'course_title', 'credits', 'student_type', 'state', 'grade'] as $field) {
      $q->groupBy($field);
    }
    if (!empty($filters['semester'])) {
      $q->condition('sem.nid', (int) $filters['semester']);
    }
    if (!empty($filters['student_type'])) {
      $q->condition('st.field_student_type_value', $filters['student_type']);
    }
    if (!empty($filters['department'])) {
      $q->condition('d.tid', (int) $filters['department']);
    }
    return $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Aggregates outcome rows into metrics grouped by a key.
   *
   * @param array $rows
   *   Rows from getOutcomeCounts().
   * @param callable $key
   *   Returns [group_key, group_label] for a row.
   *
   * @return array<string, array<string, mixed>>
   *   Metrics per group, sorted by label.
   */
  public function aggregate(array $rows, callable $key): array {
    $groups = [];
    foreach ($rows as $row) {
      [$id, $label] = $key($row);
      $g = &$groups[$id];
      $g ??= [
        'label' => $label,
        'registrations' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'withdrawn' => 0,
        'dropped' => 0,
        'waitlisted' => 0,
        'graded' => 0,
        'success' => 0,
        'passed' => 0,
        'points' => 0.0,
        'marks' => array_fill_keys(GradeScale::MARKS, 0),
      ];
      $n = (int) $row['n'];
      $state = $row['state'];
      $grade = $row['grade'];
      $g['registrations'] += $n;
      match ($state) {
        'enrolled' => $g['in_progress'] += $n,
        'completed' => $g['completed'] += $n,
        'withdrawn' => $g['withdrawn'] += $n,
        'dropped' => $g['dropped'] += $n,
        'waitlisted' => $g['waitlisted'] += $n,
        default => NULL,
      };
      if ($state === 'withdrawn') {
        $g['marks']['W'] += $n;
      }
      if ($state === 'completed' && $grade !== NULL && isset($g['marks'][$grade])) {
        $g['marks'][$grade] += $n;
        if (in_array($grade, GradeScale::SUCCESS, TRUE)) {
          $g['success'] += $n;
        }
        if (in_array($grade, GradeScale::PASSING, TRUE)) {
          $g['passed'] += $n;
        }
        if (isset(GradeScale::POINTS[$grade])) {
          $g['graded'] += $n;
          $g['points'] += GradeScale::POINTS[$grade] * $n;
        }
      }
      unset($g);
    }
    foreach ($groups as &$g) {
      // Outcomes: completed with a final mark, or withdrawn.
      $finished = $g['completed'] + $g['withdrawn'];
      $g['success_rate'] = $finished ? round(100 * $g['success'] / $finished, 1) : NULL;
      $g['pass_rate'] = $finished ? round(100 * $g['passed'] / $finished, 1) : NULL;
      $g['withdrawal_rate'] = $finished ? round(100 * $g['withdrawn'] / $finished, 1) : NULL;
      $g['avg_gpa'] = $g['graded'] ? round($g['points'] / $g['graded'], 2) : NULL;
    }
    unset($g);
    uasort($groups, fn($a, $b) => strnatcasecmp((string) $a['label'], (string) $b['label']));
    return $groups;
  }

  /**
   * Per-student academic summary rows for the student performance report.
   *
   * @param array $filters
   *   Optional keys: student_type, standing (good, probation, none),
   *   must_matriculate (bool).
   *
   * @return array<int, array<string, mixed>>
   *   Rows keyed by uid: uid, name, student_id, student_type, program,
   *   summary (CreditSummary), standing.
   */
  public function getStudentSummaries(array $filters = []): array {
    // One aggregate query for every student's registrations.
    $q = $this->baseQuery();
    $q->addField('r', 'user_uid', 'uid');
    $q->addField('r', 'state');
    $q->addField('g', 'field_grade_value', 'grade');
    $q->addField('cr', 'field_credits_value', 'credits');
    $q->addExpression('COUNT(*)', 'n');
    $q->groupBy('uid')->groupBy('state')->groupBy('grade')->groupBy('credits');
    $q->condition('r.state', GradeScale::TRANSCRIPT_STATES, 'IN');
    $summaries = [];
    foreach ($q->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $summary = $summaries[$row['uid']] ??= new CreditSummary();
      for ($i = 0; $i < (int) $row['n']; $i++) {
        $summary->add($row['state'], $row['grade'], (int) $row['credits']);
      }
    }

    // Student accounts (role student) with their record fields.
    $uq = $this->database->select('users_field_data', 'u');
    $uq->join('user__roles', 'ro', "[ro].[entity_id] = [u].[uid] AND [ro].[roles_target_id] = 'student'");
    $uq->leftJoin('user__field_student_id', 'sid', '[sid].[entity_id] = [u].[uid]');
    $uq->leftJoin('user__field_student_type', 'st', '[st].[entity_id] = [u].[uid]');
    $uq->leftJoin('user__field_program', 'pr', '[pr].[entity_id] = [u].[uid]');
    $uq->leftJoin('node_field_data', 'p', '[p].[nid] = [pr].[field_program_target_id] AND [p].[default_langcode] = 1');
    $uq->fields('u', ['uid', 'name']);
    $uq->addField('sid', 'field_student_id_value', 'student_id');
    $uq->addField('st', 'field_student_type_value', 'student_type');
    $uq->addField('p', 'title', 'program');
    $uq->condition('u.default_langcode', 1);
    if (!empty($filters['student_type'])) {
      $uq->condition('st.field_student_type_value', $filters['student_type']);
    }
    $uq->orderBy('u.name');

    $threshold = (int) $this->setting('matriculation_credit_threshold', 12);
    $rows = [];
    foreach ($uq->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $summary = $summaries[$row['uid']] ?? new CreditSummary();
      $standing = $this->getStanding($summary);
      $must = $row['student_type'] !== 'matriculated' && $summary->earned >= $threshold;
      if (!empty($filters['standing']) && $filters['standing'] !== $standing['key']) {
        continue;
      }
      if (!empty($filters['must_matriculate']) && !$must) {
        continue;
      }
      $rows[(int) $row['uid']] = $row + ['summary' => $summary, 'standing' => $standing, 'must_matriculate' => $must];
    }
    return $rows;
  }

}

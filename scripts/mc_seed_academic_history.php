<?php

/**
 * @file
 * Seeds multi-semester academic history for sample students.
 *
 * Usage:
 *   ddev drush php:script scripts/mc_seed_academic_history.php
 *   ddev drush php:script scripts/mc_seed_academic_history.php -- --students=100
 *
 * Also included by mc_seed.php. Safe to re-run: semesters, sessions, students,
 * and registrations are matched before anything is created.
 *
 * Today is inside Fall 2026, so:
 *   - Spring 2026 and Summer 2026 are finished (Completed with grades, some W/I/P).
 *   - Fall 2026 is in progress (Enrolled, no grade).
 *   - Spring 2027 has early registrations (Enrolled).
 * Non-matriculated students take lighter loads, stay under the credit cap, and
 * a few reach the matriculation threshold so the policy is visible.
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;
use Drupal\registration\Entity\Registration;
use Drupal\user\Entity\User;

$historyOpts = ['students' => 60];
foreach ($extra ?? [] as $arg) {
  if (preg_match('/^--students=(\d+)$/', (string) $arg, $m)) {
    $historyOpts['students'] = (int) $m[1];
  }
}

$etm = \Drupal::entityTypeManager();
$nodes = $etm->getStorage('node');
$regStorage = $etm->getStorage('registration');
$say = fn(string $msg) => fwrite(STDERR, $msg . PHP_EOL);
$tz = new \DateTimeZone(\Drupal::config('system.date')->get('timezone.default') ?: 'UTC');
$utc = fn(DrupalDateTime $d) => (clone $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');

// ── Semesters ────────────────────────────────────────────────────────
$semesterSpecs = [
  'Spring 2026' => ['2026-01-20', '2026-05-15', '2025-10-27', '2026-01-19', '2026-02-03', '2026-04-10'],
  'Summer 2026' => ['2026-06-01', '2026-08-07', '2026-03-02', '2026-05-31', '2026-06-08', '2026-07-17'],
  'Fall 2026' => ['2026-08-24', '2026-12-18', '2026-04-01', '2026-08-23', '2026-09-08', '2026-11-06'],
  'Spring 2027' => ['2027-01-19', '2027-05-14', '2026-10-26', '2027-01-18', '2027-02-02', '2027-04-09'],
];
$semesters = [];
foreach ($semesterSpecs as $title => [$start, $end, $open, $close, $drop, $withdraw]) {
  $found = $nodes->loadByProperties(['type' => 'semester', 'title' => $title]);
  $node = $found ? reset($found) : Node::create(['type' => 'semester', 'title' => $title, 'uid' => 1, 'status' => 1]);
  foreach ([
    'field_start_date' => $start, 'field_end_date' => $end,
    'field_registration_open' => $open, 'field_registration_close' => $close,
    'field_drop_deadline' => $drop, 'field_withdraw_deadline' => $withdraw,
  ] as $field => $value) {
    $node->set($field, $value);
  }
  if ($node->hasField('field_academic_year')) {
    // Academic years run fall through summer: Fall 2026 - Summer 2027 is 2026–2027.
    $year = (int) substr($start, 0, 4);
    $first = (int) substr($start, 5, 2) >= 8 ? $year : $year - 1;
    $label = sprintf('%d–%d', $first, $first + 1);
    $terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'academic_year', 'name' => $label]);
    $term = $terms ? reset($terms) : \Drupal\taxonomy\Entity\Term::create(['vid' => 'academic_year', 'name' => $label, 'weight' => $first]);
    $term->isNew() && $term->save();
    $node->set('field_academic_year', $term->id());
  }
  $node->save();
  $semesters[$title] = (int) $node->id();
}
// Other semesters created by mc_seed.php also get an academic year.
foreach ($nodes->loadByProperties(['type' => 'semester']) as $node) {
  if ($node->hasField('field_academic_year') && $node->get('field_academic_year')->isEmpty() && ($start = $node->get('field_start_date')->value)) {
    $year = (int) substr($start, 0, 4);
    $first = (int) substr($start, 5, 2) >= 8 ? $year : $year - 1;
    $label = sprintf('%d–%d', $first, $first + 1);
    $terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'academic_year', 'name' => $label]);
    $term = $terms ? reset($terms) : \Drupal\taxonomy\Entity\Term::create(['vid' => 'academic_year', 'name' => $label, 'weight' => $first]);
    $term->isNew() && $term->save();
    $node->set('field_academic_year', $term->id())->save();
  }
}

// ── Courses and professors to build sessions from ────────────────────
$courseIds = array_values($nodes->getQuery()->accessCheck(FALSE)->condition('type', 'course')->sort('nid')->range(0, 240)->execute());
$professors = array_values($etm->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('roles', 'professor')->sort('uid')->execute());
if (!$courseIds || !$professors) {
  throw new \RuntimeException('Run mc_seed.php first: courses and professor accounts are required.');
}
$courses = $nodes->loadMultiple($courseIds);

/**
 * Finds or creates N sessions for a semester; returns session info.
 */
$ensureSessions = function (string $semester, int $count) use ($nodes, $semesters, $semesterSpecs, $courses, $professors, $tz, $utc): array {
  $sid = $semesters[$semester];
  $existing = $nodes->loadByProperties(['type' => 'course_session', 'field_semester' => $sid]);
  $courseList = array_values($courses);
  $offset = crc32($semester) % 24;
  for ($i = count($existing); $i < $count; $i++) {
    $course = $courseList[($offset + $i * 7) % count($courseList)];
    [$start, , $open, $close] = $semesterSpecs[$semester];
    $begin = new DrupalDateTime(sprintf('%s %02d:00', $start, 8 + 2 * ($i % 6)), $tz);
    $begin->modify('+' . ($i % 5) . ' days');
    $capacity = 24 + $i % 12;
    $node = Node::create([
      'type' => 'course_session',
      'title' => sprintf('%s-%02d (%s)', $course->get('field_course_code')->value, 1 + intdiv($i, count($courseList)), $semester),
      'uid' => 1,
      'status' => 1,
      'field_course' => $course->id(),
      'field_semester' => $sid,
      'field_professor' => $professors[$i % count($professors)],
      'field_date_time' => ['value' => $utc($begin), 'end_value' => $utc((clone $begin)->modify('+75 minutes'))],
      'field_location' => ['North Campus', 'Central Campus', 'East Campus', 'Online'][$i % 4] . ($i % 4 === 3 ? '' : ', Room ' . (100 + $i % 300)),
      'field_capacity' => $capacity,
      'field_requires_matriculation' => (int) ($i % 6 === 0),
      'field_registration' => 'course_session',
    ]);
    $node->save();
    $settings = $node->get('field_registration')->createHostEntity()->getSettings();
    $settings->set('status', TRUE);
    $settings->set('capacity', $capacity);
    $settings->set('open', $open . 'T00:00:00');
    $settings->set('close', $close . 'T23:59:59');
    $settings->save();
    $existing[$node->id()] = $node;
  }
  $info = [];
  foreach ($existing as $node) {
    $info[] = [
      'nid' => (int) $node->id(),
      'credits' => (int) $node->get('field_course')->entity?->get('field_credits')->value,
      'restricted' => (bool) $node->get('field_requires_matriculation')->value,
    ];
  }
  return $info;
};

$sessions = [
  'Spring 2026' => $ensureSessions('Spring 2026', 60),
  'Summer 2026' => $ensureSessions('Summer 2026', 30),
  'Fall 2026' => $ensureSessions('Fall 2026', 50),
  'Spring 2027' => $ensureSessions('Spring 2027', 50),
];
$say('Sessions ready: ' . implode(', ', array_map(fn($t, $s) => "$t " . count($s), array_keys($sessions), $sessions)));

// ── Students ─────────────────────────────────────────────────────────
$programs = array_values($nodes->getQuery()->accessCheck(FALSE)->condition('type', 'program')->sort('nid')->range(0, 200)->execute());
$profiles = [
  // Grade patterns by student "profile" (cycled through the courses a student takes).
  ['A', 'A', 'B', 'A', 'A'],
  ['B', 'A', 'B', 'C', 'B'],
  ['C', 'B', 'C', 'B', 'P'],
  ['C', 'D', 'F', 'C', 'D'],
  ['B', 'C', 'I', 'A', 'B'],
];

$created = 0;
for ($i = 0; $i < $historyOpts['students']; $i++) {
  $name = sprintf('student%04d', $i + 1);
  $found = $etm->getStorage('user')->loadByProperties(['name' => $name]);
  $matriculated = $i % 4 !== 3;
  $student = $found ? reset($found) : User::create([
    'name' => $name,
    'mail' => "$name@example.edu",
    'pass' => \Drupal::service('password_generator')->generate(20),
    'status' => 1,
  ]);
  $student->addRole('student');
  if ($student->isNew() || $student->get('field_student_type')->isEmpty()) {
    $student->set('field_student_type', $matriculated ? 'matriculated' : 'non_matriculated');
    $student->set('field_student_id', sprintf('MCC%07d', $i + 1));
    $student->set('field_residency', ['in_district', 'in_district', 'out_of_district', 'out_of_state'][$i % 4]);
    if ($matriculated && $programs) {
      $student->set('field_program', $programs[$i % count($programs)]);
    }
  }
  if ($student->hasField('field_enrollment_status') && $student->get('field_enrollment_status')->isEmpty()) {
    $student->set('field_enrollment_status', 'active');
  }
  if ($student->hasField('field_matriculation_date') && $student->get('field_matriculation_date')->isEmpty()
    && $student->get('field_student_type')->value === 'matriculated') {
    $student->set('field_matriculation_date', '2025-12-15');
  }
  $student->save();
  $uid = (int) $student->id();
  $matriculated = $student->get('field_student_type')->value === 'matriculated';

  // Earlier seeds marked Fall 2026 registrations Completed mid-term; Fall is in progress.
  $fallIds = array_column($sessions['Fall 2026'], 'nid');
  foreach ($regStorage->loadByProperties(['user_uid' => $uid, 'state' => 'completed', 'entity_id' => $fallIds ?: [0]]) as $reg) {
    $reg->set('state', 'enrolled');
    $reg->set('field_grade', NULL);
    $reg->save();
  }

  $profile = $profiles[$i % count($profiles)];
  // Courses per term. Non-matriculated students take fewer; every 8th
  // non-matriculated student takes more to cross the matriculation threshold.
  $loads = $matriculated
    ? ['Spring 2026' => 4, 'Summer 2026' => $i % 2 ? 2 : 0, 'Fall 2026' => 4, 'Spring 2027' => $i % 3 ? 3 : 0]
    : ['Spring 2026' => 2, 'Summer 2026' => $i % 8 === 3 ? 2 : 1, 'Fall 2026' => 2, 'Spring 2027' => 1];
  $cap = (int) (\Drupal::config('mc_college_core.settings')->get('non_matriculated_credit_cap') ?? 11);

  $k = 0;
  foreach ($loads as $term => $count) {
    $pool = $sessions[$term];
    $termCredits = 0;
    $taken = 0;
    for ($j = 0; $taken < $count && $j < count($pool); $j++) {
      $session = $pool[($i * 5 + $j * 3) % count($pool)];
      if ($session['restricted'] && !$matriculated) {
        continue;
      }
      if (!$matriculated && $termCredits + $session['credits'] > $cap) {
        continue;
      }
      $exists = $regStorage->getQuery()->accessCheck(FALSE)
        ->condition('entity_type_id', 'node')->condition('entity_id', $session['nid'])
        ->condition('user_uid', $uid)->count()->execute();
      $taken++;
      $termCredits += $session['credits'];
      if ($exists) {
        continue;
      }
      $finished = in_array($term, ['Spring 2026', 'Summer 2026'], TRUE);
      $grade = $profile[$k++ % count($profile)];
      $withdrawn = $finished && ($i + $k) % 11 === 0;
      Registration::create([
        'type' => 'course_session',
        'entity_type_id' => 'node',
        'entity_id' => $session['nid'],
        'user_uid' => $uid,
        'author_uid' => 1,
        'count' => 1,
        'state' => $finished ? ($withdrawn ? 'withdrawn' : 'completed') : 'enrolled',
        'field_grade' => $finished && !$withdrawn ? $grade : NULL,
      ])->save();
      $created++;
    }
  }
}
$say("Academic history ready: {$historyOpts['students']} students, $created new registrations.");

<?php

/**
 * @file
 * Seeds Montgomery Community College demo/load-test content.
 *
 * Usage:
 *   ddev drush php:script scripts/mc_seed.php
 *   ddev drush php:script scripts/mc_seed.php -- --sessions=500
 *   ddev drush php:script scripts/mc_seed.php -- --students=100 --force
 *
 * Options (defaults in $opts below): --sessions --students
 *   --faculty-profiles --courses --programs --scholarships --events --news
 *   --force (seed again even though a previous run completed)
 *
 * All people, emails (example.edu), phone numbers (555) and campuses are
 * fictional. Taxonomy terms, semesters, faculty and student accounts are
 * matched and reused, so a forced re-run only adds bulk content.
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;
use Drupal\registration\Entity\Registration;
use Drupal\search_api\Entity\Index;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;

$opts = [
  'sessions' => 200,
  'students' => 60,
  'faculty-profiles' => 1500,
  'courses' => 1200,
  'programs' => 400,
  'scholarships' => 600,
  'events' => 700,
  'news' => 600,
  'force' => FALSE,
];
foreach ($extra ?? [] as $arg) {
  if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $arg, $m)) {
    if (!array_key_exists($m[1], $opts)) {
      throw new \InvalidArgumentException("Unknown option --{$m[1]}");
    }
    $opts[$m[1]] = isset($m[2]) ? (int) $m[2] : TRUE;
  }
}

$state = \Drupal::state();
if ($state->get('mc_seed.completed') && !$opts['force']) {
  echo "Already seeded on " . date('Y-m-d H:i', $state->get('mc_seed.completed')) . ". Use -- --force to add another batch.\n";
  return;
}

$etm = \Drupal::entityTypeManager();
$memory = \Drupal::service('entity.memory_cache');
$log = fn(string $msg) => fwrite(STDERR, $msg . PHP_EOL);
$progress = function (string $label, int $done, int $total) use ($memory, $log): void {
  if ($done % 100 === 0) {
    $memory->deleteAll();
  }
  if ($done % 250 === 0 || $done === $total) {
    $log(sprintf('  %-17s %5d/%d', $label, $done, $total));
  }
};
$pick = fn(array $list, int $i) => $list[$i % count($list)];
$utc = fn(DrupalDateTime $d) => (clone $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');

// Indexing 5,000 nodes one by one is slow; queue them for cron instead.
$index = \Drupal::moduleHandler()->moduleExists('search_api') ? Index::load('content') : NULL;
$index_directly = $index?->getOption('index_directly');
if ($index && $index_directly) {
  $index->setOption('index_directly', FALSE)->save();
}

try {
  // ── Reference data ────────────────────────────────────────────────
  $departments = [
    'Accounting' => 'ACC', 'American Sign Language' => 'ASL', 'Art' => 'ART',
    'Automotive Technology' => 'AUT', 'Biology' => 'BIO', 'Biotechnology' => 'BIT',
    'Building Trades Technology' => 'BLD', 'Business' => 'BUS', 'Chemistry' => 'CHE',
    'Computer Science' => 'CMS', 'Criminal Justice' => 'CRJ', 'Culinary Arts' => 'CUL',
    'Cybersecurity' => 'CYB', 'Economics' => 'ECO', 'Education' => 'EDU',
    'Engineering' => 'EGR', 'English' => 'ENG', 'Health Information Management' => 'HIM',
    'History' => 'HIS', 'Mathematics' => 'MAT', 'Music' => 'MUS', 'Nursing' => 'NUR',
    'Physics' => 'PHY', 'Psychology' => 'PSY',
  ];
  $deptNames = array_keys($departments);
  $levels = [
    'Certificate' => 'Certificate',
    'Associate of Arts (A.A.)' => 'A.A.',
    'Associate of Science (A.S.)' => 'A.S.',
    'Associate of Applied Science (A.A.S.)' => 'A.A.S.',
    'Letter of Recognition' => 'Letter of Recognition',
  ];
  $levelNames = array_keys($levels);
  $firstNames = ['Maria', 'James', 'Aisha', 'Daniel', 'Priya', 'Michael', 'Sofia', 'Andre', 'Wei', 'Latoya', 'Hassan', 'Elena', 'Carlos', 'Grace', 'Omar', 'Nina', 'Thomas', 'Fatima', 'Kevin', 'Rosa'];
  $lastNames = ['Alvarez', 'Brooks', 'Chen', 'Diallo', 'Espinoza', 'Foster', 'Gupta', 'Hernandez', 'Ibrahim', 'Johnson', 'Kowalski', 'Lee', 'Mensah', 'Nguyen', 'Okafor', 'Patel', 'Rivera', 'Singh', 'Thompson', 'Williams'];
  $campuses = ['North Campus', 'Central Campus', 'East Campus', 'Online'];

  $ensureTerm = function (string $vid, string $name) use ($etm): int {
    $found = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    if ($found) {
      return (int) reset($found)->id();
    }
    $term = Term::create(['vid' => $vid, 'name' => $name]);
    $term->save();
    return (int) $term->id();
  };
  $firstTermId = function (string $vid) use ($etm): ?int {
    $ids = $etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', $vid)->sort('weight')->range(0, 1)->execute();
    return $ids ? (int) reset($ids) : NULL;
  };
  $termIds = function (string $vid) use ($etm): array {
    return array_values($etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', $vid)->execute());
  };
  $ensureUser = function (string $name, string $mail, array $roles, array $fields = []) use ($etm): User {
    $found = $etm->getStorage('user')->loadByProperties(['name' => $name]);
    $user = $found ? reset($found) : User::create([
      'name' => $name,
      'mail' => $mail,
      'pass' => \Drupal::service('password_generator')->generate(20),
      'status' => 1,
    ]);
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    foreach ($fields as $field => $value) {
      $user->set($field, $value);
    }
    $user->save();
    return $user;
  };

  $deptTids = [];
  foreach ($deptNames as $name) {
    $deptTids[$name] = $ensureTerm('department', $name);
  }
  $levelTids = [];
  foreach ($levelNames as $name) {
    $levelTids[$name] = $ensureTerm('program_level', $name);
  }
  $log('Taxonomy terms ready.');

  // ── Semesters ─────────────────────────────────────────────────────
  $semesterSpecs = [
    'Fall 2026' => ['2026-08-24', '2026-12-18', '2026-04-01', '2026-08-23', '2026-09-08', '2026-11-06'],
    'Spring 2027' => ['2027-01-19', '2027-05-14', '2026-10-26', '2027-01-18', '2027-02-02', '2027-04-09'],
    'Summer 2027' => ['2027-06-01', '2027-08-06', '2027-03-01', '2027-05-31', '2027-06-08', '2027-07-16'],
    'Fall 2027' => ['2027-08-30', '2027-12-17', '2027-04-05', '2027-08-29', '2027-09-13', '2027-11-05'],
  ];
  $semesterNids = [];
  foreach ($semesterSpecs as $title => [$start, $end, $open, $close, $drop, $withdraw]) {
    $found = $etm->getStorage('node')->loadByProperties(['type' => 'semester', 'title' => $title]);
    $node = $found ? reset($found) : Node::create(['type' => 'semester', 'title' => $title, 'uid' => 1, 'status' => 1]);
    $node->set('field_start_date', $start);
    $node->set('field_end_date', $end);
    $node->set('field_registration_open', $open);
    $node->set('field_registration_close', $close);
    $node->set('field_drop_deadline', $drop);
    $node->set('field_withdraw_deadline', $withdraw);
    $node->save();
    $semesterNids[$title] = (int) $node->id();
  }
  $log('Semesters ready: ' . count($semesterNids));

  // ── Faculty accounts (25, Professor role) ─────────────────────────
  $facultyUids = [];
  for ($i = 0; $i < 25; $i++) {
    $first = $firstNames[$i % 20];
    $last = $lastNames[($i + 3 * intdiv($i, 20)) % 20];
    $username = strtolower("$first.$last");
    $facultyUids[] = (int) $ensureUser($username, "$username@example.edu", ['professor'])->id();
  }
  $log('Faculty accounts ready: ' . count($facultyUids));

  // ── Faculty profiles ──────────────────────────────────────────────
  $positions = ['Professor', 'Associate Professor', 'Assistant Professor', 'Instructor'];
  for ($i = 0, $n = $opts['faculty-profiles']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    $initial = chr(65 + intdiv($i, 400) % 26);
    Node::create([
      'type' => 'faculty_profile',
      'title' => sprintf('%s %s. %s', $firstNames[$i % 20], $initial, $lastNames[intdiv($i, 20) % 20]),
      'uid' => 1,
      'status' => 1,
      'field_position' => $pick($positions, $i) . " of $dept",
      'field_department' => $deptTids[$dept],
      'field_faculty_email' => sprintf('faculty%04d@example.edu', $i + 1),
      'field_phone' => sprintf('(555) 01%02d', $i % 100),
      'field_office' => sprintf('%s, Room %d', $pick($campuses, $i % 3), 100 + $i % 400),
      'body' => ['value' => "<p>Faculty member in the $dept department at Montgomery Community College.</p>", 'format' => 'content_format'],
    ])->save();
    $progress('faculty_profile', $i + 1, $n);
  }

  // ── Courses ───────────────────────────────────────────────────────
  $topics = ['Introduction to', 'Principles of', 'Foundations of', 'Survey of', 'Applied', 'Methods in', 'Topics in', 'Seminar in', 'Practicum in', 'Advanced'];
  $roman = ['I', 'II', 'III', 'IV', 'V'];
  $courseNids = [];
  for ($i = 0, $n = $opts['courses']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    $num = 101 + intdiv($i, 24);
    $code = $departments[$dept] . ' ' . $num;
    $title = sprintf('%s: %s %s %s', $code, $pick($topics, intdiv($i, 24)), $dept, $roman[intdiv($i, 240) % 5]);
    $node = Node::create([
      'type' => 'course',
      'title' => $title,
      'uid' => 1,
      'status' => 1,
      'field_course_code' => $code,
      'field_credits' => 1 + ($i % 4),
      'field_department' => $deptTids[$dept],
      'body' => ['value' => "<p>Survey of $dept. Covers core concepts and transfer preparation.</p>", 'format' => 'content_format'],
    ]);
    $node->save();
    $courseNids[] = ['nid' => (int) $node->id(), 'code' => $code];
    $progress('course', $i + 1, $n);
  }

  // ── Course sessions (with registration enabled) ───────────────────
  $sessionNids = [];
  $semesterTitles = array_keys($semesterSpecs);
  $tz = new \DateTimeZone(\Drupal::config('system.date')->get('timezone.default') ?: 'UTC');
  for ($i = 0, $n = $opts['sessions']; $i < $n && $courseNids; $i++) {
    $course = $courseNids[$i % count($courseNids)];
    $semester = $semesterTitles[$i % count($semesterTitles)];
    [$semStart, , $regOpen, $regClose] = $semesterSpecs[$semester];
    $start = new DrupalDateTime(sprintf('%s %02d:00', $semStart, 8 + 2 * ($i % 6)), $tz);
    $start->modify('+' . ($i % 5) . ' days');
    $end = (clone $start)->modify('+75 minutes');
    $capacity = 20 + $i % 16;
    $campus = $pick($campuses, $i);
    $node = Node::create([
      'type' => 'course_session',
      'title' => sprintf('%s-%02d (%s)', $course['code'], 1 + intdiv($i, count($courseNids)), $semester),
      'uid' => 1,
      'status' => 1,
      'field_course' => $course['nid'],
      'field_semester' => $semesterNids[$semester],
      'field_professor' => $facultyUids[$i % count($facultyUids)],
      'field_date_time' => ['value' => $utc($start), 'end_value' => $utc($end)],
      'field_location' => $campus === 'Online' ? 'Online' : sprintf('%s, Room %d', $campus, 100 + $i % 300),
      'field_capacity' => $capacity,
      'field_requires_matriculation' => (int) ($i % 5 === 0),
      'field_registration' => 'course_session',
    ]);
    $node->save();
    $settings = $node->get('field_registration')->createHostEntity()->getSettings();
    $settings->set('status', TRUE);
    $settings->set('capacity', $capacity);
    $settings->set('open', $regOpen . 'T00:00:00');
    $settings->set('close', $regClose . 'T23:59:59');
    $settings->save();
    $sessionNids[] = ['nid' => (int) $node->id(), 'semester' => $semester, 'restricted' => $i % 5 === 0];
    $progress('course_session', $i + 1, $n);
  }

  // ── Programs (Educare program type, published via moderation) ─────
  $tracks = ['General', 'Transfer Pathway', 'Career Track', 'Honors'];
  $programNids = [];
  for ($i = 0, $n = $opts['programs']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    $level = $levelNames[intdiv($i, 24) % 5];
    $node = Node::create([
      'type' => 'program',
      'title' => sprintf('%s – %s (%s)', $dept, $pick($tracks, intdiv($i, 120)), $levels[$level]),
      'uid' => 1,
      'moderation_state' => 'published',
      'field_department' => $deptTids[$dept],
      'field_program_level' => $levelTids[$level],
      'field_credits' => 24 + ($i % 8) * 6,
      'field_description' => "The $dept program prepares students for transfer or workforce entry.",
    ]);
    $node->save();
    $programNids[] = (int) $node->id();
    $progress('program', $i + 1, $n);
  }
  // Educare's program page needs Program Type and Study Level.
  require __DIR__ . '/mc_backfill_program_taxonomy.php';

  // Students and their multi-semester academic history are seeded by
  // mc_seed_academic_history.php at the end of this script.

  // ── Scholarships ──────────────────────────────────────────────────
  $kinds = ['Merit', 'Achievement', 'Opportunity', 'Excellence', 'Leadership', 'Community', 'Promise', 'Transfer', 'Workforce', 'First-Generation', 'Returning Student', 'Foundation', 'Alumni', 'Presidential', 'Deans', 'Service', 'Innovation', 'Access', 'Veterans', 'Single Parent', 'STEM Pathways', 'Arts', 'Career Ready', 'Honors', 'Endowed'];
  for ($i = 0, $n = $opts['scholarships']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    $suffix = $i >= 600 ? ' ' . (intdiv($i, 600) + 1) : '';
    Node::create([
      'type' => 'scholarship',
      'title' => sprintf('%s %s Scholarship%s', $dept, $kinds[intdiv($i, 24) % 25], $suffix),
      'uid' => 1,
      'status' => 1,
      'field_award_amount' => 250 + ($i % 40) * 125,
      'field_deadline' => date('Y-m-d', strtotime('2027-02-01 +' . ($i % 330) . ' days')),
      'field_department' => $deptTids[$dept],
      'field_application_url' => ['uri' => 'https://example.edu/scholarships/apply', 'title' => 'Apply online'],
      'body' => ['value' => "<p>Supports students in $dept.</p>", 'format' => 'content_format'],
    ])->save();
    $progress('scholarship', $i + 1, $n);
  }

  // ── Events (Educare event type: field_when is a Smart Date) ───────
  $eventTypes = ['Information Session', 'Transfer Fair', 'Open House', 'Career Workshop', 'Financial Aid Night', 'Campus Tour'];
  $eventCats = $termIds('event_categories');
  for ($i = 0, $n = $opts['events']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    $start = new DrupalDateTime('2027-01-15 18:00', $tz);
    $start->modify('+' . ($i % 400) . ' days');
    Node::create([
      'type' => 'event',
      'title' => sprintf('%s: %s (%s)', $pick($eventTypes, $i), $dept, $start->format('M j, Y')),
      'uid' => 1,
      'status' => 1,
      'field_when' => [
        'value' => $start->getTimestamp(),
        'end_value' => $start->getTimestamp() + 7200,
        'duration' => 120,
        'timezone' => '',
      ],
      'field_location' => $pick($campuses, $i % 3),
      'field_description' => 'Open to prospective and current students.',
      'field_event_categories' => $eventCats ? $pick($eventCats, $i) : NULL,
    ])->save();
    $progress('event', $i + 1, $n);
  }

  // ── News (Educare news type: category is required) ────────────────
  $newsVerbs = ['Announces', 'Launches', 'Celebrates', 'Expands', 'Highlights'];
  $newsCats = $termIds('news_categories');
  for ($i = 0, $n = $opts['news']; $i < $n; $i++) {
    $dept = $deptNames[$i % 24];
    Node::create([
      'type' => 'news',
      'title' => sprintf('Montgomery Community College %s %s Initiative #%d', $pick($newsVerbs, $i), $dept, $i + 1),
      'uid' => 1,
      'moderation_state' => 'published',
      'field_description' => "News from the $dept department.",
      'field_content' => ['value' => "<p>News from the $dept department.</p>", 'format' => 'content_format'],
      'field_news_categories' => $newsCats ? $pick($newsCats, $i) : NULL,
    ])->save();
    $progress('news', $i + 1, $n);
  }

  // ── Students with multi-semester academic history ─────────────────
  $extra = ["--students={$opts['students']}"];
  require __DIR__ . '/mc_seed_academic_history.php';

  $state->set('mc_seed.completed', \Drupal::time()->getRequestTime());
  $log('Seeding complete. Run cron to index content for search.');
}
finally {
  if ($index && $index_directly) {
    Index::load('content')->setOption('index_directly', TRUE)->save();
  }
}

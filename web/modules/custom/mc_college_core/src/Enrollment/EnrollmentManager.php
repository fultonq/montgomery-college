<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Enrollment;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\registration\Entity\RegistrationInterface;

/**
 * Add/drop/withdraw rules and schedule data.
 *
 * Deadlines come from the session's Semester:
 * - Through the Drop Deadline: students may drop (no transcript entry, no
 *   tuition charge).
 * - After the Drop Deadline, through the Withdraw Deadline: students may
 *   withdraw (W on transcript, tuition still owed).
 * - After the Withdraw Deadline: no self-service; registrars may still
 *   change state through the registration edit form.
 * Waitlisted students may leave the waitlist (drop) until the term ends.
 */
final class EnrollmentManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Today's date (Y-m-d) in the site timezone.
   */
  public function today(): string {
    return DrupalDateTime::createFromTimestamp($this->time->getRequestTime())->format('Y-m-d');
  }

  /**
   * The semester node of a course_session registration.
   */
  public function getSemester(RegistrationInterface $registration): ?NodeInterface {
    $session = $registration->getHostEntity()?->getEntity();
    return $session instanceof NodeInterface && $session->hasField('field_semester')
      ? $session->get('field_semester')->entity
      : NULL;
  }

  /**
   * The self-service action available on a registration, or NULL.
   *
   * @return string|null
   *   'drop', 'withdraw', or NULL.
   */
  public function availableAction(RegistrationInterface $registration): ?string {
    $semester = $this->getSemester($registration);
    if (!$semester) {
      return NULL;
    }
    $today = $this->today();
    $state = $registration->get('state')->value;
    $end = $semester->get('field_end_date')->value;
    $drop = $semester->get('field_drop_deadline')->value;
    $withdraw = $semester->get('field_withdraw_deadline')->value;

    if ($state === 'waitlisted') {
      return $end && $today <= $end ? 'drop' : NULL;
    }
    if ($state !== 'enrolled') {
      return NULL;
    }
    if ($drop && $today <= $drop) {
      return 'drop';
    }
    if ($withdraw && $today <= $withdraw) {
      return 'withdraw';
    }
    return NULL;
  }

  /**
   * Whether an account may perform an action on a registration.
   */
  public function canPerform(RegistrationInterface $registration, string $action, AccountInterface $account): bool {
    if (!in_array($action, ['drop', 'withdraw'], TRUE) || $registration->bundle() !== 'course_session') {
      return FALSE;
    }
    $state = $registration->get('state')->value;
    // Registrars may drop or withdraw any active registration at any time.
    if ($account->hasPermission('administer course_session registration')) {
      return $action === 'drop'
        ? in_array($state, ['enrolled', 'waitlisted'], TRUE)
        : $state === 'enrolled';
    }
    return $account->isAuthenticated()
      && $registration->getUserId() === (int) $account->id()
      && $account->hasPermission('manage own course registrations')
      && $this->availableAction($registration) === $action;
  }

  /**
   * Applies a drop or withdraw.
   */
  public function perform(RegistrationInterface $registration, string $action): void {
    $registration->set('state', $action === 'drop' ? 'dropped' : 'withdrawn');
    $registration->save();
  }

  /**
   * A student's registrations for terms that have not ended, by semester.
   *
   * @return array<int, array{semester: \Drupal\node\NodeInterface, registrations: \Drupal\registration\Entity\RegistrationInterface[]}>
   *   Keyed by semester node ID, ordered by start date.
   */
  public function getCurrentSchedule(int $uid): array {
    $storage = $this->entityTypeManager->getStorage('registration');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course_session')
      ->condition('user_uid', $uid)
      ->condition('state', ['enrolled', 'waitlisted', 'withdrawn', 'completed'], 'IN')
      ->execute();
    $today = $this->today();
    $terms = [];
    foreach ($storage->loadMultiple($ids) as $registration) {
      $semester = $this->getSemester($registration);
      if (!$semester || ($semester->get('field_end_date')->value ?? '') < $today) {
        continue;
      }
      $terms[$semester->id()] ??= ['semester' => $semester, 'registrations' => []];
      $terms[$semester->id()]['registrations'][] = $registration;
    }
    uasort($terms, fn($a, $b) => strcmp((string) $a['semester']->get('field_start_date')->value, (string) $b['semester']->get('field_start_date')->value));
    return $terms;
  }

  /**
   * Formats a session's meeting date range ("Mon, Jan 19, 9:00 AM - 10:15 AM").
   */
  public function formatMeeting(NodeInterface $session): string {
    if (!$session->hasField('field_date_time') || $session->get('field_date_time')->isEmpty()) {
      return '';
    }
    $item = $session->get('field_date_time')->first();
    $utc = new \DateTimeZone('UTC');
    $start = new DrupalDateTime($item->value, $utc);
    $end = new DrupalDateTime($item->end_value, $utc);
    $tz = new \DateTimeZone(date_default_timezone_get());
    $start->setTimezone($tz);
    $end->setTimezone($tz);
    return $start->format('D, M j, g:i A') . ' – ' . $end->format('g:i A');
  }

  /**
   * Course sessions taught by a professor, grouped by semester (newest first).
   *
   * @return array<int, array{semester: \Drupal\node\NodeInterface, sessions: \Drupal\node\NodeInterface[]}>
   *   Keyed by semester node ID.
   */
  public function getTeachingSessions(int $uid): array {
    $nodes = $this->entityTypeManager->getStorage('node');
    $ids = $nodes->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course_session')
      ->condition('field_professor', $uid)
      ->sort('title')
      ->execute();
    $terms = [];
    foreach ($nodes->loadMultiple($ids) as $session) {
      $semester = $session->get('field_semester')->entity;
      if (!$semester) {
        continue;
      }
      $terms[$semester->id()] ??= ['semester' => $semester, 'sessions' => []];
      $terms[$semester->id()]['sessions'][] = $session;
    }
    uasort($terms, fn($a, $b) => strcmp((string) $b['semester']->get('field_start_date')->value, (string) $a['semester']->get('field_start_date')->value));
    return $terms;
  }

  /**
   * Registrations in a course session, keyed by registration ID.
   *
   * @return \Drupal\registration\Entity\RegistrationInterface[]
   *   Registrations, sorted by student name.
   */
  public function getRoster(NodeInterface $session, array $states = ['enrolled', 'completed', 'withdrawn', 'waitlisted']): array {
    $storage = $this->entityTypeManager->getStorage('registration');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course_session')
      ->condition('entity_type_id', 'node')
      ->condition('entity_id', $session->id())
      ->condition('state', $states, 'IN')
      ->execute();
    $roster = $storage->loadMultiple($ids);
    uasort($roster, fn($a, $b) => strnatcasecmp((string) $a->getUser()?->getDisplayName(), (string) $b->getUser()?->getDisplayName()));
    return $roster;
  }

}

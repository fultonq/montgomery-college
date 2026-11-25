<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Academic;

/**
 * Grading rules for Montgomery Community College.
 *
 * - A-F carry grade points and count toward GPA.
 * - P (pass) earns credit but does not affect GPA.
 * - I (incomplete) is attempted but neither earns credit nor affects GPA.
 * - Withdrawn registrations appear as W: attempted, no credit, no GPA.
 * - Enrolled registrations appear as IP (in progress).
 * - Dropped and waitlisted registrations never appear on a transcript.
 */
final class GradeScale {

  /**
   * Grade points per letter grade.
   */
  public const POINTS = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, 'F' => 0.0];

  /**
   * Grades that earn credit.
   */
  public const PASSING = ['A', 'B', 'C', 'D', 'P'];

  /**
   * Grades counted as "successful" completion (community college success rate).
   */
  public const SUCCESS = ['A', 'B', 'C', 'P'];

  /**
   * Every transcript mark, in display order.
   */
  public const MARKS = ['A', 'B', 'C', 'D', 'F', 'P', 'I', 'W'];

  /**
   * Registration states that appear on a transcript.
   */
  public const TRANSCRIPT_STATES = ['enrolled', 'completed', 'withdrawn'];

  /**
   * Registration states that hold a seat (count toward a term's credit load).
   */
  public const ACTIVE_STATES = ['enrolled', 'completed'];

  /**
   * The mark shown on a transcript for a registration.
   */
  public static function mark(string $state, ?string $grade): string {
    return match ($state) {
      'withdrawn' => 'W',
      'enrolled' => 'IP',
      'completed' => $grade !== NULL && $grade !== '' ? $grade : 'NR',
      default => '',
    };
  }

  /**
   * Whether a completed grade counts toward GPA.
   */
  public static function countsTowardGpa(string $state, ?string $grade): bool {
    return $state === 'completed' && $grade !== NULL && isset(self::POINTS[$grade]);
  }

  /**
   * Whether a registration earned credit.
   */
  public static function earnsCredit(string $state, ?string $grade): bool {
    return $state === 'completed' && in_array($grade, self::PASSING, TRUE);
  }

  /**
   * Whether a registration counts as attempted credit.
   */
  public static function isAttempted(string $state): bool {
    return in_array($state, ['completed', 'withdrawn'], TRUE);
  }

}

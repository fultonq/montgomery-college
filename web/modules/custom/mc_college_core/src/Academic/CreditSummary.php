<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Academic;

/**
 * Accumulates credits and grade points for a term or a whole record.
 */
final class CreditSummary {

  /**
   * Credits completed or withdrawn.
   */
  public int $attempted = 0;

  /**
   * Credits completed with a passing grade (A-D, P).
   */
  public int $earned = 0;

  /**
   * Credits graded A-F (the GPA denominator).
   */
  public int $gpaCredits = 0;

  /**
   * Grade points times credits.
   */
  public float $qualityPoints = 0.0;

  /**
   * Credits currently in progress.
   */
  public int $inProgress = 0;

  /**
   * Adds one registration.
   */
  public function add(string $state, ?string $grade, int $credits): void {
    if ($state === 'enrolled') {
      $this->inProgress += $credits;
      return;
    }
    if (GradeScale::isAttempted($state)) {
      $this->attempted += $credits;
    }
    if (GradeScale::earnsCredit($state, $grade)) {
      $this->earned += $credits;
    }
    if (GradeScale::countsTowardGpa($state, $grade)) {
      $this->gpaCredits += $credits;
      $this->qualityPoints += GradeScale::POINTS[$grade] * $credits;
    }
  }

  /**
   * Merges another summary into this one.
   */
  public function merge(CreditSummary $other): void {
    $this->attempted += $other->attempted;
    $this->earned += $other->earned;
    $this->gpaCredits += $other->gpaCredits;
    $this->qualityPoints += $other->qualityPoints;
    $this->inProgress += $other->inProgress;
  }

  /**
   * GPA rounded to two decimals, or NULL with no GPA credits.
   */
  public function gpa(): ?float {
    return $this->gpaCredits > 0 ? round($this->qualityPoints / $this->gpaCredits, 2) : NULL;
  }

  /**
   * GPA formatted for display ("3.25" or "—").
   */
  public function formattedGpa(): string {
    $gpa = $this->gpa();
    return $gpa === NULL ? '—' : number_format($gpa, 2);
  }

  /**
   * Share of attempted credits that were earned, 0-100, or NULL.
   */
  public function completionRate(): ?float {
    return $this->attempted > 0 ? round(100 * $this->earned / $this->attempted, 1) : NULL;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Enforces enrollment policy on course_session registrations.
 */
#[Constraint(
  id: 'McCollegeEnrollmentPolicy',
  label: new TranslatableMarkup('MC College enrollment policy', [], ['context' => 'Validation']),
  type: 'entity:registration',
)]
final class EnrollmentPolicyConstraint extends SymfonyConstraint {

  public string $mustMatriculate = 'Non-matriculated students must matriculate after earning @threshold credits (@earned earned). Apply to a program before registering for more courses.';

  public string $creditCap = 'Non-matriculated students may take at most @cap credits per semester. This course (@credits credits) would bring @semester to @total credits.';

  public string $notActive = 'Only students with an active enrollment status can register. Current status: @status.';

}

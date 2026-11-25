<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks new registrations while a past-term balance is outstanding.
 */
#[Constraint(
  id: 'McCollegeFinancialHold',
  label: new TranslatableMarkup('MC College financial hold', [], ['context' => 'Validation']),
  type: 'entity:registration',
)]
final class FinancialHoldConstraint extends SymfonyConstraint {

  public string $message = 'Registration is on hold: there is an unpaid balance of @amount from earlier semesters. Contact the Bursar\'s Office.';

}

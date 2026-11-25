<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mc_college_billing\TuitionBilling;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the financial hold for new course registrations.
 */
final class FinancialHoldConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly TuitionBilling $billing,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('mc_college_billing.tuition'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $registration, Constraint $constraint): void {
    /** @var \Drupal\registration\Entity\RegistrationInterface $registration */
    /** @var \Drupal\mc_college_billing\Plugin\Validation\Constraint\FinancialHoldConstraint $constraint */
    if (!$this->billing->setting('financial_hold_enabled', FALSE)
      || !$registration->isNew()
      || $registration->bundle() !== 'course_session'
      || !$registration->getUserId()) {
      return;
    }
    $session = $registration->getHostEntity()?->getEntity();
    $start = $session instanceof NodeInterface ? $session->get('field_semester')->entity?->get('field_start_date')->value : NULL;
    if (!$start) {
      return;
    }
    $due = $this->billing->getPastDueBalance($registration->getUserId(), $start);
    if ($due > (float) $this->billing->setting('financial_hold_threshold', '0')) {
      $this->context->addViolation($constraint->message, ['@amount' => '$' . number_format($due, 2)]);
    }
  }

}

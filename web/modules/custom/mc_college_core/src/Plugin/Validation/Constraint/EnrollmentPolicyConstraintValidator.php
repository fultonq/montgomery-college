<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mc_college_core\Academic\AcademicRecords;
use Drupal\mc_college_core\Academic\GradeScale;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates registrations against the non-matriculated student policy.
 *
 * Applies when a registration newly takes a seat: a new registration, or an
 * existing one moving from a non-seat state (waitlisted) into enrolled.
 */
final class EnrollmentPolicyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly AcademicRecords $records,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mc_college_core.academic_records'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $registration, Constraint $constraint): void {
    /** @var \Drupal\registration\Entity\RegistrationInterface $registration */
    /** @var \Drupal\mc_college_core\Plugin\Validation\Constraint\EnrollmentPolicyConstraint $constraint */
    if ($registration->bundle() !== 'course_session' || !$registration->getUserId()) {
      return;
    }
    $state = $registration->get('state')->value;
    if ($state !== 'enrolled') {
      return;
    }
    if (!$registration->isNew()) {
      $original = $this->entityTypeManager->getStorage('registration')->loadUnchanged($registration->id());
      if ($original && in_array($original->get('state')->value, GradeScale::ACTIVE_STATES, TRUE)) {
        // Already held a seat; nothing new to validate.
        return;
      }
    }

    $user = $registration->getUser();
    $session = $registration->getHostEntity()?->getEntity();
    if (!$user || !$session instanceof NodeInterface || !$user->hasField('field_student_type')) {
      return;
    }

    if ($user->hasField('field_enrollment_status') && !$user->get('field_enrollment_status')->isEmpty()) {
      $status = $user->get('field_enrollment_status')->value;
      if ($status !== 'active') {
        $this->context->addViolation($constraint->notActive, ['@status' => $status]);
        return;
      }
    }

    if ($user->get('field_student_type')->value === 'matriculated') {
      return;
    }

    $uid = (int) $user->id();
    if ($this->records->setting('enforce_matriculation', TRUE)) {
      $threshold = (int) $this->records->setting('matriculation_credit_threshold', 12);
      $earned = $this->records->getEarnedCredits($uid);
      if ($earned >= $threshold) {
        $this->context->addViolation($constraint->mustMatriculate, ['@threshold' => $threshold, '@earned' => $earned]);
        return;
      }
    }

    $semester = $session->get('field_semester')->entity;
    $course = $session->get('field_course')->entity;
    if (!$semester || !$course) {
      return;
    }
    $cap = (int) $this->records->setting('non_matriculated_credit_cap', 11);
    $credits = (int) $course->get('field_credits')->value;
    $total = $this->records->getTermLoad($uid, (int) $semester->id(), $registration->isNew() ? NULL : (int) $registration->id()) + $credits;
    if ($total > $cap) {
      $this->context->addViolation($constraint->creditCap, [
        '@cap' => $cap,
        '@credits' => $credits,
        '@semester' => $semester->label(),
        '@total' => $total,
      ]);
    }
  }

}

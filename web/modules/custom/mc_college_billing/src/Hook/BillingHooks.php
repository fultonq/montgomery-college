<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\mc_college_billing\TuitionBilling;
use Drupal\user\UserInterface;

/**
 * Keeps term bills in sync with registrations and residency.
 */
final class BillingHooks {

  public function __construct(
    private readonly TuitionBilling $billing,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for registration.
   */
  #[Hook('registration_insert')]
  public function registrationInsert(EntityInterface $registration): void {
    $this->billing->syncForRegistration($registration);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for registration.
   */
  #[Hook('registration_update')]
  public function registrationUpdate(EntityInterface $registration): void {
    $original = $registration->getOriginal() ?? NULL;
    if ($original && $original->get('state')->value === $registration->get('state')->value
      && $original->get('entity_id')->value === $registration->get('entity_id')->value) {
      // Grade-only changes do not affect billing.
      return;
    }
    $this->billing->syncForRegistration($registration);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for registration.
   */
  #[Hook('registration_delete')]
  public function registrationDelete(EntityInterface $registration): void {
    $this->billing->syncForRegistration($registration);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for user.
   *
   * Residency determines the tuition rate, so re-bill when it changes.
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $user): void {
    $original = $user->getOriginal() ?? NULL;
    if ($original && $user->hasField('field_residency')
      && $original->get('field_residency')->value !== $user->get('field_residency')->value) {
      $this->billing->syncAllForStudent($user);
    }
  }

  /**
   * Implements hook_entity_type_alter().
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['registration'])) {
      $entity_types['registration']->addConstraint('McCollegeFinancialHold');
    }
  }

}

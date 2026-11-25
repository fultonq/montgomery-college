<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mc_college_core\Enrollment\EnrollmentManager;
use Drupal\registration\Entity\RegistrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms dropping or withdrawing from a course session.
 */
final class RegistrationActionForm extends ConfirmFormBase {

  private RegistrationInterface $registration;

  private string $action;

  public function __construct(
    private readonly EnrollmentManager $enrollment,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('mc_college_core.enrollment'));
  }

  /**
   * Route access callback.
   */
  public static function access(RegistrationInterface $registration, string $action, AccountInterface $account): AccessResultInterface {
    /** @var \Drupal\mc_college_core\Enrollment\EnrollmentManager $enrollment */
    $enrollment = \Drupal::service('mc_college_core.enrollment');
    return AccessResult::allowedIf($enrollment->canPerform($registration, $action, $account))
      ->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mc_college_core_registration_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?RegistrationInterface $registration = NULL, string $action = 'drop'): array {
    $this->registration = $registration;
    $this->action = $action;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $session = $this->registration->getHostEntity()?->getEntity()?->label();
    return $this->action === 'drop'
      ? $this->t('Drop @session?', ['@session' => $session])
      : $this->t('Withdraw from @session?', ['@session' => $session]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->action === 'drop'
      ? $this->t('The course is removed from your schedule with no transcript entry. Any tuition for it is removed from your bill.')
      : $this->t('You will receive a W on your transcript. Withdrawn credits count as attempted, do not affect GPA, and tuition is not refunded.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->action === 'drop' ? $this->t('Drop course') : $this->t('Withdraw');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('mc_college_core.schedule', ['user' => $this->registration->getUserId()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Re-check in case the deadline passed while the form was open.
    if (!$this->enrollment->canPerform($this->registration, $this->action, $this->currentUser())) {
      $this->messenger()->addError($this->t('This change is no longer allowed. Contact the Registrar.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    $this->enrollment->perform($this->registration, $this->action);
    $this->messenger()->addStatus($this->action === 'drop'
      ? $this->t('Dropped @session.', ['@session' => $this->registration->getHostEntity()?->getEntity()?->label()])
      : $this->t('Withdrew from @session.', ['@session' => $this->registration->getHostEntity()?->getEntity()?->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}

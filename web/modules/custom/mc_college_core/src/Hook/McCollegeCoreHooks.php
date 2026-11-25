<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\registration\HostEntityInterface;

/**
 * Hook implementations for MC College Core.
 */
final class McCollegeCoreHooks {

  use StringTranslationTrait;

  /**
   * Student record fields: only the student and user administrators see them.
   */
  private const STUDENT_FIELDS = [
    'field_student_type',
    'field_student_id',
    'field_program',
    'field_residency',
    'field_gpa',
    'field_enrollment_status',
    'field_matriculation_date',
  ];

  /**
   * Host operations a Professor gets for sessions they teach.
   */
  private const PROFESSOR_HOST_OPERATIONS = [
    'manage',
    'manage registrations',
    'view registrations',
    'update registrations',
    'edit registrations state',
  ];

  /**
   * Content types added by the mc_college_structure recipe.
   */
  private const COLLEGE_BUNDLES = ['course', 'course_session', 'semester', 'faculty_profile', 'scholarship'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_type_alter().
   *
   * Attaches the enrollment policy (non-matriculated credit cap and
   * matriculation threshold, active enrollment status) to registrations.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['registration'])) {
      $entity_types['registration']->addConstraint('McCollegeEnrollmentPolicy');
    }
  }

  /**
   * Implements hook_registration_host__access().
   *
   * - Professors can manage the roster of sessions they teach.
   * - Non-matriculated students cannot self-register for restricted sessions.
   */
  #[Hook('registration_host__access')]
  public function registrationHostAccess(HostEntityInterface $host_entity, string $operation, AccountInterface $account): AccessResultInterface {
    $node = $host_entity->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'course_session') {
      return AccessResult::neutral();
    }

    if (in_array($operation, self::PROFESSOR_HOST_OPERATIONS, TRUE)) {
      return AccessResult::allowedIf(
        $account->hasPermission('grade own course sessions') && $this->teaches($node, $account)
      )->cachePerPermissions()->cachePerUser()->addCacheableDependency($node);
    }

    if ($operation === 'register self' && (bool) $node->get('field_requires_matriculation')->value) {
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      $type = $user && $user->hasField('field_student_type') ? $user->get('field_student_type')->value : NULL;
      $result = AccessResult::forbiddenIf($type !== 'matriculated', 'This course session is restricted to matriculated students.')
        ->cachePerUser()->addCacheableDependency($node);
      if ($user) {
        $result->addCacheableDependency($user);
      }
      return $result;
    }

    return AccessResult::neutral();
  }

  /**
   * Implements hook_entity_field_access().
   *
   * - Grades: editable by registrars and the session's professor; visible to
   *   them and to the student who owns the registration.
   * - Student record fields: visible to the student and user administrators;
   *   editable only by user administrators.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $entity_type = $field_definition->getTargetEntityTypeId();
    $field_name = $field_definition->getName();

    if ($entity_type === 'registration' && $field_name === 'field_grade') {
      if ($items === NULL) {
        return AccessResult::neutral();
      }
      /** @var \Drupal\registration\Entity\RegistrationInterface $registration */
      $registration = $items->getEntity();
      $host = $registration->getHostEntity()?->getEntity();
      $is_registrar = $account->hasPermission('administer course_session registration');
      $teaches = $host instanceof NodeInterface
        && $account->hasPermission('grade own course sessions')
        && $this->teaches($host, $account);

      $allowed = $is_registrar || $teaches;
      if ($operation === 'view') {
        $allowed = $allowed
          || $account->hasPermission('view any course_session registration')
          || ($account->isAuthenticated() && $registration->getUserId() === (int) $account->id());
      }
      $result = AccessResult::forbiddenIf(!$allowed)
        ->cachePerPermissions()->cachePerUser()->addCacheableDependency($registration);
      if ($host) {
        $result->addCacheableDependency($host);
      }
      return $result;
    }

    if ($entity_type === 'user' && in_array($field_name, self::STUDENT_FIELDS, TRUE)) {
      $is_admin = $account->hasPermission('administer users');
      if ($operation === 'edit') {
        return AccessResult::forbiddenIf(!$is_admin)->cachePerPermissions();
      }
      if ($items === NULL) {
        return AccessResult::neutral();
      }
      $own = $account->isAuthenticated() && (int) $items->getEntity()->id() === (int) $account->id();
      return AccessResult::forbiddenIf(!$is_admin && !$own)->cachePerPermissions()->cachePerUser();
    }

    return AccessResult::neutral();
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for content_template.
   *
   * Varbase creates an empty Canvas "full" content template for every new
   * content type. An empty enabled template renders a blank page, so these
   * are saved disabled and the college types use their entity view displays
   * until someone designs a template in Canvas and enables it.
   */
  #[Hook('content_template_presave')]
  public function contentTemplatePresave(EntityInterface $template): void {
    if (!$template->isNew() || !$template instanceof ConfigEntityInterface) {
      return;
    }
    $parts = explode('.', (string) $template->id());
    if (count($parts) === 3 && $parts[0] === 'node' && $parts[2] === 'full'
      && in_array($parts[1], self::COLLEGE_BUNDLES, TRUE)
      && empty($template->get('component_tree'))) {
      $template->setStatus(FALSE);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for entity_view_display.
   *
   * Varbase also creates a near-empty "full" view display alongside each
   * content template. Disabled, full pages fall back to the default display
   * defined by the recipe.
   */
  #[Hook('entity_view_display_presave')]
  public function entityViewDisplayPresave(EntityInterface $display): void {
    if ($display->isNew()
      && $display instanceof ConfigEntityInterface
      && $display->get('targetEntityType') === 'node'
      && $display->get('mode') === 'full'
      && in_array($display->get('bundle'), self::COLLEGE_BUNDLES, TRUE)) {
      $display->setStatus(FALSE);
    }
  }

  /**
   * Implements hook_preprocess_node().
   *
   * Adds a bundle class to college content and a registration status badge
   * to full semester pages.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $variables['node'];
    $bundle = $node->bundle();
    if (!in_array($bundle, ['course', 'course_session', 'semester', 'faculty_profile', 'scholarship', 'program'], TRUE)) {
      return;
    }
    $variables['attributes']['class'][] = 'mc-node';
    $variables['attributes']['class'][] = 'mc-node--' . str_replace('_', '-', $bundle);

    if ($bundle !== 'semester' || ($variables['view_mode'] ?? '') !== 'full'
      || $node->get('field_registration_open')->isEmpty() || $node->get('field_registration_close')->isEmpty()) {
      return;
    }
    $today = (new DrupalDateTime('today'))->format('Y-m-d');
    $open = $node->get('field_registration_open')->value;
    $close = $node->get('field_registration_close')->value;
    [$status, $label] = match (TRUE) {
      $today < $open => ['upcoming', $this->t('Registration opens soon')],
      $today <= $close => ['open', $this->t('Registration is open')],
      default => ['closed', $this->t('Registration is closed')],
    };
    $variables['content']['mc_registration_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $label,
      '#attributes' => ['class' => ['mc-registration-status', 'mc-registration-status--' . $status]],
      '#weight' => -100,
      '#cache' => ['max-age' => 86400],
    ];
  }

  /**
   * Whether the account is the assigned professor of a course session.
   */
  private function teaches(NodeInterface $session, AccountInterface $account): bool {
    return $account->isAuthenticated()
      && $session->hasField('field_professor')
      && (int) $session->get('field_professor')->target_id === (int) $account->id();
  }

}

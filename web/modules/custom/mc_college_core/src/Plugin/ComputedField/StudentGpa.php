<?php

declare(strict_types=1);

namespace Drupal\mc_college_core\Plugin\ComputedField;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\computed_field\Attribute\ComputedField;
use Drupal\computed_field\Field\ComputedFieldDefinitionWithValuePluginInterface;
use Drupal\computed_field\Plugin\ComputedField\ComputedFieldBase;
use Drupal\computed_field\Plugin\ComputedField\SingleValueTrait;

/**
 * Cumulative GPA for a student account.
 *
 * Credit-weighted average of grade points over the student's Completed
 * course_session registrations graded A-F. P and I grades, and Dropped,
 * Withdrawn, Enrolled, and Waitlisted registrations, are excluded.
 */
#[ComputedField(
  id: 'mc_college_student_gpa',
  label: new TranslatableMarkup('Cumulative GPA'),
  field_type: 'decimal',
  no_ui: TRUE,
  attach: [
    'scope' => 'bundle',
    'field_name' => 'field_gpa',
    'entity_types' => ['user' => ['user']],
  ],
)]
final class StudentGpa extends ComputedFieldBase {

  use SingleValueTrait;

  /**
   * Grade points per letter grade.
   */
  public const GRADE_POINTS = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, 'F' => 0.0];

  /**
   * {@inheritdoc}
   */
  public function getFieldLabel(): string {
    return (string) new TranslatableMarkup('Cumulative GPA');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageDefinitionSettings(): array {
    return ['precision' => 3, 'scale' => 2];
  }

  /**
   * {@inheritdoc}
   */
  public function singleComputeValue(EntityInterface $host_entity, ComputedFieldDefinitionWithValuePluginInterface $computed_field_definition): mixed {
    return self::calculate((int) $host_entity->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheability(EntityInterface $host_entity, ComputedFieldDefinitionWithValuePluginInterface $computed_field_definition): ?CacheableMetadata {
    return (new CacheableMetadata())->setCacheTags(['registration_list', 'node_list']);
  }

  /**
   * Calculates the GPA for a user, or NULL if there are no graded credits.
   *
   * Delegates to the academic records service so the GPA field, transcript,
   * and reports always agree.
   */
  public static function calculate(int $uid): ?string {
    if ($uid === 0) {
      return NULL;
    }
    /** @var \Drupal\mc_college_core\Academic\AcademicRecords $records */
    $records = \Drupal::service('mc_college_core.academic_records');
    $gpa = $records->getCumulativeGpa($uid);
    return $gpa === NULL ? NULL : number_format($gpa, 2, '.', '');
  }

}

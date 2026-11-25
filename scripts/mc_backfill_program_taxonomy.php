<?php

/**
 * @file
 * Fills Educare's Program Type and Study Level on programs that lack them.
 *
 * Educare's program page (Canvas content template node.program.full) shows
 * badges bound to the first value of field_program_type and field_study_level;
 * a program with either field empty fails to render those badges.
 *
 * Usage: ddev drush php:script scripts/mc_backfill_program_taxonomy.php
 * Safe to re-run: only empty fields are filled.
 */

$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term');
$termId = function (string $vid, string $name) use ($terms): int {
  $found = $terms->loadByProperties(['vid' => $vid, 'name' => $name]);
  if ($found) {
    return (int) reset($found)->id();
  }
  $term = $terms->create(['vid' => $vid, 'name' => $name]);
  $term->save();
  return (int) $term->id();
};

// Department => Educare program type.
$typeByDepartment = [
  'Accounting' => 'Business', 'Business' => 'Business', 'Culinary Arts' => 'Business',
  'American Sign Language' => 'Arts & Humanities', 'Art' => 'Arts & Humanities', 'English' => 'Arts & Humanities',
  'History' => 'Arts & Humanities', 'Music' => 'Arts & Humanities',
  'Biology' => 'Sciences', 'Biotechnology' => 'Sciences', 'Chemistry' => 'Sciences', 'Physics' => 'Sciences',
  'Mathematics' => 'Sciences', 'Nursing' => 'Sciences', 'Health Information Management' => 'Sciences',
  'Automotive Technology' => 'Engineering & Applied Sciences', 'Building Trades Technology' => 'Engineering & Applied Sciences',
  'Computer Science' => 'Engineering & Applied Sciences', 'Cybersecurity' => 'Engineering & Applied Sciences',
  'Engineering' => 'Engineering & Applied Sciences',
  'Criminal Justice' => 'Social Sciences', 'Economics' => 'Social Sciences', 'Education' => 'Social Sciences',
  'Psychology' => 'Social Sciences',
];
$undergraduate = $termId('study_level', 'Undergraduate');

$nodes = $etm->getStorage('node');
$ids = $nodes->getQuery()->accessCheck(FALSE)->condition('type', 'program')->execute();
$fixed = 0;
foreach (array_chunk($ids, 100) as $chunk) {
  foreach ($nodes->loadMultiple($chunk) as $program) {
    $changed = FALSE;
    if ($program->get('field_program_type')->isEmpty()) {
      $department = $program->hasField('field_department') ? $program->get('field_department')->entity?->label() : NULL;
      $program->set('field_program_type', $termId('program_type', $typeByDepartment[$department] ?? 'Sciences'));
      $changed = TRUE;
    }
    if ($program->get('field_study_level')->isEmpty()) {
      $program->set('field_study_level', $undergraduate);
      $changed = TRUE;
    }
    if ($changed) {
      // Keep the current moderation state (published programs stay published).
      $program->setNewRevision(TRUE);
      $program->setRevisionLogMessage('Backfilled Program Type and Study Level for the Educare program page.');
      $program->save();
      $fixed++;
    }
  }
  \Drupal::service('entity.memory_cache')->deleteAll();
}
fwrite(STDERR, "Programs updated: $fixed of " . count($ids) . PHP_EOL);

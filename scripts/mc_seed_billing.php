<?php

/**
 * @file
 * Builds term bills for every student and records sample payments.
 *
 * Usage:
 *   ddev drush php:script scripts/mc_seed_billing.php
 *   ddev drush php:script scripts/mc_seed_billing.php -- --no-payments
 *
 * Re-running recalculates every bill (e.g. after changing rates) and only adds
 * sample payments to bills that have none yet.
 *
 * Sample payments (Bursar's Office): finished terms are mostly paid in full,
 * with a few partial payments; the current term is about half paid; future
 * terms are unpaid.
 */

use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;

$withPayments = !in_array('--no-payments', $extra ?? [], TRUE);
$etm = \Drupal::entityTypeManager();
/** @var \Drupal\mc_college_billing\TuitionBilling $billing */
$billing = \Drupal::service('mc_college_billing.tuition');
$updater = \Drupal::service('commerce_payment.order_updater');
$today = date('Y-m-d');

$uids = \Drupal::database()->query("SELECT DISTINCT user_uid FROM {registration} WHERE type = 'course_session' AND user_uid > 0")->fetchCol();
$bills = 0;
$payments = 0;
foreach ($etm->getStorage('user')->loadMultiple($uids) as $student) {
  $billing->syncAllForStudent($student);
  foreach ($billing->loadBills((int) $student->id()) as $order) {
    $bills++;
    if (!$withPayments || $etm->getStorage('commerce_payment')->loadMultipleByOrder($order)) {
      continue;
    }
    $semester = $order->get('field_semester')->entity;
    $start = $semester?->get('field_start_date')->value;
    $end = $semester?->get('field_end_date')->value;
    $total = (float) ($order->getTotalPrice()?->getNumber() ?? 0);
    if ($total <= 0 || !$start || $start > $today) {
      continue;
    }
    $n = (int) $student->id();
    $share = match (TRUE) {
      $end < $today => $n % 9 === 0 ? 0.6 : 1.0,
      default => $n % 3 === 0 ? 1.0 : 0.5,
    };
    $amount = round($total * $share, 2);
    Payment::create([
      'payment_gateway' => 'bursar',
      'order_id' => $order->id(),
      'amount' => new Price(number_format($amount, 2, '.', ''), $order->getTotalPrice()->getCurrencyCode()),
      'state' => 'completed',
      'completed' => strtotime($start . ' +10 days'),
    ])->save();
    $payments++;
  }
}
$updater->updateOrders();
fwrite(STDERR, "Bills synced: $bills; sample payments recorded: $payments." . PHP_EOL);

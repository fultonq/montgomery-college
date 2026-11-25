<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Builds and keeps per-student, per-semester tuition bills in sync.
 *
 * One Commerce order of type "term_bill" per student per semester. Charges:
 * - Tuition: course credits x the per-credit rate for the student's residency.
 * - Technology fee: billable credits x the per-credit fee.
 * - Registration and student activity fees: flat per term, when any credits
 *   are billable.
 * Billable registrations are Enrolled, Completed, and Withdrawn (a W comes
 * after the drop deadline, so tuition is owed). Dropped and Waitlisted
 * registrations are not billed. Bills are recalculated whenever a
 * registration changes; payments recorded against the order are preserved.
 */
final class TuitionBilling {

  public const ORDER_TYPE = 'term_bill';

  public const ORDER_ITEM_TYPE = 'mc_charge';

  public const BILLABLE_STATES = ['enrolled', 'completed', 'withdrawn'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Billing settings value.
   */
  public function setting(string $key, mixed $default = NULL): mixed {
    return $this->configFactory->get('mc_college_billing.settings')->get($key) ?? $default;
  }

  /**
   * Per-credit tuition rate for a residency.
   */
  public function rateFor(?string $residency): string {
    $rates = (array) $this->setting('tuition_per_credit', []);
    return (string) ($rates[$residency ?? ''] ?? $rates['out_of_state'] ?? '0');
  }

  /**
   * Computes the charge lines for a student's semester.
   *
   * @return array<int, array{title: string, quantity: string, unit: string, key: string}>
   *   Charge lines. Unit amounts are decimal strings in the store currency.
   */
  public function calculateCharges(UserInterface $student, NodeInterface $semester): array {
    $registrations = $this->entityTypeManager->getStorage('registration')->loadByProperties([
      'type' => 'course_session',
      'user_uid' => $student->id(),
      'state' => self::BILLABLE_STATES,
    ]);
    $residency = $student->hasField('field_residency') ? $student->get('field_residency')->value : NULL;
    $residency_label = $residency
      ? ($student->get('field_residency')->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values')[$residency] ?? $residency)
      : 'Out-of-State (residency not on file)';
    $rate = $this->rateFor($residency);

    $lines = [];
    $credits = 0;
    foreach ($registrations as $registration) {
      $session = $registration->getHostEntity()?->getEntity();
      if (!$session instanceof NodeInterface || (int) $session->get('field_semester')->target_id !== (int) $semester->id()) {
        continue;
      }
      $course = $session->get('field_course')->entity;
      $course_credits = (int) $course?->get('field_credits')->value;
      if ($course_credits <= 0) {
        continue;
      }
      $credits += $course_credits;
      $w = $registration->get('state')->value === 'withdrawn' ? ' (W)' : '';
      $lines[] = [
        'key' => 'tuition:' . $registration->id(),
        'title' => sprintf('Tuition: %s%s — %d cr @ %s/cr (%s)', $session->label(), $w, $course_credits, '$' . number_format((float) $rate, 2), $residency_label),
        'quantity' => (string) $course_credits,
        'unit' => $rate,
      ];
    }
    if ($credits === 0) {
      return [];
    }
    $tech = (string) $this->setting('technology_fee_per_credit', '0');
    if ((float) $tech > 0) {
      $lines[] = ['key' => 'fee:technology', 'title' => sprintf('Technology fee — %d cr', $credits), 'quantity' => (string) $credits, 'unit' => $tech];
    }
    foreach ((array) $this->setting('term_fees', []) as $id => $fee) {
      if ((float) ($fee['amount'] ?? 0) > 0) {
        $lines[] = ['key' => 'fee:' . $id, 'title' => (string) $fee['label'], 'quantity' => '1', 'unit' => (string) $fee['amount']];
      }
    }
    return $lines;
  }

  /**
   * Loads a student's bill for a semester, if any.
   */
  public function loadBill(int $uid, int $semester_nid): ?OrderInterface {
    $ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ORDER_TYPE)
      ->condition('uid', $uid)
      ->condition('field_semester', $semester_nid)
      ->range(0, 1)
      ->execute();
    return $ids ? $this->entityTypeManager->getStorage('commerce_order')->load(reset($ids)) : NULL;
  }

  /**
   * All of a student's bills, oldest semester first.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   Bills keyed by order ID.
   */
  public function loadBills(int $uid): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $bills = $storage->loadMultiple($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ORDER_TYPE)
      ->condition('uid', $uid)
      ->execute());
    uasort($bills, fn($a, $b) => strcmp(
      (string) $a->get('field_semester')->entity?->get('field_start_date')->value,
      (string) $b->get('field_semester')->entity?->get('field_start_date')->value,
    ));
    return $bills;
  }

  /**
   * Creates or updates a student's bill for a semester.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The bill, or NULL when nothing is billable and no bill exists.
   */
  public function syncBill(UserInterface $student, NodeInterface $semester): ?OrderInterface {
    $lines = $this->calculateCharges($student, $semester);
    $order = $this->loadBill((int) $student->id(), (int) $semester->id());
    if (!$order && !$lines) {
      return NULL;
    }
    $currency = (string) $this->setting('currency', 'USD');

    if (!$order) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->loadDefault();
      if (!$store) {
        $this->logger->error('No default Commerce store; cannot bill @name.', ['@name' => $student->getAccountName()]);
        return NULL;
      }
      $order = $this->entityTypeManager->getStorage('commerce_order')->create([
        'type' => self::ORDER_TYPE,
        'state' => 'draft',
        'uid' => $student->id(),
        'mail' => $student->getEmail(),
        'store_id' => $store->id(),
        'field_semester' => $semester->id(),
      ]);
    }

    // Replace charge lines; keep payments (they live on the payment entities).
    $old_items = $order->getItems();
    $items = [];
    foreach ($lines as $line) {
      $item = OrderItem::create([
        'type' => self::ORDER_ITEM_TYPE,
        'title' => $line['title'],
        'quantity' => $line['quantity'],
        'unit_price' => new Price($line['unit'], $currency),
        'overridden_unit_price' => TRUE,
      ]);
      $item->setData('mc_key', $line['key']);
      $item->save();
      $items[] = $item;
    }
    $order->setItems($items);
    $order->recalculateTotalPrice();
    // Bills are statements, not carts: never let the cart refresh run, or its
    // availability check removes charge lines (they have no purchasable entity).
    $order->setRefreshState(OrderInterface::REFRESH_SKIP);
    if ($order->getState()->getId() === 'draft') {
      $order->save();
      $order->setRefreshState(OrderInterface::REFRESH_SKIP);
      // Placing assigns the order (bill) number and locks it out of cart refresh.
      $order->getState()->applyTransitionById('place');
    }
    $order->save();
    foreach ($old_items as $old) {
      $old->delete();
    }
    return $order;
  }

  /**
   * Resyncs the bill for a registration's student and semester.
   */
  public function syncForRegistration(object $registration): void {
    if ($registration->bundle() !== 'course_session' || !$registration->getUserId()) {
      return;
    }
    $session = $registration->getHostEntity()?->getEntity();
    $semester = $session instanceof NodeInterface ? $session->get('field_semester')->entity : NULL;
    $student = $registration->getUser();
    if ($semester && $student) {
      $this->syncBill($student, $semester);
    }
  }

  /**
   * Resyncs every bill for a student (e.g. after a residency change).
   */
  public function syncAllForStudent(UserInterface $student): void {
    $semester_ids = [];
    foreach ($this->entityTypeManager->getStorage('registration')->loadByProperties(['type' => 'course_session', 'user_uid' => $student->id()]) as $registration) {
      $session = $registration->getHostEntity()?->getEntity();
      if ($session instanceof NodeInterface && ($sid = $session->get('field_semester')->target_id)) {
        $semester_ids[$sid] = $sid;
      }
    }
    foreach ($this->loadBills((int) $student->id()) as $bill) {
      $sid = $bill->get('field_semester')->target_id;
      $semester_ids[$sid] = $sid;
    }
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($semester_ids) as $semester) {
      $this->syncBill($student, $semester);
    }
  }

  /**
   * Outstanding balance on bills for semesters that started before a date.
   */
  public function getPastDueBalance(int $uid, string $before_date): float {
    $due = 0.0;
    foreach ($this->loadBills($uid) as $bill) {
      $start = $bill->get('field_semester')->entity?->get('field_start_date')->value;
      if ($start && $start < $before_date && ($balance = $bill->getBalance())) {
        $due += max(0.0, (float) $balance->getNumber());
      }
    }
    return $due;
  }

  /**
   * Balance rows for the bursar report.
   *
   * @return array<int, array<string, mixed>>
   *   One row per bill: order, student, semester, total, paid, balance.
   */
  public function getBalanceRows(?int $semester_nid = NULL, bool $outstanding_only = FALSE): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', self::ORDER_TYPE)->sort('order_id');
    if ($semester_nid) {
      $query->condition('field_semester', $semester_nid);
    }
    $rows = [];
    foreach (array_chunk($query->execute(), 200) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $order) {
        $total = (float) ($order->getTotalPrice()?->getNumber() ?? 0);
        $paid = (float) ($order->getTotalPaid()?->getNumber() ?? 0);
        $balance = $total - $paid;
        if ($outstanding_only && $balance <= 0.004) {
          continue;
        }
        $rows[] = [
          'order' => $order,
          'student' => $order->getCustomer(),
          'semester' => $order->get('field_semester')->entity,
          'total' => $total,
          'paid' => $paid,
          'balance' => $balance,
        ];
      }
    }
    return $rows;
  }

}

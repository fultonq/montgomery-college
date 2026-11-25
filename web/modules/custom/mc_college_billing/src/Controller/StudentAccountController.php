<?php

declare(strict_types=1);

namespace Drupal\mc_college_billing\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\mc_college_billing\TuitionBilling;
use Drupal\mc_college_core\Form\ReportFilterForm;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student account statement and bursar balances report.
 */
final class StudentAccountController extends ControllerBase {

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
   * Access: the student, or staff who may view student accounts.
   */
  public function access(UserInterface $user, AccountInterface $account): AccessResultInterface {
    $own = $account->isAuthenticated() && (int) $account->id() === (int) $user->id()
      && $account->hasPermission('view own student account');
    return AccessResult::allowedIf($own || $account->hasPermission('view student accounts'))
      ->cachePerPermissions()->cachePerUser();
  }

  /**
   * Tuition and fees statement for one student.
   */
  public function account(UserInterface $user): array {
    $can_record = $this->currentUser()->hasPermission('administer commerce_payment');
    $build = [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => ['max-age' => 0],
    ];
    $bills = $this->billing->loadBills((int) $user->id());
    $grand_total = $grand_paid = 0.0;
    foreach ($bills as $order) {
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $semester = $order->get('field_semester')->entity;
      $rows = [];
      foreach ($order->getItems() as $item) {
        $rows[] = [
          $item->label(),
          $this->money((float) $item->getUnitPrice()->getNumber()) . ($item->getQuantity() > 1 ? ' × ' . (int) $item->getQuantity() : ''),
          $this->money((float) $item->getTotalPrice()->getNumber()),
        ];
      }
      $payments = $this->entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order);
      foreach ($payments as $payment) {
        /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
        if ($payment->getState()->getId() !== 'completed' && $payment->getState()->getId() !== 'partially_refunded') {
          continue;
        }
        $rows[] = [
          ['data' => $this->t('Payment received @date — @gateway', [
            '@date' => date('M j, Y', (int) ($payment->getCompletedTime() ?: $payment->get('authorized')->value ?: \Drupal::time()->getRequestTime())),
            '@gateway' => $payment->getPaymentGateway()?->label() ?? '',
          ]), 'class' => ['mc-payment']],
          '',
          '−' . $this->money((float) $payment->getBalance()->getNumber()),
        ];
      }
      $total = (float) ($order->getTotalPrice()?->getNumber() ?? 0);
      $paid = (float) ($order->getTotalPaid()?->getNumber() ?? 0);
      $grand_total += $total;
      $grand_paid += $paid;
      $footer = [
        [['data' => $this->t('Charges'), 'colspan' => 2], $this->money($total)],
        [['data' => $this->t('Payments'), 'colspan' => 2], '−' . $this->money($paid)],
        [['data' => ['#markup' => '<strong>' . $this->t('Balance due') . '</strong>'], 'colspan' => 2], ['data' => ['#markup' => '<strong>' . $this->money($total - $paid) . '</strong>']]],
      ];
      if ($can_record) {
        $footer[] = [['data' => ['#type' => 'link', '#title' => $this->t('Record a payment (bill #@n)', ['@n' => $order->getOrderNumber()]), '#url' => Url::fromRoute('entity.commerce_payment.collection', ['commerce_order' => $order->id()])], 'colspan' => 3]];
      }
      $build['bill_' . $order->id()] = [
        '#type' => 'table',
        '#caption' => $this->t('@term — bill #@number', ['@term' => $semester?->label() ?? $this->t('Unknown term'), '@number' => $order->getOrderNumber()]),
        '#header' => [$this->t('Description'), $this->t('Rate'), $this->t('Amount')],
        '#rows' => $rows,
        '#footer' => $footer,
        '#attributes' => ['class' => ['mc-table']],
      ];
    }

    $balance = $grand_total - $grand_paid;
    $build['summary'] = [
      '#type' => 'container',
      '#weight' => -10,
      '#attributes' => ['class' => ['mc-stats']],
      'charges' => $this->stat($this->t('Total charges'), $this->money($grand_total)),
      'paid' => $this->stat($this->t('Total paid'), $this->money($grand_paid)),
      'balance' => $this->stat($balance < 0 ? $this->t('Credit on account') : $this->t('Balance due'), $this->money(abs($balance)), $balance > 0.004 ? 'mc-standing--probation' : 'mc-standing--good'),
    ];
    $build['how'] = [
      '#weight' => -9,
      '#markup' => '<div class="mc-alert mc-alert--info">' . $this->t('@instructions', ['@instructions' => $this->billing->setting('payment_instructions', '')]) . '</div>',
    ];
    if (!$bills) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No charges yet.') . '</p>'];
    }
    return $build;
  }

  /**
   * Bursar report: balances per student per semester.
   */
  public function balances(Request $request): array {
    $semester = (int) $request->query->get('semester') ?: NULL;
    $rows_data = $this->billing->getBalanceRows($semester, (bool) $request->query->get('outstanding'));
    $total = array_sum(array_column($rows_data, 'total'));
    $paid = array_sum(array_column($rows_data, 'paid'));
    $outstanding = count(array_filter($rows_data, fn($r) => $r['balance'] > 0.004));

    $rows = [];
    foreach ($rows_data as $r) {
      $rows[] = [
        $r['student'] ? ['data' => ['#type' => 'link', '#title' => $r['student']->getDisplayName(), '#url' => Url::fromRoute('mc_college_billing.account', ['user' => $r['student']->id()])]] : '',
        $r['student']?->get('field_student_id')->value ?? '',
        $r['semester']?->label() ?? '',
        ['data' => ['#type' => 'link', '#title' => '#' . $r['order']->getOrderNumber(), '#url' => $r['order']->toUrl()]],
        $this->money($r['total']),
        $this->money($r['paid']),
        ['data' => $this->money($r['balance']), 'class' => [$r['balance'] > 0.004 ? 'mc-standing--probation' : 'mc-standing--good']],
        ['data' => ['#type' => 'link', '#title' => $this->t('Payments'), '#url' => Url::fromRoute('entity.commerce_payment.collection', ['commerce_order' => $r['order']->id()])]],
      ];
    }
    $form = $this->formBuilder()->getForm(ReportFilterForm::class, ['semester']);
    return [
      '#attached' => ['library' => ['mc_college_core/academic']],
      '#cache' => ['max-age' => 0],
      'filters' => $form,
      'outstanding_link' => [
        '#type' => 'link',
        '#title' => $request->query->get('outstanding') ? $this->t('Show all bills') : $this->t('Only outstanding balances'),
        '#url' => Url::fromRoute('<current>', [], ['query' => array_filter(['semester' => $semester, 'outstanding' => $request->query->get('outstanding') ? NULL : 1])]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute('mc_college_billing.balances_export', [], ['query' => $request->query->all()]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mc-stats']],
        'bills' => $this->stat($this->t('Bills'), (string) count($rows_data)),
        'billed' => $this->stat($this->t('Billed'), $this->money($total)),
        'collected' => $this->stat($this->t('Collected'), $this->money($paid)),
        'due' => $this->stat($this->t('Outstanding'), $this->money($total - $paid)),
        'count' => $this->stat($this->t('Bills with a balance'), (string) $outstanding),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Student'), $this->t('ID'), $this->t('Semester'), $this->t('Bill'), $this->t('Charges'), $this->t('Paid'), $this->t('Balance'), ''],
        '#rows' => $rows,
        '#empty' => $this->t('No bills.'),
        '#attributes' => ['class' => ['mc-table']],
      ],
    ];
  }

  /**
   * CSV export of the balances report.
   */
  public function export(Request $request): Response {
    $rows = $this->billing->getBalanceRows((int) $request->query->get('semester') ?: NULL, (bool) $request->query->get('outstanding'));
    $response = new StreamedResponse(function () use ($rows) {
      $out = fopen('php://output', 'w');
      fputcsv($out, ['Student', 'Student ID', 'Semester', 'Bill', 'Charges', 'Paid', 'Balance'], ',', '"', '\\');
      foreach ($rows as $r) {
        fputcsv($out, [
          $r['student']?->getDisplayName(), $r['student']?->get('field_student_id')->value,
          $r['semester']?->label(), $r['order']->getOrderNumber(),
          number_format($r['total'], 2, '.', ''), number_format($r['paid'], 2, '.', ''), number_format($r['balance'], 2, '.', ''),
        ], ',', '"', '\\');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="mc-college-student-balances.csv"');
    return $response;
  }

  private function money(float $amount): string {
    return '$' . number_format($amount, 2);
  }

  private function stat($label, string $value, string $modifier = ''): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="mc-stat {{ modifier }}"><span class="mc-stat__value">{{ value }}</span><span class="mc-stat__label">{{ label }}</span></div>',
      '#context' => ['label' => $label, 'value' => $value, 'modifier' => $modifier],
    ];
  }

}

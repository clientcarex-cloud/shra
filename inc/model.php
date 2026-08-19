<?php
/** Domain logic shared by every page: invoices, payments, subscriptions, attendance. */

function customer_name(array $c): string
{
    return trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
}

function find_customer(int $id): ?array
{
    return one('SELECT * FROM customers WHERE id=?', [$id]);
}

/** ---------------------------------------------------------------- Invoices */

function next_invoice_no(string $issueDate): array
{
    $fy     = fy_label($issueDate);
    $prefix = setting('invoice_prefix', 'SHRA');
    $seq    = (int) scalar('SELECT COALESCE(MAX(seq_no),0)+1 FROM invoices WHERE fy=?', [$fy]);
    $no     = sprintf('%s/%s/%04d', $prefix, $fy, $seq);
    return [$no, $seq, $fy];
}

/**
 * @param array $items  [['description'=>, 'qty'=>, 'rate'=>, 'plan_id'=>null], ...]
 * @param array $opt    discount, tax_pct, notes, subscription_id, source, issue_date, due_date, created_by
 */
function create_invoice(int $customerId, array $items, array $opt = []): int
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $issue = $opt['issue_date'] ?? today();
        [$no, $seq, $fy] = next_invoice_no($issue);

        $subtotal = 0;
        $clean = [];
        foreach ($items as $it) {
            $desc = trim((string)($it['description'] ?? ''));
            $qty  = round((float)($it['qty']  ?? 0), 2);
            $rate = round((float)($it['rate'] ?? 0), 2);
            if ($desc === '' || $qty <= 0) continue;
            $amt = round($qty * $rate, 2);
            $subtotal += $amt;
            $clean[] = ['plan_id' => $it['plan_id'] ?? null, 'description' => $desc,
                        'qty' => $qty, 'rate' => $rate, 'amount' => $amt];
        }
        if (!$clean) throw new RuntimeException('An invoice needs at least one line item.');

        $discount = min(round((float)($opt['discount'] ?? 0), 2), $subtotal);
        $taxPct   = round((float)($opt['tax_pct'] ?? 0), 2);
        $taxable  = max(0, $subtotal - $discount);
        $tax      = round($taxable * $taxPct / 100, 2);
        $total    = round($taxable + $tax, 2);

        $id = insert('invoices', [
            'invoice_no'      => $no,
            'seq_no'          => $seq,
            'fy'              => $fy,
            'token'           => rand_token(20),
            'customer_id'     => $customerId,
            'subscription_id' => $opt['subscription_id'] ?? null,
            'source'          => $opt['source'] ?? 'staff',
            'issue_date'      => $issue,
            'due_date'        => $opt['due_date'] ?? $issue,
            'subtotal'        => $subtotal,
            'discount'        => $discount,
            'tax_pct'         => $taxPct,
            'tax_amount'      => $tax,
            'total'           => $total,
            'paid_amount'     => 0,
            'status'          => $total <= 0 ? 'paid' : 'unpaid',
            'notes'           => $opt['notes'] ?? null,
            'created_by'      => $opt['created_by'] ?? (current_user()['id'] ?? null),
            'created_at'      => now(),
        ]);
        foreach ($clean as $c) { $c['invoice_id'] = $id; insert('invoice_items', $c); }

        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Recompute paid_amount + status from verified payments. */
function invoice_recalc(int $invoiceId): void
{
    $inv = one('SELECT * FROM invoices WHERE id=?', [$invoiceId]);
    if (!$inv) return;
    if ($inv['status'] === 'cancelled') return;

    $paid = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status="verified"',
                           [$invoiceId]);
    $total = (float) $inv['total'];
    $status = 'unpaid';
    if ($paid >= $total - 0.009 && $total > 0) $status = 'paid';
    elseif ($paid > 0)                         $status = 'partial';
    elseif ($total <= 0)                       $status = 'paid';

    update('invoices', ['paid_amount' => round($paid, 2), 'status' => $status], 'id=?', [$invoiceId]);
}

function invoice_balance(array $inv): float
{
    return round((float)$inv['total'] - (float)$inv['paid_amount'], 2);
}

function add_payment(int $invoiceId, float $amount, string $mode, array $opt = []): int
{
    $id = insert('payments', [
        'invoice_id'  => $invoiceId,
        'amount'      => round($amount, 2),
        'mode'        => $mode,
        'reference'   => $opt['reference'] ?? null,
        'paid_at'     => $opt['paid_at'] ?? now(),
        'source'      => $opt['source'] ?? 'staff',
        'status'      => $opt['status'] ?? 'verified',
        'received_by' => $opt['received_by'] ?? (current_user()['id'] ?? null),
        'verified_by' => ($opt['status'] ?? 'verified') === 'verified' ? (current_user()['id'] ?? null) : null,
        'notes'       => $opt['notes'] ?? null,
    ]);
    invoice_recalc($invoiceId);
    return $id;
}

/** UPI deep link used for the "scan and pay" QR. Returns '' when no UPI id is configured. */
function upi_uri(float $amount, string $note = ''): string
{
    $pa = trim((string) setting('upi_id', ''));
    if ($pa === '') return '';
    $params = [
        'pa' => $pa,
        'pn' => setting('upi_payee', setting('academy_name', APP_NAME)),
        'cu' => 'INR',
    ];
    if ($amount > 0)  $params['am'] = number_format($amount, 2, '.', '');
    if ($note !== '') $params['tn'] = substr(preg_replace('/[^A-Za-z0-9 \/\-]/', '', $note), 0, 40);
    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** ----------------------------------------------------------- Subscriptions */

function create_subscription(int $customerId, array $data): int
{
    $start = $data['start_date'] ?? today();
    $plan  = !empty($data['plan_id']) ? one('SELECT * FROM plans WHERE id=?', [$data['plan_id']]) : null;

    $total    = (int)   ($data['total_sessions'] ?? ($plan['sessions']     ?? 1));
    $duration = (int)   ($data['duration_min']   ?? ($plan['duration_min'] ?? 30));
    $price    = (float) ($data['price']          ?? ($plan['amount']       ?? 0));
    $validity = (int)   ($data['validity_days']  ?? ($plan['validity_days'] ?? 30));
    $end      = $data['end_date'] ?? date('Y-m-d', strtotime($start . ' +' . max(1, $validity) . ' days'));

    return insert('subscriptions', [
        'customer_id'    => $customerId,
        'plan_id'        => $data['plan_id'] ?? null,
        'plan_name'      => $data['plan_name'] ?? ($plan['name'] ?? 'Custom package'),
        'trainer_id'     => $data['trainer_id'] ?? null,
        'start_date'     => $start,
        'end_date'       => $end,
        'total_sessions' => $total,
        'used_sessions'  => 0,
        'duration_min'   => $duration,
        'price'          => $price,
        'status'         => 'active',
        'notes'          => $data['notes'] ?? null,
        'created_by'     => current_user()['id'] ?? null,
        'created_at'     => now(),
    ]);
}

/** Recount used sessions from attendance and flip status when finished/expired. */
function refresh_subscription(int $subId): void
{
    $s = one('SELECT * FROM subscriptions WHERE id=?', [$subId]);
    if (!$s || in_array($s['status'], ['cancelled'], true)) return;

    $used = (int) scalar('SELECT COUNT(*) FROM ride_sessions WHERE subscription_id=? AND status="present"', [$subId]);
    $status = 'active';
    if ($used >= (int)$s['total_sessions'] && (int)$s['total_sessions'] > 0) $status = 'completed';
    elseif ($s['end_date'] && $s['end_date'] < today())                      $status = 'expired';

    update('subscriptions', ['used_sessions' => $used, 'status' => $status], 'id=?', [$subId]);
}

function subscription_progress(array $s): array
{
    $total = max(0, (int)$s['total_sessions']);
    $used  = max(0, (int)$s['used_sessions']);
    $left  = max(0, $total - $used);
    $pct   = $total > 0 ? min(100, (int) round($used * 100 / $total)) : 0;
    $daysLeft = $s['end_date'] ? (int) floor((strtotime($s['end_date']) - strtotime(today())) / 86400) : null;
    return ['total' => $total, 'used' => $used, 'left' => $left, 'pct' => $pct, 'days_left' => $daysLeft];
}

/** The subscription a walk-in rider should be checked into today, if any. */
function active_subscription(int $customerId): ?array
{
    return one('SELECT * FROM subscriptions
                WHERE customer_id=? AND status="active" AND used_sessions < total_sessions
                ORDER BY end_date ASC, id ASC LIMIT 1', [$customerId]);
}

/** ------------------------------------------------------------- Attendance */

function mark_attendance(array $d): int
{
    $subId = !empty($d['subscription_id']) ? (int)$d['subscription_id'] : null;
    $id = insert('ride_sessions', [
        'customer_id'     => (int)$d['customer_id'],
        'subscription_id' => $subId,
        'trainer_id'      => !empty($d['trainer_id']) ? (int)$d['trainer_id'] : null,
        'ride_type'       => $d['ride_type'] ?? ($subId ? 'subscription' : 'guest'),
        'horse_name'      => $d['horse_name'] ?? null,
        'ride_date'       => $d['ride_date'] ?? today(),
        'ride_time'       => $d['ride_time'] ?? date('H:i'),
        'duration_min'    => (int)($d['duration_min'] ?? 30),
        'status'          => $d['status'] ?? 'present',
        'skills'          => $d['skills'] ?? null,
        'remarks'         => $d['remarks'] ?? null,
        'marked_by'       => current_user()['id'] ?? null,
        'created_at'      => now(),
    ]);
    if ($subId) refresh_subscription($subId);
    return $id;
}

/** Nightly-ish housekeeping, cheap enough to run on dashboard load. */
function expire_stale_subscriptions(): void
{
    q('UPDATE subscriptions SET status="expired"
       WHERE status="active" AND end_date IS NOT NULL AND end_date < CURDATE()');
}

/** ------------------------------------------------------------------ Leads */

function lead_note(int $leadId, string $kind, string $note = ''): void
{
    insert('lead_activities', [
        'lead_id'    => $leadId,
        'kind'       => $kind,
        'note'       => $note,
        'user_id'    => current_user()['id'] ?? null,
        'created_at' => now(),
    ]);
    update('leads', ['last_contact' => now()], 'id=?', [$leadId]);
}

/** Options list for <select> of active plans. */
function plan_options(?string $audience = null): array
{
    $sql = 'SELECT * FROM plans WHERE status="active"';
    $p = [];
    if ($audience) { $sql .= ' AND audience IN (?, "all")'; $p[] = $audience; }
    return all($sql . ' ORDER BY sort_order, id', $p);
}

function trainer_options(): array
{
    return all('SELECT id, name FROM trainers WHERE status="active" ORDER BY name');
}

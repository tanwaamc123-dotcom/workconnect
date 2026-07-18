<?php

declare(strict_types=1);

function stripe_api_request(string $method, string $path, array $params = []): array
{
    if (!stripe_is_configured()) {
        throw new RuntimeException('Stripe is not configured yet.');
    }

    $ch = curl_init('https://api.stripe.com' . $path);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize Stripe request.');
    }

    $headers = [
        'Authorization: Bearer ' . stripe_secret_key(),
        'Stripe-Version: 2025-06-30.basil',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($params !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Stripe request failed: ' . $error);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Stripe returned an unexpected response.');
    }

    if ($code < 200 || $code >= 300) {
        $message = $data['error']['message'] ?? ('Stripe returned HTTP ' . $code . '.');
        throw new RuntimeException((string) $message);
    }

    return $data;
}

function stripe_signature_is_valid(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $item) {
        [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
        if ($key !== '' && $value !== '') {
            $parts[$key][] = $value;
        }
    }

    $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp <= 0 || $signatures === []) {
        return false;
    }
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

function stripe_checkout_success_url(string $type, int $requestId): string
{
    if ($type === 'topup') {
        return app_base_url() . '/?page=topup&stripe=success&request=' . $requestId;
    }
    return app_base_url() . '/?page=orders&stripe=success&request=' . $requestId;
}

function stripe_checkout_cancel_url(string $type, int $subjectId = 0): string
{
    if ($type === 'topup') {
        return app_base_url() . '/?page=topup&stripe=cancel';
    }
    return app_base_url() . '/?page=checkout&id=' . $subjectId . '&stripe=cancel';
}

function create_payment_request(
    string $type,
    int $userId,
    float $amount,
    string $title,
    array $payload = [],
    ?int $serviceId = null
): int {
    $reference = strtoupper($type === 'topup' ? 'TOP' : 'CHK') . '-' . strtoupper(bin2hex(random_bytes(5)));
    db()->prepare(
        "INSERT INTO payment_requests (request_type,user_id,service_id,amount,currency,status,provider,provider_session_id,provider_payment_intent,reference_code,title,payload_json) VALUES (?,?,?,?,?,'pending','stripe',NULL,NULL,?,?,?)"
    )->execute([
        $type,
        $userId,
        $serviceId,
        $amount,
        'thb',
        $reference,
        $title,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    return database_last_insert_id();
}

function update_payment_request_session(int $requestId, array $session): void
{
    $sessionId = trim((string) ($session['id'] ?? ''));
    $paymentIntentId = trim((string) ($session['payment_intent'] ?? ''));
    db()->prepare(
        'UPDATE payment_requests SET provider_session_id=?,provider_payment_intent=?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
    )->execute([
        $sessionId !== '' ? $sessionId : null,
        $paymentIntentId !== '' ? $paymentIntentId : null,
        $requestId,
    ]);
}

function payment_request_by_session(string $sessionId): ?array
{
    return fetch_one('SELECT * FROM payment_requests WHERE provider_session_id=?', [$sessionId]);
}

function payment_request_payload(array $request): array
{
    $payload = json_decode((string) ($request['payload_json'] ?? '{}'), true);
    return is_array($payload) ? $payload : [];
}

function create_stripe_checkout_session_for_payment_request(array $request): array
{
    $title = (string) $request['title'];
    $requestId = (int) $request['id'];
    $amountSatang = (int) round((float) $request['amount'] * 100);
    $type = (string) $request['request_type'];
    $serviceId = (int) ($request['service_id'] ?? 0);
    $params = [
        'mode' => 'payment',
        'success_url' => stripe_checkout_success_url($type, $requestId),
        'cancel_url' => stripe_checkout_cancel_url($type, $serviceId),
        'payment_method_types[0]' => 'promptpay',
        'line_items[0][price_data][currency]' => 'thb',
        'line_items[0][price_data][product_data][name]' => $title,
        'line_items[0][price_data][unit_amount]' => (string) $amountSatang,
        'line_items[0][quantity]' => '1',
        'metadata[payment_request_id]' => (string) $requestId,
        'metadata[payment_request_type]' => $type,
        'client_reference_id' => (string) $requestId,
        'submit_type' => 'pay',
    ];

    if ($type === 'topup') {
        $params['customer_creation'] = 'always';
    }

    return stripe_api_request('POST', '/v1/checkout/sessions', $params);
}

function mark_payment_request_failed(array $request, string $status = 'failed'): void
{
    db()->prepare("UPDATE payment_requests SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'")->execute([$status, (int) $request['id']]);
}

function fulfill_payment_request_from_session(array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }
    $request = payment_request_by_session($sessionId);
    if (!$request || (string) $request['status'] === 'completed') {
        return;
    }

    $paymentStatus = (string) ($session['payment_status'] ?? '');
    if ($paymentStatus !== 'paid') {
        return;
    }
    $expectedAmount = (int) round((float) $request['amount'] * 100);
    $actualAmount = (int) ($session['amount_total'] ?? -1);
    $currency = strtolower((string) ($session['currency'] ?? ''));
    $referenceId = (int) ($session['client_reference_id'] ?? 0);
    if ($actualAmount !== $expectedAmount || $currency !== (string) $request['currency'] || $referenceId !== (int) $request['id']) {
        throw new RuntimeException('Stripe payment details do not match the payment request.');
    }

    $payload = payment_request_payload($request);
    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $paymentIntentValue = trim($paymentIntentId) !== '' ? $paymentIntentId : null;

    db()->beginTransaction();
    try {
        $latest = fetch_one('SELECT * FROM payment_requests WHERE id=?', [(int) $request['id']]);
        if (!$latest || (string) $latest['status'] === 'completed') {
            db()->commit();
            return;
        }
        db()->prepare(
            "UPDATE payment_requests SET status='completed',provider_payment_intent=?,updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$paymentIntentValue, (int) $latest['id']]);

        if ((string) $latest['request_type'] === 'topup') {
            $reference = (string) $latest['reference_code'];
            if (!scalar('SELECT COUNT(*) FROM wallet_transactions WHERE reference=?', [$reference])) {
                db()->prepare('INSERT INTO wallet_transactions (user_id,amount,method,status,reference,note,slip_path,is_demo) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([
                        (int) $latest['user_id'],
                        (float) $latest['amount'],
                        'promptpay',
                        'completed',
                        $reference,
                        $payload['note'] ?? 'Stripe PromptPay top up',
                        '',
                        0,
                    ]);
                db()->prepare('UPDATE users SET wallet_balance=COALESCE(wallet_balance,0)+?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([(float) $latest['amount'], (int) $latest['user_id']]);
                notify((int) $latest['user_id'], 'payment', 'Wallet topped up', 'Your PromptPay payment was confirmed and your wallet has been updated.', '?page=topup&tx=' . $reference, false);
            }
        } elseif ((string) $latest['request_type'] === 'checkout') {
            if ((int) ($latest['order_id'] ?? 0) === 0) {
                $service = fetch_one('SELECT * FROM services WHERE id=?', [(int) ($latest['service_id'] ?? 0)]);
                if (!$service || (string) $service['status'] !== 'active') {
                    throw new RuntimeException('Service not available during payment fulfillment.');
                }
                $orderNumber = 'WC-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $dueAt = date('Y-m-d H:i:s', strtotime('+' . (int) $service['delivery_days'] . ' days'));
                db()->prepare("INSERT INTO orders (order_number,customer_id,seller_id,service_id,status,requirements,subtotal,discount,total,due_at,is_demo,coupon_code) VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?)")
                    ->execute([
                        $orderNumber,
                        (int) $latest['user_id'],
                        (int) $service['seller_id'],
                        (int) $service['id'],
                        (string) ($payload['requirements'] ?? ''),
                        (float) ($payload['subtotal'] ?? $latest['amount']),
                        (float) ($payload['discount'] ?? 0),
                        (float) $latest['amount'],
                        $dueAt,
                        (int) ($service['is_demo'] ?? 0),
                        (string) ($payload['coupon_code'] ?? ''),
                    ]);
                $orderId = database_last_insert_id();
                db()->prepare("INSERT INTO payments (order_id,amount,method,status,transaction_ref,is_demo,paid_at) VALUES (?,?,?,'paid',?,?,CURRENT_TIMESTAMP)")
                    ->execute([
                        $orderId,
                        (float) $latest['amount'],
                        'promptpay',
                        $paymentIntentId !== '' ? $paymentIntentId : $sessionId,
                        (int) ($service['is_demo'] ?? 0),
                    ]);
                db()->prepare('UPDATE payment_requests SET order_id=? WHERE id=?')->execute([$orderId, (int) $latest['id']]);
                notify((int) $service['seller_id'], 'order', 'New order received', $orderNumber . ' was paid via PromptPay and is ready for your review.', '?page=seller-orders', false);
                notify((int) $latest['user_id'], 'payment', 'Payment confirmed', 'Your PromptPay payment was confirmed and order ' . $orderNumber . ' has been created.', '?page=orders', false);
            }
        }

        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

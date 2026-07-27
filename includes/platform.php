<?php

declare(strict_types=1);

final class PublicRuntimeException extends RuntimeException
{
}

function request_id(): string
{
    static $requestId = null;
    if (is_string($requestId)) {
        return $requestId;
    }
    $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $requestId = preg_match('/^[a-zA-Z0-9._-]{8,80}$/', $incoming)
        ? $incoming
        : bin2hex(random_bytes(12));
    return $requestId;
}

function app_log(string $level, string $message, array $context = []): void
{
    $directory = dirname(__DIR__) . '/storage/private/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0770, true);
    }
    $record = [
        'timestamp' => gmdate(DATE_ATOM),
        'level' => strtolower($level),
        'request_id' => request_id(),
        'message' => $message,
        'context' => $context,
    ];
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        @file_put_contents($directory . '/app.log', $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function public_error_message(Throwable $error): string
{
    app_log($error instanceof PublicRuntimeException ? 'warning' : 'error', $error->getMessage(), [
        'type' => $error::class,
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
    ]);
    if (!app_is_production() || $error instanceof PublicRuntimeException) {
        return $error->getMessage();
    }
    return 'The request could not be completed. Please try again or contact support with reference ' . request_id() . '.';
}

function amount_to_satang(mixed $amount): int
{
    if (is_int($amount)) {
        return $amount * 100;
    }
    if (is_float($amount)) {
        if (!is_finite($amount)) {
            throw new PublicRuntimeException('The amount is invalid.');
        }
        $amount = number_format($amount, 2, '.', '');
    }
    $normalized = trim(str_replace([',', '฿', ' '], '', (string) $amount));
    if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', $normalized, $match)) {
        throw new PublicRuntimeException('Enter a valid amount with no more than two decimal places.');
    }
    $whole = (int) $match[2];
    $fraction = str_pad((string) ($match[3] ?? ''), 2, '0');
    $satang = ($whole * 100) + (int) $fraction;
    if (($match[1] ?? '') === '-') {
        $satang *= -1;
    }
    return $satang;
}

function decimal_to_satang(mixed $amount): int
{
    if (is_int($amount)) {
        return $amount * 100;
    }
    return amount_to_satang($amount);
}

function satang_to_decimal(int $satang): string
{
    $negative = $satang < 0 ? '-' : '';
    $absolute = abs($satang);
    return $negative . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function satang_to_float(int $satang): float
{
    return (float) satang_to_decimal($satang);
}

function money_satang(int $satang): string
{
    $decimals = $satang % 100 === 0 ? 0 : 2;
    return currency_symbol_setting('฿') . number_format(satang_to_float($satang), $decimals);
}

function value_satang(array $row, string $satangColumn, string $legacyColumn): int
{
    if (array_key_exists($satangColumn, $row) && $row[$satangColumn] !== null) {
        return (int) $row[$satangColumn];
    }
    return decimal_to_satang($row[$legacyColumn] ?? 0);
}

function thai_id_is_valid(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (!preg_match('/^\d{13}$/', $digits) || preg_match('/^(\d)\1{12}$/', $digits)) {
        return false;
    }
    $sum = 0;
    for ($index = 0; $index < 12; $index++) {
        $sum += ((int) $digits[$index]) * (13 - $index);
    }
    return ((11 - ($sum % 11)) % 10) === (int) $digits[12];
}

function sensitive_fingerprint(string $value): string
{
    $key = app_encryption_key();
    if ($key === '') {
        throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
    }
    return hash_hmac('sha256', $value, $key);
}

function admin_capabilities(array $user): array
{
    if (($user['role'] ?? '') !== 'admin') {
        return [];
    }
    $role = (string) ($user['admin_role'] ?? 'owner');
    $map = [
        'owner' => ['*'],
        'finance' => ['finance.view', 'finance.manage', 'payout.manage', 'export.finance', 'export.read'],
        'support' => ['users.view', 'orders.view', 'orders.manage', 'messages.view', 'disputes.manage'],
        'moderator' => ['users.view', 'users.manage', 'services.manage', 'categories.manage', 'coupons.manage', 'reports.view'],
        'analyst' => ['reports.view', 'finance.view', 'orders.view', 'export.read'],
    ];
    return $map[$role] ?? [];
}

function admin_can(array $user, string $capability): bool
{
    $capabilities = admin_capabilities($user);
    return in_array('*', $capabilities, true) || in_array($capability, $capabilities, true);
}

function require_admin_capability(string $capability): array
{
    $user = require_role('admin');
    if (!admin_can($user, $capability)) {
        audit_event((int) $user['id'], 'admin_access_denied', 'capability', 0, ['capability' => $capability]);
        flash('error', 'Your admin role does not have permission for that action.');
        redirect(admin_start_page($user));
    }
    return $user;
}

function admin_start_page(array $user): string
{
    return match ((string) ($user['admin_role'] ?? 'owner')) {
        'finance' => '?page=admin-finance',
        'support' => '?page=admin-orders',
        'moderator' => '?page=admin-moderation',
        'analyst' => '?page=admin-reports',
        default => '?page=admin-control',
    };
}

function audit_event(
    ?int $actorId,
    string $event,
    string $targetType = '',
    int $targetId = 0,
    array $details = []
): void {
    $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db()->prepare(
        'INSERT INTO security_logs (user_id,event,ip_address,target_type,target_id,details_json,user_agent,request_id) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $actorId,
        substr($event, 0, 120),
        client_ip(),
        substr($targetType, 0, 80),
        $targetId > 0 ? $targetId : null,
        is_string($encoded) ? $encoded : '{}',
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        request_id(),
    ]);
}

function platform_fee_bps(): int
{
    $percent = max(0.0, min(50.0, platform_fee_setting(10)));
    return (int) round($percent * 100);
}

function calculate_platform_fee_satang(int $totalSatang, ?int $feeBps = null): int
{
    return intdiv(($totalSatang * ($feeBps ?? platform_fee_bps())) + 5000, 10000);
}

function lock_financial_accounts(array $userIds): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('Financial account locks require an active database transaction.');
    }
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    sort($userIds, SORT_NUMERIC);
    $statement = db()->prepare('UPDATE users SET updated_at=updated_at WHERE id=?');
    foreach ($userIds as $userId) {
        $statement->execute([$userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Financial account not found.');
        }
    }
}

function order_has_active_dispute(int $orderId): bool
{
    return $orderId > 0 && (int) scalar(
        "SELECT COUNT(*) FROM disputes WHERE order_id=? AND status IN ('open','investigating')",
        [$orderId]
    ) > 0;
}

function lock_order_record(int $orderId): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('Order locks require an active database transaction.');
    }
    $statement = db()->prepare('UPDATE orders SET updated_at=updated_at WHERE id=?');
    $statement->execute([$orderId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Order not found.');
    }
}

function ledger_post(string $reference, string $type, array $entries, array $metadata = []): int
{
    $existing = fetch_one('SELECT id,transaction_type FROM ledger_transactions WHERE reference=?', [$reference]);
    if ($existing) {
        if (!hash_equals((string) $existing['transaction_type'], $type)) {
            throw new RuntimeException('Ledger reference is already used by another transaction type.');
        }
        return (int) $existing['id'];
    }
    $entries = array_values(array_filter(
        $entries,
        static fn(array $entry): bool => (int) ($entry['amount_satang'] ?? 0) !== 0
    ));
    if (count($entries) < 2) {
        throw new RuntimeException('A ledger transaction needs at least two entries.');
    }
    $sum = 0;
    foreach ($entries as $entry) {
        $amount = (int) ($entry['amount_satang'] ?? 0);
        $sum += $amount;
    }
    if ($sum !== 0) {
        throw new RuntimeException('Ledger transaction is not balanced.');
    }
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $insertTransaction = db()->prepare(
        'INSERT INTO ledger_transactions (reference,transaction_type,order_id,user_id,metadata_json)
         VALUES (?,?,?,?,?) ON CONFLICT(reference) DO NOTHING'
    );
    $insertTransaction->execute([
        $reference,
        $type,
        isset($metadata['order_id']) ? (int) $metadata['order_id'] : null,
        isset($metadata['user_id']) ? (int) $metadata['user_id'] : null,
        is_string($metadataJson) ? $metadataJson : '{}',
    ]);
    if ($insertTransaction->rowCount() !== 1) {
        $existing = fetch_one('SELECT id,transaction_type FROM ledger_transactions WHERE reference=?', [$reference]);
        if (!$existing || !hash_equals((string) $existing['transaction_type'], $type)) {
            throw new RuntimeException('Ledger transaction could not be claimed safely.');
        }
        return (int) $existing['id'];
    }
    $transactionId = database_last_insert_id();
    $insert = db()->prepare(
        'INSERT INTO ledger_entries (transaction_id,account_code,owner_type,owner_id,amount_satang) VALUES (?,?,?,?,?)'
    );
    foreach ($entries as $entry) {
        $insert->execute([
            $transactionId,
            (string) $entry['account_code'],
            (string) ($entry['owner_type'] ?? 'platform'),
            (int) ($entry['owner_id'] ?? 0),
            (int) $entry['amount_satang'],
        ]);
    }
    return $transactionId;
}

function ledger_balance(string $accountCode, string $ownerType = 'platform', int $ownerId = 0): int
{
    return (int) scalar(
        'SELECT COALESCE(SUM(amount_satang),0) FROM ledger_entries WHERE account_code=? AND owner_type=? AND owner_id=?',
        [$accountCode, $ownerType, $ownerId]
    );
}

function initialize_ledger_opening_balances(): void
{
    if ((string) scalar("SELECT COALESCE(meta_value,'') FROM schema_meta WHERE meta_key='ledger_opening_v2'") === 'done') {
        return;
    }
    db()->beginTransaction();
    try {
        foreach (fetch_all('SELECT id,wallet_balance_satang FROM users WHERE wallet_balance_satang<>0') as $account) {
            $amount = (int) $account['wallet_balance_satang'];
            ledger_post('OPEN-WALLET-' . (int) $account['id'], 'opening_balance', [
                ['account_code' => 'customer_wallet', 'owner_type' => 'user', 'owner_id' => (int) $account['id'], 'amount_satang' => $amount],
                ['account_code' => 'migration_equity', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$amount],
            ], ['user_id' => (int) $account['id']]);
        }
        $orders = fetch_all(
            "SELECT orders.* FROM orders JOIN payments ON payments.order_id=orders.id WHERE payments.status='paid'"
        );
        foreach ($orders as $order) {
            $total = value_satang($order, 'total_satang', 'total');
            if ($total <= 0) {
                continue;
            }
            if (in_array((string) $order['status'], ['pending', 'in_progress', 'review'], true)) {
                ledger_post('OPEN-ESCROW-' . (int) $order['id'], 'opening_escrow', [
                    ['account_code' => 'platform_escrow', 'owner_type' => 'order', 'owner_id' => (int) $order['id'], 'amount_satang' => $total],
                    ['account_code' => 'migration_equity', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$total],
                ], ['order_id' => (int) $order['id']]);
            } elseif ((string) $order['status'] === 'completed') {
                $fee = (int) ($order['platform_fee_satang'] ?? calculate_platform_fee_satang($total, (int) ($order['fee_rate_bps'] ?? 1000)));
                $sellerNet = max(0, $total - $fee);
                ledger_post('OPEN-EARNINGS-' . (int) $order['id'], 'opening_earnings', [
                    ['account_code' => 'seller_payable', 'owner_type' => 'user', 'owner_id' => (int) $order['seller_id'], 'amount_satang' => $sellerNet],
                    ['account_code' => 'platform_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => $fee],
                    ['account_code' => 'migration_equity', 'owner_type' => 'platform', 'owner_id' => 0, 'amount_satang' => -$total],
                ], ['order_id' => (int) $order['id']]);
            }
        }
        db()->prepare(
            "INSERT INTO schema_meta (meta_key,meta_value) VALUES ('ledger_opening_v2','done') ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value"
        )->execute();
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
}

function record_order_event(
    int $orderId,
    ?int $actorId,
    string $event,
    ?string $fromStatus,
    ?string $toStatus,
    string $reason = '',
    array $metadata = []
): void {
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db()->prepare(
        'INSERT INTO order_events (order_id,actor_id,event,from_status,to_status,reason,metadata_json) VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $orderId,
        $actorId,
        $event,
        $fromStatus,
        $toStatus,
        substr($reason, 0, 1000),
        is_string($encoded) ? $encoded : '{}',
    ]);
}

function claim_provider_event(string $eventId, string $type, string $payload): bool
{
    if ($eventId === '') {
        return false;
    }
    $hash = hash('sha256', $payload);
    $existing = fetch_one('SELECT status,payload_hash,updated_at FROM payment_provider_events WHERE event_id=?', [$eventId]);
    if ($existing && (string) $existing['payload_hash'] !== $hash) {
        throw new RuntimeException('Provider event payload changed for an existing event ID.');
    }
    $processingIsFresh = $existing
        && (string) $existing['status'] === 'processing'
        && strtotime((string) $existing['updated_at']) >= time() - 300;
    if ($existing && ((string) $existing['status'] === 'processed' || $processingIsFresh)) {
        return false;
    }
    db()->prepare(
        "INSERT INTO payment_provider_events (event_id,event_type,status,payload_hash,attempts,last_error,updated_at)
         VALUES (?,?,'processing',?,1,'',CURRENT_TIMESTAMP)
         ON CONFLICT(event_id) DO UPDATE SET status='processing',attempts=payment_provider_events.attempts+1,last_error='',updated_at=CURRENT_TIMESTAMP"
    )->execute([$eventId, $type, $hash]);
    return true;
}

function finish_provider_event(string $eventId, bool $success, string $error = ''): void
{
    db()->prepare(
        'UPDATE payment_provider_events SET status=?,processed_at=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?'
    )->execute([
        $success ? 'processed' : 'failed',
        $success ? gmdate('Y-m-d H:i:s') : null,
        substr($error, 0, 1000),
        $eventId,
    ]);
}

function base32_encode_secret(string $binary): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($binary) as $character) {
        $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $encoded;
}

function base32_decode_secret(string $encoded): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
    $bits = '';
    foreach (str_split($clean) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) {
            return '';
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }
    return $decoded;
}

function totp_code(string $secret, int $counter): string
{
    $key = base32_decode_secret($secret);
    if ($key === '') {
        return '';
    }
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function verify_totp(string $secret, string $submitted, int $lastCounter = -1): ?int
{
    if (!preg_match('/^\d{6}$/', $submitted)) {
        return null;
    }
    $counter = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $candidateCounter = $counter + $offset;
        if ($candidateCounter > $lastCounter && hash_equals(totp_code($secret, $candidateCounter), $submitted)) {
            return $candidateCounter;
        }
    }
    return null;
}

function queue_email(string $recipient, string $subject, string $body, string $template = 'plain', array $metadata = []): int
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email recipient is invalid.');
    }
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db()->prepare(
        "INSERT INTO outbox_messages (channel,recipient,subject,body,template,status,metadata_json,next_attempt_at)
         VALUES ('email',?,?,?,?, 'pending',?,CURRENT_TIMESTAMP)"
    )->execute([$recipient, $subject, $body, $template, is_string($encoded) ? $encoded : '{}']);
    return database_last_insert_id();
}

function send_outbox_via_transport(array $message): void
{
    $transport = strtolower((string) env_value('MAIL_TRANSPORT', 'log'));
    $from = trim((string) env_value('MAIL_FROM', 'WorkConnect <hello@workconnect.test>'));
    if ($transport === 'resend') {
        $apiKey = trim((string) env_value('RESEND_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }
        $payload = json_encode([
            'from' => $from,
            'to' => [(string) $message['recipient']],
            'subject' => (string) $message['subject'],
            'text' => (string) $message['body'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init('https://api.resend.com/emails');
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize email request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Idempotency-Key: workconnect-outbox-' . (int) $message['id'],
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Resend delivery failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        }
        return;
    }
    if ($transport === 'mail') {
        if (!@mail((string) $message['recipient'], (string) $message['subject'], (string) $message['body'], 'From: ' . $from)) {
            throw new RuntimeException('PHP mail delivery failed.');
        }
        return;
    }
    $directory = dirname(__DIR__) . '/storage/private/mail';
    ensure_upload_protection($directory);
    $line = gmdate(DATE_ATOM) . "\t" . $message['recipient'] . "\t" . $message['subject'] . "\n" . $message['body'] . "\n---\n";
    if (file_put_contents($directory . '/outbox.log', $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write local outbox log.');
    }
}

function attempt_outbox_delivery(int $messageId): bool
{
    $message = fetch_one(
        "SELECT * FROM outbox_messages WHERE id=? AND status IN ('pending','failed') AND next_attempt_at<=CURRENT_TIMESTAMP",
        [$messageId]
    );
    if (!$message) {
        return false;
    }
    $claim = db()->prepare(
        "UPDATE outbox_messages SET status='processing',attempts=attempts+1,updated_at=CURRENT_TIMESTAMP
         WHERE id=? AND status IN ('pending','failed')"
    );
    $claim->execute([$messageId]);
    if ($claim->rowCount() !== 1) {
        return false;
    }
    try {
        send_outbox_via_transport($message);
        db()->prepare(
            "UPDATE outbox_messages SET status='sent',sent_at=CURRENT_TIMESTAMP,last_error='',updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$messageId]);
        return true;
    } catch (Throwable $error) {
        $attempts = (int) $message['attempts'] + 1;
        $delay = min(21600, 60 * (2 ** min(8, $attempts)));
        db()->prepare(
            'UPDATE outbox_messages SET status=?,next_attempt_at=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
        )->execute([
            $attempts >= 10 ? 'dead' : 'failed',
            gmdate('Y-m-d H:i:s', time() + $delay),
            mb_substr($error->getMessage(), 0, 1000),
            $messageId,
        ]);
        app_log('error', 'Outbox delivery failed.', ['message_id' => $messageId, 'attempts' => $attempts]);
        return false;
    }
}

function process_outbox_batch(int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $ids = fetch_all(
        "SELECT id FROM outbox_messages WHERE status IN ('pending','failed')
         AND next_attempt_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT $limit"
    );
    $sent = 0;
    foreach ($ids as $row) {
        if (attempt_outbox_delivery((int) $row['id'])) {
            $sent++;
        }
    }
    return ['selected' => count($ids), 'sent' => $sent, 'failed' => count($ids) - $sent];
}

function record_job_run(string $jobName, string $status, string $detail = ''): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $jobName)) {
        throw new InvalidArgumentException('Job name is invalid.');
    }
    if (!in_array($status, ['ok', 'failed'], true)) {
        throw new InvalidArgumentException('Job status is invalid.');
    }
    db()->prepare('INSERT INTO job_runs (job_name,status,detail) VALUES (?,?,?)')->execute([
        $jobName,
        $status,
        mb_substr($detail, 0, 1000),
    ]);
}

function latest_job_run(string $jobName): ?array
{
    return fetch_one('SELECT * FROM job_runs WHERE job_name=? ORDER BY id DESC LIMIT 1', [$jobName]);
}

function application_timezone(): DateTimeZone
{
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        try {
            $timezone = new DateTimeZone((string) env_value('APP_TIMEZONE', 'Asia/Bangkok'));
        } catch (Throwable $error) {
            $timezone = new DateTimeZone('Asia/Bangkok');
        }
    }
    return $timezone;
}

function display_datetime(?string $date, string $format = 'd M Y H:i'): string
{
    if (!$date) {
        return 'Not set';
    }
    try {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
            ->setTimezone(application_timezone())
            ->format($format);
    } catch (Throwable $error) {
        return 'Not set';
    }
}

function page_number(): int
{
    return max(1, min(100000, (int) ($_GET['p'] ?? 1)));
}

function pagination_limit(int $default = 25): int
{
    return max(10, min(100, $default));
}

function pagination_offset(int $limit): int
{
    return (page_number() - 1) * $limit;
}

function account_export_payload(array $user): array
{
    $userId = (int) $user['id'];
    $profile = [
        'id' => $userId,
        'role' => (string) ($user['role'] ?? ''),
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'phone' => (string) ($user['phone'] ?? ''),
        'bio' => (string) ($user['bio'] ?? ''),
        'status' => (string) ($user['status'] ?? ''),
        'birth_date' => (string) ($user['birth_date'] ?? ''),
        'thai_id_number' => decrypt_sensitive((string) ($user['id_card_number'] ?? '')),
        'email_notifications' => (bool) ($user['email_notifications'] ?? false),
        'theme' => (string) ($user['theme'] ?? 'light'),
        'language' => (string) ($user['language'] ?? 'en'),
        'created_at' => (string) $user['created_at'],
        'updated_at' => (string) $user['updated_at'],
    ];
    $payouts = fetch_all(
        'SELECT id,amount_satang,status,destination_label,reference,requested_at,reviewed_at,paid_at,rejection_reason
         FROM payouts WHERE seller_id=? ORDER BY id',
        [$userId]
    );
    foreach ($payouts as &$payout) {
        $payout['destination_label'] = decrypt_sensitive((string) $payout['destination_label']);
    }
    unset($payout);
    return [
        'format' => 'workconnect-account-export-v1',
        'generated_at' => gmdate(DATE_ATOM),
        'profile' => $profile,
        'services' => fetch_all('SELECT id,category_id,title,description,price_satang,delivery_days,features,status,views,created_at,updated_at FROM services WHERE seller_id=? ORDER BY id', [$userId]),
        'orders' => fetch_all('SELECT id,order_number,customer_id,seller_id,service_id,status,requirements,subtotal_satang,discount_satang,total_satang,platform_fee_satang,seller_net_satang,due_at,accepted_at,cancellation_reason,created_at,updated_at FROM orders WHERE customer_id=? OR seller_id=? ORDER BY id', [$userId, $userId]),
        'messages' => fetch_all('SELECT id,order_id,sender_id,receiver_id,body,attachment,is_read,created_at FROM messages WHERE sender_id=? OR receiver_id=? ORDER BY id', [$userId, $userId]),
        'payments' => fetch_all('SELECT payments.id,payments.order_id,payments.amount_satang,payments.refunded_satang,payments.method,payments.status,payments.transaction_ref,payments.paid_at FROM payments JOIN orders ON orders.id=payments.order_id WHERE orders.customer_id=? OR orders.seller_id=? ORDER BY payments.id', [$userId, $userId]),
        'wallet_transactions' => fetch_all('SELECT id,amount_satang,method,status,reference,note,created_at,updated_at FROM wallet_transactions WHERE user_id=? ORDER BY id', [$userId]),
        'deliveries' => fetch_all('SELECT order_deliveries.id,order_deliveries.order_id,order_deliveries.seller_id,order_deliveries.message,order_deliveries.attachment,order_deliveries.revision_number,order_deliveries.status,order_deliveries.created_at FROM order_deliveries JOIN orders ON orders.id=order_deliveries.order_id WHERE orders.customer_id=? OR orders.seller_id=? ORDER BY order_deliveries.id', [$userId, $userId]),
        'reviews' => fetch_all('SELECT id,order_id,customer_id,seller_id,rating,comment,created_at FROM reviews WHERE customer_id=? OR seller_id=? ORDER BY id', [$userId, $userId]),
        'favorites' => fetch_all('SELECT favorites.service_id,services.title,favorites.created_at FROM favorites JOIN services ON services.id=favorites.service_id WHERE favorites.user_id=? ORDER BY favorites.id', [$userId]),
        'notifications' => fetch_all('SELECT id,type,title,body,link,is_read,created_at FROM notifications WHERE user_id=? ORDER BY id', [$userId]),
        'disputes' => fetch_all('SELECT disputes.id,disputes.order_id,disputes.opened_by,disputes.against_user_id,disputes.reason,disputes.details,disputes.status,disputes.resolution,disputes.resolution_action,disputes.created_at,disputes.updated_at,disputes.resolved_at FROM disputes JOIN orders ON orders.id=disputes.order_id WHERE orders.customer_id=? OR orders.seller_id=? ORDER BY disputes.id', [$userId, $userId]),
        'payouts' => $payouts,
        'security_events' => fetch_all('SELECT event,ip_address,target_type,target_id,details_json,user_agent,request_id,created_at FROM security_logs WHERE user_id=? ORDER BY id', [$userId]),
        'account_requests' => fetch_all('SELECT id,request_type,status,notes,requested_at,completed_at FROM account_requests WHERE user_id=? ORDER BY id', [$userId]),
    ];
}

function storage_driver(): string
{
    return pick_value(strtolower((string) env_value('STORAGE_DRIVER', 'local')), ['local', 's3'], 'local');
}

function object_storage_is_configured(): bool
{
    foreach (['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY', 'S3_SECRET_KEY'] as $key) {
        if (trim((string) env_value($key, '')) === '') {
            return false;
        }
    }
    return true;
}

function object_storage_request(string $method, string $key, string $body = '', string $contentType = 'application/octet-stream'): array
{
    if (!object_storage_is_configured()) {
        throw new RuntimeException('S3-compatible object storage is not configured.');
    }
    $endpoint = rtrim((string) env_value('S3_ENDPOINT', ''), '/');
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !in_array((string) ($parts['scheme'] ?? ''), ['https', 'http'], true) || empty($parts['host'])) {
        throw new RuntimeException('S3_ENDPOINT is invalid.');
    }
    $bucket = trim((string) env_value('S3_BUCKET', ''));
    $region = trim((string) env_value('S3_REGION', 'auto'));
    $accessKey = trim((string) env_value('S3_ACCESS_KEY', ''));
    $secretKey = trim((string) env_value('S3_SECRET_KEY', ''));
    $encodedKey = implode('/', array_map('rawurlencode', array_filter(explode('/', ltrim($key, '/')), 'strlen')));
    $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
    $canonicalUri = ($basePath === '' ? '' : $basePath) . '/' . rawurlencode($bucket) . '/' . $encodedKey;
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $host = (string) $parts['host'] . $port;
    $url = (string) $parts['scheme'] . '://' . $host . $canonicalUri;
    $payloadHash = hash('sha256', $body);
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $canonicalHeaders = "host:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = strtoupper($method) . "\n" . $canonicalUri . "\n\n"
        . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
    $scope = $date . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
    $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $regionKey = hash_hmac('sha256', $region, $dateKey, true);
    $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
    $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    $headers = [
        'Authorization: ' . $authorization,
        'Content-Type: ' . $contentType,
        'X-Amz-Content-Sha256: ' . $payloadHash,
        'X-Amz-Date: ' . $amzDate,
    ];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize object storage request.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ]);
    if (in_array(strtoupper($method), ['PUT', 'POST'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException('Object storage request failed: ' . $error);
    }
    $responseBody = substr($response, $headerSize);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Object storage returned HTTP ' . $status . '.');
    }
    return ['status' => $status, 'body' => $responseBody, 'content_type' => $responseType];
}

function object_storage_put(string $key, string $body, string $contentType): void
{
    object_storage_request('PUT', $key, $body, $contentType);
}

function object_storage_fetch(string $reference): array
{
    if (!str_starts_with($reference, 'object:')) {
        throw new InvalidArgumentException('Object storage reference is invalid.');
    }
    $key = substr($reference, 7);
    if ($key === '' || str_contains($key, '..')) {
        throw new InvalidArgumentException('Object storage key is invalid.');
    }
    return object_storage_request('GET', $key);
}

function object_storage_delete(string $reference): void
{
    if (!str_starts_with($reference, 'object:')) {
        return;
    }
    $key = substr($reference, 7);
    if ($key !== '' && !str_contains($key, '..')) {
        object_storage_request('DELETE', $key);
    }
}

function readiness_report(): array
{
    $checks = [];
    $record = static function (string $name, bool $ok, string $detail) use (&$checks): void {
        $checks[$name] = ['ok' => $ok, 'detail' => $detail];
    };
    try {
        $connection = db();
        $record('database', (int) $connection->query('SELECT 1')->fetchColumn() === 1, database_driver($connection));
        $version = (int) scalar("SELECT COALESCE(meta_value,'0') FROM schema_meta WHERE meta_key='schema_version'");
        $record('schema', $version >= 3, 'version ' . $version);
        $ledgerSum = (int) scalar('SELECT COALESCE(SUM(amount_satang),0) FROM ledger_entries');
        $record('ledger', $ledgerSum === 0, $ledgerSum === 0 ? 'balanced' : 'out of balance');
    } catch (Throwable $error) {
        $record('database', false, 'unavailable');
        app_log('error', 'Readiness database check failed.', ['type' => $error::class]);
    }
    if (storage_driver() === 's3') {
        $record('storage', object_storage_is_configured(), 's3-compatible');
    } else {
        $root = upload_storage_root();
        if (!is_dir($root)) {
            @mkdir($root, 0770, true);
        }
        $record('storage', is_dir($root) && is_writable($root), 'local');
    }
    $mailTransport = strtolower((string) env_value('MAIL_TRANSPORT', 'log'));
    $mailReady = $mailTransport === 'resend'
        ? trim((string) env_value('RESEND_API_KEY', '')) !== ''
        : in_array($mailTransport, ['log', 'mail'], true);
    $record('mail', $mailReady, $mailTransport);
    if (app_is_production()) {
        $record('https', str_starts_with(app_base_url(), 'https://'), 'required');
        $record('stripe', stripe_is_configured() && stripe_webhook_is_configured(), 'payment and webhook');
        $mfaMissing = (int) scalar(
            "SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id
             WHERE roles.name='admin' AND users.status='active' AND users.admin_mfa_enabled<>1"
        );
        $record('admin_mfa', $mfaMissing === 0, $mfaMissing === 0 ? 'enabled' : 'required');
    }
    return [
        'ok' => !in_array(false, array_column($checks, 'ok'), true),
        'checks' => $checks,
        'timestamp' => gmdate(DATE_ATOM),
        'request_id' => request_id(),
    ];
}

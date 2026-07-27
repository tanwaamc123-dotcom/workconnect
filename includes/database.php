<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    [$dsn, $username, $password] = database_connection_config();
    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $connection->exec('PRAGMA foreign_keys = ON');
        try {
            $connection->exec('PRAGMA journal_mode = WAL');
        } catch (Throwable $error) {
            // Some local/XAMPP setups mount SQLite read-only for WAL. Fall back to default journaling.
        }
        initialize_database($connection);
    } else {
        assert_database_schema($connection);
    }
    database_maybe_housekeeping($connection);

    $pdo = $connection;
    return $pdo;
}

function database_connection_config(): array
{
    $url = trim((string) env_value('DATABASE_URL', ''));
    if ($url === '') {
        $databasePath = dirname(__DIR__) . '/storage/workconnect.sqlite';
        $dir = dirname($databasePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create database directory.');
        }
        return ['sqlite:' . $databasePath, null, null];
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
        throw new RuntimeException('DATABASE_URL must be a PostgreSQL connection URL.');
    }
    $host = (string) ($parts['host'] ?? '');
    $database = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($host === '' || $database === '') {
        throw new RuntimeException('DATABASE_URL is missing the PostgreSQL host or database name.');
    }
    parse_str((string) ($parts['query'] ?? ''), $options);
    $sslmode = (string) ($options['sslmode'] ?? env_value('DB_SSLMODE', 'require'));
    if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('DB_SSLMODE is invalid.');
    }
    $port = (int) ($parts['port'] ?? 5432);
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslmode);
    return [$dsn, rawurldecode((string) ($parts['user'] ?? '')), rawurldecode((string) ($parts['pass'] ?? ''))];
}

function database_driver(?PDO $pdo = null): string
{
    return (string) ($pdo ?? db())->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function database_maybe_housekeeping(PDO $connection): void
{
    try {
        if (random_int(1, 100) !== 1) {
            return;
        }
        if (database_driver($connection) === 'pgsql') {
            $connection->exec("DELETE FROM sessions WHERE (persistent=1 AND expires_at<CURRENT_TIMESTAMP) OR (persistent=0 AND last_activity<CURRENT_TIMESTAMP-INTERVAL '12 hours')");
            $connection->exec("DELETE FROM rate_limits WHERE window_started_at<CURRENT_TIMESTAMP-INTERVAL '2 days'");
            $connection->exec("DELETE FROM password_reset_tokens WHERE expires_at<CURRENT_TIMESTAMP-INTERVAL '1 day'");
            $connection->exec("UPDATE payment_requests SET status='failed',processing_started_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND processing_started_at<CURRENT_TIMESTAMP-INTERVAL '15 minutes'");
            $connection->exec("UPDATE outbox_messages SET status='failed',next_attempt_at=CURRENT_TIMESTAMP,last_error='Recovered stale processing claim',updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND updated_at<CURRENT_TIMESTAMP-INTERVAL '15 minutes'");
            return;
        }
        $connection->exec("DELETE FROM sessions WHERE (persistent=1 AND expires_at<CURRENT_TIMESTAMP) OR (persistent=0 AND last_activity<datetime('now','-12 hours'))");
        $connection->exec("DELETE FROM rate_limits WHERE window_started_at<datetime('now','-2 days')");
        $connection->exec("DELETE FROM password_reset_tokens WHERE expires_at<datetime('now','-1 day')");
        $connection->exec("UPDATE payment_requests SET status='failed',processing_started_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND processing_started_at<datetime('now','-15 minutes')");
        $connection->exec("UPDATE outbox_messages SET status='failed',next_attempt_at=CURRENT_TIMESTAMP,last_error='Recovered stale processing claim',updated_at=CURRENT_TIMESTAMP WHERE status='processing' AND updated_at<datetime('now','-15 minutes')");
    } catch (Throwable $error) {
        error_log('WorkConnect database housekeeping failed: ' . $error->getMessage());
    }
}

function database_last_insert_id(?PDO $pdo = null): int
{
    $connection = $pdo ?? db();
    if (database_driver($connection) === 'pgsql') {
        return (int) $connection->query('SELECT LASTVAL()')->fetchColumn();
    }
    return (int) $connection->lastInsertId();
}

function database_interval_expression(string $amount): string
{
    if (!preg_match('/^[+-]\d+ (minute|minutes|hour|hours|day|days)$/', $amount)) {
        throw new InvalidArgumentException('Invalid database interval.');
    }
    if (database_driver() === 'pgsql') {
        $operator = str_starts_with($amount, '-') ? '-' : '+';
        return "CURRENT_TIMESTAMP $operator INTERVAL " . db()->quote(ltrim($amount, '+-'));
    }
    return 'datetime(\'now\', ' . db()->quote($amount) . ')';
}

function database_month_expression(string $column): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
        throw new InvalidArgumentException('Invalid database column.');
    }
    return database_driver() === 'pgsql'
        ? "TO_CHAR($column, 'YYYY-MM')"
        : "strftime('%Y-%m', $column)";
}

function database_portable_sql(string $sql, ?string $driver = null): string
{
    if (($driver ?? database_driver()) !== 'pgsql') {
        return $sql;
    }

    // The original SQLite queries used double quotes for text literals.
    $sql = preg_replace_callback('/"([^"]*)"/', static function (array $match): string {
        return "'" . str_replace("'", "''", $match[1]) . "'";
    }, $sql) ?? $sql;
    $sql = preg_replace_callback(
        "/datetime\\(\\s*'now'\\s*,\\s*'([+-])(\\d+) (minutes?|hours?|days?)'\\s*\\)/i",
        static fn(array $match): string => "CURRENT_TIMESTAMP {$match[1]} INTERVAL '{$match[2]} {$match[3]}'",
        $sql
    ) ?? $sql;
    $sql = preg_replace_callback(
        "/date\\(\\s*'now'\\s*,\\s*'([+-])(\\d+) (days?)'\\s*\\)/i",
        static fn(array $match): string => "CURRENT_DATE {$match[1]} INTERVAL '{$match[2]} {$match[3]}'",
        $sql
    ) ?? $sql;
    $sql = preg_replace(
        "/strftime\\(\\s*'%Y-%m'\\s*,\\s*([a-z_][a-z0-9_.]*)\\s*\\)/i",
        'TO_CHAR($1, \'YYYY-MM\')',
        $sql
    ) ?? $sql;
    $sql = preg_replace(
        "/SUM\\(\\s*([a-z_.]+)\\s*=\\s*'([^']+)'\\s*\\)/i",
        'SUM(CASE WHEN $1=\'$2\' THEN 1 ELSE 0 END)',
        $sql
    ) ?? $sql;
    return $sql;
}

function assert_database_schema(PDO $pdo): void
{
    try {
        $version = (int) $pdo->query("SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")->fetchColumn();
    } catch (Throwable $error) {
        throw new RuntimeException('PostgreSQL is connected but not initialized. Run: php scripts/database-migrate.php', 0, $error);
    }
    if ($version < 3) {
        throw new RuntimeException('PostgreSQL schema is out of date. Run: php scripts/database-migrate.php');
    }
}

function initialize_database(PDO $pdo): void
{
    // Bump this whenever the SQLite bootstrap schema changes so existing installs migrate.
    $runtimeRevision = '20260724.1';
    try {
        $currentRevision = (string) $pdo->query(
            "SELECT meta_value FROM schema_meta WHERE meta_key='runtime_schema_revision'"
        )->fetchColumn();
        if (hash_equals($runtimeRevision, $currentRevision)) {
            return;
        }
    } catch (Throwable $error) {
        // A fresh database does not have schema_meta yet.
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,label TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,role_id INTEGER NOT NULL,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,avatar TEXT DEFAULT '',phone TEXT DEFAULT '',bio TEXT DEFAULT '',status TEXT NOT NULL DEFAULT 'active',
            email_notifications INTEGER NOT NULL DEFAULT 1,is_demo INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        );
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL,ip_address TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',last_activity TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS security_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,event TEXT NOT NULL,ip_address TEXT DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0,
            window_started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            blocked_until TEXT DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,used_at TEXT DEFAULT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,code TEXT NOT NULL,color TEXT NOT NULL DEFAULT 'blue');
        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,seller_id INTEGER NOT NULL,category_id INTEGER NOT NULL,title TEXT NOT NULL,
            description TEXT NOT NULL,price REAL NOT NULL,delivery_days INTEGER NOT NULL DEFAULT 7,features TEXT NOT NULL DEFAULT '',
            thumbnail TEXT NOT NULL DEFAULT 'website',status TEXT NOT NULL DEFAULT 'active',views INTEGER NOT NULL DEFAULT 0,is_demo INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY (category_id) REFERENCES categories(id)
        );
        CREATE TABLE IF NOT EXISTS order_status (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,label TEXT NOT NULL,sort_order INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS coupons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT NOT NULL UNIQUE,discount_percent INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,
            expires_at TEXT,is_demo INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,order_number TEXT NOT NULL UNIQUE,customer_id INTEGER NOT NULL,seller_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL,status TEXT NOT NULL DEFAULT 'pending',requirements TEXT NOT NULL,subtotal REAL NOT NULL,
            discount REAL NOT NULL DEFAULT 0,total REAL NOT NULL,due_at TEXT,is_demo INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES users(id),FOREIGN KEY (seller_id) REFERENCES users(id),FOREIGN KEY (service_id) REFERENCES services(id)
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,sender_id INTEGER NOT NULL,receiver_id INTEGER NOT NULL,body TEXT NOT NULL,
            attachment TEXT DEFAULT '',is_read INTEGER NOT NULL DEFAULT 0,is_demo INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY (sender_id) REFERENCES users(id),FOREIGN KEY (receiver_id) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER NOT NULL UNIQUE,amount REAL NOT NULL,method TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'paid',transaction_ref TEXT NOT NULL,is_demo INTEGER NOT NULL DEFAULT 0,paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,amount REAL NOT NULL,method TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'completed',reference TEXT NOT NULL,note TEXT DEFAULT '',is_demo INTEGER NOT NULL DEFAULT 0,
            slip_path TEXT DEFAULT '',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,type TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,
            link TEXT DEFAULT '',is_read INTEGER NOT NULL DEFAULT 0,is_demo INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER NOT NULL UNIQUE,customer_id INTEGER NOT NULL,seller_id INTEGER NOT NULL,
            rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),comment TEXT NOT NULL,is_demo INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (order_id) REFERENCES orders(id),
            FOREIGN KEY (customer_id) REFERENCES users(id),FOREIGN KEY (seller_id) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,service_id INTEGER NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id,service_id),FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS system_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT NOT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS newsletter_subscribers (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS schema_meta (meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS payment_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_type TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            service_id INTEGER DEFAULT NULL,
            order_id INTEGER DEFAULT NULL,
            amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT 'thb',
            status TEXT NOT NULL DEFAULT 'pending',
            provider TEXT NOT NULL DEFAULT 'stripe',
            provider_session_id TEXT DEFAULT NULL,
            provider_payment_intent TEXT DEFAULT NULL,
            reference_code TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT '',
            payload_json TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS ledger_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL UNIQUE,
            transaction_type TEXT NOT NULL,
            order_id INTEGER DEFAULT NULL,
            user_id INTEGER DEFAULT NULL,
            metadata_json TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS ledger_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id INTEGER NOT NULL,
            account_code TEXT NOT NULL,
            owner_type TEXT NOT NULL DEFAULT 'platform',
            owner_id INTEGER NOT NULL DEFAULT 0,
            amount_satang INTEGER NOT NULL CHECK(amount_satang<>0),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (transaction_id) REFERENCES ledger_transactions(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS order_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            actor_id INTEGER DEFAULT NULL,
            event TEXT NOT NULL,
            from_status TEXT DEFAULT NULL,
            to_status TEXT DEFAULT NULL,
            reason TEXT NOT NULL DEFAULT '',
            metadata_json TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS coupon_redemptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coupon_id INTEGER NOT NULL,
            order_id INTEGER NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            discount_satang INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS payment_provider_events (
            event_id TEXT PRIMARY KEY,
            event_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'processing',
            payload_hash TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 1,
            last_error TEXT NOT NULL DEFAULT '',
            processed_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS payouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seller_id INTEGER NOT NULL,
            amount_satang INTEGER NOT NULL CHECK(amount_satang>0),
            status TEXT NOT NULL DEFAULT 'requested',
            destination_label TEXT NOT NULL DEFAULT '',
            reference TEXT NOT NULL UNIQUE,
            requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by INTEGER DEFAULT NULL,
            reviewed_at TEXT DEFAULT NULL,
            paid_at TEXT DEFAULT NULL,
            rejection_reason TEXT NOT NULL DEFAULT '',
            is_demo INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS disputes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            opened_by INTEGER NOT NULL,
            against_user_id INTEGER DEFAULT NULL,
            reason TEXT NOT NULL,
            details TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            assigned_to INTEGER DEFAULT NULL,
            resolution TEXT NOT NULL DEFAULT '',
            resolution_action TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT DEFAULT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (against_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE IF NOT EXISTS dispute_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispute_id INTEGER NOT NULL,
            uploaded_by INTEGER NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            attachment TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
        );
        CREATE TABLE IF NOT EXISTS order_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            seller_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            attachment TEXT NOT NULL DEFAULT '',
            revision_number INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'submitted',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT
        );
        CREATE TABLE IF NOT EXISTS outbox_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL DEFAULT 'email',
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            template TEXT NOT NULL DEFAULT 'plain',
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            metadata_json TEXT NOT NULL DEFAULT '{}',
            next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at TEXT DEFAULT NULL,
            last_error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS job_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_name TEXT NOT NULL,
            status TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT '',
            finished_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS account_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            request_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            notes TEXT NOT NULL DEFAULT '',
            requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    SQL);

    foreach (['users','services','coupons','orders','messages','payments','notifications','reviews','wallet_transactions'] as $table) {
        ensure_column($pdo, $table, 'is_demo', 'INTEGER NOT NULL DEFAULT 0');
    }
    ensure_column($pdo, 'wallet_transactions', 'updated_at', 'TEXT');
    ensure_column($pdo, 'wallet_transactions', 'slip_path', "TEXT DEFAULT ''");
    ensure_column($pdo, 'payment_requests', 'provider_session_id', 'TEXT');
    ensure_column($pdo, 'payment_requests', 'provider_payment_intent', 'TEXT');
    ensure_column($pdo, 'payment_requests', 'title', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'payment_requests', 'payload_json', "TEXT NOT NULL DEFAULT '{}'");
    ensure_column($pdo, 'payment_requests', 'updated_at', 'TEXT');
    foreach ([
        ['security_logs', 'target_type', "TEXT NOT NULL DEFAULT ''"],
        ['security_logs', 'target_id', 'INTEGER DEFAULT NULL'],
        ['security_logs', 'details_json', "TEXT NOT NULL DEFAULT '{}'"],
        ['security_logs', 'user_agent', "TEXT NOT NULL DEFAULT ''"],
        ['security_logs', 'request_id', "TEXT NOT NULL DEFAULT ''"],
        ['users', 'wallet_balance_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['users', 'id_card_fingerprint', "TEXT NOT NULL DEFAULT ''"],
        ['users', 'admin_role', "TEXT NOT NULL DEFAULT ''"],
        ['users', 'admin_mfa_secret', "TEXT NOT NULL DEFAULT ''"],
        ['users', 'admin_mfa_enabled', 'INTEGER NOT NULL DEFAULT 0'],
        ['users', 'mfa_last_counter', 'INTEGER NOT NULL DEFAULT -1'],
        ['sessions', 'persistent', 'INTEGER NOT NULL DEFAULT 0'],
        ['sessions', 'expires_at', 'TEXT DEFAULT NULL'],
        ['services', 'price_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['services', 'moderation_version', 'INTEGER NOT NULL DEFAULT 1'],
        ['coupons', 'max_uses', 'INTEGER DEFAULT NULL'],
        ['coupons', 'per_user_limit', 'INTEGER NOT NULL DEFAULT 1'],
        ['coupons', 'minimum_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'subtotal_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'discount_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'total_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'fee_rate_bps', 'INTEGER NOT NULL DEFAULT 1000'],
        ['orders', 'platform_fee_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'seller_net_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'revision_limit', 'INTEGER NOT NULL DEFAULT 2'],
        ['orders', 'revision_count', 'INTEGER NOT NULL DEFAULT 0'],
        ['orders', 'accepted_at', 'TEXT DEFAULT NULL'],
        ['orders', 'cancellation_reason', "TEXT NOT NULL DEFAULT ''"],
        ['payments', 'amount_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['payments', 'refunded_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['wallet_transactions', 'amount_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['payment_requests', 'amount_satang', 'INTEGER NOT NULL DEFAULT 0'],
        ['payment_requests', 'processing_started_at', 'TEXT DEFAULT NULL'],
    ] as [$table, $column, $definition]) {
        ensure_column($pdo, $table, $column, $definition);
    }
    $pdo->exec("UPDATE wallet_transactions SET updated_at=COALESCE(NULLIF(updated_at,''), CURRENT_TIMESTAMP) WHERE updated_at IS NULL OR updated_at=''");
    $pdo->exec("UPDATE payment_requests SET updated_at=COALESCE(NULLIF(updated_at,''), CURRENT_TIMESTAMP) WHERE updated_at IS NULL OR updated_at=''");
    $pdo->exec("UPDATE payment_requests SET provider_session_id=NULL WHERE provider_session_id=''");
    $pdo->exec("UPDATE payment_requests SET provider_payment_intent=NULL WHERE provider_payment_intent=''");
    $pdo->exec('DROP INDEX IF EXISTS idx_payment_requests_provider_session_id');
    $pdo->exec('DROP INDEX IF EXISTS idx_payment_requests_provider_payment_intent');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_requests_provider_session_id ON payment_requests(provider_session_id) WHERE provider_session_id IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_requests_provider_payment_intent ON payment_requests(provider_payment_intent) WHERE provider_payment_intent IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_sessions_user_token ON sessions(user_id, token_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_read_id ON notifications(user_id, is_read, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications(user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_receiver_read_id ON messages(receiver_id, is_read, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_order_created ON messages(order_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_customer_status ON orders(customer_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_seller_status ON orders(seller_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_service ON orders(service_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders(status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_status_paid ON payments(status, paid_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payment_requests_user_status ON payment_requests(user_id, status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_services_status_seller ON services(status, seller_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_services_category_status ON services(category_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user_status ON wallet_transactions(user_id, status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_logs_user_created ON security_logs(user_id, created_at)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_wallet_transactions_reference ON wallet_transactions(reference)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_user_expires ON password_reset_tokens(user_id, expires_at)');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_id_card_fingerprint ON users(id_card_fingerprint) WHERE id_card_fingerprint<>''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_logs_request_id ON security_logs(request_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ledger_entries_account_owner ON ledger_entries(account_code,owner_type,owner_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ledger_entries_transaction ON ledger_entries(transaction_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_events_order_created ON order_events(order_id,created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_coupon_user ON coupon_redemptions(coupon_id,user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_provider_events_status_updated ON payment_provider_events(status,updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payouts_seller_status ON payouts(seller_id,status,requested_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_disputes_status_updated ON disputes(status,updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_disputes_order ON disputes(order_id)');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_disputes_active_order_unique ON disputes(order_id) WHERE status IN ('open','investigating')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_deliveries_order_created ON order_deliveries(order_id,created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_outbox_status_next ON outbox_messages(status,next_attempt_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_job_runs_name_finished ON job_runs(job_name,finished_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_account_requests_user_type_status ON account_requests(user_id,request_type,status,requested_at)');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_account_requests_pending_unique ON account_requests(user_id,request_type) WHERE status='pending'");
    $pdo->exec("DELETE FROM sessions WHERE last_activity < datetime('now', '-30 days')");
    $pdo->exec("DELETE FROM rate_limits WHERE window_started_at < datetime('now', '-2 days')");
    $pdo->exec("DELETE FROM password_reset_tokens WHERE expires_at < datetime('now', '-1 day')");
        foreach ([
            ['users', 'theme', "TEXT NOT NULL DEFAULT 'light'"],
            ['users', 'language', "TEXT NOT NULL DEFAULT 'en'"],
            ['users', 'text_scale', "TEXT NOT NULL DEFAULT 'medium'"],
            ['users', 'ui_scale', "TEXT NOT NULL DEFAULT 'comfortable'"],
            ['users', 'wallet_balance', 'REAL NOT NULL DEFAULT 0'],
            ['users', 'birth_date', "TEXT DEFAULT ''"],
            ['users', 'id_card_number', "TEXT DEFAULT ''"],
            ['users', 'id_card_front', "TEXT DEFAULT ''"],
            ['users', 'id_card_back', "TEXT DEFAULT ''"],
            ['users', 'verification_notes', "TEXT DEFAULT ''"],
            ['orders', 'coupon_code', "TEXT NOT NULL DEFAULT ''"],
        ] as [$table, $column, $definition]) {
            ensure_column($pdo, $table, $column, $definition);
        }
    $pdo->exec("UPDATE users SET theme=COALESCE(NULLIF(theme,''),'light'), language=COALESCE(NULLIF(language,''),'en'), text_scale=COALESCE(NULLIF(text_scale,''),'medium'), ui_scale=COALESCE(NULLIF(ui_scale,''),'comfortable'), wallet_balance=COALESCE(wallet_balance,0)");
    $pdo->exec('UPDATE users SET wallet_balance_satang=CAST(ROUND(COALESCE(wallet_balance,0)*100) AS INTEGER) WHERE wallet_balance_satang=0 AND COALESCE(wallet_balance,0)<>0');
    $pdo->exec('UPDATE services SET price_satang=CAST(ROUND(COALESCE(price,0)*100) AS INTEGER) WHERE price_satang=0 AND COALESCE(price,0)<>0');
    $pdo->exec('UPDATE orders SET subtotal_satang=CAST(ROUND(COALESCE(subtotal,0)*100) AS INTEGER),discount_satang=CAST(ROUND(COALESCE(discount,0)*100) AS INTEGER),total_satang=CAST(ROUND(COALESCE(total,0)*100) AS INTEGER) WHERE total_satang=0 AND COALESCE(total,0)<>0');
    $pdo->exec('UPDATE orders SET platform_fee_satang=CAST(ROUND(total_satang*fee_rate_bps/10000.0) AS INTEGER),seller_net_satang=total_satang-CAST(ROUND(total_satang*fee_rate_bps/10000.0) AS INTEGER) WHERE total_satang>0 AND seller_net_satang=0');
    $pdo->exec('UPDATE payments SET amount_satang=CAST(ROUND(COALESCE(amount,0)*100) AS INTEGER) WHERE amount_satang=0 AND COALESCE(amount,0)<>0');
    $pdo->exec('UPDATE wallet_transactions SET amount_satang=CAST(ROUND(COALESCE(amount,0)*100) AS INTEGER) WHERE amount_satang=0 AND COALESCE(amount,0)<>0');
    $pdo->exec('UPDATE payment_requests SET amount_satang=CAST(ROUND(COALESCE(amount,0)*100) AS INTEGER) WHERE amount_satang=0 AND COALESCE(amount,0)<>0');
    $pdo->exec("UPDATE users SET admin_role='owner' WHERE role_id=(SELECT id FROM roles WHERE name='admin') AND admin_role=''");
    $pdo->exec("UPDATE system_settings SET setting_value='50',updated_at=CURRENT_TIMESTAMP WHERE setting_key='topup_minimum' AND setting_value IN ('', '200')");
    $pdo->exec("UPDATE system_settings SET setting_value='hosted_promptpay',updated_at=CURRENT_TIMESTAMP WHERE setting_key='payment_mode' AND setting_value='manual_wallet'");
    $pdo->exec("UPDATE system_settings SET setting_value='Pay with PromptPay on the Stripe-hosted page. Your wallet is credited automatically after Stripe confirms the payment.',updated_at=CURRENT_TIMESTAMP WHERE setting_key='payment_instructions' AND setting_value='Transfer the exact amount, upload the slip, and wait for admin approval before using your balance.'");
    bootstrap_reference_data($pdo);
    migrate_legacy_demo_data($pdo);
    ensure_enhanced_demo_data($pdo);
    $pdo->exec("INSERT INTO schema_meta (meta_key,meta_value) VALUES ('schema_version','3') ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value");
    $revisionStatement = $pdo->prepare(
        "INSERT INTO schema_meta (meta_key,meta_value) VALUES ('runtime_schema_revision',?)
         ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value"
    );
    $revisionStatement->execute([$runtimeRevision]);
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    if (!in_array($column, array_column($columns, 'name'), true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function bootstrap_reference_data(PDO $pdo): void
{
    $pdo->exec("INSERT INTO roles (name,label) VALUES ('customer','Customer'),('seller','Seller'),('admin','Administrator') ON CONFLICT(name) DO NOTHING");
    $pdo->exec("INSERT INTO categories (name,code,color) VALUES
        ('Website & App','WA','blue'),('Graphic Design','GD','violet'),('Document Services','DS','green'),('Media Production','MP','amber') ON CONFLICT(name) DO NOTHING");
    $pdo->exec("INSERT INTO order_status (name,label,sort_order) VALUES
        ('pending','Pending',1),('in_progress','In progress',2),('review','Needs review',3),('completed','Completed',4),('cancelled','Cancelled',5) ON CONFLICT(name) DO NOTHING");
    $pdo->exec("INSERT INTO system_settings (setting_key,setting_value) VALUES
        ('site_name','WorkConnect'),('site_tagline','Connect. Collaborate. Succeed.'),('support_email','hello@workconnect.test'),('support_phone',''),('contact_ig','https://www.instagram.com/waa_xzz/'),('currency_symbol','฿'),('platform_fee','10'),('topup_minimum','50'),('topup_slip_required','0'),('maintenance_mode','0'),('registration_open','1'),('seller_auto_approval','0'),('demo_mode','0'),('announcement_banner',''),('announcement_banner_duration','15'),('default_theme','light'),('default_language','en'),('default_text_scale','medium'),('default_ui_scale','comfortable'),('default_email_notifications','1'),
        ('payment_mode','hosted_promptpay'),('payment_instructions','Pay with PromptPay on the Stripe-hosted page. Your wallet is credited automatically after Stripe confirms the payment.'),('bank_account_name',''),('bank_name',''),('bank_account_number',''),('promptpay_id','') ON CONFLICT(setting_key) DO NOTHING");
}

function migrate_legacy_demo_data(PDO $pdo): void
{
    if ($pdo->query("SELECT COUNT(*) FROM schema_meta WHERE meta_key='demo_tracking_v1'")->fetchColumn()) return;
    $emails = "'customer@workconnect.test','seller@workconnect.test','admin@workconnect.test','marcus@workconnect.test','pim@workconnect.test'";
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE users SET is_demo=1 WHERE email IN ($emails)");
        $pdo->exec('UPDATE services SET is_demo=1 WHERE seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('UPDATE orders SET is_demo=1 WHERE service_id IN (SELECT id FROM services WHERE is_demo=1) OR customer_id IN (SELECT id FROM users WHERE is_demo=1) OR seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('UPDATE messages SET is_demo=1 WHERE order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('UPDATE payments SET is_demo=1 WHERE order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('UPDATE reviews SET is_demo=1 WHERE order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('UPDATE notifications SET is_demo=1 WHERE user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec("UPDATE coupons SET is_demo=1 WHERE code IN ('WELCOME10','STUDENT15')");
        $pdo->exec("INSERT INTO schema_meta (meta_key,meta_value) VALUES ('demo_tracking_v1','complete')");
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function ensure_enhanced_demo_data(PDO $pdo): void
{
    if (!(int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_demo=1')->fetchColumn()) {
        return;
    }
    if ($pdo->query("SELECT COUNT(*) FROM schema_meta WHERE meta_key='demo_dataset_v3'")->fetchColumn()) {
        return;
    }

    $demoDate = static function (int $monthsAgo, int $day, string $time = '10:00:00'): string {
        return date(sprintf('Y-m-%02d %s', $day, $time), strtotime("-{$monthsAgo} months"));
    };

    $password = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare('INSERT INTO users (role_id,name,email,password_hash,phone,bio,status,theme,language,text_scale,ui_scale,email_notifications,is_demo,wallet_balance) SELECT (SELECT id FROM roles WHERE name=?),?,?,?,?,?,?,?,?,?,?,?,1,? WHERE NOT EXISTS (SELECT 1 FROM users WHERE email=?)');
    $seedUser = static function (string $role, string $name, string $email, string $phone, string $bio, string $status, float $walletBalance = 0.0) use ($insertUser, $password): void {
        $insertUser->execute([$role, $name, $email, $password, $phone, $bio, $status, 'light', 'en', 'medium', 'comfortable', 1, $walletBalance, $email]);
    };

    $seedUser('customer', 'Alex Morgan', 'customer@workconnect.test', '089-555-0101', 'Small business owner building useful digital products.', 'active', 2500);
    $seedUser('seller', 'Ananya Prasert', 'seller@workconnect.test', '089-555-0202', 'Digital designer and web developer focused on clear, useful experiences.', 'active');
    $seedUser('admin', 'Narin Admin', 'admin@workconnect.test', '089-555-0303', 'WorkConnect platform administrator.', 'active');
    $seedUser('seller', 'Marcus Tan', 'marcus@workconnect.test', '', 'Brand designer helping teams communicate with confidence.', 'active');
    $seedUser('seller', 'Pimchanok Lee', 'pim@workconnect.test', '', 'Presentation and document specialist.', 'active');
    $seedUser('customer', 'Nina Somchai', 'nina@workconnect.test', '089-555-0404', 'Customer testing a second workflow with more saved services and messages.', 'active', 1800);
    $seedUser('seller', 'Somchai Review', 'somchai.review@workconnect.test', '089-555-0505', 'A newer seller profile waiting for admin approval.', 'pending_approval');

    $pdo->prepare('UPDATE users SET bio=?, wallet_balance=?, email_notifications=1 WHERE email=?')->execute(['Small business owner building useful digital products.', 2500, 'customer@workconnect.test']);
    $pdo->prepare('UPDATE users SET bio=?, wallet_balance=?, email_notifications=1 WHERE email=?')->execute(['Customer testing a second workflow with more saved services and messages.', 1800, 'nina@workconnect.test']);

    $sellerId = (int) $pdo->query("SELECT id FROM users WHERE email='seller@workconnect.test'")->fetchColumn();
    $marcusId = (int) $pdo->query("SELECT id FROM users WHERE email='marcus@workconnect.test'")->fetchColumn();
    $pimId = (int) $pdo->query("SELECT id FROM users WHERE email='pim@workconnect.test'")->fetchColumn();
    $customerId = (int) $pdo->query("SELECT id FROM users WHERE email='customer@workconnect.test'")->fetchColumn();
    $ninaId = (int) $pdo->query("SELECT id FROM users WHERE email='nina@workconnect.test'")->fetchColumn();
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE email='admin@workconnect.test'")->fetchColumn();
    $somchaiId = (int) $pdo->query("SELECT id FROM users WHERE email='somchai.review@workconnect.test'")->fetchColumn();

    $insertService = $pdo->prepare('INSERT INTO services (seller_id,category_id,title,description,price,delivery_days,features,thumbnail,views,is_demo) SELECT ?, (SELECT id FROM categories WHERE name=?), ?, ?, ?, ?, ?, ?, ?, 1 WHERE NOT EXISTS (SELECT 1 FROM services WHERE title=? AND is_demo=1)');
    $seedService = static function (int $sellerId, string $category, string $title, string $description, float $price, int $days, string $features, string $thumbnail, int $views) use ($insertService): void {
        $insertService->execute([$sellerId, $category, $title, $description, $price, $days, $features, $thumbnail, $views, $title]);
    };

    $seedService($sellerId, 'Website & App', 'Responsive Website Design', 'A polished, responsive business website designed around your goals and content.', 1500, 7, "Responsive layout\nContact form\nBasic SEO\nTwo revisions", 'website', 412);
    $seedService($marcusId, 'Graphic Design', 'Brand Identity & Logo', 'A practical visual identity system that makes your business recognizable.', 2000, 5, "Logo suite\nColor system\nTypography guide\nSource files", 'brand', 286);
    $seedService($pimId, 'Document Services', 'PowerPoint Presentation', 'Clear presentation design for pitches, classes, and project reports.', 900, 3, "Up to 15 slides\nEditable source\nCharts and icons\nTwo revisions", 'slides', 208);
    $seedService($sellerId, 'Media Production', 'Short Video Editing', 'Sharp, engaging short-form editing for TikTok, Reels, and product stories.', 1200, 4, "Up to 60 seconds\nCaptions\nMusic sync\nColor correction", 'video', 191);
    $seedService($sellerId, 'Website & App', 'E-Commerce Website', 'A focused online store with catalog, cart, and checkout-ready structure.', 3500, 14, "Product catalog\nCart experience\nMobile responsive\nAdmin handoff", 'commerce', 454);
    $seedService($marcusId, 'Graphic Design', 'Social Media Post Set', 'A cohesive set of social visuals prepared for your channels.', 800, 3, "10 post designs\nEditable templates\nTwo sizes\nOne revision", 'social', 156);
    $seedService($pimId, 'Document Services', 'Resume & CV Design', 'A professional resume system that keeps your experience easy to scan.', 700, 2, "ATS-friendly layout\nEditable file\nPDF export\nOne revision", 'resume', 173);
    $seedService($sellerId, 'Media Production', 'Photo Retouching', 'Natural, careful retouching for portraits and product photography.', 600, 2, "Five images\nColor correction\nSkin cleanup\nHigh-resolution export", 'photo', 129);
    $seedService($sellerId, 'Website & App', 'Mobile App UI Design', 'A clean app interface kit for onboarding, dashboard, and settings screens.', 2400, 8, "User flows\nDashboard screens\nDesign system\nTwo revision rounds", 'website', 203);
    $seedService($marcusId, 'Graphic Design', 'Pitch Deck Redesign', 'A crisp investor deck with stronger hierarchy and cleaner storytelling.', 1800, 4, "10 slides\nBrand alignment\nData polish\nEditable source", 'slides', 144);

    $pdo->exec("INSERT INTO coupons (code,discount_percent,active,expires_at,is_demo) VALUES ('WELCOME10',10,1,'2030-12-31 23:59:59',1),('STUDENT15',15,1,'2030-12-31 23:59:59',1),('WELCOME20',20,1,'2030-12-31 23:59:59',1) ON CONFLICT(code) DO NOTHING");

    $insertOrder = $pdo->prepare('INSERT INTO orders (order_number,customer_id,seller_id,service_id,status,requirements,subtotal,discount,total,due_at,is_demo,coupon_code,created_at,updated_at) SELECT ?,?,?,?,? ,?,?,?,?,?,1,?,?,? WHERE NOT EXISTS (SELECT 1 FROM orders WHERE order_number=?)');
    $seedOrder = static function (string $orderNumber, int $customerId, int $sellerId, string $serviceTitle, string $status, string $requirements, float $subtotal, float $discount, float $total, string $dueAt, string $createdAt, string $couponCode = '') use ($pdo, $insertOrder): int {
        $serviceId = (int) $pdo->query('SELECT id FROM services WHERE title=' . $pdo->quote($serviceTitle) . ' AND is_demo=1 LIMIT 1')->fetchColumn();
        $insertOrder->execute([$orderNumber, $customerId, $sellerId, $serviceId, $status, $requirements, $subtotal, $discount, $total, $dueAt, $couponCode, $createdAt, $createdAt, $orderNumber]);
        return (int) $pdo->query('SELECT id FROM orders WHERE order_number=' . $pdo->quote($orderNumber) . ' LIMIT 1')->fetchColumn();
    };

    $order101 = $seedOrder('WC-DEMO-101', $customerId, $sellerId, 'Responsive Website Design', 'in_progress', 'Create a responsive landing page for a local tutoring studio.', 1500, 150, 1350, date('Y-m-d H:i:s', strtotime('+5 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'WELCOME10');
    $order102 = $seedOrder('WC-DEMO-102', $customerId, $sellerId, 'Short Video Editing', 'review', 'Edit a 45-second product introduction with captions.', 1200, 0, 1200, date('Y-m-d H:i:s', strtotime('+1 day')), date('Y-m-d H:i:s', strtotime('-3 days')));
    $order103 = $seedOrder('WC-DEMO-103', $customerId, $sellerId, 'E-Commerce Website', 'completed', 'Build a small catalog store for handmade stationery.', 3500, 0, 3500, date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-16 days')));
    $order104 = $seedOrder('WC-DEMO-104', $ninaId, $marcusId, 'Brand Identity & Logo', 'pending', 'I need a simple identity refresh for a wellness studio.', 2000, 0, 2000, date('Y-m-d H:i:s', strtotime('+7 days')), date('Y-m-d H:i:s', strtotime('-2 days')));
    $order105 = $seedOrder('WC-DEMO-105', $ninaId, $pimId, 'PowerPoint Presentation', 'completed', 'Turn my project notes into a clean, investor-ready slide deck.', 900, 0, 900, date('Y-m-d H:i:s', strtotime('-1 days')), date('Y-m-d H:i:s', strtotime('-12 days')));
    $order106 = $seedOrder('WC-DEMO-106', $ninaId, $sellerId, 'Mobile App UI Design', 'in_progress', 'Design onboarding and dashboard screens for a booking app.', 2400, 0, 2400, date('Y-m-d H:i:s', strtotime('+8 days')), date('Y-m-d H:i:s', strtotime('-4 days')));
    $order107 = $seedOrder('WC-DEMO-107', $customerId, $marcusId, 'Pitch Deck Redesign', 'cancelled', 'We decided to pause the pitch deck project for now.', 1800, 0, 1800, date('Y-m-d H:i:s', strtotime('+2 days')), date('Y-m-d H:i:s', strtotime('-6 days')));

    $insertPayment = $pdo->prepare('INSERT INTO payments (order_id,amount,method,status,transaction_ref,is_demo,paid_at) SELECT ?,?,?,?,?,1,? WHERE NOT EXISTS (SELECT 1 FROM payments WHERE order_id=?)');
    $seedPayment = static function (int $orderId, float $amount, string $method, string $reference, string $paidAt) use ($insertPayment): void {
        $insertPayment->execute([$orderId, $amount, $method, 'paid', $reference, $paidAt, $orderId]);
    };
    $seedPayment($order101, 1350, 'promptpay', 'PAY-DEMO-201', date('Y-m-d H:i:s', strtotime('-5 days')));
    $seedPayment($order102, 1200, 'card', 'PAY-DEMO-202', date('Y-m-d H:i:s', strtotime('-3 days')));
    $seedPayment($order103, 3500, 'card', 'PAY-DEMO-203', date('Y-m-d H:i:s', strtotime('-16 days')));
    $seedPayment($order104, 2000, 'bank', 'PAY-DEMO-204', date('Y-m-d H:i:s', strtotime('-2 days')));
    $seedPayment($order105, 900, 'promptpay', 'PAY-DEMO-205', date('Y-m-d H:i:s', strtotime('-12 days')));
    $seedPayment($order106, 2400, 'card', 'PAY-DEMO-206', date('Y-m-d H:i:s', strtotime('-4 days')));

    $insertMessage = $pdo->prepare('INSERT INTO messages (order_id,sender_id,receiver_id,body,attachment,is_read,is_demo,created_at) SELECT ?,?,?,?,?,?,1,? WHERE NOT EXISTS (SELECT 1 FROM messages WHERE order_id=? AND sender_id=? AND receiver_id=? AND body=?)');
    $seedMessage = static function (int $orderId, int $senderId, int $receiverId, string $body, bool $read, string $createdAt, string $attachment = '') use ($insertMessage): void {
        $insertMessage->execute([$orderId, $senderId, $receiverId, $body, $attachment, $read ? 1 : 0, $createdAt, $orderId, $senderId, $receiverId, $body]);
    };
    $seedMessage($order101, $sellerId, $customerId, 'I completed the responsive header and started the service section.', false, date('Y-m-d H:i:s', strtotime('-35 minutes')));
    $seedMessage($order101, $customerId, $sellerId, 'Looks good. Please keep the call-to-action copy concise.', true, date('Y-m-d H:i:s', strtotime('-1 day')));
    $seedMessage($order102, $sellerId, $customerId, 'I added captions and synced the music to the intro cut.', false, date('Y-m-d H:i:s', strtotime('-7 hours')));
    $seedMessage($order102, $customerId, $sellerId, 'Great, send the version with softer transitions.', false, date('Y-m-d H:i:s', strtotime('-6 hours')));
    $seedMessage($order103, $sellerId, $customerId, 'The store layout and checkout flow are ready for final review.', false, date('Y-m-d H:i:s', strtotime('-2 days')));
    $seedMessage($order103, $customerId, $sellerId, 'Approved. Please archive the final assets in the handoff folder.', true, date('Y-m-d H:i:s', strtotime('-1 day')));
    $seedMessage($order104, $marcusId, $ninaId, 'Happy to start once you confirm the reference style and colors.', false, date('Y-m-d H:i:s', strtotime('-18 hours')));
    $seedMessage($order105, $pimId, $ninaId, 'I tightened the slide structure and added better chart hierarchy.', true, date('Y-m-d H:i:s', strtotime('-11 days')));
    $seedMessage($order106, $sellerId, $ninaId, 'I will deliver the first dashboard screens tomorrow morning.', false, date('Y-m-d H:i:s', strtotime('-3 days')));
    $seedMessage($order106, $ninaId, $sellerId, 'Perfect. Please keep the onboarding steps minimal.', false, date('Y-m-d H:i:s', strtotime('-3 days')));

    $insertNotification = $pdo->prepare('INSERT INTO notifications (user_id,type,title,body,link,is_read,is_demo,created_at) SELECT ?,?,?,?,?,?,1,? WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id=? AND title=? AND body=? AND link=?)');
    $seedNotification = static function (int $userId, string $type, string $title, string $body, string $link, bool $read, string $createdAt) use ($insertNotification): void {
        $insertNotification->execute([$userId, $type, $title, $body, $link, $read ? 1 : 0, $createdAt, $userId, $title, $body, $link]);
    };
    $seedNotification($customerId, 'order', 'Order updated', 'Responsive Website Design is now in progress.', '?page=orders', false, date('Y-m-d H:i:s', strtotime('-5 days')));
    $seedNotification($customerId, 'message', 'New message from Ananya', 'Your seller sent a project update.', '?page=messages&order=' . $order101, false, date('Y-m-d H:i:s', strtotime('-35 minutes')));
    $seedNotification($customerId, 'payment', 'Wallet topped up', 'Your demo wallet balance was increased.', '?page=topup', true, date('Y-m-d H:i:s', strtotime('-10 days')));
    $seedNotification($customerId, 'review', 'Project completed', 'Your completed order is ready for a review.', '?page=orders', false, date('Y-m-d H:i:s', strtotime('-16 days')));
    $seedNotification($ninaId, 'order', 'New project started', 'Your brand identity project is waiting for your input.', '?page=orders', false, date('Y-m-d H:i:s', strtotime('-2 days')));
    $seedNotification($ninaId, 'message', 'Message from Marcus', 'Marcus is ready to begin the next revision.', '?page=messages&order=' . $order104, false, date('Y-m-d H:i:s', strtotime('-18 hours')));
    $seedNotification($sellerId, 'order', 'New order received', 'Alex placed an order for Responsive Website Design.', '?page=seller-orders', false, date('Y-m-d H:i:s', strtotime('-5 days')));
    $seedNotification($sellerId, 'order', 'Approval needed', 'A new seller profile is waiting for review.', '?page=admin-users', false, date('Y-m-d H:i:s', strtotime('-1 day')));
    $seedNotification($marcusId, 'order', 'Order on hold', 'Nina has a new brand request ready.', '?page=seller-orders', false, date('Y-m-d H:i:s', strtotime('-2 days')));
    $seedNotification($adminId, 'account', 'Seller approval required', 'Somchai Review requested seller access.', '?page=admin-users', false, date('Y-m-d H:i:s', strtotime('-6 hours')));

    $insertFavorite = $pdo->prepare('INSERT INTO favorites (user_id,service_id) VALUES (?,?) ON CONFLICT(user_id,service_id) DO NOTHING');
    foreach ([
        [$customerId, 'Responsive Website Design'],
        [$customerId, 'E-Commerce Website'],
        [$customerId, 'Short Video Editing'],
        [$ninaId, 'Brand Identity & Logo'],
        [$ninaId, 'Resume & CV Design'],
        [$ninaId, 'Pitch Deck Redesign'],
    ] as [$userId, $serviceTitle]) {
        $serviceId = (int) $pdo->query('SELECT id FROM services WHERE title=' . $pdo->quote($serviceTitle) . ' AND is_demo=1 LIMIT 1')->fetchColumn();
        if ($serviceId) {
            $insertFavorite->execute([$userId, $serviceId]);
        }
    }

    $insertWallet = $pdo->prepare('INSERT INTO wallet_transactions (user_id,amount,method,status,reference,note,is_demo,created_at) SELECT ?,?,?,?,?,?,1,? WHERE NOT EXISTS (SELECT 1 FROM wallet_transactions WHERE reference=?)');
    $seedWallet = static function (int $userId, float $amount, string $method, string $reference, string $note, string $createdAt) use ($insertWallet): void {
        $insertWallet->execute([$userId, $amount, $method, 'completed', $reference, $note, $createdAt, $reference]);
    };
    $seedWallet($customerId, 2000, 'promptpay', 'TOP-DEMO-501', 'PromptPay top up for customer demo account.', date('Y-m-d H:i:s', strtotime('-10 days')));
    $seedWallet($customerId, 500, 'card', 'TOP-DEMO-502', 'Small card top up after a completed project.', date('Y-m-d H:i:s', strtotime('-4 days')));
    $seedWallet($ninaId, 1800, 'bank', 'TOP-DEMO-503', 'Bank top up for a second customer workflow.', date('Y-m-d H:i:s', strtotime('-3 days')));

    $insertLog = $pdo->prepare('INSERT INTO security_logs (user_id,event,ip_address,created_at) SELECT ?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM security_logs WHERE user_id IS ? AND event=? AND ip_address=? AND created_at=?)');
    $seedLog = static function (?int $userId, string $event, string $ipAddress, string $createdAt) use ($insertLog): void {
        $insertLog->execute([$userId, $event, $ipAddress, $createdAt, $userId, $event, $ipAddress, $createdAt]);
    };
    $seedLog($adminId, 'login_success', '10.0.0.11', date('Y-m-d H:i:s', strtotime('-1 day')));
    $seedLog($adminId, 'seller_approved', '10.0.0.11', date('Y-m-d H:i:s', strtotime('-20 hours')));
    $seedLog($adminId, 'broadcast_sent', '10.0.0.11', date('Y-m-d H:i:s', strtotime('-12 hours')));
    $seedLog($sellerId, 'profile_updated', '10.0.0.24', date('Y-m-d H:i:s', strtotime('-4 days')));
    $seedLog($customerId, 'login_success', '10.0.0.30', date('Y-m-d H:i:s', strtotime('-5 days')));
    $seedLog($customerId, 'wallet_topup', '10.0.0.30', date('Y-m-d H:i:s', strtotime('-4 days')));
    $seedLog($ninaId, 'login_success', '10.0.0.31', date('Y-m-d H:i:s', strtotime('-3 days')));

    $userIds = [
        'customer' => $customerId,
        'seller' => $sellerId,
        'admin' => $adminId,
        'marcus' => $marcusId,
        'pim' => $pimId,
        'nina' => $ninaId,
        'somchai' => $somchaiId,
    ];
    $seedHistoricalProject = static function (
        string $orderNumber,
        string $customerRole,
        string $sellerRole,
        string $serviceTitle,
        string $status,
        string $requirements,
        float $subtotal,
        float $discount,
        float $total,
        string $createdAt,
        string $dueAt,
        array $messages = [],
        ?array $payment = null,
        ?array $review = null,
        array $notifications = [],
        ?array $wallet = null,
        array $logs = [],
        string $couponCode = ''
    ) use (
        $pdo,
        $userIds,
        $insertOrder,
        $insertMessage,
        $insertNotification,
        $insertPayment,
        $insertWallet,
        $insertLog
    ): int {
        $serviceId = (int) $pdo->query('SELECT id FROM services WHERE title=' . $pdo->quote($serviceTitle) . ' AND is_demo=1 LIMIT 1')->fetchColumn();
        $customerId = $userIds[$customerRole];
        $sellerId = $userIds[$sellerRole];
        $insertOrder->execute([$orderNumber, $customerId, $sellerId, $serviceId, $status, $requirements, $subtotal, $discount, $total, $dueAt, $couponCode, $createdAt, $createdAt, $orderNumber]);
        $orderId = (int) $pdo->query('SELECT id FROM orders WHERE order_number=' . $pdo->quote($orderNumber) . ' LIMIT 1')->fetchColumn();

        foreach ($messages as [$senderRole, $receiverRole, $body, $read, $messageAt, $attachment]) {
            $insertMessage->execute([
                $orderId,
                $userIds[$senderRole],
                $userIds[$receiverRole],
                $body,
                $attachment ?? '',
                $read ? 1 : 0,
                $messageAt,
                $orderId,
                $userIds[$senderRole],
                $userIds[$receiverRole],
                $body,
            ]);
        }

        foreach ($notifications as [$userRole, $type, $title, $body, $link, $read, $createdAtNotification]) {
            $insertNotification->execute([
                $userIds[$userRole],
                $type,
                $title,
                $body,
                $link,
                $read ? 1 : 0,
                $createdAtNotification,
                $userIds[$userRole],
                $title,
                $body,
                $link,
            ]);
        }

        if ($payment) {
            $insertPayment->execute([
                $orderId,
                $payment['amount'] ?? $total,
                $payment['method'] ?? 'card',
                $payment['status'] ?? 'paid',
                $payment['reference'] ?? ('PAY-' . $orderNumber),
                $payment['paid_at'] ?? $createdAt,
                $orderId,
            ]);
        }

        if ($review) {
            $pdo->prepare('INSERT INTO reviews (order_id,customer_id,seller_id,rating,comment,is_demo,created_at) VALUES (?,?,?,?,?,1,?) ON CONFLICT(order_id) DO UPDATE SET customer_id=excluded.customer_id,seller_id=excluded.seller_id,rating=excluded.rating,comment=excluded.comment,is_demo=excluded.is_demo,created_at=excluded.created_at')
                ->execute([$orderId, $customerId, $sellerId, $review['rating'] ?? 5, $review['comment'] ?? 'Excellent work and clear communication.', $review['created_at'] ?? $createdAt]);
        }

        if ($wallet) {
            $insertWallet->execute([
                $userIds[$wallet['user_role'] ?? $customerRole],
                $wallet['amount'] ?? 0,
                $wallet['method'] ?? 'card',
                $wallet['status'] ?? 'completed',
                $wallet['reference'] ?? ('TOP-' . $orderNumber),
                $wallet['note'] ?? '',
                $wallet['created_at'] ?? $createdAt,
                $wallet['reference'] ?? ('TOP-' . $orderNumber),
            ]);
        }

        foreach ($logs as [$userRole, $event, $ipAddress, $createdAtLog]) {
            $insertLog->execute([
                $userIds[$userRole] ?? null,
                $event,
                $ipAddress,
                $createdAtLog,
                $userIds[$userRole] ?? null,
                $event,
                $ipAddress,
                $createdAtLog,
            ]);
        }

        return $orderId;
    };

    $seedHistoricalProject(
        'WC-DEMO-201',
        'customer',
        'marcus',
        'Social Media Post Set',
        'completed',
        'Create a six-post awareness set for a local wellness clinic.',
        1600,
        0,
        1600,
        $demoDate(6, 6, '09:30:00'),
        $demoDate(6, 12, '17:00:00'),
        [
            ['marcus', 'customer', 'Draft one is ready with softer colors and larger CTA text.', false, $demoDate(6, 8, '11:15:00'), ''],
            ['customer', 'marcus', 'Looks good. Keep the headline shorter on the final set.', true, $demoDate(6, 8, '15:40:00'), ''],
        ],
        ['amount' => 1600, 'method' => 'promptpay', 'reference' => 'PAY-DEMO-301', 'paid_at' => $demoDate(6, 12, '18:10:00')],
        ['rating' => 5, 'comment' => 'The pack felt polished and matched the brief closely.'],
        [
            ['customer', 'review', 'Project delivered', 'Social Media Post Set moved to review.', '?page=orders', false, $demoDate(6, 12, '18:15:00')],
            ['marcus', 'payment', 'Payment received', 'A completed order was paid successfully.', '?page=seller-orders', true, $demoDate(6, 12, '18:20:00')],
        ],
        ['user_role' => 'customer', 'amount' => 1200, 'method' => 'promptpay', 'reference' => 'TOP-DEMO-601', 'note' => 'Top up for upcoming content work.', 'created_at' => $demoDate(6, 1, '08:00:00')],
        [
            ['admin', 'audit_export', '10.0.0.11', $demoDate(6, 2, '09:00:00')],
        ]
    );

    $seedHistoricalProject(
        'WC-DEMO-202',
        'nina',
        'pim',
        'Resume & CV Design',
        'completed',
        'Refresh my resume for a product designer role and keep the layout ATS-friendly.',
        700,
        0,
        700,
        $demoDate(5, 10, '10:00:00'),
        $demoDate(5, 14, '17:00:00'),
        [
            ['pim', 'nina', 'I cleaned up the summary section and aligned the bullet rhythm.', false, $demoDate(5, 11, '12:20:00'), ''],
            ['nina', 'pim', 'Nice. Keep the skills section compact and easy to scan.', true, $demoDate(5, 11, '16:50:00'), ''],
        ],
        ['amount' => 700, 'method' => 'card', 'reference' => 'PAY-DEMO-302', 'paid_at' => $demoDate(5, 14, '18:30:00')],
        ['rating' => 5, 'comment' => 'Very clear layout and fast communication.'],
        [
            ['nina', 'review', 'Resume order completed', 'Your resume order is ready for final approval.', '?page=orders', false, $demoDate(5, 14, '18:35:00')],
        ],
        ['user_role' => 'nina', 'amount' => 1800, 'method' => 'bank', 'reference' => 'TOP-DEMO-602', 'note' => 'Bank top up for a new client workflow.', 'created_at' => $demoDate(5, 2, '09:10:00')],
        [
            ['admin', 'login_success', '10.0.0.11', $demoDate(5, 2, '09:00:00')],
        ]
    );

    $seedHistoricalProject(
        'WC-DEMO-203',
        'customer',
        'seller',
        'E-Commerce Website',
        'in_progress',
        'Build a compact store for handmade stationery with product pages and checkout-ready structure.',
        3500,
        150,
        3350,
        $demoDate(4, 15, '09:00:00'),
        $demoDate(4, 28, '17:00:00'),
        [
            ['seller', 'customer', 'I mapped the product catalog and started the cart flow.', false, $demoDate(4, 16, '11:00:00'), ''],
            ['customer', 'seller', 'Please keep the homepage simple and focus on product clarity.', true, $demoDate(4, 16, '15:30:00'), ''],
        ],
        null,
        null,
        [
            ['customer', 'order', 'Work started', 'Your e-commerce project is now in progress.', '?page=orders', false, $demoDate(4, 15, '09:20:00')],
        ],
        ['user_role' => 'customer', 'amount' => 2500, 'method' => 'promptpay', 'reference' => 'TOP-DEMO-603', 'note' => 'Top up for the store project deposit.', 'created_at' => $demoDate(4, 15, '08:45:00')],
        [
            ['customer', 'wallet_topup', '10.0.0.30', $demoDate(4, 15, '08:50:00')],
        ]
    );

    $seedHistoricalProject(
        'WC-DEMO-204',
        'nina',
        'seller',
        'Mobile App UI Design',
        'review',
        'Design onboarding, dashboard, and settings screens for a booking app.',
        2400,
        0,
        2400,
        $demoDate(3, 8, '09:00:00'),
        $demoDate(3, 19, '17:00:00'),
        [
            ['seller', 'nina', 'The first screen set is ready and the icon style is consistent.', false, $demoDate(3, 10, '12:45:00'), ''],
            ['nina', 'seller', 'Great. Keep the system spacing a little wider on mobile.', false, $demoDate(3, 10, '18:05:00'), ''],
        ],
        ['amount' => 2400, 'method' => 'card', 'reference' => 'PAY-DEMO-304', 'paid_at' => $demoDate(3, 19, '18:10:00')],
        ['rating' => 4, 'comment' => 'Strong progress and clean component structure.'],
        [
            ['nina', 'review', 'App UI in review', 'Your app UI delivery is waiting for final feedback.', '?page=orders', false, $demoDate(3, 19, '18:15:00')],
        ],
        null,
        [
            ['seller', 'profile_updated', '10.0.0.24', $demoDate(3, 10, '19:00:00')],
        ]
    );

    $seedHistoricalProject(
        'WC-DEMO-205',
        'customer',
        'seller',
        'Short Video Editing',
        'completed',
        'Edit a product teaser for social channels with captions and tighter cuts.',
        1200,
        0,
        1200,
        $demoDate(2, 12, '10:00:00'),
        $demoDate(2, 16, '17:00:00'),
        [
            ['seller', 'customer', 'The cut is done and I added subtitles for the product highlights.', false, $demoDate(2, 13, '14:15:00'), ''],
            ['customer', 'seller', 'Looks good. Please send the version with softer transitions.', true, $demoDate(2, 13, '19:40:00'), ''],
        ],
        ['amount' => 1200, 'method' => 'card', 'reference' => 'PAY-DEMO-305', 'paid_at' => $demoDate(2, 16, '18:05:00')],
        ['rating' => 5, 'comment' => 'Quick turnaround and clean edits.'],
        [
            ['customer', 'payment', 'Payment received', 'The video order has been paid in full.', '?page=orders', true, $demoDate(2, 16, '18:10:00')],
        ],
        ['user_role' => 'customer', 'amount' => 500, 'method' => 'card', 'reference' => 'TOP-DEMO-604', 'note' => 'Small top up before the final review.', 'created_at' => $demoDate(2, 10, '08:30:00')],
        [
            ['customer', 'wallet_topup', '10.0.0.30', $demoDate(2, 10, '08:35:00')],
        ]
    );

    $seedHistoricalProject(
        'WC-DEMO-206',
        'nina',
        'marcus',
        'Brand Identity & Logo',
        'completed',
        'Refresh the visual identity for a wellness studio with a calm and modern look.',
        2000,
        0,
        2000,
        $demoDate(1, 5, '09:30:00'),
        $demoDate(1, 12, '17:00:00'),
        [
            ['marcus', 'nina', 'The logo suite and palette are ready for the final pass.', false, $demoDate(1, 6, '13:10:00'), ''],
            ['nina', 'marcus', 'Looks strong. Please keep the typography a little softer.', true, $demoDate(1, 6, '17:45:00'), ''],
        ],
        ['amount' => 2000, 'method' => 'promptpay', 'reference' => 'PAY-DEMO-306', 'paid_at' => $demoDate(1, 12, '18:15:00')],
        ['rating' => 5, 'comment' => 'Very clean brand direction and nice details.'],
        [
            ['nina', 'review', 'Brand project completed', 'Your brand identity project is ready for download.', '?page=orders', false, $demoDate(1, 12, '18:20:00')],
        ],
        ['user_role' => 'nina', 'amount' => 800, 'method' => 'promptpay', 'reference' => 'TOP-DEMO-605', 'note' => 'Top up for the last design round.', 'created_at' => $demoDate(1, 4, '08:00:00')],
        [
            ['admin', 'broadcast_sent', '10.0.0.11', $demoDate(1, 7, '09:00:00')],
        ]
    );

    $pdo->exec("INSERT INTO schema_meta (meta_key,meta_value) VALUES ('demo_dataset_v3','complete') ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value");
}

function demo_is_installed(): bool
{
    return (bool) db()->query('SELECT COUNT(*) FROM users WHERE is_demo=1')->fetchColumn();
}

function install_demo_data(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_demo=1')->fetchColumn() > 0) {
        throw new RuntimeException('Demo data is already installed.');
    }
    $demoEmails = ['customer@workconnect.test','seller@workconnect.test','admin@workconnect.test','marcus@workconnect.test','pim@workconnect.test','nina@workconnect.test','somchai.review@workconnect.test'];
    $placeholders = implode(',', array_fill(0, count($demoEmails), '?'));
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN ($placeholders)");
    $check->execute($demoEmails);
    if ((int) $check->fetchColumn() > 0) throw new RuntimeException('A demo email is already used by a real account. Rename that account before installing demo data.');

    $pdo->beginTransaction();
    try {
        $insertUser = $pdo->prepare('INSERT INTO users (role_id,name,email,password_hash,phone,bio,is_demo) VALUES ((SELECT id FROM roles WHERE name=?),?,?,?,?,?,1)');
        $password = password_hash('Demo1234!', PASSWORD_DEFAULT);
        $insertUser->execute(['customer','Alex Morgan','customer@workconnect.test',$password,'089-555-0101','Small business owner building useful digital products.']);
        $insertUser->execute(['seller','Ananya Prasert','seller@workconnect.test',$password,'089-555-0202','Digital designer and web developer focused on clear, useful experiences.']);
        $insertUser->execute(['admin','Narin Admin','admin@workconnect.test',$password,'089-555-0303','WorkConnect platform administrator.']);
        $insertUser->execute(['seller','Marcus Tan','marcus@workconnect.test',$password,'','Brand designer helping teams communicate with confidence.']);
        $insertUser->execute(['seller','Pimchanok Lee','pim@workconnect.test',$password,'','Presentation and document specialist.']);

        $sellerId = (int) $pdo->query("SELECT id FROM users WHERE email='seller@workconnect.test'")->fetchColumn();
        $marcusId = (int) $pdo->query("SELECT id FROM users WHERE email='marcus@workconnect.test'")->fetchColumn();
        $pimId = (int) $pdo->query("SELECT id FROM users WHERE email='pim@workconnect.test'")->fetchColumn();
        $service = $pdo->prepare('INSERT INTO services (seller_id,category_id,title,description,price,delivery_days,features,thumbnail,views,is_demo) VALUES (?,(SELECT id FROM categories WHERE name=?),?,?,?,?,?,?,?,1)');
        $rows = [
            [$sellerId,'Website & App','Responsive Website Design','A polished, responsive business website designed around your goals and content.',1500,7,"Responsive layout\nContact form\nBasic SEO\nTwo revisions",'website',328],
            [$marcusId,'Graphic Design','Brand Identity & Logo','A practical visual identity system that makes your business recognizable.',2000,5,"Logo suite\nColor system\nTypography guide\nSource files",'brand',241],
            [$pimId,'Document Services','PowerPoint Presentation','Clear presentation design for pitches, classes, and project reports.',900,3,"Up to 15 slides\nEditable source\nCharts and icons\nTwo revisions",'slides',198],
            [$sellerId,'Media Production','Short Video Editing','Sharp, engaging short-form editing for TikTok, Reels, and product stories.',1200,4,"Up to 60 seconds\nCaptions\nMusic sync\nColor correction",'video',176],
            [$sellerId,'Website & App','E-Commerce Website','A focused online store with catalog, cart, and checkout-ready structure.',3500,14,"Product catalog\nCart experience\nMobile responsive\nAdmin handoff",'commerce',412],
            [$marcusId,'Graphic Design','Social Media Post Set','A cohesive set of social visuals prepared for your channels.',800,3,"10 post designs\nEditable templates\nTwo sizes\nOne revision",'social',133],
            [$pimId,'Document Services','Resume & CV Design','A professional resume system that keeps your experience easy to scan.',700,2,"ATS-friendly layout\nEditable file\nPDF export\nOne revision",'resume',162],
            [$sellerId,'Media Production','Photo Retouching','Natural, careful retouching for portraits and product photography.',600,2,"Five images\nColor correction\nSkin cleanup\nHigh-resolution export",'photo',117],
        ];
        foreach ($rows as $row) $service->execute($row);

        $pdo->exec("INSERT INTO coupons (code,discount_percent,active,expires_at,is_demo) VALUES ('WELCOME10',10,1,'2030-12-31 23:59:59',1),('STUDENT15',15,1,'2030-12-31 23:59:59',1)");
        $customerId = (int) $pdo->query("SELECT id FROM users WHERE email='customer@workconnect.test'")->fetchColumn();
        $websiteId = (int) $pdo->query("SELECT id FROM services WHERE title='Responsive Website Design' AND is_demo=1")->fetchColumn();
        $videoId = (int) $pdo->query("SELECT id FROM services WHERE title='Short Video Editing' AND is_demo=1")->fetchColumn();
        $shopId = (int) $pdo->query("SELECT id FROM services WHERE title='E-Commerce Website' AND is_demo=1")->fetchColumn();
        $order = $pdo->prepare('INSERT INTO orders (order_number,customer_id,seller_id,service_id,status,requirements,subtotal,total,due_at,created_at,is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
        $order->execute(['WC-DEMO-101',$customerId,$sellerId,$websiteId,'in_progress','Create a responsive landing page for a local tutoring studio.',1500,1500,date('Y-m-d H:i:s',strtotime('+5 days')),date('Y-m-d H:i:s',strtotime('-5 days'))]);
        $order->execute(['WC-DEMO-102',$customerId,$sellerId,$videoId,'review','Edit a 45-second product introduction with captions.',1200,1200,date('Y-m-d H:i:s',strtotime('+1 day')),date('Y-m-d H:i:s',strtotime('-3 days'))]);
        $order->execute(['WC-DEMO-103',$customerId,$sellerId,$shopId,'completed','Build a small catalog store for handmade stationery.',3500,3500,date('Y-m-d H:i:s',strtotime('-2 days')),date('Y-m-d H:i:s',strtotime('-16 days'))]);
        $orderId = (int) $pdo->query("SELECT id FROM orders WHERE order_number='WC-DEMO-101'")->fetchColumn();
        $message = $pdo->prepare('INSERT INTO messages (order_id,sender_id,receiver_id,body,is_read,created_at,is_demo) VALUES (?,?,?,?,?,?,1)');
        $message->execute([$orderId,$sellerId,$customerId,'I completed the responsive header and started the service section.',0,date('Y-m-d H:i:s',strtotime('-35 minutes'))]);
        $message->execute([$orderId,$customerId,$sellerId,'Looks good. Please keep the call-to-action copy concise.',1,date('Y-m-d H:i:s',strtotime('-1 day'))]);
        $notification = $pdo->prepare('INSERT INTO notifications (user_id,type,title,body,link,is_read,is_demo) VALUES (?,?,?,?,?,?,1)');
        $notification->execute([$customerId,'order','Order updated','Responsive Website Design is now in progress.','?page=orders',0]);
        $notification->execute([$customerId,'message','New message from Ananya','Your seller sent a project update.','?page=messages&order='.$orderId,0]);
        $notification->execute([$sellerId,'order','New order received','Alex placed an order for Responsive Website Design.','?page=seller-orders',0]);
        $pdo->exec("INSERT INTO payments (order_id,amount,method,status,transaction_ref,paid_at,is_demo) SELECT id,total,'card','paid','PAY-DEMO-103',CURRENT_TIMESTAMP,1 FROM orders WHERE order_number='WC-DEMO-103'");
        $pdo->exec("INSERT INTO reviews (order_id,customer_id,seller_id,rating,comment,is_demo) SELECT id,customer_id,seller_id,5,'Clear communication and thoughtful work throughout the project.',1 FROM orders WHERE order_number='WC-DEMO-103'");
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    ensure_enhanced_demo_data($pdo);
}

function clear_demo_data(PDO $pdo): array
{
    $files = [];
    foreach ([
        'SELECT avatar path FROM users WHERE is_demo=1',
        'SELECT id_card_front path FROM users WHERE is_demo=1',
        'SELECT id_card_back path FROM users WHERE is_demo=1',
        'SELECT thumbnail path FROM services WHERE is_demo=1',
        'SELECT attachment path FROM messages WHERE is_demo=1',
        'SELECT slip_path path FROM wallet_transactions WHERE is_demo=1',
        'SELECT order_deliveries.attachment path FROM order_deliveries JOIN orders ON orders.id=order_deliveries.order_id WHERE orders.is_demo=1',
        'SELECT dispute_evidence.attachment path FROM dispute_evidence JOIN disputes ON disputes.id=dispute_evidence.dispute_id JOIN orders ON orders.id=disputes.order_id WHERE orders.is_demo=1',
    ] as $sql) {
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $path = (string) $row['path'];
            if (str_starts_with($path, 'assets/uploads/') || str_starts_with($path, 'storage/private/uploads/')) {
                $files[] = $path;
            }
        }
    }
    $deleted = ['users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_demo=1')->fetchColumn(),'services'=>(int)$pdo->query('SELECT COUNT(*) FROM services WHERE is_demo=1')->fetchColumn(),'orders'=>(int)$pdo->query('SELECT COUNT(*) FROM orders WHERE is_demo=1')->fetchColumn()];
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM ledger_transactions WHERE order_id IN (SELECT id FROM orders WHERE is_demo=1) OR user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM payouts WHERE is_demo=1 OR seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM reviews WHERE is_demo=1 OR order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('DELETE FROM messages WHERE is_demo=1 OR order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('DELETE FROM payments WHERE is_demo=1 OR order_id IN (SELECT id FROM orders WHERE is_demo=1)');
        $pdo->exec('DELETE FROM notifications WHERE is_demo=1 OR user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM orders WHERE is_demo=1 OR service_id IN (SELECT id FROM services WHERE is_demo=1) OR customer_id IN (SELECT id FROM users WHERE is_demo=1) OR seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM services WHERE is_demo=1 OR seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM security_logs WHERE user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM users WHERE is_demo=1');
        $pdo->exec('DELETE FROM coupons WHERE is_demo=1');
        $pdo->exec("DELETE FROM schema_meta WHERE meta_key IN ('demo_tracking_v1','demo_dataset_v2','demo_dataset_v3','ledger_opening_v2')");
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    foreach (array_unique($files) as $file) {
        delete_stored_upload($file);
    }
    return $deleted;
}

/**
 * Retire demo activity while keeping its service catalogue under a real seller.
 * The caller must select an active non-demo seller before any records are changed.
 */
function retire_demo_data(PDO $pdo, int $serviceOwnerId): array
{
    $owner = $pdo->prepare(
        "SELECT users.id,users.email FROM users JOIN roles ON roles.id=users.role_id
         WHERE users.id=? AND roles.name='seller' AND users.status='active' AND users.is_demo=0"
    );
    $owner->execute([$serviceOwnerId]);
    $serviceOwner = $owner->fetch();
    if (!$serviceOwner) {
        throw new RuntimeException('Choose an active, non-demo seller account to own the retained services.');
    }

    $demoUsers = $pdo->query('SELECT id FROM users WHERE is_demo=1')->fetchAll(PDO::FETCH_COLUMN);
    if ($demoUsers === []) {
        throw new RuntimeException('No demo data is installed.');
    }

    $files = [];
    foreach ([
        'SELECT avatar path FROM users WHERE is_demo=1',
        'SELECT id_card_front path FROM users WHERE is_demo=1',
        'SELECT id_card_back path FROM users WHERE is_demo=1',
        'SELECT attachment path FROM messages WHERE is_demo=1',
        'SELECT slip_path path FROM wallet_transactions WHERE is_demo=1',
        'SELECT order_deliveries.attachment path FROM order_deliveries JOIN orders ON orders.id=order_deliveries.order_id WHERE orders.is_demo=1',
        'SELECT dispute_evidence.attachment path FROM dispute_evidence JOIN disputes ON disputes.id=dispute_evidence.dispute_id JOIN orders ON orders.id=disputes.order_id WHERE orders.is_demo=1',
    ] as $sql) {
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $path = (string) $row['path'];
            if (str_starts_with($path, 'assets/uploads/') || str_starts_with($path, 'storage/private/uploads/')) {
                $files[] = $path;
            }
        }
    }

    $summary = [
        'services_retained' => (int) $pdo->query('SELECT COUNT(*) FROM services WHERE is_demo=1')->fetchColumn(),
        'users_removed' => count($demoUsers),
        'orders_removed' => (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE is_demo=1')->fetchColumn(),
        'messages_removed' => (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE is_demo=1')->fetchColumn(),
        'payments_removed' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE is_demo=1')->fetchColumn(),
    ];

    $pdo->beginTransaction();
    try {
        // Record the affected orders before reassigning the retained services.
        $pdo->exec('CREATE TEMPORARY TABLE IF NOT EXISTS demo_retirement_orders (id INTEGER PRIMARY KEY)');
        $pdo->exec('DELETE FROM demo_retirement_orders');
        $pdo->exec(
            'INSERT INTO demo_retirement_orders (id)
             SELECT id FROM orders WHERE is_demo=1
             OR customer_id IN (SELECT id FROM users WHERE is_demo=1)
             OR seller_id IN (SELECT id FROM users WHERE is_demo=1)'
        );

        $pdo->exec(
            "DELETE FROM ledger_transactions WHERE id IN (
                SELECT transaction_id FROM ledger_entries
                WHERE (owner_type='user' AND owner_id IN (SELECT id FROM users WHERE is_demo=1))
                   OR (owner_type='order' AND owner_id IN (SELECT id FROM demo_retirement_orders))
            ) OR order_id IN (SELECT id FROM demo_retirement_orders)
              OR user_id IN (SELECT id FROM users WHERE is_demo=1)"
        );
        $pdo->exec('DELETE FROM payment_requests WHERE user_id IN (SELECT id FROM users WHERE is_demo=1) OR order_id IN (SELECT id FROM demo_retirement_orders)');
        $pdo->exec('DELETE FROM payouts WHERE is_demo=1 OR seller_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM reviews WHERE is_demo=1 OR order_id IN (SELECT id FROM demo_retirement_orders)');
        $pdo->exec('DELETE FROM messages WHERE is_demo=1 OR order_id IN (SELECT id FROM demo_retirement_orders) OR sender_id IN (SELECT id FROM users WHERE is_demo=1) OR receiver_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM payments WHERE is_demo=1 OR order_id IN (SELECT id FROM demo_retirement_orders)');
        $pdo->exec('DELETE FROM wallet_transactions WHERE is_demo=1 OR user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM notifications WHERE is_demo=1 OR user_id IN (SELECT id FROM users WHERE is_demo=1)');
        $pdo->exec('DELETE FROM orders WHERE id IN (SELECT id FROM demo_retirement_orders)');
        $pdo->exec('DELETE FROM coupons WHERE is_demo=1');
        $pdo->exec('DELETE FROM security_logs WHERE user_id IN (SELECT id FROM users WHERE is_demo=1)');

        $updateServices = $pdo->prepare(
            "UPDATE services SET seller_id=?,is_demo=0,updated_at=CURRENT_TIMESTAMP
             WHERE is_demo=1"
        );
        $updateServices->execute([$serviceOwnerId]);
        $pdo->exec('DELETE FROM users WHERE is_demo=1');
        $pdo->exec("DELETE FROM schema_meta WHERE meta_key IN ('demo_tracking_v1','demo_dataset_v2','demo_dataset_v3','ledger_opening_v2')");
        $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('demo_mode','0') ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP")
            ->execute();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    foreach (array_unique($files) as $file) {
        delete_stored_upload($file);
    }
    return $summary + ['service_owner_email' => (string) $serviceOwner['email']];
}

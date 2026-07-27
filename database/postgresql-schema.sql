BEGIN;

CREATE TABLE IF NOT EXISTS roles (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    role_id BIGINT NOT NULL REFERENCES roles(id),
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    avatar TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    bio TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    email_notifications SMALLINT NOT NULL DEFAULT 1,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    theme TEXT NOT NULL DEFAULT 'light',
    language TEXT NOT NULL DEFAULT 'en',
    text_scale TEXT NOT NULL DEFAULT 'medium',
    ui_scale TEXT NOT NULL DEFAULT 'comfortable',
    wallet_balance NUMERIC(14,2) NOT NULL DEFAULT 0,
    birth_date TEXT DEFAULT '',
    id_card_number TEXT DEFAULT '',
    id_card_front TEXT DEFAULT '',
    id_card_back TEXT DEFAULT '',
    verification_notes TEXT DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sessions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    ip_address TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS security_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    event TEXT NOT NULL,
    ip_address TEXT DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS rate_limits (
    rate_key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP
);
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    code TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT 'blue'
);
CREATE TABLE IF NOT EXISTS services (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category_id BIGINT NOT NULL REFERENCES categories(id),
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    price NUMERIC(14,2) NOT NULL,
    delivery_days INTEGER NOT NULL DEFAULT 7,
    features TEXT NOT NULL DEFAULT '',
    thumbnail TEXT NOT NULL DEFAULT 'website',
    status TEXT NOT NULL DEFAULT 'active',
    views INTEGER NOT NULL DEFAULT 0,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS order_status (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS coupons (
    id BIGSERIAL PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    discount_percent INTEGER NOT NULL,
    active SMALLINT NOT NULL DEFAULT 1,
    expires_at TIMESTAMP,
    is_demo SMALLINT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    order_number TEXT NOT NULL UNIQUE,
    customer_id BIGINT NOT NULL REFERENCES users(id),
    seller_id BIGINT NOT NULL REFERENCES users(id),
    service_id BIGINT NOT NULL REFERENCES services(id),
    status TEXT NOT NULL DEFAULT 'pending',
    requirements TEXT NOT NULL,
    subtotal NUMERIC(14,2) NOT NULL,
    discount NUMERIC(14,2) NOT NULL DEFAULT 0,
    total NUMERIC(14,2) NOT NULL,
    due_at TIMESTAMP,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    coupon_code TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS messages (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT REFERENCES orders(id) ON DELETE CASCADE,
    sender_id BIGINT NOT NULL REFERENCES users(id),
    receiver_id BIGINT NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    attachment TEXT DEFAULT '',
    is_read SMALLINT NOT NULL DEFAULT 0,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS payments (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    amount NUMERIC(14,2) NOT NULL,
    method TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'paid',
    transaction_ref TEXT NOT NULL,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount NUMERIC(14,2) NOT NULL,
    method TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed',
    reference TEXT NOT NULL,
    note TEXT DEFAULT '',
    is_demo SMALLINT NOT NULL DEFAULT 0,
    slip_path TEXT DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS notifications (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    link TEXT DEFAULT '',
    is_read SMALLINT NOT NULL DEFAULT 0,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS reviews (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id),
    customer_id BIGINT NOT NULL REFERENCES users(id),
    seller_id BIGINT NOT NULL REFERENCES users(id),
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NOT NULL,
    is_demo SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS favorites (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    service_id BIGINT NOT NULL REFERENCES services(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, service_id)
);
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id BIGSERIAL PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS schema_meta (
    meta_key TEXT PRIMARY KEY,
    meta_value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS payment_requests (
    id BIGSERIAL PRIMARY KEY,
    request_type TEXT NOT NULL,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    service_id BIGINT REFERENCES services(id) ON DELETE SET NULL,
    order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL,
    amount NUMERIC(14,2) NOT NULL,
    currency TEXT NOT NULL DEFAULT 'thb',
    status TEXT NOT NULL DEFAULT 'pending',
    provider TEXT NOT NULL DEFAULT 'stripe',
    provider_session_id TEXT,
    provider_payment_intent TEXT,
    reference_code TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE security_logs ADD COLUMN IF NOT EXISTS target_type TEXT NOT NULL DEFAULT '';
ALTER TABLE security_logs ADD COLUMN IF NOT EXISTS target_id BIGINT;
ALTER TABLE security_logs ADD COLUMN IF NOT EXISTS details_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE security_logs ADD COLUMN IF NOT EXISTS user_agent TEXT NOT NULL DEFAULT '';
ALTER TABLE security_logs ADD COLUMN IF NOT EXISTS request_id TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS wallet_balance_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS id_card_fingerprint TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_role TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_mfa_secret TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_mfa_enabled SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_last_counter BIGINT NOT NULL DEFAULT -1;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS persistent SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP;
ALTER TABLE services ADD COLUMN IF NOT EXISTS price_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE services ADD COLUMN IF NOT EXISTS moderation_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE coupons ADD COLUMN IF NOT EXISTS max_uses INTEGER;
ALTER TABLE coupons ADD COLUMN IF NOT EXISTS per_user_limit INTEGER NOT NULL DEFAULT 1;
ALTER TABLE coupons ADD COLUMN IF NOT EXISTS minimum_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS subtotal_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS total_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS fee_rate_bps INTEGER NOT NULL DEFAULT 1000;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS platform_fee_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS seller_net_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS revision_limit INTEGER NOT NULL DEFAULT 2;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS revision_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE payments ADD COLUMN IF NOT EXISTS amount_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS refunded_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE wallet_transactions ADD COLUMN IF NOT EXISTS amount_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS amount_satang BIGINT NOT NULL DEFAULT 0;
ALTER TABLE payment_requests ADD COLUMN IF NOT EXISTS processing_started_at TIMESTAMP;

CREATE TABLE IF NOT EXISTS ledger_transactions (
    id BIGSERIAL PRIMARY KEY,
    reference TEXT NOT NULL UNIQUE,
    transaction_type TEXT NOT NULL,
    order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL,
    user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS ledger_entries (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES ledger_transactions(id) ON DELETE CASCADE,
    account_code TEXT NOT NULL,
    owner_type TEXT NOT NULL DEFAULT 'platform',
    owner_id BIGINT NOT NULL DEFAULT 0,
    amount_satang BIGINT NOT NULL CHECK (amount_satang <> 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS order_events (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    actor_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    event TEXT NOT NULL,
    from_status TEXT,
    to_status TEXT,
    reason TEXT NOT NULL DEFAULT '',
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id BIGSERIAL PRIMARY KEY,
    coupon_id BIGINT NOT NULL REFERENCES coupons(id) ON DELETE RESTRICT,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    discount_satang BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS payment_provider_events (
    event_id TEXT PRIMARY KEY,
    event_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'processing',
    payload_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 1,
    last_error TEXT NOT NULL DEFAULT '',
    processed_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS payouts (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    amount_satang BIGINT NOT NULL CHECK (amount_satang > 0),
    status TEXT NOT NULL DEFAULT 'requested',
    destination_label TEXT NOT NULL DEFAULT '',
    reference TEXT NOT NULL UNIQUE,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TIMESTAMP,
    paid_at TIMESTAMP,
    rejection_reason TEXT NOT NULL DEFAULT '',
    is_demo SMALLINT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS disputes (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    opened_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    against_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reason TEXT NOT NULL,
    details TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    assigned_to BIGINT REFERENCES users(id) ON DELETE SET NULL,
    resolution TEXT NOT NULL DEFAULT '',
    resolution_action TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP
);
CREATE TABLE IF NOT EXISTS dispute_evidence (
    id BIGSERIAL PRIMARY KEY,
    dispute_id BIGINT NOT NULL REFERENCES disputes(id) ON DELETE CASCADE,
    uploaded_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    note TEXT NOT NULL DEFAULT '',
    attachment TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS order_deliveries (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    message TEXT NOT NULL,
    attachment TEXT NOT NULL DEFAULT '',
    revision_number INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'submitted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS outbox_messages (
    id BIGSERIAL PRIMARY KEY,
    channel TEXT NOT NULL DEFAULT 'email',
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    template TEXT NOT NULL DEFAULT 'plain',
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP,
    last_error TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS job_runs (
    id BIGSERIAL PRIMARY KEY,
    job_name TEXT NOT NULL,
    status TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    finished_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS account_requests (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    notes TEXT NOT NULL DEFAULT '',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_requests_provider_session_id ON payment_requests(provider_session_id) WHERE provider_session_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_payment_requests_provider_payment_intent ON payment_requests(provider_payment_intent) WHERE provider_payment_intent IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_sessions_user_token ON sessions(user_id, token_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read_id ON notifications(user_id, is_read, id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_receiver_read_id ON messages(receiver_id, is_read, id);
CREATE INDEX IF NOT EXISTS idx_messages_order_created ON messages(order_id, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_customer_status ON orders(customer_id, status);
CREATE INDEX IF NOT EXISTS idx_orders_seller_status ON orders(seller_id, status);
CREATE INDEX IF NOT EXISTS idx_orders_service ON orders(service_id);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders(status, created_at);
CREATE INDEX IF NOT EXISTS idx_payments_status_paid ON payments(status, paid_at);
CREATE INDEX IF NOT EXISTS idx_payment_requests_user_status ON payment_requests(user_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_services_status_seller ON services(status, seller_id);
CREATE INDEX IF NOT EXISTS idx_services_category_status ON services(category_id, status);
CREATE INDEX IF NOT EXISTS idx_wallet_transactions_user_status ON wallet_transactions(user_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_security_logs_user_created ON security_logs(user_id, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wallet_transactions_reference ON wallet_transactions(reference);
CREATE INDEX IF NOT EXISTS idx_password_reset_user_expires ON password_reset_tokens(user_id, expires_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_id_card_fingerprint ON users(id_card_fingerprint) WHERE id_card_fingerprint<>'';
CREATE INDEX IF NOT EXISTS idx_security_logs_request_id ON security_logs(request_id);
CREATE INDEX IF NOT EXISTS idx_ledger_entries_account_owner ON ledger_entries(account_code,owner_type,owner_id);
CREATE INDEX IF NOT EXISTS idx_ledger_entries_transaction ON ledger_entries(transaction_id);
CREATE INDEX IF NOT EXISTS idx_order_events_order_created ON order_events(order_id,created_at);
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_coupon_user ON coupon_redemptions(coupon_id,user_id);
CREATE INDEX IF NOT EXISTS idx_provider_events_status_updated ON payment_provider_events(status,updated_at);
CREATE INDEX IF NOT EXISTS idx_payouts_seller_status ON payouts(seller_id,status,requested_at);
CREATE INDEX IF NOT EXISTS idx_disputes_status_updated ON disputes(status,updated_at);
CREATE INDEX IF NOT EXISTS idx_disputes_order ON disputes(order_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_disputes_active_order_unique ON disputes(order_id) WHERE status IN ('open','investigating');
CREATE INDEX IF NOT EXISTS idx_deliveries_order_created ON order_deliveries(order_id,created_at);
CREATE INDEX IF NOT EXISTS idx_outbox_status_next ON outbox_messages(status,next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_job_runs_name_finished ON job_runs(job_name,finished_at);
CREATE INDEX IF NOT EXISTS idx_account_requests_user_type_status ON account_requests(user_id,request_type,status,requested_at);
CREATE UNIQUE INDEX IF NOT EXISTS idx_account_requests_pending_unique ON account_requests(user_id,request_type) WHERE status='pending';

UPDATE users SET wallet_balance_satang=ROUND(COALESCE(wallet_balance,0)*100)::BIGINT WHERE wallet_balance_satang=0 AND COALESCE(wallet_balance,0)<>0;
UPDATE services SET price_satang=ROUND(COALESCE(price,0)*100)::BIGINT WHERE price_satang=0 AND COALESCE(price,0)<>0;
UPDATE orders SET subtotal_satang=ROUND(COALESCE(subtotal,0)*100)::BIGINT,discount_satang=ROUND(COALESCE(discount,0)*100)::BIGINT,total_satang=ROUND(COALESCE(total,0)*100)::BIGINT WHERE total_satang=0 AND COALESCE(total,0)<>0;
UPDATE orders SET platform_fee_satang=ROUND(total_satang*fee_rate_bps/10000.0)::BIGINT,seller_net_satang=total_satang-ROUND(total_satang*fee_rate_bps/10000.0)::BIGINT WHERE total_satang>0 AND seller_net_satang=0;
UPDATE payments SET amount_satang=ROUND(COALESCE(amount,0)*100)::BIGINT WHERE amount_satang=0 AND COALESCE(amount,0)<>0;
UPDATE wallet_transactions SET amount_satang=ROUND(COALESCE(amount,0)*100)::BIGINT WHERE amount_satang=0 AND COALESCE(amount,0)<>0;
UPDATE payment_requests SET amount_satang=ROUND(COALESCE(amount,0)*100)::BIGINT WHERE amount_satang=0 AND COALESCE(amount,0)<>0;

INSERT INTO roles (name,label) VALUES
    ('customer','Customer'),('seller','Seller'),('admin','Administrator')
ON CONFLICT (name) DO NOTHING;
INSERT INTO categories (name,code,color) VALUES
    ('Website & App','WA','blue'),('Graphic Design','GD','violet'),
    ('Document Services','DS','green'),('Media Production','MP','amber')
ON CONFLICT (name) DO NOTHING;
INSERT INTO order_status (name,label,sort_order) VALUES
    ('pending','Pending',1),('in_progress','In progress',2),('review','Needs review',3),
    ('completed','Completed',4),('cancelled','Cancelled',5)
ON CONFLICT (name) DO NOTHING;
INSERT INTO system_settings (setting_key,setting_value) VALUES
    ('site_name','WorkConnect'),('site_tagline','Connect. Collaborate. Succeed.'),
    ('support_email','hello@workconnect.test'),('support_phone',''),
    ('contact_ig','https://www.instagram.com/waa_xzz/'),('currency_symbol','฿'),
    ('platform_fee','10'),('topup_minimum','50'),('topup_slip_required','0'),
    ('maintenance_mode','0'),('registration_open','1'),('seller_auto_approval','0'),
    ('demo_mode','0'),('announcement_banner',''),('announcement_banner_duration','15'),
    ('default_theme','light'),('default_language','en'),('default_text_scale','medium'),
    ('default_ui_scale','comfortable'),('default_email_notifications','1'),
    ('payment_mode','hosted_promptpay'),
    ('payment_instructions','Pay with PromptPay on the Stripe-hosted page. Your wallet is credited automatically after Stripe confirms the payment.'),
    ('bank_account_name',''),('bank_name',''),('bank_account_number',''),('promptpay_id','')
ON CONFLICT (setting_key) DO NOTHING;

UPDATE users SET admin_role='owner' WHERE role_id=(SELECT id FROM roles WHERE name='admin') AND admin_role='';

INSERT INTO schema_meta (meta_key, meta_value) VALUES ('schema_version', '3')
ON CONFLICT (meta_key) DO UPDATE SET meta_value=EXCLUDED.meta_value;

COMMIT;

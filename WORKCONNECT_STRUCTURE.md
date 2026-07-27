# โครงสร้างเว็บไซต์ WorkConnect

เอกสารนี้สรุปโครงสร้างของเว็บไซต์ WorkConnect จากไฟล์จริงในโปรเจกต์ เพื่อใช้เป็นคู่มืออธิบายระบบ, ส่งงาน, หรือใช้ต่อยอดพัฒนาเว็บไซต์

## 1. ภาพรวมระบบ

WorkConnect เป็นเว็บไซต์ marketplace สำหรับเชื่อมต่อลูกค้ากับฟรีแลนซ์ สร้างด้วย PHP 8, SQLite, CSS และ JavaScript โดยใช้โครงสร้างแบบ single entry point คือทุก request เข้าผ่าน `index.php` แล้วเลือกหน้าแสดงผลจากค่า `?page=...`

ระบบแบ่งผู้ใช้เป็น 3 บทบาทหลัก:

- Customer: ลูกค้าที่ค้นหาบริการ, สั่งงาน, ชำระเงิน, ส่งข้อความ, รีวิวงาน
- Seller: ผู้ขาย/ฟรีแลนซ์ที่สร้างบริการ, จัดการคำสั่งซื้อ, คุยกับลูกค้า, ดูรายได้และสถิติ
- Admin: ผู้ดูแลระบบที่จัดการผู้ใช้, บริการ, คำสั่งซื้อ, หมวดหมู่, คูปอง, รายงาน, การเงิน และตั้งค่าระบบ

## 2. โครงสร้างโฟลเดอร์หลัก

```text
WorkConnect/
├── index.php
├── README.md
├── WORKCONNECT_STRUCTURE.md
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── images/
│   │   └── workconnect-hero.png
│   ├── js/
│   │   └── app.js
│   └── uploads/
│       └── .htaccess
├── includes/
│   ├── actions.php
│   ├── app-footer.php
│   ├── app-header.php
│   ├── auth.php
│   ├── data.php
│   ├── database.php
│   ├── footer.php
│   ├── header.php
│   ├── helpers.php
│   └── service-card.php
├── pages/
│   ├── app.php
│   ├── auth.php
│   └── home.php
├── storage/
│   ├── .htaccess
│   └── workconnect.sqlite
└── tests/
    ├── run-smoke.sh
    └── smoke.sh
```

## 3. หน้าที่ของไฟล์สำคัญ

### `index.php`

เป็น entry point หลักของระบบ ทำหน้าที่:

- โหลดไฟล์หลักของระบบ:
  - `includes/database.php`
  - `includes/helpers.php`
  - `includes/auth.php`
  - `includes/actions.php`
- เรียก `db()` เพื่อเชื่อมต่อและเตรียมฐานข้อมูล
- รับ `POST` แล้วส่งไปที่ `handle_post_action()`
- อ่านค่า `$_GET['page']` เพื่อเลือกหน้า
- ตรวจสิทธิ์ผู้ใช้ตามบทบาท
- จัดการ realtime endpoint:
  - `?page=sync` สำหรับ polling JSON
  - `?page=stream` สำหรับ Server-Sent Events
- จัดการ export CSV ของ admin ที่ `?page=admin-export&type=...`
- เลือก layout ที่เหมาะสม:
  - หน้า public ใช้ `includes/header.php`, `pages/home.php`, `includes/footer.php`
  - หน้า login/register ใช้ `pages/auth.php`
  - หน้า app/workspace ใช้ `includes/app-header.php`, `pages/app.php`, `includes/app-footer.php`

### `includes/database.php`

เป็นไฟล์จัดการฐานข้อมูลทั้งหมด ทำหน้าที่:

- เชื่อมต่อ SQLite ผ่าน PDO
- ใช้ database หลักที่ `storage/workconnect.sqlite`
- สร้างตารางทั้งหมดหากยังไม่มี
- เพิ่ม column ใหม่ด้วย `ensure_column()`
- bootstrap ข้อมูลอ้างอิง เช่น roles, categories, order status, system settings
- ติดตั้ง/ลบ demo data

### `includes/auth.php`

เป็นระบบ authentication และ authorization ทำหน้าที่:

- เริ่ม session พร้อม cookie security options
- อ่านผู้ใช้ปัจจุบันด้วย `current_user()`
- login ด้วย `login_user()`
- logout ด้วย `logout_user()`
- บังคับ login ด้วย `require_auth()`
- บังคับ role ด้วย `require_role()`
- กำหนดหน้าแรกหลัง login ด้วย `role_home()`
- สร้างและตรวจ CSRF token
- บันทึก security log

### `includes/actions.php`

เป็นตัวจัดการ action จาก form ทั้งหมดในระบบ โดยรับค่า `action` จาก `POST` แล้ว dispatch ไปยัง function ที่เกี่ยวข้อง เช่น:

- `login`
- `register`
- `logout`
- `place_order`
- `send_message`
- `update_profile`
- `update_preferences`
- `topup_wallet`
- `save_service`
- `delete_service`
- `update_order`
- `submit_review`
- `toggle_favorite`
- `admin_user_status`
- `admin_service_status`
- `admin_category_save`
- `admin_coupon_save`
- `admin_broadcast`
- `admin_settings`
- `install_demo`
- `clear_demo`

ทุก action ผ่าน `verify_csrf()` ก่อน เพื่อป้องกัน CSRF

### `includes/helpers.php`

เป็นไฟล์ helper กลางของระบบ ใช้ซ้ำหลายหน้า เช่น:

- escape HTML ด้วย `e()`
- ดึง/ตั้ง system settings
- แปลข้อความ UI ผ่าน `t()`
- format วันที่, เวลา, เงิน
- สร้าง notification
- จัดการ flash message
- redirect
- upload file
- ตรวจชนิดไฟล์ upload
- สร้าง empty state
- สร้าง icon SVG
- จัดการ UI preferences เช่น theme, language, text scale, ui scale
- สร้าง activity heatmap

### `includes/header.php`

เป็น header สำหรับหน้าสาธารณะ เช่น home, services, about ใช้กับ guest และผู้ใช้ทั่วไป มี:

- logo WorkConnect
- navigation
- search form
- ปุ่มเข้าสู่ระบบ
- ปุ่มสมัครสมาชิก
- announcement banner
- flash message

### `includes/footer.php`

เป็น footer สำหรับ public layout มี:

- brand WorkConnect
- link Explore
- link Company
- link Support
- newsletter subscription form

### `includes/app-header.php`

เป็น header/layout สำหรับหน้าหลัง login หรือ workspace มี:

- topbar
- sidebar navigation ตาม role
- profile/menu
- notification/message badge
- wallet balance
- realtime toggle
- logout form

### `includes/app-footer.php`

เป็น footer สำหรับ app layout และโหลด script ที่ใช้ใน workspace

### `includes/data.php`

เป็นข้อมูลประกอบหน้า home เช่น:

- รายการบริการยอดนิยม
- หมวดหมู่
- ตัวเลข demo
- สถานะ demo data
- สถิติ marketplace

### `includes/service-card.php`

เป็น component สำหรับแสดง card ของบริการ ใช้ซ้ำใน:

- หน้า home
- หน้า services
- หน้า saved services
- search result

### `pages/home.php`

เป็นหน้าแรกของเว็บไซต์ ประกอบด้วย:

- hero section
- search form
- highlight benefit
- demo console
- activity panel
- onboarding cards
- category section
- how it works
- popular services

### `pages/auth.php`

เป็นหน้า login และ register ใช้เงื่อนไขจาก `$page`:

- `?page=login`
- `?page=register`

ฟอร์มในหน้านี้ส่ง `POST action` ไปที่ `includes/actions.php`

### `pages/app.php`

เป็นไฟล์รวมหน้าหลัง login เกือบทั้งหมด โดยใช้ `if / elseif` ตาม `$page` เพื่อ render หน้าต่าง ๆ เช่น:

- about
- privacy
- help-center
- safety
- community
- services
- service-detail
- search
- saved-services
- dashboard
- checkout
- orders
- messages
- notifications
- profile
- settings
- topup
- seller-dashboard
- seller-services
- seller-add-service
- seller-orders
- seller-messages
- seller-earnings
- seller-analytics
- seller-profile
- seller-settings
- admin-users
- admin-services
- admin-orders
- admin-messages
- admin-control
- admin-moderation
- admin-categories
- admin-coupons
- admin-logs
- admin-broadcast
- admin-reports
- admin-finance
- admin-settings

## 4. Routing และสิทธิ์การเข้าถึง

ระบบใช้ query string `?page=...` เพื่อเลือกหน้า

### Guest pages

หน้าเหล่านี้เปิดให้ผู้ใช้ที่ยังไม่ login:

```php
home
login
register
```

### Shared authenticated pages

ต้อง login ก่อนถึงเข้าได้:

```php
about
privacy
help-center
safety
community
services
service-detail
search
messages
notifications
profile
settings
topup
seller-pending
```

### Customer pages

ต้องเป็น role `customer`:

```php
dashboard
checkout
orders
saved-services
topup
```

### Seller pages

ต้องเป็น role `seller`:

```php
seller-dashboard
seller-services
seller-add-service
seller-orders
seller-messages
seller-earnings
seller-analytics
seller-profile
seller-settings
```

ถ้า seller ยังรออนุมัติ จะถูก redirect ไปที่:

```php
?page=seller-pending
```

### Admin pages

ต้องเป็น role `admin`:

```php
admin-users
admin-services
admin-orders
admin-messages
admin-control
admin-moderation
admin-categories
admin-coupons
admin-logs
admin-broadcast
admin-export
admin-reports
admin-finance
admin-settings
```

## 5. Flow การทำงานหลักของระบบ

### 5.1 สมัครสมาชิก

1. ผู้ใช้เปิด `?page=register`
2. กรอกชื่อ, email, password, role
3. form ส่ง `POST action=register`
4. `action_register()` ตรวจ:
   - registration เปิดอยู่หรือไม่
   - email ถูกต้องหรือไม่
   - password ยาวอย่างน้อย 8 ตัว
   - email ซ้ำหรือไม่
   - ถ้า role admin ต้องมี admin code
5. insert ข้อมูลลง `users`
6. login อัตโนมัติ
7. redirect ตาม role

### 5.2 เข้าสู่ระบบ

1. ผู้ใช้เปิด `?page=login`
2. form ส่ง `POST action=login`
3. `action_login()` ตรวจ email/password
4. ถ้าถูกต้องเรียก `login_user()`
5. สร้าง session และบันทึกลง `sessions`
6. redirect ไป workspace ตาม role

### 5.3 ค้นหาและดูบริการ

1. เปิด `?page=services`
2. ระบบ query ตาราง `services`, `categories`, `users`, `reviews`, `orders`
3. สามารถกรองด้วย:
   - keyword `q`
   - category
4. คลิกบริการไปที่ `?page=service-detail&id=...`
5. หน้า service detail แสดง:
   - รายละเอียดบริการ
   - สิ่งที่รวมในแพ็กเกจ
   - รีวิว
   - ราคา
   - seller box
   - ปุ่ม checkout สำหรับ customer

### 5.4 สั่งงาน

1. Customer เปิด `?page=checkout&id=...`
2. กรอก requirements และ coupon ถ้ามี
3. form ส่ง `POST action=place_order`
4. `action_place_order()` ตรวจ:
   - ผู้ใช้เป็น customer
   - service active
   - requirements ยาวพอ
   - coupon ถูกต้องหรือไม่
5. สร้าง order number เช่น `WC-...`
6. insert ลง `orders`
7. insert simulated payment ลง `payments`
8. แจ้ง notification ให้ seller และ customer
9. redirect ไป `?page=orders`

### 5.5 ส่งข้อความ

1. ผู้ใช้เปิดหน้า messages หรือ seller-messages
2. เลือก order conversation
3. form ส่ง `POST action=send_message`
4. ระบบตรวจว่าผู้ใช้เกี่ยวข้องกับ order นั้นหรือเป็น admin
5. สามารถส่ง:
   - ข้อความ
   - รูปภาพ
   - PDF
   - text file
6. insert ลง `messages`
7. ส่ง notification ให้ผู้รับ

### 5.6 Seller จัดการบริการ

Seller ใช้หน้า:

```php
seller-services
seller-add-service
```

workflow:

1. สร้างหรือแก้ไข service
2. form ส่ง `POST action=save_service`
3. ระบบบันทึก:
   - category
   - title
   - description
   - price
   - delivery days
   - features
   - thumbnail/custom upload
4. Seller สามารถลบบริการผ่าน `POST action=delete_service`

### 5.7 Seller จัดการคำสั่งซื้อ

Seller ใช้หน้า:

```php
seller-orders
```

action หลัก:

```php
POST action=update_order
```

ใช้ปรับ status ของ order เช่น:

- pending
- in_progress
- review
- completed
- cancelled

### 5.8 Customer รีวิวงาน

เมื่อ order เหมาะสม Customer สามารถส่ง review ผ่าน:

```php
POST action=submit_review
```

ข้อมูลถูกบันทึกในตาราง `reviews`

### 5.9 Wallet และ Top up

Customer ใช้หน้า:

```php
topup
```

action หลัก:

```php
POST action=topup_wallet
```

ข้อมูลถูกบันทึกใน `wallet_transactions`

Admin ตรวจ top up ผ่าน:

```php
POST action=admin_wallet_review
```

### 5.10 Admin จัดการระบบ

Admin มี workflow หลัก:

- จัดการผู้ใช้: `admin_user_status`
- อนุมัติ/ระงับ service: `admin_service_status`
- เพิ่ม/แก้/ลบ category: `admin_category_save`, `admin_category_delete`
- เพิ่ม/แก้/ลบ coupon: `admin_coupon_save`, `admin_coupon_delete`
- broadcast notification: `admin_broadcast`
- ตั้งค่าระบบ: `admin_settings`
- export CSV: `?page=admin-export&type=...`

## 6. โครงสร้างฐานข้อมูล

ฐานข้อมูลหลักคือ SQLite:

```text
storage/workconnect.sqlite
```

### ตารางหลัก

#### `roles`

เก็บบทบาทของผู้ใช้:

- customer
- seller
- admin

#### `users`

เก็บข้อมูลบัญชีผู้ใช้ เช่น:

- role_id
- name
- email
- password_hash
- avatar
- phone
- bio
- status
- theme
- language
- text_scale
- ui_scale
- wallet_balance
- email_notifications
- is_demo

#### `sessions`

เก็บ session login:

- user_id
- token_hash
- ip_address
- user_agent
- last_activity

#### `security_logs`

เก็บ log ด้านความปลอดภัย:

- login success
- login failed
- logout
- action สำคัญอื่น ๆ

#### `categories`

เก็บหมวดหมู่บริการ:

- name
- code
- color

หมวดเริ่มต้น:

- Website & App
- Graphic Design
- Document Services
- Media Production

#### `services`

เก็บบริการของ seller:

- seller_id
- category_id
- title
- description
- price
- delivery_days
- features
- thumbnail
- status
- views
- is_demo

#### `order_status`

เก็บสถานะคำสั่งซื้อ:

- pending
- in_progress
- review
- completed
- cancelled

#### `coupons`

เก็บคูปองส่วนลด:

- code
- discount_percent
- active
- expires_at
- is_demo

#### `orders`

เก็บคำสั่งซื้อ:

- order_number
- customer_id
- seller_id
- service_id
- status
- requirements
- subtotal
- discount
- total
- due_at
- coupon_code
- is_demo

#### `messages`

เก็บข้อความใน order conversation:

- order_id
- sender_id
- receiver_id
- body
- attachment
- is_read
- is_demo

#### `payments`

เก็บข้อมูล payment จำลอง:

- order_id
- amount
- method
- status
- transaction_ref
- paid_at
- is_demo

#### `wallet_transactions`

เก็บข้อมูลเติมเงิน:

- user_id
- amount
- method
- status
- reference
- note
- slip_path
- is_demo

#### `notifications`

เก็บ notification ในระบบ:

- user_id
- type
- title
- body
- link
- is_read
- is_demo

#### `reviews`

เก็บรีวิว:

- order_id
- customer_id
- seller_id
- rating
- comment
- is_demo

#### `favorites`

เก็บบริการที่ customer บันทึกไว้:

- user_id
- service_id

#### `system_settings`

เก็บค่าระบบ เช่น:

- site_name
- support_email
- currency_symbol
- platform_fee
- topup_minimum
- maintenance_mode
- registration_open
- seller_auto_approval
- demo_mode
- default_theme
- default_language
- admin_registration_code

#### `newsletter_subscribers`

เก็บ email ผู้สมัครรับข่าวสาร

#### `schema_meta`

เก็บสถานะ migration/demo dataset

## 7. Frontend และ Assets

### CSS

ไฟล์หลัก:

```text
assets/css/style.css
```

จัดการ:

- public layout
- app/workspace layout
- dashboard
- service card
- forms
- tables
- dialogs
- notifications
- dark theme
- responsive layout
- mobile/tablet breakpoints

### JavaScript

ไฟล์หลัก:

```text
assets/js/app.js
```

จัดการ:

- mobile menu
- password toggle
- admin code field ใน register
- toast notifications
- flash message to toast
- demo credential autofill
- demo install/clear dialog
- table filter
- preference preview
- localStorage safe wrapper
- realtime notification/message polling
- Server-Sent Events fallback
- amount preset ใน topup
- animation observer

### Images

ไฟล์หลัก:

```text
assets/images/workconnect-hero.png
```

ใช้ใน hero section ของหน้า home

### Uploads

โฟลเดอร์:

```text
assets/uploads/
```

ใช้เก็บไฟล์ upload เช่น:

- avatar
- service thumbnail
- message attachment
- topup slip

มี `.htaccess` เพื่อควบคุมการเข้าถึงไฟล์

## 8. Layout ของเว็บไซต์

### Public layout

ใช้กับ:

- home
- public header/footer

ไฟล์ที่เกี่ยวข้อง:

```text
includes/header.php
pages/home.php
includes/footer.php
```

โครงสร้าง:

```text
Header
└── Logo
└── Navigation
└── Search
└── Login/Register buttons

Main
└── Hero
└── Demo console
└── Activity
└── Onboarding
└── Categories
└── How it works
└── Popular services

Footer
└── Brand
└── Explore links
└── Company links
└── Support links
└── Newsletter form
```

### Authentication layout

ใช้กับ:

- login
- register

ไฟล์:

```text
pages/auth.php
```

### App/workspace layout

ใช้กับหน้าหลัง login:

```text
includes/app-header.php
pages/app.php
includes/app-footer.php
```

โครงสร้าง:

```text
Topbar
└── Logo
└── Role label
└── Search
└── Wallet/notification/profile controls

Sidebar
└── Role-based navigation
└── Logout

Main workspace
└── Page content from pages/app.php
```

## 9. ระบบ Realtime

มี endpoint 2 แบบใน `index.php`

### `?page=sync`

ใช้ตอบ JSON สำหรับ polling:

```json
{
  "notifications": 0,
  "messages": 0,
  "wallet_balance": 0,
  "notification_version": 0
}
```

### `?page=stream`

ใช้ Server-Sent Events ส่งข้อมูล realtime ทุกช่วงเวลา เช่น:

- unread notifications
- unread messages
- wallet balance
- order version

ถ้า browser ใช้ EventSource ไม่ได้หรือ stream error, `assets/js/app.js` จะ fallback เป็น polling

## 10. ระบบ Demo Data

หน้า home มี Demo Console ใช้สำหรับ:

- install demo data
- clear demo data

บัญชี demo:

| Role | Email | Password |
| --- | --- | --- |
| Customer | `customer@workconnect.test` | `Demo1234!` |
| Seller | `seller@workconnect.test` | `Demo1234!` |
| Admin | `admin@workconnect.test` | `Demo1234!` |

Demo data ถูกติดป้ายด้วย `is_demo=1` เพื่อให้ลบออกได้โดยไม่กระทบข้อมูลจริง

## 11. Security

ระบบมีการป้องกันหลัก ๆ ดังนี้:

- ใช้ `password_hash()` สำหรับเก็บ password
- ใช้ `password_verify()` ตอน login
- ใช้ session cookie แบบ `HttpOnly`
- ใช้ `SameSite=Lax`
- ใช้ CSRF token ทุก POST action
- ใช้ prepared statements กับ PDO
- escape output ด้วย `e()`
- ตรวจ role ด้วย `require_role()`
- suspended account ถูก logout และ redirect
- upload จำกัดชนิดไฟล์
- บันทึก security log

## 12. การรันระบบ

วางโปรเจกต์ไว้ใน XAMPP:

```text
/Applications/XAMPP/xamppfiles/htdocs/WorkConnect
```

เปิด Apache แล้วเข้า:

```text
http://localhost/WorkConnect/
```

หรือใช้ ngrok:

```bash
ngrok http 80 --url https://unbeaten-avid-drastic.ngrok-free.dev
```

แล้วเปิด:

```text
https://unbeaten-avid-drastic.ngrok-free.dev/WorkConnect/
```

## 13. Smoke Test

ไฟล์ test:

```text
tests/smoke.sh
tests/run-smoke.sh
```

ตัวอย่างการรัน:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/WorkConnect
BASE_URL=http://127.0.0.1/WorkConnect bash tests/smoke.sh
```

## 14. สรุปภาพรวมสถาปัตยกรรม

```text
Browser
  |
  v
Apache / PHP
  |
  v
index.php
  |
  +-- includes/database.php
  +-- includes/helpers.php
  +-- includes/auth.php
  +-- includes/actions.php
  |
  +-- POST action -> includes/actions.php
  |
  +-- GET page routing
      |
      +-- public page
      |   +-- includes/header.php
      |   +-- pages/home.php
      |   +-- includes/footer.php
      |
      +-- auth page
      |   +-- pages/auth.php
      |
      +-- app page
          +-- includes/app-header.php
          +-- pages/app.php
          +-- includes/app-footer.php
  |
  v
SQLite database
  |
  v
storage/workconnect.sqlite
```

## 15. จุดที่ควรรู้สำหรับการพัฒนาต่อ

- ถ้าจะเพิ่มหน้าใหม่ ให้เพิ่มชื่อ page ใน `index.php` ก่อน
- ถ้าหน้าเป็นของ role เฉพาะ ให้เพิ่มใน `$rolePages`
- ถ้าหน้าเป็น shared page ให้เพิ่มใน `$sharedPages`
- ถ้าต้องมี form POST ให้เพิ่ม action ใน `handle_post_action()`
- ถ้าต้องเพิ่มตารางหรือ column ให้แก้ใน `includes/database.php`
- ถ้าต้องแก้หน้าตา ให้แก้ที่ `assets/css/style.css`
- ถ้าต้องแก้ interaction ฝั่ง browser ให้แก้ที่ `assets/js/app.js`
- ถ้าเพิ่ม upload ใหม่ ให้ใช้ helper `store_upload()` และกำหนดชนิดไฟล์ให้ชัดเจน

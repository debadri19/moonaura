-- ===================================================================
-- MOONAURA CRYSTALS - DATABASE SCHEMA
-- ===================================================================
-- This schema is designed around the existing Google Sheets product
-- database described in README.md.
--
-- How to use this file:
--   1. Create a database (e.g. "moonaura") in MySQL / phpMyAdmin.
--   2. Import this file into that database.
--   3. Update config/config.php with your database name/user/password.
--
-- Naming rule used throughout this project:
--   Table names = plural, lowercase, underscores (e.g. "products")
--   Column names = lowercase, underscores (e.g. "sell_price")
-- ===================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ===================================================================
-- 01. CATEGORIES
-- ===================================================================
-- One row per product category (Bracelets, Rings, Pendants, etc.)
-- "image_folder_name" matches the REAL folder name already inside
-- assets/images/products/ (some are plural, some singular - kept as-is
-- to match the existing uploaded folders exactly).

CREATE TABLE categories (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name                VARCHAR(100)    NOT NULL,               -- e.g. "Bracelets"
    slug                VARCHAR(100)    NOT NULL UNIQUE,        -- e.g. "bracelets"
    image_folder_name   VARCHAR(100)    NOT NULL,               -- e.g. "bracelets" (matches assets/images/products/ folder)

    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,
    status               ENUM('active','inactive') NOT NULL DEFAULT 'active',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 02. PRODUCTS
-- ===================================================================
-- Mirrors the Google Sheets "Product Database" columns directly.

CREATE TABLE products (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identity
    sku                 VARCHAR(50)     NOT NULL UNIQUE,
    name                VARCHAR(150)    NOT NULL,
    slug                VARCHAR(150)    NOT NULL UNIQUE,

    -- Classification (from the Sheet)
    category_id         INT UNSIGNED    NOT NULL,
    crystal_type        VARCHAR(100)    NULL,
    purpose             VARCHAR(255)    NULL,                  -- e.g. "Confidence, Protection, Focus"
    zodiac              VARCHAR(100)    NULL,
    chakra              VARCHAR(100)    NULL,
    variant             VARCHAR(100)    NULL,                  -- e.g. "6mm", "8mm"
    crystal_origin      VARCHAR(150)    NULL,

    -- Images (folder + count convention described in README)
    image_folder        VARCHAR(150)    NOT NULL,              -- e.g. "bracelets/tiger-eye-bracelet"
    image_count         TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Ordering / homepage flags
    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,
    featured            TINYINT(1)      NOT NULL DEFAULT 0,    -- shows in "Featured Products"
    is_bestseller       TINYINT(1)      NOT NULL DEFAULT 0,    -- shows in "Best Sellers"
    certificate_included TINYINT(1)     NOT NULL DEFAULT 1,    -- 1 = show Certificate Included on product page; 0 = hide entirely

    -- Pricing
    mrp                 DECIMAL(10,2)   NOT NULL,
    sell_price          DECIMAL(10,2)   NOT NULL,
    gst_rate            DECIMAL(5,2)    NOT NULL DEFAULT 0.00,  -- GST % (Phase 5F); prices are GST-INCLUSIVE, rate used to DERIVE taxable value/GST, never added on top

    -- Content
    short_description   VARCHAR(500)    NULL,
    full_description    TEXT            NULL,
    care_instructions   TEXT            NULL,
    primary_benefits    VARCHAR(500)    NULL,
    weight              VARCHAR(50)     NULL,                  -- e.g. "8-10 grams"

    -- SEO (from the Sheet)
    meta_title          VARCHAR(255)    NULL,
    meta_description    VARCHAR(500)    NULL,

    -- Inventory
    stock_quantity      INT UNSIGNED    NOT NULL DEFAULT 0,
    stock_status        ENUM('in_stock','out_of_stock','low_stock')
                                         NOT NULL DEFAULT 'in_stock',

    -- Visibility (lets the admin hide a product without deleting it)
    status              ENUM('active','draft') NOT NULL DEFAULT 'active',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_products_category (category_id),
    INDEX idx_products_featured (featured),
    INDEX idx_products_bestseller (is_bestseller),
    INDEX idx_products_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 02B. PRODUCT IMAGES  (added in Phase 1A for real image management)
-- ===================================================================
-- A product can have multiple images, managed from the admin panel
-- (upload, reorder, set primary, delete). This replaces relying on
-- products.image_folder/image_count as the only source of images -
-- those two columns are left in place for backward compatibility but
-- product_images is now the source of truth once images are uploaded.

CREATE TABLE product_images (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    product_id          INT UNSIGNED    NOT NULL,
    file_path           VARCHAR(255)    NOT NULL,              -- e.g. assets/images/products/bracelets/tiger-eye-bracelet/1.webp
    alt_text            VARCHAR(255)    NULL,                  -- optional - image "alt" attribute for SEO/accessibility

    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,
    is_primary          TINYINT(1)      NOT NULL DEFAULT 0,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE,

    INDEX idx_product_images_product (product_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 03. COLLECTIONS  (curated homepage groups, e.g. "Career Success")
-- ===================================================================
-- Separate from "categories" - a collection is a hand-picked marketing
-- grouping that can mix products from different categories.

CREATE TABLE collections (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name                VARCHAR(150)    NOT NULL,               -- e.g. "Career Success Collection"
    slug                VARCHAR(150)    NOT NULL UNIQUE,        -- e.g. "career-success"
    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Pivot table: which products belong to which collection, and in what order.

CREATE TABLE collection_products (

    collection_id       INT UNSIGNED    NOT NULL,
    product_id           INT UNSIGNED    NOT NULL,
    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,

    PRIMARY KEY (collection_id, product_id),

    CONSTRAINT fk_collection_products_collection
        FOREIGN KEY (collection_id) REFERENCES collections(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_collection_products_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- CONCERN CATEGORIES  (Homepage/Frontend UX pass)
-- -------------------------------------------------------------------
-- A dedicated, structured product classification for "Shop By
-- Concern" (homepage cards + /concerns listing pages), deliberately
-- SEPARATE from products.purpose (free-text product-benefit copy
-- shown on the product detail page - untouched by this addition).
-- Modeled on the collections/collection_products pair directly
-- above: same shape, a named/slugged parent table + a many-to-many
-- pivot. concern_categories holds a FIXED vocabulary (the 13
-- approved presets, seeded below) - admins assign products to these,
-- they do not create new ones from the frontend.
-- ===================================================================

CREATE TABLE concern_categories (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name                VARCHAR(100)    NOT NULL,              -- e.g. "Love & Relationships"
    slug                VARCHAR(100)    NOT NULL UNIQUE,        -- e.g. "love-and-relationships"
    display_order       INT UNSIGNED    NOT NULL DEFAULT 0,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_concern_categories_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Pivot table: which products are assigned to which concern(s). A
-- product may belong to more than one concern (e.g. Green Aventurine
-- -> Career Success + Wealth & Prosperity).

CREATE TABLE product_concerns (

    product_id          INT UNSIGNED    NOT NULL,
    concern_category_id INT UNSIGNED    NOT NULL,

    PRIMARY KEY (product_id, concern_category_id),

    CONSTRAINT fk_product_concerns_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_product_concerns_concern
        FOREIGN KEY (concern_category_id) REFERENCES concern_categories(id)
        ON DELETE CASCADE,

    INDEX idx_product_concerns_concern (concern_category_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 04. ADMIN USERS  (foundation for the Admin Panel)
-- ===================================================================

CREATE TABLE admin_users (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name                VARCHAR(100)    NOT NULL,
    email               VARCHAR(150)    NOT NULL UNIQUE,
    password_hash       VARCHAR(255)    NOT NULL,               -- created with PHP password_hash()

    role                ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',

    last_login_at       DATETIME        NULL,

    two_factor_enabled    TINYINT(1)  NOT NULL DEFAULT 0,
    two_factor_secret     VARCHAR(500) NULL,
    two_factor_enabled_at DATETIME    NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 05. ORDERS  (Phase 2A - commerce foundation, no checkout UI yet)
-- ===================================================================
-- Every order is fully self-contained (customer name/email/phone are
-- stored directly here) so GUEST CHECKOUT works with no account
-- system at all. "user_id" is left nullable with NO foreign key yet -
-- there is no users/customers table to reference (out of scope for
-- this phase). When that table is built later, a guest order gets
-- linked to a real account with a single UPDATE on user_id, matched
-- by customer_email or customer_phone - nothing else on the order
-- changes, so order history is never altered by the linking step.
--
-- INVOICE STRATEGY: invoice_number and invoice_generated_at are both
-- nullable. There is deliberately no "invoice file path" column -
-- invoices are meant to be rendered on demand from this order's own
-- data in a future phase, not generated once and stored as a file.

CREATE TABLE orders (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Human-readable identifiers
    order_number        VARCHAR(20)     NOT NULL UNIQUE,         -- e.g. "MOAOD202601290001" (Phase 3 format, daily reset)
    invoice_number       VARCHAR(20)     NULL UNIQUE,            -- e.g. "MOAINV202601290001" - set only once an invoice is generated
    invoice_generated_at DATETIME        NULL,

    -- Customer details (captured directly - NOT a reference to an
    -- accounts table, so this works for guests today and members later)
    customer_name       VARCHAR(150)    NOT NULL,
    customer_email      VARCHAR(150)    NOT NULL,
    customer_phone      VARCHAR(20)     NOT NULL,

    -- Nullable link to a future registered account. No FK constraint
    -- yet - see note above.
    user_id             INT UNSIGNED    NULL,

    -- Money
    currency            CHAR(3)         NOT NULL DEFAULT 'INR',
    gst_rate            DECIMAL(5,2)    NOT NULL DEFAULT 0.00,   -- order-level rate; meaningful when every line shares one rate, else 0.00
    subtotal            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- sum of order_items.line_total, before discount/shipping/gst
    discount            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    shipping_charge     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    gst_amount          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- total DERIVED GST (inclusive pricing - informational, never added on top)
    taxable_value       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- sum of line taxable values (Phase 5F)
    cgst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- intra-state CGST portion (Phase 5F)
    sgst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- intra-state SGST portion (Phase 5F)
    igst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- inter-state IGST portion (Phase 5F)
    tax_type            VARCHAR(10)     NULL,                    -- 'intra'|'inter'; NULL when seller state not configured
    grand_total         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- subtotal - discount + shipping_charge (GST already included in prices)

    -- Status
    payment_status      ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    order_status        ENUM('pending','processing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    payment_method       VARCHAR(50)     NULL,                   -- e.g. "razorpay", "cod" - set once a payment phase exists

    notes               TEXT            NULL,

    -- Shipping / tracking (Phase 5 - set by admin once shipped)
    courier_partner      VARCHAR(100)    NULL,                   -- e.g. "Delhivery", "Blue Dart"
    awb_number           VARCHAR(100)    NULL,                   -- courier AWB / tracking number
    tracking_url         VARCHAR(255)    NULL,                   -- courier tracking page URL

    -- Stock management (Phase 6) - the idempotency marker for the
    -- deduction lifecycle in includes/stock-functions.php. NULL = this
    -- order holds no inventory right now; NOT NULL = stock was deducted
    -- for a confirmed order and is held until it's cancelled.
    stock_deducted_at    DATETIME        NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    -- Indexed for the future guest-to-account linking described above,
    -- and for looking up "my orders" by email/phone before accounts exist.
    INDEX idx_orders_customer_email (customer_email),
    INDEX idx_orders_customer_phone (customer_phone),
    INDEX idx_orders_user_id (user_id),
    INDEX idx_orders_status (order_status),

    -- Phase 2D: customers now exists, so this finally gets its real FK.
    -- ON DELETE SET NULL - deleting a customer account never deletes
    -- their order history, it just becomes an unlinked (guest-like) order.
    CONSTRAINT fk_orders_customer
        FOREIGN KEY (user_id) REFERENCES customers(id)
        ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 06. ORDER ITEMS
-- ===================================================================
-- Stores a full SNAPSHOT of the product at the time of purchase, so
-- editing or even deleting a product later never changes what a past
-- order shows. product_id is kept as a soft reference only (nullable,
-- ON DELETE SET NULL) - useful while the product still exists, never
-- required for the order to display correctly.

CREATE TABLE order_items (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id            INT UNSIGNED    NOT NULL,
    product_id          INT UNSIGNED    NULL,                    -- soft reference only - see note above

    -- Snapshot of the product exactly as it was when ordered
    product_name        VARCHAR(150)    NOT NULL,
    product_slug        VARCHAR(150)    NOT NULL,
    unit_price          DECIMAL(10,2)   NOT NULL,
    quantity            INT UNSIGNED    NOT NULL DEFAULT 1,
    gst_rate            DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
    taxable_value       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- line GST-inclusive total / (1 + rate/100) (Phase 5F)
    cgst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- line intra-state CGST (Phase 5F)
    sgst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- line intra-state SGST (Phase 5F)
    igst_amount         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,   -- line inter-state IGST (Phase 5F)
    line_total          DECIMAL(10,2)   NOT NULL,                -- unit_price * quantity (GST-inclusive)

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE SET NULL,

    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 07. ORDER ADDRESSES
-- ===================================================================
-- One shipping address per order (UNIQUE on order_id). Kept as its
-- own table rather than columns on "orders" so it's easy to extend
-- later (e.g. a future billing address) without reshaping the orders
-- table itself.

CREATE TABLE order_addresses (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id            INT UNSIGNED    NOT NULL UNIQUE,

    full_name           VARCHAR(150)    NOT NULL,
    phone               VARCHAR(20)     NOT NULL,

    address_line1       VARCHAR(255)    NOT NULL,
    address_line2       VARCHAR(255)    NULL,
    landmark            VARCHAR(255)    NULL,                   -- added in Phase 2C (checkout collects this)
    city                VARCHAR(100)    NOT NULL,
    state               VARCHAR(100)    NOT NULL,
    postal_code         VARCHAR(12)     NOT NULL,
    country             VARCHAR(100)    NOT NULL DEFAULT 'India',

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_order_addresses_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 07b. ORDER STATUS HISTORY  (Phase 5 - order timeline)
-- ===================================================================
-- One row per REAL status/payment milestone: order placed, payment
-- confirmed, processing, shipped, delivered, cancelled. Written only
-- from actual transitions (log_order_status_event() /
-- update_order_status() / update_order_payment_status()), never
-- synthesised. order_status uses the orders.order_status vocabulary
-- plus the 'payment_confirmed' marker for the Payment Confirmed
-- milestone.

CREATE TABLE order_status_history (

    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id        INT UNSIGNED    NOT NULL,
    order_status    VARCHAR(30)     NOT NULL,
    note            VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,

    INDEX idx_order_status_history_order (order_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 07B. LOGIN ATTEMPTS  (Phase 6 - login security / brute-force lockout)
-- ===================================================================
-- Brute-force lockout bookkeeping for BOTH admin and customer login
-- (see includes/login-security.php). One row per attempted email
-- address, storing only the minimal state needed: consecutive failed
-- attempts, when the last one happened, and until when the address is
-- locked. The email is normalized (same normalize_email() the login
-- functions use) and unique, so a repeated attacker or a legitimate
-- user always updates the SAME row. No foreign keys - the row exists
-- even when the email belongs to no account, which is exactly what
-- keeps the lockout from revealing which emails have accounts.

CREATE TABLE login_attempts (

    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(191) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_failed_at  DATETIME     NULL,
    locked_until    DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_login_attempts_email (email)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 08. DAILY SEQUENCES  (Phase 3 - replaces order_number_sequences)
-- ===================================================================
-- One row per (sequence_type, sequence_date) pair. Used for BOTH:
--   - order numbers:   MOAOD<YYYYMMDD><4-digit sequence>
--   - invoice numbers: MOAINV<YYYYMMDD><4-digit sequence>
-- Each type's counter resets to 1 at the start of every calendar day,
-- and the two types are counted completely independently of each
-- other. generate_order_number()/generate_invoice_number() in
-- includes/order-functions.php lock the relevant row with
-- SELECT ... FOR UPDATE inside the same transaction that creates the
-- order/invoice, so two things happening at the same instant can
-- never be given the same number.

CREATE TABLE daily_sequences (

    sequence_type       VARCHAR(20)     NOT NULL,   -- 'order' or 'invoice'
    sequence_date       DATE            NOT NULL,
    next_number         INT UNSIGNED    NOT NULL DEFAULT 1,

    PRIMARY KEY (sequence_type, sequence_date)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 09. CUSTOMERS  (Phase 2D - customer accounts)
-- ===================================================================

CREATE TABLE customers (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name                VARCHAR(150)    NOT NULL,
    email               VARCHAR(150)    NOT NULL UNIQUE,
    phone               VARCHAR(20)     NOT NULL UNIQUE,
    password_hash       VARCHAR(255)    NOT NULL,               -- created with PHP password_hash()

    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',

    theme_preference    ENUM('light','dark','system') NULL DEFAULT NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 10. CUSTOMER ADDRESSES  (saved addresses - separate from
--     order_addresses, which are per-order historical snapshots)
-- ===================================================================

CREATE TABLE customer_addresses (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id         INT UNSIGNED    NOT NULL,

    full_name           VARCHAR(150)    NOT NULL,
    phone               VARCHAR(20)     NOT NULL,

    address_line1       VARCHAR(255)    NOT NULL,
    address_line2       VARCHAR(255)    NULL,
    landmark            VARCHAR(255)    NULL,
    city                VARCHAR(100)    NOT NULL,
    state               VARCHAR(100)    NOT NULL,
    postal_code         VARCHAR(12)     NOT NULL,
    country             VARCHAR(100)    NOT NULL DEFAULT 'India',

    is_default          TINYINT(1)      NOT NULL DEFAULT 0,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_customer_addresses_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE,

    INDEX idx_customer_addresses_customer (customer_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 11. ADMIN PASSWORD RESETS  (Phase 2D - admin "Forgot Password")
-- ===================================================================
-- The token itself is never stored - only its SHA-256 hash, the same
-- principle as password_hash for actual passwords. The raw token only
-- ever exists in the reset link itself. "used_at" makes every token
-- single-use; "expires_at" makes it time-limited.

CREATE TABLE admin_password_resets (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    admin_id            INT UNSIGNED    NOT NULL,
    token_hash          VARCHAR(255)    NOT NULL,

    expires_at          DATETIME        NOT NULL,
    used_at             DATETIME        NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_admin_password_resets_admin
        FOREIGN KEY (admin_id) REFERENCES admin_users(id)
        ON DELETE CASCADE,

    INDEX idx_admin_password_resets_admin (admin_id),
    INDEX idx_admin_password_resets_token_hash (token_hash)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 11c. ADMIN 2FA RECOVERY CODES + SECURITY LOG  (Phase 5F.1)
-- ===================================================================
-- Recovery codes are the additive Phase 5F.1 enhancement to the admin
-- TOTP 2FA system. Only SHA-256 hashes are stored (never the
-- plaintext). Codes live in batches: the most recently generated batch
-- is active, so regenerating invalidates all earlier batches. Codes
-- are single-use via consumed_at + an atomic guarded UPDATE.
-- admin_security_log is the append-only audit trail for recovery-code
-- events (never stores code values).

CREATE TABLE admin_recovery_codes (

    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    admin_id        INT UNSIGNED    NOT NULL,
    batch_id        VARCHAR(32)     NOT NULL,
    code_hash       CHAR(64)        NOT NULL,

    consumed_at     DATETIME        NULL,
    consumed_by_ip  VARCHAR(45)     NULL,

    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_admin_recovery_codes_admin
        FOREIGN KEY (admin_id) REFERENCES admin_users(id)
        ON DELETE CASCADE,

    INDEX idx_admin_recovery_codes_admin (admin_id),
    INDEX idx_admin_recovery_codes_batch (admin_id, batch_id),
    INDEX idx_admin_recovery_codes_hash (code_hash)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_security_log (

    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    admin_id        INT UNSIGNED    NULL,
    event_type      VARCHAR(50)     NOT NULL,
    detail          VARCHAR(255)    NOT NULL DEFAULT '',
    ip_address      VARCHAR(45)     NULL,

    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_admin_security_log_admin
        FOREIGN KEY (admin_id) REFERENCES admin_users(id)
        ON DELETE CASCADE,

    INDEX idx_admin_security_log_admin (admin_id, created_at),
    INDEX idx_admin_security_log_type (event_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 11b. CUSTOMER PASSWORD RESETS  (Phase 5B - customer "Forgot
--      Password") - the customer-side mirror of admin_password_resets
--      above, backing account/forgot-password.php and
--      account/reset-password.php.
-- ===================================================================
-- The token itself is never stored - only its SHA-256 hash, the same
-- principle as password_hash for actual passwords. The raw token only
-- ever exists in the reset link itself. "used_at" makes every token
-- single-use; "expires_at" makes it time-limited.

CREATE TABLE customer_password_resets (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id         INT UNSIGNED    NOT NULL,
    token_hash          VARCHAR(255)    NOT NULL,

    expires_at          DATETIME        NOT NULL,
    used_at             DATETIME        NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_customer_password_resets_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE,

    INDEX idx_customer_password_resets_customer (customer_id),
    INDEX idx_customer_password_resets_token_hash (token_hash)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 12. SETTINGS  (Phase 3A - generic key-value config)
-- ===================================================================
-- NOT payment-specific - a general-purpose store for anything that
-- should be admin-configurable without a code change. Phase 3A seeds
-- it with the active payment gateway and whether COD is enabled, but
-- any future setting belongs here too, not as a new hardcoded
-- constant. There is no Admin Settings UI yet (out of scope for 3A) -
-- only this table and its read/write functions
-- (includes/settings-functions.php) exist so far; a future admin
-- page can write to it directly with no other code changes.

CREATE TABLE settings (

    setting_key         VARCHAR(100)    PRIMARY KEY,
    setting_value       VARCHAR(255)    NULL,

    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- INVOICE DESIGNER SETTINGS  (Phase 6)
-- -------------------------------------------------------------------
-- Single-row singleton (id is always 1) holding the Invoice
-- Designer's full configuration as one JSON blob - see
-- includes/invoice-designer-functions.php for the shape/defaults.
-- Deliberately separate from the generic `settings` table above:
-- setting_value there is VARCHAR(255), far too small for this many
-- structured fields. Never touches orders/order_items/order_addresses
-- (the immutable order snapshot) or GST calculation - this table only
-- controls how an already-computed order is laid out on the invoice
-- PDF page.
-- ===================================================================

CREATE TABLE invoice_designer_settings (

    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,

    config      LONGTEXT         NOT NULL,

    updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 13. PAYMENT TRANSACTIONS  (Phase 3A)
-- ===================================================================
-- One-to-MANY with orders, on purpose - "Retry Failed Payment" means
-- a single order can have several payment attempts over time (one
-- gateway order + one row per attempt), which also gives a complete
-- audit trail (the "Transaction Logging" requirement) for free.
-- Deliberately gateway-agnostic: no Razorpay-specific column names -
-- "gateway" says which one, gateway_order_id/gateway_payment_id/
-- gateway_signature are generic slots any gateway's identifiers fit
-- into, and raw_response keeps the full JSON reply for audit/debugging
-- without needing gateway-specific columns for every field a gateway
-- might return.

CREATE TABLE payment_transactions (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id            INT UNSIGNED    NOT NULL,
    gateway             VARCHAR(50)     NOT NULL,               -- e.g. "razorpay"

    gateway_order_id    VARCHAR(100)    NULL,
    gateway_payment_id  VARCHAR(100)    NULL,
    gateway_signature   VARCHAR(255)    NULL,

    status              VARCHAR(30)     NOT NULL DEFAULT 'created', -- 'created' | 'paid' | 'failed' | 'submitted' (Manual UPI, awaiting admin verification)
    raw_response        TEXT            NULL,                   -- full JSON reply from the gateway, for audit/debugging
    screenshot_path     VARCHAR(255)    NULL,                   -- Manual UPI only: optional customer-uploaded proof-of-payment image

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payment_transactions_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,

    INDEX idx_payment_transactions_order (order_id),
    INDEX idx_payment_transactions_gateway_order_id (gateway_order_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- 16. ORDER EMAIL LOG
-- ===================================================================
-- Phase 5D Step 2: one row per transactional order email ATTEMPT
-- (order confirmation / shipped / delivered). Only rows with
-- status='sent' count towards the dedup guard (see
-- includes/order-emails.php) - a 'failed' attempt never blocks a
-- later retry, but a successful send is never repeated. Kept as its
-- own table (rather than columns on orders) so every attempt and its
-- failure detail survives for the admin/ops audit trail.

CREATE TABLE order_email_log (

    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id        INT UNSIGNED NOT NULL,
    email_type      VARCHAR(40)  NOT NULL,   -- 'order_confirmation' | 'order_shipped' | 'order_delivered'
    recipient       VARCHAR(190) NOT NULL,   -- the customer email the attempt targeted
    subject         VARCHAR(190) NOT NULL,   -- the subject line actually sent

    status          VARCHAR(20)  NOT NULL,   -- 'sent' = accepted by mailer/SMTP (counts for dedup); 'failed' = attempt errored
    error_message   TEXT         NULL,       -- truncated failure detail, never credentials

    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_order_email_log_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,

    INDEX idx_order_email_log_order (order_id),
    INDEX idx_order_email_log_order_type (order_id, email_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- Phase #25A: persistent customer cart rows (unused until #25B/#25C).
-- Session cart ($_SESSION['cart']) remains the live source of truth.
-- One row per (customer, product); quantity 1-99 matches cart-functions.
-- ===================================================================

CREATE TABLE customer_carts (

    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id         INT UNSIGNED    NOT NULL,
    product_id          INT UNSIGNED    NOT NULL,
    quantity            TINYINT UNSIGNED NOT NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_customer_carts_customer_product
        UNIQUE (customer_id, product_id),

    CONSTRAINT fk_customer_carts_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_customer_carts_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    INDEX idx_customer_carts_customer (customer_id),
    INDEX idx_customer_carts_product (product_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- NEWSLETTER SUBSCRIBERS CACHE  (Admin Phase 1)
-- -------------------------------------------------------------------
-- Local cache of Brevo newsletter contacts for Admin search, filters,
-- CSV export, and sync status. Brevo remains the source of truth.
-- ===================================================================

CREATE TABLE newsletter_subscribers (

    email               VARCHAR(190)    NOT NULL PRIMARY KEY,
    name                VARCHAR(160)    NULL,
    status              ENUM('pending','confirmed','unsubscribed') NOT NULL,
    source              VARCHAR(40)     NOT NULL DEFAULT 'brevo',
    brevo_id            BIGINT UNSIGNED NULL,
    subscribed_at       DATETIME        NULL,
    confirmed_at        DATETIME        NULL,
    unsubscribed_at     DATETIME        NULL,
    last_modified_at    DATETIME        NULL,
    synced_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_newsletter_subscribers_status (status),
    INDEX idx_newsletter_subscribers_name (name),
    INDEX idx_newsletter_subscribers_synced (synced_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ===================================================================
-- NEWSLETTER CAMPAIGNS  (Admin Phase 2 drafts + Phase 3 sending)
-- -------------------------------------------------------------------
-- Admin campaign storage. Sending uses existing Brevo SMTP via
-- includes/mailer.php. Recipients come from newsletter_subscribers.
-- ===================================================================

CREATE TABLE newsletter_campaigns (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(160)    NOT NULL,
    subject             VARCHAR(200)    NOT NULL DEFAULT '',
    preview_text        VARCHAR(255)    NULL,
    sender_name         VARCHAR(120)    NOT NULL,
    sender_email        VARCHAR(190)    NOT NULL,
    reply_to            VARCHAR(190)    NULL,
    body_html           LONGTEXT        NOT NULL,
    cta_text            VARCHAR(120)    NULL,
    cta_url             VARCHAR(500)    NULL,
    status              ENUM('draft','scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at        DATETIME        NULL,
    started_at          DATETIME        NULL,
    completed_at        DATETIME        NULL,
    recipient_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    sent_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    failure_reason      VARCHAR(255)    NULL,
    locked_at           DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_newsletter_campaigns_status (status),
    INDEX idx_newsletter_campaigns_updated (updated_at),
    INDEX idx_newsletter_campaigns_scheduled (status, scheduled_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE newsletter_campaign_sends (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT UNSIGNED    NOT NULL,
    campaign_name       VARCHAR(160)    NOT NULL,
    status              ENUM('sending','sent','failed','cancelled') NOT NULL,
    is_test             TINYINT(1)      NOT NULL DEFAULT 0,
    recipient_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    sent_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    scheduled_at        DATETIME        NULL,
    started_at          DATETIME        NULL,
    completed_at        DATETIME        NULL,
    failure_reason      VARCHAR(255)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_newsletter_sends_campaign (campaign_id),
    INDEX idx_newsletter_sends_created (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE newsletter_campaign_recipients (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT UNSIGNED    NOT NULL,
    email               VARCHAR(190)    NOT NULL,
    name                VARCHAR(160)    NULL,
    status              ENUM('pending','claimed','sent','failed','unknown') NOT NULL DEFAULT 'pending',
    error_message       VARCHAR(255)    NULL,
    sent_at             DATETIME        NULL,
    claimed_at          DATETIME        NULL,
    claim_token         CHAR(32)        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_newsletter_campaign_recipient (campaign_id, email),
    INDEX idx_newsletter_recipients_status (campaign_id, status),
    INDEX idx_newsletter_recipients_claim (campaign_id, status, claimed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;

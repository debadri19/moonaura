<?php
/* ===================================================================
   ADMIN NEWSLETTER HELPERS (Phase 1 + Phase 2)
   -------------------------------------------------------------------
   Builds Admin subscriber management and campaign drafts on top of
   the existing Brevo Contacts integration. Does not send campaigns.
   Storefront signup (newsletter_subscribe) is unchanged.
   Sync loads pending and confirmed lists and honors emailBlacklisted.
   Cached addresses missing from both lists are stored as unsubscribed
   so a remote blacklist/unlink cannot keep them campaign-eligible.
   Never writes to the customers table.
=================================================================== */

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings-functions.php';

const NEWSLETTER_ADMIN_PAGE_SIZE = 20;
const NEWSLETTER_PERSONALIZE_FALLBACK_FIRST_NAME = 'there';

function newsletter_admin_valid_statuses(): array
{
    return ['pending', 'confirmed', 'unsubscribed'];
}

function newsletter_admin_contact_name(array $contact): string
{
    $attributes = $contact['attributes'] ?? [];
    if (!is_array($attributes)) {
        return '';
    }

    $first = trim((string) ($attributes['FIRSTNAME'] ?? $attributes['FIRST_NAME'] ?? ''));
    $last  = trim((string) ($attributes['LASTNAME'] ?? $attributes['LAST_NAME'] ?? ''));
    $name  = trim($first . ' ' . $last);

    if ($name !== '') {
        return mb_substr($name, 0, 160);
    }

    return mb_substr(trim((string) ($attributes['NAME'] ?? '')), 0, 160);
}

function newsletter_admin_parse_datetime($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $stamp = strtotime($value);
    if ($stamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $stamp);
}

function newsletter_admin_status_from_contact(array $contact): string
{
    if (!empty($contact['emailBlacklisted'])) {
        return 'unsubscribed';
    }

    $listIds = newsletter_contact_list_ids($contact);

    if (in_array((int) BREVO_NEWSLETTER_LIST_ID, $listIds, true)) {
        return 'confirmed';
    }

    if (in_array((int) BREVO_NEWSLETTER_PENDING_LIST_ID, $listIds, true)) {
        return 'pending';
    }

    return 'unsubscribed';
}

function newsletter_admin_ensure_tables(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        db()->query('SELECT email FROM newsletter_subscribers LIMIT 1');
        db()->query('SELECT id FROM newsletter_campaigns LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        error_log('newsletter_admin_ensure_tables: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function newsletter_campaign_tables_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!newsletter_admin_ensure_tables()) {
        $ready = false;
        return $ready;
    }

    try {
        db()->query('SELECT scheduled_at, locked_at FROM newsletter_campaigns LIMIT 1');
        db()->query('SELECT id FROM newsletter_campaign_sends LIMIT 1');
        db()->query('SELECT id, claimed_at, claim_token FROM newsletter_campaign_recipients LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        error_log('newsletter_campaign_tables_ready: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function newsletter_admin_counts(): array
{
    $empty = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0];

    if (!newsletter_admin_ensure_tables()) {
        return $empty;
    }

    try {
        $stmt = db()->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'confirmed') AS confirmed,
                SUM(status = 'pending') AS pending,
                SUM(status = 'unsubscribed') AS unsubscribed
             FROM newsletter_subscribers"
        );
        $row = $stmt->fetch() ?: [];

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'confirmed'     => (int) ($row['confirmed'] ?? 0),
            'pending'       => (int) ($row['pending'] ?? 0),
            'unsubscribed'  => (int) ($row['unsubscribed'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('newsletter_admin_counts: ' . $e->getMessage());
        return $empty;
    }
}

function newsletter_admin_sync_meta(): array
{
    return [
        'last_sync_at'     => get_setting('newsletter_last_sync_at', ''),
        'last_sync_status' => get_setting('newsletter_last_sync_status', ''),
        'last_sync_error'  => get_setting('newsletter_last_sync_error', ''),
        'last_sync_count'  => get_setting('newsletter_last_sync_count', ''),
    ];
}

function newsletter_admin_fetch_list_contacts(int $listId): array
{
    $contacts = [];
    $offset   = 0;
    $limit    = 50;

    do {
        $result = newsletter_brevo_request(
            'GET',
            '/contacts/lists/' . $listId . '/contacts?limit=' . $limit . '&offset=' . $offset
        );
        $httpStatus = (int) ($result['status'] ?? 0);
        if ($httpStatus !== 200) {
            newsletter_log_brevo_failure($httpStatus, $result);
            return ['ok' => false, 'contacts' => [], 'error' => 'Could not load subscribers from Brevo.'];
        }

        $body     = is_array($result['body'] ?? null) ? $result['body'] : [];
        $page     = is_array($body['contacts'] ?? null) ? $body['contacts'] : [];
        $count    = (int) ($body['count'] ?? 0);

        foreach ($page as $contact) {
            if (is_array($contact) && !empty($contact['email'])) {
                $contacts[] = $contact;
            }
        }

        $offset += $limit;
        if ($page === [] || $offset >= $count) {
            break;
        }
    } while ($offset < 10000);

    return ['ok' => true, 'contacts' => $contacts, 'error' => ''];
}

function newsletter_admin_upsert_subscriber(array $row): void
{
    $stmt = db()->prepare(
        'INSERT INTO newsletter_subscribers
            (email, name, status, source, brevo_id, subscribed_at, confirmed_at,
             unsubscribed_at, last_modified_at, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            status = VALUES(status),
            source = IF(source = \'admin\', source, VALUES(source)),
            brevo_id = VALUES(brevo_id),
            subscribed_at = VALUES(subscribed_at),
            confirmed_at = VALUES(confirmed_at),
            unsubscribed_at = VALUES(unsubscribed_at),
            last_modified_at = VALUES(last_modified_at),
            synced_at = VALUES(synced_at)'
    );

    $stmt->execute([
        $row['email'],
        $row['name'] !== '' ? $row['name'] : null,
        $row['status'],
        $row['source'],
        $row['brevo_id'],
        $row['subscribed_at'],
        $row['confirmed_at'],
        $row['unsubscribed_at'],
        $row['last_modified_at'],
        $row['synced_at'],
    ]);
}

function newsletter_admin_row_from_contact(array $contact, string $forcedStatus = '', string $source = 'brevo'): array
{
    $email  = normalize_email((string) ($contact['email'] ?? ''));
    $status = $forcedStatus !== '' ? $forcedStatus : newsletter_admin_status_from_contact($contact);
    $created = newsletter_admin_parse_datetime($contact['createdAt'] ?? null);
    $modified = newsletter_admin_parse_datetime($contact['modifiedAt'] ?? null);
    $now = date('Y-m-d H:i:s');

    return [
        'email'            => $email,
        'name'             => newsletter_admin_contact_name($contact),
        'status'           => $status,
        'source'           => $source,
        'brevo_id'         => isset($contact['id']) ? (int) $contact['id'] : null,
        'subscribed_at'    => $created,
        'confirmed_at'     => $status === 'confirmed' ? ($modified ?: $created) : null,
        'unsubscribed_at'  => $status === 'unsubscribed' ? ($modified ?: $now) : null,
        'last_modified_at' => $modified,
        'synced_at'        => $now,
    ];
}

function newsletter_admin_sync_from_brevo(): array
{
    if (!newsletter_is_configured()) {
        return ['ok' => false, 'error' => 'Brevo newsletter integration is not configured.', 'count' => 0];
    }

    if (!newsletter_admin_ensure_tables()) {
        return ['ok' => false, 'error' => 'Newsletter tables are missing. Apply database/migration_newsletter_admin.sql.', 'count' => 0];
    }

    $pending = newsletter_admin_fetch_list_contacts((int) BREVO_NEWSLETTER_PENDING_LIST_ID);
    if (!$pending['ok']) {
        newsletter_admin_store_sync_result(false, 0, $pending['error']);
        return ['ok' => false, 'error' => $pending['error'], 'count' => 0];
    }

    $confirmed = newsletter_admin_fetch_list_contacts((int) BREVO_NEWSLETTER_LIST_ID);
    if (!$confirmed['ok']) {
        newsletter_admin_store_sync_result(false, 0, $confirmed['error']);
        return ['ok' => false, 'error' => $confirmed['error'], 'count' => 0];
    }

    $merged = [];

    foreach ($pending['contacts'] as $contact) {
        $row = newsletter_admin_row_from_contact($contact);
        if ($row['email'] === '' || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $merged[$row['email']] = $row;
    }

    foreach ($confirmed['contacts'] as $contact) {
        $row = newsletter_admin_row_from_contact($contact);
        if ($row['email'] === '' || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (($row['status'] ?? '') === 'pending' && isset($merged[$row['email']])) {
            continue;
        }
        $merged[$row['email']] = $row;
    }

    try {
        $existingStmt = db()->query(
            'SELECT email, name, status, source, brevo_id, subscribed_at, confirmed_at, unsubscribed_at
             FROM newsletter_subscribers'
        );
        $existingRows = $existingStmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_admin_sync_from_brevo existing: ' . $e->getMessage());
        newsletter_admin_store_sync_result(false, 0, 'Could not read the local subscriber cache.');
        return ['ok' => false, 'error' => 'Could not read the local subscriber cache.', 'count' => 0];
    }

    $now = date('Y-m-d H:i:s');
    foreach ($existingRows as $existing) {
        $email = normalize_email((string) ($existing['email'] ?? ''));
        if ($email === '' || isset($merged[$email])) {
            continue;
        }

        $merged[$email] = [
            'email'            => $email,
            'name'             => (string) ($existing['name'] ?? ''),
            'status'           => 'unsubscribed',
            'source'           => (string) ($existing['source'] ?: 'brevo'),
            'brevo_id'         => $existing['brevo_id'] ?? null,
            'subscribed_at'    => $existing['subscribed_at'] ?? null,
            'confirmed_at'     => $existing['confirmed_at'] ?? null,
            'unsubscribed_at'  => $existing['unsubscribed_at'] ?: $now,
            'last_modified_at' => $now,
            'synced_at'        => $now,
        ];
    }

    try {
        foreach ($merged as $row) {
            if ($row['name'] === '') {
                $keep = db()->prepare('SELECT name FROM newsletter_subscribers WHERE email = ? LIMIT 1');
                $keep->execute([$row['email']]);
                $existingName = trim((string) $keep->fetchColumn());
                if ($existingName !== '') {
                    $row['name'] = $existingName;
                }
            }
            newsletter_admin_upsert_subscriber($row);
        }
    } catch (PDOException $e) {
        error_log('newsletter_admin_sync_from_brevo upsert: ' . $e->getMessage());
        newsletter_admin_store_sync_result(false, 0, 'Could not save subscribers locally.');
        return ['ok' => false, 'error' => 'Could not save subscribers locally.', 'count' => 0];
    }

    $count = count($merged);
    newsletter_admin_store_sync_result(true, $count, '');

    return ['ok' => true, 'error' => '', 'count' => $count];
}

function newsletter_admin_store_sync_result(bool $ok, int $count, string $error): void
{
    set_setting('newsletter_last_sync_at', date('Y-m-d H:i:s'));
    set_setting('newsletter_last_sync_status', $ok ? 'ok' : 'error');
    set_setting('newsletter_last_sync_error', $ok ? '' : mb_substr($error, 0, 240));
    set_setting('newsletter_last_sync_count', (string) $count);
}

function newsletter_admin_list_query(string $search, string $status): array
{
    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]  = '(email LIKE ? OR name LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if (in_array($status, newsletter_admin_valid_statuses(), true)) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    return [
        'where'  => $where === [] ? '' : (' WHERE ' . implode(' AND ', $where)),
        'params' => $params,
    ];
}

function newsletter_admin_list_subscribers(string $search, string $status, int $page): array
{
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];

    if (!newsletter_admin_ensure_tables()) {
        return $empty;
    }

    $query = newsletter_admin_list_query($search, $status);
    $page  = max(1, $page);

    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM newsletter_subscribers' . $query['where']);
        $countStmt->execute($query['params']);
        $total = (int) $countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('newsletter_admin_list_subscribers count: ' . $e->getMessage());
        return $empty;
    }

    $totalPages = max(1, (int) ceil($total / NEWSLETTER_ADMIN_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * NEWSLETTER_ADMIN_PAGE_SIZE;

    try {
        $sql = 'SELECT email, name, status, source, subscribed_at, confirmed_at, unsubscribed_at, last_modified_at, synced_at
                FROM newsletter_subscribers'
                . $query['where'] . '
                ORDER BY synced_at DESC, email ASC
                LIMIT ' . (int) NEWSLETTER_ADMIN_PAGE_SIZE . ' OFFSET ' . (int) $offset;
        $stmt = db()->prepare($sql);
        $stmt->execute($query['params']);
        $rows = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_admin_list_subscribers: ' . $e->getMessage());
        return $empty;
    }

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
    ];
}

function newsletter_admin_export_rows(string $search, string $status): array
{
    if (!newsletter_admin_ensure_tables()) {
        return [];
    }

    $query = newsletter_admin_list_query($search, $status);

    try {
        $stmt = db()->prepare(
            'SELECT email, name, status, source, subscribed_at, confirmed_at, unsubscribed_at
             FROM newsletter_subscribers'
             . $query['where'] . '
             ORDER BY email ASC'
        );
        $stmt->execute($query['params']);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_admin_export_rows: ' . $e->getMessage());
        return [];
    }
}

function newsletter_admin_get_cached_subscriber(string $email): ?array
{
    if (!newsletter_admin_ensure_tables()) {
        return null;
    }

    $email = normalize_email($email);
    if ($email === '') {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('newsletter_admin_get_cached_subscriber: ' . $e->getMessage());
        return null;
    }
}

function newsletter_admin_refresh_one(string $email): array
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.', 'subscriber' => null];
    }

    if (!newsletter_is_configured()) {
        return ['ok' => false, 'error' => 'Brevo newsletter integration is not configured.', 'subscriber' => null];
    }

    $result = newsletter_get_contact($email);
    $httpStatus = (int) ($result['status'] ?? 0);

    if ($httpStatus === 404) {
        $cached = newsletter_admin_get_cached_subscriber($email);
        if ($cached) {
            $cached['status'] = 'unsubscribed';
            $cached['unsubscribed_at'] = $cached['unsubscribed_at'] ?: date('Y-m-d H:i:s');
            newsletter_admin_upsert_subscriber([
                'email'            => $email,
                'name'             => (string) ($cached['name'] ?? ''),
                'status'           => 'unsubscribed',
                'source'           => (string) ($cached['source'] ?? 'brevo'),
                'brevo_id'         => $cached['brevo_id'] ?? null,
                'subscribed_at'    => $cached['subscribed_at'] ?? null,
                'confirmed_at'     => $cached['confirmed_at'] ?? null,
                'unsubscribed_at'  => $cached['unsubscribed_at'],
                'last_modified_at' => date('Y-m-d H:i:s'),
                'synced_at'        => date('Y-m-d H:i:s'),
            ]);
        }
        return ['ok' => true, 'error' => '', 'subscriber' => newsletter_admin_get_cached_subscriber($email)];
    }

    if ($httpStatus !== 200) {
        newsletter_log_brevo_failure($httpStatus, $result);
        return ['ok' => false, 'error' => 'Could not refresh this subscriber from Brevo.', 'subscriber' => null];
    }

    $cached = newsletter_admin_get_cached_subscriber($email);
    $row = newsletter_admin_row_from_contact($result['body'] ?? []);
    if ($row['name'] === '' && !empty($cached['name'])) {
        $row['name'] = (string) $cached['name'];
    }
    if (!empty($cached['source'])) {
        $row['source'] = (string) $cached['source'];
    }
    newsletter_admin_upsert_subscriber($row);

    return ['ok' => true, 'error' => '', 'subscriber' => newsletter_admin_get_cached_subscriber($email)];
}

function newsletter_admin_add_subscriber(string $email, string $name = ''): string
{
    $email = normalize_email($email);
    $name  = mb_substr(trim($name), 0, 160);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'invalid';
    }

    $result = newsletter_subscribe($email);
    if ($result === 'error' || $result === 'invalid') {
        return $result;
    }

    if ($result === 'pending' && $name !== '') {
        $update = newsletter_brevo_request(
            'PUT',
            '/contacts/' . rawurlencode($email),
            ['attributes' => ['FIRSTNAME' => $name]]
        );
        $httpStatus = (int) ($update['status'] ?? 0);
        if (!in_array($httpStatus, [200, 204], true)) {
            newsletter_log_brevo_failure($httpStatus, $update);
        }
    }

    $contact = newsletter_get_contact($email);
    $row = [
        'email'            => $email,
        'name'             => $name,
        'status'           => $result === 'already' ? 'confirmed' : 'pending',
        'source'           => 'admin',
        'brevo_id'         => null,
        'subscribed_at'    => date('Y-m-d H:i:s'),
        'confirmed_at'     => $result === 'already' ? date('Y-m-d H:i:s') : null,
        'unsubscribed_at'  => null,
        'last_modified_at' => date('Y-m-d H:i:s'),
        'synced_at'        => date('Y-m-d H:i:s'),
    ];

    if ((int) ($contact['status'] ?? 0) === 200) {
        $fromBrevo = newsletter_admin_row_from_contact($contact['body'] ?? [], $row['status'], 'admin');
        if ($name !== '') {
            $fromBrevo['name'] = $name;
        }
        $row = $fromBrevo;
        $row['source'] = 'admin';
    }

    if (newsletter_admin_ensure_tables()) {
        newsletter_admin_upsert_subscriber($row);
    }

    return $result;
}

function newsletter_admin_unsubscribe(string $email): array
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.'];
    }

    if (!newsletter_is_configured()) {
        return ['ok' => false, 'error' => 'Brevo newsletter integration is not configured.'];
    }

    $result = newsletter_brevo_request(
        'PUT',
        '/contacts/' . rawurlencode($email),
        [
            'emailBlacklisted' => true,
            'unlinkListIds'    => [
                (int) BREVO_NEWSLETTER_PENDING_LIST_ID,
                (int) BREVO_NEWSLETTER_LIST_ID,
            ],
        ]
    );

    $httpStatus = (int) ($result['status'] ?? 0);
    if (!in_array($httpStatus, [200, 204], true) && $httpStatus !== 404) {
        newsletter_log_brevo_failure($httpStatus, $result);
        return ['ok' => false, 'error' => 'Could not unsubscribe this address in Brevo.'];
    }

    $cached = newsletter_admin_get_cached_subscriber($email) ?: [
        'name'          => '',
        'source'        => 'brevo',
        'brevo_id'      => null,
        'subscribed_at' => null,
        'confirmed_at'  => null,
    ];

    if (newsletter_admin_ensure_tables()) {
        newsletter_admin_upsert_subscriber([
            'email'            => $email,
            'name'             => (string) ($cached['name'] ?? ''),
            'status'           => 'unsubscribed',
            'source'           => (string) ($cached['source'] ?? 'brevo'),
            'brevo_id'         => $cached['brevo_id'] ?? null,
            'subscribed_at'    => $cached['subscribed_at'] ?? null,
            'confirmed_at'     => $cached['confirmed_at'] ?? null,
            'unsubscribed_at'  => date('Y-m-d H:i:s'),
            'last_modified_at' => date('Y-m-d H:i:s'),
            'synced_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    return ['ok' => true, 'error' => ''];
}

function newsletter_admin_query_string(array $base, array $extra = []): string
{
    $merged = array_merge($base, $extra);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }
    return $merged === [] ? '' : ('?' . http_build_query($merged));
}

function newsletter_admin_format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }

    $stamp = strtotime($value);
    if ($stamp === false) {
        return '—';
    }

    return date('d M Y, h:i A', $stamp);
}

function newsletter_admin_badge_class(string $status): string
{
    return match ($status) {
        'confirmed'    => 'admin-badge-active',
        'pending'      => 'admin-badge-pending',
        'unsubscribed' => 'admin-badge-inactive',
        default        => 'admin-badge-draft',
    };
}

function newsletter_admin_configured_sender(): array
{
    return [
        'name'  => (string) MAIL_FROM_NAME,
        'email' => (string) MAIL_FROM_ADDRESS,
        'reply' => (string) MAIL_REPLY_TO_ADDRESS,
    ];
}

function newsletter_is_public_ip(string $ip): bool
{
    $ip = strtolower(trim($ip));
    $ip = trim($ip, '[]');
    if ($ip === '') {
        return false;
    }
    if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/', $ip, $mapped)) {
        return newsletter_is_public_ip($mapped[1]);
    }
    if (preg_match('/^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/i', $ip, $mapped)) {
        $hi = hexdec($mapped[1]);
        $lo = hexdec($mapped[2]);
        return newsletter_is_public_ip(
            (($hi >> 8) & 255) . '.' . ($hi & 255) . '.' . (($lo >> 8) & 255) . '.' . ($lo & 255)
        );
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $unsigned = sprintf('%u', $long);
        // FILTER_FLAG_NO_RES_RANGE does not cover CGNAT (100.64.0.0/10,
        // RFC 6598) or multicast (224.0.0.0/4, RFC 5771); reject both.
        foreach ([
            ['100.64.0.0', '100.127.255.255'],
            ['224.0.0.0', '239.255.255.255'],
        ] as $range) {
            if ($unsigned >= sprintf('%u', ip2long($range[0]))
                && $unsigned <= sprintf('%u', ip2long($range[1]))) {
                return false;
            }
        }

        return true;
    }

    $packed = @inet_pton($ip);
    if ($packed === false || ord($packed[0]) === 0xff) {
        // Reject IPv6 multicast ff00::/8, also uncovered by NO_RES_RANGE.
        return false;
    }

    return true;
}

function newsletter_is_public_host(string $host): bool
{
    $host = strtolower(trim($host));
    $host = trim($host, '[]');
    $host = rtrim($host, '.');
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    foreach (['.internal', '.local', '.lan', '.home', '.corp', '.invalid', '.onion'] as $suffix) {
        if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
            return false;
        }
    }

    if (strpbrk($host, " \t\r\n/%\\@") !== false) {
        return false;
    }
    if (preg_match('/^\d+$/', $host) || preg_match('/^0x[0-9a-f]+$/i', $host)) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) || str_contains($host, ':')) {
        return newsletter_is_public_ip($host);
    }
    if (!str_contains($host, '.')) {
        return false;
    }
    if (!preg_match('/^(xn--)?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.(xn--)?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $host)) {
        return false;
    }

    return true;
}

function newsletter_is_public_https_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
        return false;
    }

    $compact = strtolower(preg_replace('/\s+/', '', $url) ?? '');
    $compact = html_entity_decode($compact, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['javascript:', 'vbscript:', 'data:', 'file:', 'about:'] as $scheme) {
        if (str_starts_with($compact, $scheme)) {
            return false;
        }
    }
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $host = (string) ($parts['host'] ?? '');
    if ($host === '' || !newsletter_is_public_host($host)) {
        return false;
    }
    if (array_key_exists('port', $parts) && (int) $parts['port'] !== 443) {
        return false;
    }

    return true;
}

function newsletter_campaign_allowed_html(): array
{
    return [
        'p'      => [],
        'h1'     => [],
        'h2'     => [],
        'h3'     => [],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'u'      => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'br'     => [],
        'span'   => [],
        'div'    => [],
        'a'      => ['href', 'title'],
        'img'    => ['src', 'alt', 'width', 'height'],
    ];
}

function newsletter_sanitize_dom_element(DOMElement $element, array $allowed): void
{
    if ($element->parentNode === null) {
        return;
    }

    $tag = strtolower($element->tagName);
    $drop = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta',
        'noscript', 'svg', 'math', 'form', 'input', 'button', 'textarea',
        'select', 'base', 'applet',
    ];
    if (in_array($tag, $drop, true)) {
        $element->parentNode->removeChild($element);
        return;
    }

    if (!isset($allowed[$tag])) {
        $parent = $element->parentNode;
        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
        return;
    }

    $keep = $allowed[$tag];
    $remove = [];
    if ($element->hasAttributes()) {
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $keep, true)) {
                $remove[] = $attribute->name;
            }
        }
    }
    foreach ($remove as $name) {
        $element->removeAttribute($name);
    }

    if ($tag === 'a') {
        $href = trim($element->getAttribute('href'));
        if ($href === '' || !newsletter_is_public_https_url($href)) {
            $parent = $element->parentNode;
            while ($element->firstChild) {
                $parent->insertBefore($element->firstChild, $element);
            }
            $parent->removeChild($element);
            return;
        }
        $element->setAttribute('href', $href);
        $title = trim($element->getAttribute('title'));
        if ($title !== '') {
            $element->setAttribute('title', mb_substr($title, 0, 200));
        }
    }

    if ($tag === 'img') {
        $src = trim($element->getAttribute('src'));
        if ($src === '' || !newsletter_is_public_https_url($src)) {
            $element->parentNode->removeChild($element);
            return;
        }
        $element->setAttribute('src', $src);
        $element->setAttribute('alt', mb_substr(trim($element->getAttribute('alt')), 0, 200));
        foreach (['width', 'height'] as $dimension) {
            $value = $element->getAttribute($dimension);
            if ($value !== '' && !preg_match('/^\d{1,4}$/', $value)) {
                $element->removeAttribute($dimension);
            }
        }
    }
}

function newsletter_sanitize_dom_children(DOMNode $parent, array $allowed): void
{
    foreach (iterator_to_array($parent->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            newsletter_sanitize_dom_children($child, $allowed);
            newsletter_sanitize_dom_element($child, $allowed);
            continue;
        }
        if ($child->nodeType === XML_TEXT_NODE) {
            continue;
        }
        if ($child->nodeType === XML_CDATA_SECTION_NODE && $parent instanceof DOMElement) {
            $parent->replaceChild($parent->ownerDocument->createTextNode((string) $child->nodeValue), $child);
            continue;
        }
        $parent->removeChild($child);
    }
}

function newsletter_sanitize_campaign_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        error_log('newsletter_sanitize_campaign_html: DOMDocument is unavailable');
        return '';
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<div id="nws-root">' . $html . '</div>';
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><body>' . $wrapped . '</body>',
        LIBXML_NONET | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return '';
    }

    $root = $document->getElementById('nws-root');
    if (!$root) {
        foreach ($document->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'nws-root') {
                $root = $div;
                break;
            }
        }
    }
    if (!$root) {
        return '';
    }

    $allowed = newsletter_campaign_allowed_html();
    newsletter_sanitize_dom_children($root, $allowed);

    $output = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $output .= $document->saveHTML($child);
    }

    return trim($output);
}

function newsletter_apply_personalization(string $html, array $subscriber = []): string
{
    $first = trim((string) ($subscriber['first_name'] ?? $subscriber['name'] ?? ''));
    if ($first === '') {
        $first = NEWSLETTER_PERSONALIZE_FALLBACK_FIRST_NAME;
    } else {
        $first = explode(' ', $first)[0];
    }

    return str_replace('{{first_name}}', h($first), $html);
}

function newsletter_admin_get_campaign(int $id): ?array
{
    if ($id <= 0 || !newsletter_admin_ensure_tables()) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM newsletter_campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('newsletter_admin_get_campaign: ' . $e->getMessage());
        return null;
    }
}

function newsletter_admin_list_campaigns(): array
{
    if (!newsletter_admin_ensure_tables()) {
        return [];
    }

    try {
        $sql = newsletter_campaign_tables_ready()
            ? "SELECT id, name, subject, status, scheduled_at, recipient_count, sent_count, failed_count,
                      updated_at, created_at, completed_at
               FROM newsletter_campaigns
               ORDER BY updated_at DESC"
            : "SELECT id, name, subject, status, updated_at, created_at
               FROM newsletter_campaigns
               WHERE status = 'draft'
               ORDER BY updated_at DESC";
        $stmt = db()->query($sql);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('newsletter_admin_list_campaigns: ' . $e->getMessage());
        return [];
    }
}

function newsletter_admin_save_campaign(array $data, ?int $id = null): array
{
    if (!newsletter_admin_ensure_tables()) {
        return ['ok' => false, 'id' => 0, 'error' => 'Newsletter tables are missing. Apply database/migration_newsletter_admin.sql.'];
    }

    $sender = newsletter_admin_configured_sender();
    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 160);
    $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 200);
    $preview = mb_substr(trim((string) ($data['preview_text'] ?? '')), 0, 255);
    $replyTo = normalize_email((string) ($data['reply_to'] ?? $sender['reply']));
    $body = newsletter_sanitize_campaign_html((string) ($data['body_html'] ?? ''));
    $ctaText = mb_substr(trim((string) ($data['cta_text'] ?? '')), 0, 120);
    $ctaUrl = trim((string) ($data['cta_url'] ?? ''));

    if ($name === '') {
        return ['ok' => false, 'id' => 0, 'error' => 'Campaign name is required.'];
    }

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => 0, 'error' => 'Reply-To must be a valid email address.'];
    }

    if ($ctaUrl !== '' && !newsletter_is_public_https_url($ctaUrl)) {
        return ['ok' => false, 'id' => 0, 'error' => 'CTA URL must be a public https address without credentials or private hosts.'];
    }

    try {
        if ($id) {
            $existing = newsletter_admin_get_campaign($id);
            if (!$existing) {
                return ['ok' => false, 'id' => 0, 'error' => 'Campaign not found.'];
            }
            $currentStatus = (string) ($existing['status'] ?? 'draft');
            if (!in_array($currentStatus, ['draft', 'failed', 'cancelled'], true)) {
                return ['ok' => false, 'id' => 0, 'error' => 'This campaign can no longer be edited.'];
            }

            $stmt = db()->prepare(
                'UPDATE newsletter_campaigns
                 SET name = ?, subject = ?, preview_text = ?, sender_name = ?, sender_email = ?,
                     reply_to = ?, body_html = ?, cta_text = ?, cta_url = ?
                 WHERE id = ? AND status IN (\'draft\',\'failed\',\'cancelled\')'
            );
            $stmt->execute([
                $name,
                $subject,
                $preview !== '' ? $preview : null,
                $sender['name'] !== '' ? $sender['name'] : SITE_NAME,
                $sender['email'],
                $replyTo !== '' ? $replyTo : null,
                $body,
                $ctaText !== '' ? $ctaText : null,
                $ctaUrl !== '' ? $ctaUrl : null,
                $id,
            ]);
            return ['ok' => true, 'id' => $id, 'error' => ''];
        }

        $stmt = db()->prepare(
            'INSERT INTO newsletter_campaigns
                (name, subject, preview_text, sender_name, sender_email, reply_to, body_html, cta_text, cta_url, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')'
        );
        $stmt->execute([
            $name,
            $subject,
            $preview !== '' ? $preview : null,
            $sender['name'] !== '' ? $sender['name'] : SITE_NAME,
            $sender['email'],
            $replyTo !== '' ? $replyTo : null,
            $body,
            $ctaText !== '' ? $ctaText : null,
            $ctaUrl !== '' ? $ctaUrl : null,
        ]);

        return ['ok' => true, 'id' => (int) db()->lastInsertId(), 'error' => ''];
    } catch (PDOException $e) {
        error_log('newsletter_admin_save_campaign: ' . $e->getMessage());
        return ['ok' => false, 'id' => 0, 'error' => 'Could not save the campaign draft.'];
    }
}

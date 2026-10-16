<?php
/* ===================================================================
   ADMIN - NEWSLETTER
   -------------------------------------------------------------------
   Audience management (Phase 1), campaign drafts/sending (Phase 2/3),
   and campaign history/analytics (Phase 4) on the existing Brevo
   Contacts + SMTP stack. Open/click tracking is not configured.
=================================================================== */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/newsletter-campaign-functions.php';

require_admin_login();

$admin      = current_admin();
$pageTitle  = 'Newsletter';
$activePage = 'newsletter';

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page   = (int) ($_GET['page'] ?? 1);
if (!in_array($status, newsletter_admin_valid_statuses(), true)) {
    $status = '';
}

$cSearch = trim($_GET['c_search'] ?? '');
$cStatus = trim($_GET['c_status'] ?? '');
$cSort   = trim($_GET['c_sort'] ?? 'updated_at');
$cPage   = (int) ($_GET['c_page'] ?? 1);
$hPage   = (int) ($_GET['h_page'] ?? 1);
$from    = newsletter_campaign_parse_date($_GET['from'] ?? '');
$to      = newsletter_campaign_parse_date($_GET['to'] ?? '');
if (!in_array($cStatus, newsletter_campaign_valid_statuses(), true)) {
    $cStatus = '';
}
if (!isset(newsletter_campaign_list_sorts()[$cSort])) {
    $cSort = 'updated_at';
}
if ($from !== null && $to !== null && $from > $to) {
    $swap = $from;
    $from = $to;
    $to = $swap;
}

$listQuery = [];
if ($search !== '') {
    $listQuery['search'] = $search;
}
if ($status !== '') {
    $listQuery['status'] = $status;
}

$campaignQuery = [];
if ($cSearch !== '') {
    $campaignQuery['c_search'] = $cSearch;
}
if ($cStatus !== '') {
    $campaignQuery['c_status'] = $cStatus;
}
if ($cSort !== 'updated_at') {
    $campaignQuery['c_sort'] = $cSort;
}
if ($from !== null) {
    $campaignQuery['from'] = $from;
}
if ($to !== null) {
    $campaignQuery['to'] = $to;
}

$sharedQuery = array_merge($listQuery, $campaignQuery);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = newsletter_admin_export_rows($search, $status);
    $filename = 'newsletter-subscribers-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Email', 'Name', 'Status', 'Source', 'Subscribed at', 'Confirmed at', 'Unsubscribed at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['email'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['source'] ?? ''),
            (string) ($row['subscribed_at'] ?? ''),
            (string) ($row['confirmed_at'] ?? ''),
            (string) ($row['unsubscribed_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$addEmail = '';
$addName  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'sync') {
        $sync = newsletter_admin_sync_from_brevo();
        if ($sync['ok']) {
            flash_set('success', 'Subscriber list refreshed from Brevo (' . (int) $sync['count'] . ' contacts).');
        } else {
            flash_set('error', $sync['error'] !== '' ? $sync['error'] : 'Sync failed.');
        }
        redirect('newsletter.php' . newsletter_admin_query_string($sharedQuery));
    }

    if ($action === 'add') {
        $addEmail = normalize_email((string) ($_POST['email'] ?? ''));
        $addName  = trim((string) ($_POST['name'] ?? ''));
        $result   = newsletter_admin_add_subscriber($addEmail, $addName);

        if ($result === 'invalid') {
            flash_set('error', 'Please enter a valid email address.');
        } elseif ($result === 'already') {
            flash_set('success', 'That address is already a confirmed subscriber.');
            redirect('newsletter.php' . newsletter_admin_query_string($sharedQuery));
        } elseif ($result === 'pending') {
            flash_set('success', 'Subscriber added to the pending list. Confirmation still follows the existing double opt-in flow.');
            redirect('newsletter.php' . newsletter_admin_query_string($sharedQuery));
        } else {
            flash_set('error', 'Could not add the subscriber. Check the newsletter integration and try again.');
        }
    }

    if ($action === 'cancel_schedule') {
        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        $result = newsletter_campaign_cancel_schedule($campaignId);
        flash_set($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Scheduled send cancelled.' : $result['error']);
        redirect('newsletter.php' . newsletter_admin_query_string($sharedQuery));
    }
}

$successMessage = flash_get('success');
$errorMessage   = flash_get('error');
$counts         = newsletter_admin_counts();
$syncMeta       = newsletter_admin_sync_meta();
$list           = newsletter_admin_list_subscribers($search, $status, $page);
$campaignList   = newsletter_campaign_list($cSearch, $cStatus, $cSort, $cPage, $from, $to);
$campaigns      = $campaignList['rows'];
$sendHistory    = newsletter_campaign_history_page($hPage, true);
$campaignStats  = newsletter_campaign_analytics($from, $to);
$configured     = newsletter_is_configured();
$tablesReady    = newsletter_admin_ensure_tables();
$sendReady      = newsletter_campaign_tables_ready();
$unsubReady     = newsletter_unsub_secret_configured();
$publicOrigin   = newsletter_public_base_url() !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/admin-theme-boot.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/webp" href="<?= asset_url('assets/images/icons/favicon/favicon.webp') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('assets/images/icons/favicon/apple-touch-icon.webp') ?>">
    <title>Newsletter | MoonAura Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= versioned_asset('dashboard/assets/css/admin.css', 'assets/css/admin.css') ?>">
</head>
<body class="admin-body">

    <div class="admin-wrapper">

        <?php include __DIR__ . '/includes/admin-sidebar.php'; ?>

        <div class="admin-main">

            <?php include __DIR__ . '/includes/admin-header.php'; ?>

            <div class="admin-content">

                <?php if ($successMessage): ?>
                    <div class="admin-alert admin-alert-success"><?= h($successMessage) ?></div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="admin-alert admin-alert-error"><?= h($errorMessage) ?></div>
                <?php endif; ?>

                <?php if (!$tablesReady): ?>
                    <div class="admin-alert admin-alert-warning">
                        Newsletter tables are missing. Apply database/migration_newsletter_admin.sql, then refresh.
                    </div>
                <?php elseif (!$sendReady): ?>
                    <div class="admin-alert admin-alert-warning">
                        Campaign sending tables are missing. Apply database/migration_newsletter_campaign_sending.sql, then refresh.
                    </div>
                <?php endif; ?>

                <?php if (!$configured): ?>
                    <div class="admin-alert admin-alert-warning">
                        Brevo newsletter integration is not configured. Subscriber actions will fail until it is set up.
                    </div>
                <?php endif; ?>

                <?php if (!$unsubReady): ?>
                    <div class="admin-alert admin-alert-warning">
                        NEWSLETTER_UNSUB_SECRET is not configured. Campaign sending and public unsubscribe links are disabled until a dedicated secret of at least 16 characters is set in the environment.
                    </div>
                <?php elseif (!$publicOrigin): ?>
                    <div class="admin-alert admin-alert-warning">
                        SITE_URL must be a public https origin before campaign emails can include a secure unsubscribe link.
                    </div>
                <?php endif; ?>

                <div class="admin-placeholder-grid admin-dashboard-stats">
                    <a href="newsletter.php<?= h(newsletter_admin_query_string($campaignQuery)) ?>" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-envelope-open-text"></i>
                            <h3>Total Subscribers</h3>
                            <p><?= (int) $counts['total'] ?></p>
                        </div>
                    </a>
                    <a href="newsletter.php<?= h(newsletter_admin_query_string($campaignQuery, ['status' => 'confirmed'])) ?>" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-circle-check"></i>
                            <h3>Confirmed</h3>
                            <p><?= (int) $counts['confirmed'] ?></p>
                        </div>
                    </a>
                    <a href="newsletter.php<?= h(newsletter_admin_query_string($campaignQuery, ['status' => 'pending'])) ?>" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-hourglass-half"></i>
                            <h3>Pending</h3>
                            <p><?= (int) $counts['pending'] ?></p>
                        </div>
                    </a>
                    <a href="newsletter.php<?= h(newsletter_admin_query_string($campaignQuery, ['status' => 'unsubscribed'])) ?>" style="text-decoration: none; color: inherit;">
                        <div class="admin-placeholder-card">
                            <i class="fa-solid fa-ban"></i>
                            <h3>Unsubscribed</h3>
                            <p><?= (int) $counts['unsubscribed'] ?></p>
                        </div>
                    </a>
                </div>

                <div class="admin-form-card newsletter-analytics-card">
                    <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Campaign Analytics</h3>
                    <p class="admin-field-hint">Counts come from local campaign and recipient rows. Accepted send means SMTP accepted the message. Delivery, opens, clicks, and bounces are not tracked.</p>
                    <form method="get" action="newsletter.php" class="admin-search-form newsletter-analytics-filter">
                        <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= h($search) ?>"><?php endif; ?>
                        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
                        <?php if ($cSearch !== ''): ?><input type="hidden" name="c_search" value="<?= h($cSearch) ?>"><?php endif; ?>
                        <?php if ($cStatus !== ''): ?><input type="hidden" name="c_status" value="<?= h($cStatus) ?>"><?php endif; ?>
                        <?php if ($cSort !== 'updated_at'): ?><input type="hidden" name="c_sort" value="<?= h($cSort) ?>"><?php endif; ?>
                        <label for="campaign-from">Created from</label>
                        <input type="date" id="campaign-from" name="from" value="<?= h((string) ($from ?? '')) ?>">
                        <label for="campaign-to">to</label>
                        <input type="date" id="campaign-to" name="to" value="<?= h((string) ($to ?? '')) ?>">
                        <button type="submit" class="admin-btn-secondary">Apply</button>
                        <?php if ($from !== null || $to !== null): ?>
                            <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($listQuery, array_diff_key($campaignQuery, ['from' => 1, 'to' => 1])))) ?>" class="admin-btn-secondary">Clear dates</a>
                        <?php endif; ?>
                    </form>
                    <div class="admin-placeholder-grid admin-dashboard-stats newsletter-campaign-stats">
                        <div class="admin-placeholder-card">
                            <h3>Total Campaigns</h3>
                            <p><?= (int) $campaignStats['total'] ?></p>
                        </div>
                        <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($sharedQuery, ['c_status' => 'draft']))) ?>" style="text-decoration: none; color: inherit;">
                            <div class="admin-placeholder-card">
                                <h3>Draft</h3>
                                <p><?= (int) $campaignStats['draft'] ?></p>
                            </div>
                        </a>
                        <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($sharedQuery, ['c_status' => 'scheduled']))) ?>" style="text-decoration: none; color: inherit;">
                            <div class="admin-placeholder-card">
                                <h3>Scheduled</h3>
                                <p><?= (int) $campaignStats['scheduled'] ?></p>
                            </div>
                        </a>
                        <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($sharedQuery, ['c_status' => 'sent']))) ?>" style="text-decoration: none; color: inherit;">
                            <div class="admin-placeholder-card">
                                <h3>Sent</h3>
                                <p><?= (int) $campaignStats['sent'] ?></p>
                            </div>
                        </a>
                        <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($sharedQuery, ['c_status' => 'failed']))) ?>" style="text-decoration: none; color: inherit;">
                            <div class="admin-placeholder-card">
                                <h3>Failed</h3>
                                <p><?= (int) $campaignStats['failed'] ?></p>
                            </div>
                        </a>
                        <div class="admin-placeholder-card">
                            <h3>Recipients Targeted</h3>
                            <p><?= (int) $campaignStats['recipients'] ?></p>
                        </div>
                        <div class="admin-placeholder-card">
                            <h3>Accepted Sends</h3>
                            <p><?= (int) $campaignStats['accepted_sends'] ?></p>
                        </div>
                        <div class="admin-placeholder-card">
                            <h3>Failed Recipient Sends</h3>
                            <p><?= (int) $campaignStats['failed_sends'] ?></p>
                        </div>
                    </div>
                    <p class="admin-field-hint">Test emails logged: <?= (int) $campaignStats['test_sends'] ?> (excluded from recipient totals). Opens, clicks, deliveries, and bounces: Tracking not configured.</p>
                </div>

                <div class="admin-detail-grid newsletter-admin-top">

                    <div class="admin-form-card">
                        <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Brevo Sync</h3>
                        <div class="admin-detail-row">
                            <span>Integration</span>
                            <span><?= $configured ? 'Configured' : 'Not configured' ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Last sync</span>
                            <span><?= h(newsletter_admin_format_datetime($syncMeta['last_sync_at'] ?: null)) ?></span>
                        </div>
                        <div class="admin-detail-row">
                            <span>Status</span>
                            <span>
                                <?php if ($syncMeta['last_sync_status'] === 'ok'): ?>
                                    <span class="admin-badge admin-badge-active">Success</span>
                                <?php elseif ($syncMeta['last_sync_status'] === 'error'): ?>
                                    <span class="admin-badge admin-badge-failed">Failed</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge-pending">Not synced</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($syncMeta['last_sync_status'] === 'ok' && $syncMeta['last_sync_count'] !== ''): ?>
                            <div class="admin-detail-row">
                                <span>Contacts stored</span>
                                <span><?= (int) $syncMeta['last_sync_count'] ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($syncMeta['last_sync_status'] === 'error' && $syncMeta['last_sync_error'] !== ''): ?>
                            <p class="admin-field-hint"><?= h($syncMeta['last_sync_error']) ?></p>
                        <?php endif; ?>
                        <form method="post" class="admin-form-actions">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sync">
                            <button type="submit" class="admin-btn-primary" <?= $configured ? '' : 'disabled' ?>>
                                <i class="fa-solid fa-rotate"></i>
                                Sync from Brevo
                            </button>
                        </form>
                    </div>

                    <div class="admin-form-card">
                        <h3 style="margin-top: 0; padding-top: 0; border-top: none; color: var(--admin-link);">Add Subscriber</h3>
                        <p class="admin-field-hint">Uses the existing pending-list double opt-in flow. Confirmed subscribers are not added twice.</p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">
                            <label for="newsletter-add-email">Email</label>
                            <input type="email" id="newsletter-add-email" name="email" value="<?= h($addEmail) ?>" required>
                            <label for="newsletter-add-name">Name (optional)</label>
                            <input type="text" id="newsletter-add-name" name="name" value="<?= h($addName) ?>" maxlength="160">
                            <div class="admin-form-actions">
                                <button type="submit" class="admin-btn-primary">
                                    <i class="fa-solid fa-plus"></i>
                                    Add Subscriber
                                </button>
                            </div>
                        </form>
                    </div>

                </div>

                <div class="admin-toolbar admin-orders-toolbar">
                    <form method="get" action="newsletter.php" class="admin-search-form">
                        <?php foreach ($campaignQuery as $key => $value): ?>
                            <input type="hidden" name="<?= h((string) $key) ?>" value="<?= h((string) $value) ?>">
                        <?php endforeach; ?>
                        <input
                            type="text"
                            name="search"
                            value="<?= h($search) ?>"
                            placeholder="Search by email or name..."
                        >
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="unsubscribed" <?= $status === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
                        </select>
                        <button type="submit" class="admin-btn-secondary">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                        <?php if ($search !== '' || $status !== ''): ?>
                            <a href="newsletter.php<?= h(newsletter_admin_query_string($campaignQuery)) ?>" class="admin-btn-secondary">Clear</a>
                        <?php endif; ?>
                    </form>

                    <div class="admin-orders-actions">
                        <span style="font-size: 14px; color: var(--text-light);">
                            <?= (int) $list['total'] ?> subscriber<?= (int) $list['total'] === 1 ? '' : 's' ?>
                        </span>
                        <a href="newsletter.php<?= h(newsletter_admin_query_string($listQuery, ['export' => 'csv'])) ?>" class="admin-btn-secondary">
                            <i class="fa-solid fa-file-csv"></i>
                            Export CSV
                        </a>
                        <a href="newsletter-campaign-form.php" class="admin-btn-primary">
                            <i class="fa-solid fa-plus"></i>
                            Create Campaign
                        </a>
                    </div>
                </div>

                <div class="admin-table-card">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Subscribed</th>
                                <th>Confirmed</th>
                                <th>Source</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($list['rows'])): ?>
                                <tr>
                                    <td colspan="7" class="admin-table-empty">
                                        <?= ($search !== '' || $status !== '') ? 'No subscribers match your search/filter.' : 'No subscribers cached yet. Sync from Brevo to load the audience.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($list['rows'] as $subscriber): ?>
                                    <tr>
                                        <td><?= h($subscriber['email']) ?></td>
                                        <td><?= h($subscriber['name'] !== '' && $subscriber['name'] !== null ? $subscriber['name'] : '—') ?></td>
                                        <td>
                                            <span class="admin-badge <?= h(newsletter_admin_badge_class((string) $subscriber['status'])) ?>">
                                                <?= h(ucfirst((string) $subscriber['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= h(newsletter_admin_format_datetime($subscriber['subscribed_at'] ?? null)) ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($subscriber['confirmed_at'] ?? null)) ?></td>
                                        <td><?= h(ucfirst((string) ($subscriber['source'] ?? 'brevo'))) ?></td>
                                        <td class="admin-table-actions">
                                            <a href="newsletter-subscriber.php?email=<?= h(rawurlencode((string) $subscriber['email'])) ?>" title="View">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if (($subscriber['status'] ?? '') !== 'unsubscribed'): ?>
                                                <form
                                                    method="post"
                                                    action="newsletter-unsubscribe.php"
                                                    onsubmit="return confirm('Unsubscribe this address from the newsletter? Customer accounts are not deleted.');"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="email" value="<?= h($subscriber['email']) ?>">
                                                    <button type="submit" class="admin-icon-btn-danger" title="Unsubscribe">
                                                        <i class="fa-solid fa-user-minus"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($list['total'] > NEWSLETTER_ADMIN_PAGE_SIZE): ?>
                        <div class="admin-pagination">
                            <span style="font-size: 13px; color: var(--text-light);">
                                Page <?= (int) $list['page'] ?> of <?= (int) $list['total_pages'] ?>
                            </span>
                            <div class="admin-pagination-links">
                                <?php if ($list['page'] > 1): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['page' => $list['page'] - 1])) ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($pageNum = 1; $pageNum <= $list['total_pages']; $pageNum++): ?>
                                    <?php if ($pageNum === $list['page']): ?>
                                        <span class="active"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['page' => $pageNum])) ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($list['page'] < $list['total_pages']): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['page' => $list['page'] + 1])) ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-table-card admin-recent-orders-card">
                    <div class="admin-recent-orders-head">
                        <h3>Campaign History</h3>
                        <a href="newsletter-campaign-form.php" class="admin-btn-secondary">Create Campaign</a>
                    </div>
                    <form method="get" action="newsletter.php" class="admin-search-form newsletter-campaign-filter">
                        <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= h($search) ?>"><?php endif; ?>
                        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
                        <?php if ($from !== null): ?><input type="hidden" name="from" value="<?= h($from) ?>"><?php endif; ?>
                        <?php if ($to !== null): ?><input type="hidden" name="to" value="<?= h($to) ?>"><?php endif; ?>
                        <input type="text" name="c_search" value="<?= h($cSearch) ?>" placeholder="Search campaigns...">
                        <select name="c_status" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            <?php foreach (newsletter_campaign_valid_statuses() as $option): ?>
                                <option value="<?= h($option) ?>" <?= $cStatus === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="c_sort" onchange="this.form.submit()">
                            <?php foreach (newsletter_campaign_list_sorts() as $sortKey => $sortLabel): ?>
                                <option value="<?= h($sortKey) ?>" <?= $cSort === $sortKey ? 'selected' : '' ?>><?= h($sortLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="admin-btn-secondary">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                        <?php if ($cSearch !== '' || $cStatus !== '' || $cSort !== 'updated_at'): ?>
                            <a href="newsletter.php<?= h(newsletter_admin_query_string(array_merge($listQuery, array_intersect_key($campaignQuery, ['from' => 1, 'to' => 1])))) ?>" class="admin-btn-secondary">Clear</a>
                        <?php endif; ?>
                    </form>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Subject</th>
                                <th>Created</th>
                                <th>Scheduled</th>
                                <th>Sent</th>
                                <th>Status</th>
                                <th>Accepted / Targeted</th>
                                <th>Failed</th>
                                <th>Engagement</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                                <tr>
                                    <td colspan="10" class="admin-table-empty">No campaigns match these filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($campaigns as $campaign): ?>
                                    <?php $campaignStatus = (string) ($campaign['status'] ?? 'draft'); ?>
                                    <tr>
                                        <td><?= h($campaign['name']) ?></td>
                                        <td><?= h($campaign['subject'] !== '' ? $campaign['subject'] : '—') ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($campaign['created_at'] ?? null)) ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($campaign['scheduled_at'] ?? null)) ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($campaign['completed_at'] ?? null)) ?></td>
                                        <td>
                                            <span class="admin-badge <?= h(newsletter_campaign_badge_class($campaignStatus)) ?>">
                                                <?= h(ucfirst($campaignStatus)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (in_array($campaignStatus, ['sent', 'failed', 'sending'], true)): ?>
                                                <?= (int) ($campaign['sent_count'] ?? 0) ?> / <?= (int) ($campaign['recipient_count'] ?? 0) ?>
                                            <?php else: ?>
                                                <?= isset($campaign['recipient_count']) && (int) $campaign['recipient_count'] > 0 ? (int) $campaign['recipient_count'] : '—' ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= in_array($campaignStatus, ['sent', 'failed', 'sending'], true) ? (int) ($campaign['failed_count'] ?? 0) : '—' ?></td>
                                        <td>Not available</td>
                                        <td class="admin-table-actions">
                                            <a href="newsletter-campaign-form.php?id=<?= (int) $campaign['id'] ?>" title="<?= in_array($campaignStatus, ['draft', 'failed', 'cancelled'], true) ? 'Edit' : 'View' ?>">
                                                <i class="fa-solid <?= in_array($campaignStatus, ['draft', 'failed', 'cancelled'], true) ? 'fa-pen' : 'fa-eye' ?>"></i>
                                            </a>
                                            <?php if ($campaignStatus === 'scheduled'): ?>
                                                <form
                                                    method="post"
                                                    onsubmit="return confirm('Cancel this scheduled send?');"
                                                >
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="cancel_schedule">
                                                    <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>">
                                                    <button type="submit" class="admin-icon-btn-danger" title="Cancel schedule">
                                                        <i class="fa-solid fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($campaignList['total'] > NEWSLETTER_CAMPAIGN_PAGE_SIZE): ?>
                        <div class="admin-pagination">
                            <span style="font-size: 13px; color: var(--text-light);">
                                Page <?= (int) $campaignList['page'] ?> of <?= (int) $campaignList['total_pages'] ?>
                            </span>
                            <div class="admin-pagination-links">
                                <?php if ($campaignList['page'] > 1): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['c_page' => $campaignList['page'] - 1])) ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($pageNum = 1; $pageNum <= $campaignList['total_pages']; $pageNum++): ?>
                                    <?php if ($pageNum === $campaignList['page']): ?>
                                        <span class="active"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['c_page' => $pageNum])) ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($campaignList['page'] < $campaignList['total_pages']): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['c_page' => $campaignList['page'] + 1])) ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-table-card admin-recent-orders-card">
                    <div class="admin-recent-orders-head">
                        <h3>Send Log</h3>
                    </div>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Accepted / Targeted</th>
                                <th>Started</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sendHistory['rows'])): ?>
                                <tr>
                                    <td colspan="6" class="admin-table-empty">No sends logged yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sendHistory['rows'] as $send): ?>
                                    <tr>
                                        <td>
                                            <a href="newsletter-campaign-form.php?id=<?= (int) $send['campaign_id'] ?>">
                                                <?= h((string) $send['campaign_name']) ?>
                                            </a>
                                        </td>
                                        <td><?= !empty($send['is_test']) ? 'Test' : 'Campaign' ?></td>
                                        <td>
                                            <span class="admin-badge <?= h(newsletter_campaign_badge_class((string) $send['status'])) ?>">
                                                <?= h(ucfirst((string) $send['status'])) ?>
                                            </span>
                                            <?php if (!empty($send['failure_reason'])): ?>
                                                <div class="admin-field-hint"><?= h((string) $send['failure_reason']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int) ($send['sent_count'] ?? 0) ?> / <?= (int) ($send['recipient_count'] ?? 0) ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($send['started_at'] ?? null)) ?></td>
                                        <td><?= h(newsletter_admin_format_datetime($send['completed_at'] ?? null)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php if ($sendHistory['total'] > NEWSLETTER_CAMPAIGN_PAGE_SIZE): ?>
                        <div class="admin-pagination">
                            <span style="font-size: 13px; color: var(--text-light);">
                                Page <?= (int) $sendHistory['page'] ?> of <?= (int) $sendHistory['total_pages'] ?>
                            </span>
                            <div class="admin-pagination-links">
                                <?php if ($sendHistory['page'] > 1): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['h_page' => $sendHistory['page'] - 1])) ?>">Previous</a>
                                <?php endif; ?>
                                <?php for ($pageNum = 1; $pageNum <= $sendHistory['total_pages']; $pageNum++): ?>
                                    <?php if ($pageNum === $sendHistory['page']): ?>
                                        <span class="active"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['h_page' => $pageNum])) ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($sendHistory['page'] < $sendHistory['total_pages']): ?>
                                    <a href="newsletter.php<?= h(newsletter_admin_query_string($sharedQuery, ['h_page' => $sendHistory['page'] + 1])) ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </div>

</body>
</html>

<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_helper.php';

// Auth check - Strictly restricted to Main Admin
require_main_admin();

$admin_username = $_SESSION['username'] ?? 'Admin';
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $post_action = trim($_POST['post_action'] ?? 'send');

    // ── Retry a specific failed/pending notification ───────────
    if ($post_action === 'retry_notification') {
        $notif_id = (int)($_POST['notif_id'] ?? 0);
        if ($notif_id > 0) {
            $ok = NotificationHelper::retryNotification($conn, $notif_id);
            $success_msg = $ok
                ? "Notification #{$notif_id} was successfully re-sent."
                : "Retry attempted for #{$notif_id} but SMTP delivery failed. Check SMTP settings or try again later.";
            if (!$ok) $error_msg = $success_msg and $success_msg = '';
        }

    // ── Process entire pending/failed queue ────────────────────
    } elseif ($post_action === 'process_queue') {
        $results = NotificationHelper::processQueue($conn);
        $success_msg = "Queue processed: {$results['attempted']} attempted, "
                     . "{$results['sent']} sent, {$results['failed']} failed.";

    // ── Manual single-recipient dispatch ──────────────────────
    } else {
        $channel = 'email';
        $booking_id     = (int)($_POST['booking_id'] ?? 0);
        $custom_contact = trim($_POST['custom_contact'] ?? '');
        $subject        = trim($_POST['subject'] ?? 'Notification from Tyoy Creation');
        $message        = trim($_POST['message'] ?? '');

        $recipient_name    = 'Client';
        $recipient_contact = $custom_contact;

        if ($booking_id > 0) {
            $b_stmt = $conn->prepare("SELECT client_name, client_phone, client_email FROM bookings WHERE id = ? LIMIT 1");
            $b_stmt->bind_param("i", $booking_id);
            $b_stmt->execute();
            $b_res = $b_stmt->get_result();
            if ($b_res && $b_row = $b_res->fetch_assoc()) {
                $b_row = qes_decrypt_booking($b_row);
                $recipient_name = $b_row['client_name'];
                if (empty($recipient_contact)) {
                    $recipient_contact = $b_row['client_email'];
                }
            }
            $b_stmt->close();
        }

        if (empty($recipient_contact) || empty($message)) {
            $error_msg = "Please specify a recipient email address and a message.";
        } elseif (!filter_var($recipient_contact, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address (e.g., client@example.com).";
        } else {
            $mail_sent = NotificationHelper::sendCustomEmail($recipient_contact, $recipient_name, $subject, $message);
            $log_status = $mail_sent ? 'sent' : 'failed';
            $ok = NotificationHelper::logNotification(
                $conn,
                $booking_id > 0 ? $booking_id : null,
                $recipient_name, $recipient_contact,
                'email', 'custom', $subject, $message, $log_status
            );
            if ($ok && $mail_sent) {
                $success_msg = "Email notification successfully dispatched and recorded for {$recipient_name} ({$recipient_contact}).";
            } elseif ($ok && !$mail_sent) {
                $error_msg = "Email queued but SMTP delivery failed. It will be available for retry in the log below.";
            } else {
                $error_msg = "Failed to save notification.";
            }
        }
    }
}

// Fetch all clients for recipient dropdown
$clients = [];
$c_res = $conn->query("SELECT id, reference_no, client_name, client_phone, client_email, event_title FROM bookings");
if ($c_res) {
    while ($r = $c_res->fetch_assoc()) {
        $clients[] = qes_decrypt_booking($r);
    }
    usort($clients, fn($a, $b) => strcasecmp($a['client_name'] ?? '', $b['client_name'] ?? ''));
}

// Fetch recent notifications log (include new queue columns)
$notifications = [];
$n_res = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 100");
if ($n_res) {
    while ($r = $n_res->fetch_assoc()) {
        $notifications[] = qes_decrypt_notification($r);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyoy Creation - Email Notifications</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .notifications-container {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 28px;
        }

        .sender-card, .logs-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 28px;
        }

        .channel-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #1e3a24;
            background: #eef2ee;
            border: 1px solid #c7d8c7;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .char-counter {
            font-size: 12px;
            color: var(--text-muted);
            text-align: right;
            margin-top: 4px;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title">Notifications (Email)</h1>
            <div class="admin-profile">
                <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
            </div>
        </header>

        <div class="admin-body">
            <?php if (!empty($success_msg)): ?>
                <div style="background: #d1fae5; color: #065f46; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?= htmlspecialchars($success_msg) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div style="background: #fee2e2; color: #dc2626; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>

            <div class="notifications-container">
                <!-- Direct Dispatch Card -->
                <div class="sender-card">
                    <div class="channel-indicator">
                        <i class="fa-solid fa-envelope"></i> Send Email Notification
                    </div>

                    <form method="POST" action="a_notifications.php">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="post_action" value="send">
                        <input type="hidden" name="channel" value="email">

                        <div class="form-group">
                            <label class="form-label">Select Client Recipient</label>
                            <select name="booking_id" id="recipientSelect" class="form-control" onchange="autoFillRecipient()">
                                <option value="">-- Choose Booked Client --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>" data-phone="<?= htmlspecialchars($c['client_phone']) ?>" data-email="<?= htmlspecialchars($c['client_email']) ?>" data-name="<?= htmlspecialchars($c['client_name']) ?>">
                                        <?= htmlspecialchars($c['client_name']) ?> (<?= htmlspecialchars($c['reference_no']) ?> - <?= htmlspecialchars($c['event_title']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" id="contactFieldLabel">Recipient Email Address *</label>
                            <input type="email" name="custom_contact" id="customContactInput" class="form-control" placeholder="client@example.com" required>
                        </div>

                        <div class="form-group" id="subjectGroup">
                            <label class="form-label">Email Subject *</label>
                            <input type="text" name="subject" id="subjectInput" class="form-control" value="Update Regarding Your Event - Tyoy Creation" required>
                        </div>

                        <div class="form-group">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label class="form-label" style="margin-bottom: 0;">Message Content *</label>
                                <select onchange="loadQuickTemplate(this.value)" style="font-size: 12px; padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border-color);">
                                    <option value="">-- Insert Template --</option>
                                    <option value="reminder">Event Reminder Template</option>
                                    <option value="coord">Consultation & Meeting Request</option>
                                    <option value="payment">Payment / Downpayment Reminder</option>
                                </select>
                            </div>
                            <textarea name="message" id="messageTextarea" class="form-control" rows="6" placeholder="Type email message..." required oninput="updateCharCount()"></textarea>
                            <div class="char-counter" id="charCount">0 characters</div>
                        </div>

                        <button type="submit" class="btn-primary" style="width: 100%; padding: 12px; font-size: 14px; margin-top: 10px;">
                            <i class="fa-solid fa-paper-plane"></i> Send Email
                        </button>
                    </form>
                </div>

                <!-- Notifications Queue Log -->
                <div class="logs-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h2 style="font-size: 18px; margin:0;">Notifications Log</h2>
                        <form method="POST" action="a_notifications.php" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="post_action" value="process_queue">
                            <button type="submit" class="btn-primary" style="font-size:12px; padding:7px 14px;" title="Retry all pending and failed notifications">
                                <i class="fa-solid fa-rotate-right"></i> Process Queue
                            </button>
                        </form>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Attempts</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notifications)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                            No notifications logged yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $n):
                                        $n_status   = $n['status'] ?? 'sent';
                                        $n_attempts = (int)($n['attempt_count'] ?? 1);
                                        $n_max      = (int)($n['max_attempts']  ?? 3);
                                        $n_sent_at  = !empty($n['sent_at'])   ? date('M d, g:i A', strtotime($n['sent_at'])) : null;
                                        $n_error    = !empty($n['last_error']) ? htmlspecialchars($n['last_error'])           : null;
                                    ?>
                                        <tr>
                                            <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;">
                                                <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?>
                                                <?php if ($n_sent_at): ?>
                                                    <div style="color:#16a34a; font-size:11px;">Sent <?= $n_sent_at ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 700; font-size: 13px;"><?= htmlspecialchars($n['recipient_name']) ?></div>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($n['recipient_contact']) ?></div>
                                            </td>
                                            <td style="font-size: 12px; max-width: 180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($n['subject'] ?? '') ?>">
                                                <?= htmlspecialchars($n['subject'] ?? '') ?>
                                            </td>
                                            <td style="text-align:center; font-size:13px;">
                                                <span title="<?= $n_attempts ?> of <?= $n_max ?> max"><?= $n_attempts ?>/<?= $n_max ?></span>
                                            </td>
                                            <td>
                                                <?php if ($n_status === 'sent'): ?>
                                                    <span class="badge badge-approved"><span class="badge-dot"></span> Sent</span>
                                                <?php elseif ($n_status === 'pending'): ?>
                                                    <span class="badge" style="background:#fef9c3;color:#854d0e;"><span class="badge-dot" style="background:#ca8a04;"></span> Pending</span>
                                                <?php else: ?>
                                                    <span class="badge badge-rejected" title="<?= $n_error ?>">
                                                        <span class="badge-dot"></span> Failed
                                                    </span>
                                                    <?php if ($n_error): ?>
                                                        <div style="font-size:10px;color:#dc2626;margin-top:3px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= $n_error ?>"><?= $n_error ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($n_status !== 'sent'): ?>
                                                    <form method="POST" action="a_notifications.php" style="margin:0;">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="post_action" value="retry_notification">
                                                        <input type="hidden" name="notif_id" value="<?= (int)$n['id'] ?>">
                                                        <button type="submit" class="btn-primary" style="font-size:11px;padding:5px 10px;background:#2563eb;" title="Retry sending this notification">
                                                            <i class="fa-solid fa-rotate-right"></i> Retry
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="font-size:11px;color:var(--text-muted);">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>

    <script>
        function autoFillRecipient() {
            const select = document.getElementById('recipientSelect');
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value) {
                const email = selectedOpt.getAttribute('data-email');
                if (email) {
                    document.getElementById('customContactInput').value = email;
                }
            }
        }

        function loadQuickTemplate(type) {
            const select = document.getElementById('recipientSelect');
            const opt = select.options[select.selectedIndex];
            const name = (opt && opt.value) ? opt.getAttribute('data-name') : 'Valued Client';

            const textarea = document.getElementById('messageTextarea');
            const subject = document.getElementById('subjectInput');

            if (type === 'reminder') {
                subject.value = 'Friendly Reminder: Your Upcoming Event with Tyoy Creation';
                textarea.value = `Dear ${name},\n\nThis is a friendly reminder regarding your upcoming event with Tyoy Creation. Our team is making all necessary preparations to ensure your celebration is flawless.\n\nPlease reach out if you have any questions or additional details to provide before the event date.\n\nWarm regards,\nTyoy Creation Planning Team`;
            } else if (type === 'coord') {
                subject.value = 'Coordination & Setup Alignment - Tyoy Creation';
                textarea.value = `Hello ${name},\n\nOur lead event coordinator would like to schedule a quick 15-minute alignment call with you to finalize your program, venue layout, and setup details.\n\nPlease let us know your available time today or tomorrow so we can schedule accordingly.\n\nWarm regards,\nTyoy Creation Planning Team`;
            } else if (type === 'payment') {
                subject.value = 'Booking Deposit Notice - Tyoy Creation';
                textarea.value = `Dear ${name},\n\nThank you for choosing Tyoy Creation for your special day! This is a gentle reminder regarding the reservation deposit for your booked event.\n\nPlease coordinate with our team to confirm your venue reservation and lock in your styling package.\n\nWarm regards,\nTyoy Creation Planning Team`;
            }
            updateCharCount();
        }

        function updateCharCount() {
            const text = document.getElementById('messageTextarea').value;
            document.getElementById('charCount').textContent = `${text.length} characters`;
        }
    </script>
</body>
</html>

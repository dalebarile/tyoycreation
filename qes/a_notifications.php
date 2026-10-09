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
            $msg = $ok
                ? "Notification #{$notif_id} was successfully re-sent."
                : "Retry attempted for #{$notif_id} but SMTP delivery failed. Check SMTP settings or try again later.";

            if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => $ok,
                    'message' => $msg,
                    'notif_id' => $notif_id,
                    'status' => $ok ? 'sent' : 'failed'
                ]);
                exit;
            }

            if ($ok) {
                $success_msg = $msg;
            } else {
                $error_msg = $msg;
            }
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
            $b_stmt = $conn->prepare("SELECT client_name, client_phone, client_email, reference_no, event_title, event_date, event_start, venue FROM bookings WHERE id = ? LIMIT 1");
            $b_stmt->bind_param("i", $booking_id);
            $b_stmt->execute();
            $b_res = $b_stmt->get_result();
            if ($b_res && $b_row = $b_res->fetch_assoc()) {
                $b_row = qes_decrypt_booking($b_row);
                $recipient_name = $b_row['client_name'] ?? 'Client';
                if (empty($recipient_contact)) {
                    $recipient_contact = $b_row['client_email'];
                }

                $placeholders = [
                    '{client_name}'   => $recipient_name,
                    '{reference_no}'  => $b_row['reference_no'] ?? '',
                    '{event_title}'   => $b_row['event_title'] ?? '',
                    '{event_date}'    => !empty($b_row['event_date']) ? date('M d, Y', strtotime($b_row['event_date'])) : (!empty($b_row['event_start']) ? date('M d, Y g:i A', strtotime($b_row['event_start'])) : ''),
                    '{venue}'         => $b_row['venue'] ?? '',
                    '{business_name}' => 'Tyoy Creation',
                    '{business_phone}'=> get_setting($conn, 'contact_phone', '0917-000-000')
                ];
                $message = str_replace(array_keys($placeholders), array_values($placeholders), $message);
                $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
            }
            $b_stmt->close();
        } else {
            $placeholders = [
                '{business_name}' => 'Tyoy Creation',
                '{business_phone}'=> get_setting($conn, 'contact_phone', '0917-000-000')
            ];
            $message = str_replace(array_keys($placeholders), array_values($placeholders), $message);
            $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
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
        .table-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 24px;
        }

        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.active {
            display: flex;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 620px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .placeholder-tag {
            background: #eef2ee;
            color: #2b5329;
            border: 1px solid #c7d8c7;
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 11.5px;
            font-family: monospace;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
        }

        .placeholder-tag:hover {
            background: #2b5329;
            color: #ffffff;
            border-color: #2b5329;
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
            <h1 class="admin-page-title"><i class="fa-solid fa-bell" style="color: var(--primary); margin-right: 8px;"></i> Email Notifications Log</h1>
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <button type="button" class="btn-primary" onclick="openSendEmailModal()" style="padding: 9px 18px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 3px 10px rgba(43,83,41,0.2);">
                    <i class="fa-solid fa-paper-plane"></i> Send Email Notification
                </button>
                <div class="admin-profile">
                    <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                    <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
                </div>
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

            <!-- Full-Width Notifications Queue Log -->
            <div class="table-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h2 style="font-size: 18px; margin: 0; font-weight: 800; color: var(--text-primary);">Notifications Log</h2>
                        <p style="font-size: 13px; color: var(--text-secondary); margin: 3px 0 0 0;">Complete audit trail of system notices and custom emails dispatched to clients</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="btn-primary" onclick="openSendEmailModal()" style="font-size: 12.5px; padding: 8px 16px; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-paper-plane"></i> Send Email Notification
                        </button>
                        <form method="POST" action="a_notifications.php" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="post_action" value="process_queue">
                            <button type="submit" class="btn-secondary" style="font-size: 12.5px; padding: 8px 14px; display: inline-flex; align-items: center; gap: 6px;" title="Retry all pending and failed notifications">
                                <i class="fa-solid fa-rotate-right"></i> Process Queue
                            </button>
                        </form>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="custom-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Recipient</th>
                                <th>Subject</th>
                                <th style="text-align: center;">Attempts</th>
                                <th>Status</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notifications)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 36px;">
                                        <i class="fa-regular fa-bell-slash" style="font-size: 26px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                        No notifications logged yet. Click <strong>Send Email Notification</strong> to dispatch your first notice.
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
                                        <td style="font-size: 12px; max-width: 260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($n['subject'] ?? '') ?>">
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
                                                    <div style="font-size:10px;color:#dc2626;margin-top:3px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= $n_error ?>"><?= $n_error ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($n_status !== 'sent'): ?>
                                                <form method="POST" action="a_notifications.php" style="margin:0;" onsubmit="handleNotificationRetry(event, this, <?= (int)$n['id'] ?>)">
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

        </div> <!-- .admin-body -->
    </main>

    <!-- Send Email Notification Modal with Placeholders -->
    <div class="modal-backdrop" id="sendEmailModal">
        <div class="modal-card">
            <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fbfdfa;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                        <i class="fa-solid fa-paper-plane"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 16px; margin: 0; font-weight: 700;">Send Email Notification</h3>
                        <p style="font-size: 12px; color: var(--text-secondary); margin: 2px 0 0 0;">Compose and dispatch email message directly to client</p>
                    </div>
                </div>
                <button type="button" onclick="closeSendEmailModal()" style="background: transparent; border: none; font-size: 20px; color: #94a3b8; cursor: pointer;" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="a_notifications.php" style="margin: 0; display: flex; flex-direction: column; overflow-y: auto;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="post_action" value="send">
                <input type="hidden" name="channel" value="email">

                <div style="padding: 22px 24px; overflow-y: auto; max-height: calc(85vh - 140px);">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-size: 13px; font-weight: 600;">Select Client Recipient</label>
                        <select name="booking_id" id="recipientSelect" class="form-control" onchange="autoFillRecipient()">
                            <option value="">-- Choose Booked Client (or enter manual email below) --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-phone="<?= htmlspecialchars($c['client_phone']) ?>" 
                                        data-email="<?= htmlspecialchars($c['client_email']) ?>" 
                                        data-name="<?= htmlspecialchars($c['client_name']) ?>"
                                        data-ref="<?= htmlspecialchars($c['reference_no']) ?>"
                                        data-event="<?= htmlspecialchars($c['event_title']) ?>">
                                    <?= htmlspecialchars($c['client_name']) ?> (<?= htmlspecialchars($c['reference_no']) ?> - <?= htmlspecialchars($c['event_title']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-size: 13px; font-weight: 600;">Recipient Email Address *</label>
                        <input type="email" name="custom_contact" id="customContactInput" class="form-control" placeholder="client@example.com" value="<?= htmlspecialchars($_GET['contact'] ?? '') ?>" required>
                    </div>

                    <div class="form-group" style="margin-bottom: 14px;">
                        <label class="form-label" style="font-size: 13px; font-weight: 600;">Email Subject *</label>
                        <input type="text" name="subject" id="subjectInput" class="form-control" value="Update Regarding Your Event - Tyoy Creation" required>
                    </div>

                    <!-- Dynamic Placeholders in Modal -->
                    <div style="margin-bottom: 14px; background: #f8faf8; border: 1px solid #dce5dc; border-radius: 10px; padding: 12px 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-code" style="color: var(--primary);"></i> Available Placeholders:
                            </span>
                            <span style="font-size: 11px; color: var(--text-muted);">Click placeholder tag to insert</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                            <span class="placeholder-tag" onclick="insertPlaceholder('{client_name}')" title="Insert Client Name">{client_name}</span>
                            <span class="placeholder-tag" onclick="insertPlaceholder('{reference_no}')" title="Insert Booking Reference">{reference_no}</span>
                            <span class="placeholder-tag" onclick="insertPlaceholder('{event_title}')" title="Insert Event Title">{event_title}</span>
                            <span class="placeholder-tag" onclick="insertPlaceholder('{event_date}')" title="Insert Event Date">{event_date}</span>
                            <span class="placeholder-tag" onclick="insertPlaceholder('{venue}')" title="Insert Venue">{venue}</span>
                            <span class="placeholder-tag" onclick="insertPlaceholder('{business_name}')" title="Insert Business Name">{business_name}</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label class="form-label" style="margin-bottom: 0; font-size: 13px; font-weight: 600;">Message Content *</label>
                            <select onchange="loadQuickTemplate(this.value)" style="font-size: 12px; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border-color); outline: none;">
                                <option value="">-- Quick Templates --</option>
                                <option value="reminder">Event Reminder</option>
                                <option value="coord">Consultation &amp; Meeting</option>
                                <option value="payment">Downpayment Notice</option>
                            </select>
                        </div>
                        <textarea name="message" id="messageTextarea" class="form-control" rows="6" placeholder="Dear {client_name},&#10;&#10;We are writing regarding your booking {reference_no}..." required oninput="updateCharCount()"></textarea>
                        <div class="char-counter" id="charCount">0 characters</div>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeSendEmailModal()" style="padding: 10px 18px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding: 10px 22px; font-size: 13px; font-weight: 700; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-paper-plane"></i> Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openSendEmailModal() {
            const m = document.getElementById('sendEmailModal');
            if (m) m.classList.add('active');
        }

        function closeSendEmailModal() {
            const m = document.getElementById('sendEmailModal');
            if (m) m.classList.remove('active');
        }

        function insertPlaceholder(tag) {
            const textarea = document.getElementById('messageTextarea');
            if (!textarea) return;

            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;

            if (start !== undefined && end !== undefined) {
                textarea.value = text.substring(0, start) + tag + text.substring(end);
                textarea.selectionStart = textarea.selectionEnd = start + tag.length;
            } else {
                textarea.value += tag;
            }
            textarea.focus();
            updateCharCount();
        }

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
            const name = (opt && opt.value) ? opt.getAttribute('data-name') : '{client_name}';
            const ref = (opt && opt.value) ? opt.getAttribute('data-ref') : '{reference_no}';

            const textarea = document.getElementById('messageTextarea');
            const subject = document.getElementById('subjectInput');

            if (type === 'reminder') {
                subject.value = 'Friendly Reminder: Your Upcoming Event with Tyoy Creation';
                textarea.value = `Dear ${name},\n\nThis is a friendly reminder regarding your upcoming event with Tyoy Creation (Booking Ref: ${ref}). Our styling and coordination team is making all necessary preparations to ensure your celebration is flawless.\n\nPlease reach out if you have any questions or additional details to provide before the event date.\n\nWarm regards,\nTyoy Creation Planning Team`;
            } else if (type === 'coord') {
                subject.value = 'Coordination & Setup Alignment - Tyoy Creation';
                textarea.value = `Hello ${name},\n\nOur lead event coordinator would like to schedule a quick 15-minute alignment call regarding your booking ${ref} to finalize your program, venue layout, and setup details.\n\nPlease let us know your available time today or tomorrow so we can schedule accordingly.\n\nWarm regards,\nTyoy Creation Planning Team`;
            } else if (type === 'payment') {
                subject.value = 'Booking Deposit Notice - Tyoy Creation';
                textarea.value = `Dear ${name},\n\nThank you for choosing Tyoy Creation for your special day! This is a gentle reminder regarding the reservation deposit for booking ${ref}.\n\nPlease coordinate with our team to confirm your venue reservation and lock in your styling package.\n\nWarm regards,\nTyoy Creation Planning Team`;
            }
            updateCharCount();
        }

        function updateCharCount() {
            const text = document.getElementById('messageTextarea').value;
            document.getElementById('charCount').textContent = `${text.length} characters`;
        }

        // Close on ESC or backdrop click
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSendEmailModal();
        });
        window.addEventListener('click', (e) => {
            const m = document.getElementById('sendEmailModal');
            if (m && e.target === m) closeSendEmailModal();
        });

        // Smooth Asynchronous Notification Retry
        function handleNotificationRetry(e, form, id) {
            e.preventDefault();
            const btn = form.querySelector('button');
            if (!btn || btn.disabled) return;
            const oldHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
            btn.style.opacity = '0.85';

            const fd = new FormData(form);
            fd.append('ajax', '1');

            fetch('a_notifications.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const tr = form.closest('tr');
                    if (tr) {
                        // Update Status (cell index 4) to Sent badge
                        if (tr.cells[4]) {
                            tr.cells[4].innerHTML = '<span class="badge badge-approved"><span class="badge-dot"></span> Sent</span>';
                        }
                        // Update Attempts count (cell index 3)
                        if (tr.cells[3]) {
                            const span = tr.cells[3].querySelector('span') || tr.cells[3];
                            const txt = span.textContent.trim();
                            const parts = txt.split('/');
                            if (parts.length === 2) {
                                const cur = (parseInt(parts[0], 10) || 0) + 1;
                                span.textContent = cur + '/' + parts[1];
                            }
                        }
                        // Replace Retry button with dash in cell index 5
                        if (tr.cells[5]) {
                            tr.cells[5].innerHTML = '<span style="font-size:11px;color:var(--text-muted);">&mdash;</span>';
                        }
                    }
                    showNotifToast(data.message || 'Notification successfully re-sent!', 'success');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = oldHtml;
                    btn.style.opacity = '1';
                    showNotifToast(data.message || 'Delivery failed. Check SMTP configuration.', 'error');
                }
            })
            .catch(err => {
                // Fallback to standard form submission
                form.submit();
            });
        }

        function showNotifToast(message, type) {
            let container = document.getElementById('notifToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notifToastContainer';
                container.style.cssText = 'position:fixed;top:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            const isSuccess = (type === 'success');
            toast.style.cssText = `display:flex;align-items:center;gap:12px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);pointer-events:auto;transition:all 0.3s ease;transform:translateY(-10px);opacity:0;${isSuccess ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;'}`;
            toast.innerHTML = `<i class="fa-solid ${isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation'}" style="font-size:16px;color:${isSuccess ? '#059669' : '#dc2626'};"></i><span>${message}</span>`;
            container.appendChild(toast);
            requestAnimationFrame(() => {
                toast.style.transform = 'translateY(0)';
                toast.style.opacity = '1';
            });
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-10px)';
                setTimeout(() => toast.remove(), 350);
            }, 4500);
        }

        // Automatically open modal if recipient/contact GET param provided
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('contact') || params.get('recipient') || <?= !empty($error_msg) ? 'true' : 'false' ?>) {
                openSendEmailModal();
            }
        });
    </script>
</body>
</html>

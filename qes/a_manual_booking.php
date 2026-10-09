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
    $client_name = trim($_POST['client_name'] ?? '');
    $client_phone = preg_replace('/[^0-9]/', '', trim($_POST['client_phone'] ?? ''));
    $client_email = trim($_POST['client_email'] ?? '');
    $client_address = trim($_POST['client_address'] ?? '');
    
    $event_title = trim($_POST['event_title'] ?? '');
    $event_type = trim($_POST['event_type'] ?? 'Weddings');
    $event_date = trim($_POST['event_date'] ?? '');
    $event_time = trim($_POST['event_time'] ?? '10:00');
    $guest_count = (int)($_POST['guest_count'] ?? 50);
    $location_venue = trim($_POST['location_venue'] ?? '');
    
    $requirements = $_POST['service_requirements'] ?? [];
    $service_requirements = is_array($requirements) ? implode(', ', $requirements) : trim($requirements);
    $special_notes = trim($_POST['special_notes'] ?? '');
    $send_notice = isset($_POST['send_notice']);

    $createResult = create_booking_inquiry($conn, [
        'client_name'          => $client_name,
        'client_email'         => $client_email,
        'client_phone'         => $client_phone,
        'client_address'       => $client_address,
        'event_title'          => $event_title,
        'event_type'           => $event_type,
        'event_date'           => $event_date,
        'event_time'           => $event_time,
        'guest_count'          => $guest_count,
        'location_venue'       => $location_venue,
        'service_requirements' => $service_requirements,
        'special_notes'        => $special_notes,
        'status'               => 'approved',
        'source'               => 'manual_entry',
        'check_conflict'       => true,
        'send_notice'          => $send_notice ? 'approval' : 'none',
        'ref_prefix'           => "EV-" . date('Y') . "-M"
    ]);

    if ($createResult['success']) {
        $success_msg = "Manual booking '{$event_title}' (Ref: {$createResult['reference_no']}) was successfully saved as APPROVED and added directly to the Master Calendar!";
    } else {
        $error_msg = $createResult['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyoy Creation - Manual Booking Entry</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .form-card-container {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 36px;
            max-width: 820px;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title">Manual Booking Entry</h1>
            <div class="admin-profile">
                <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
            </div>
        </header>

        <div class="admin-body">
            <?php if (!empty($success_msg)): ?>
                <div style="background: #d1fae5; color: #065f46; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; font-size: 14px; font-weight: 600;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($success_msg) ?></span>
                    </div>
                    <a href="a_calendar.php" class="btn-primary" style="padding: 6px 14px; font-size: 12px;">View on Calendar</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div style="background: #fee2e2; color: #dc2626; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-size: 14px;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>

            <div class="form-card-container">
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 24px;">
                    Enter walk-in, phone-in, or social media bookings directly into the system. This will save immediately as an <strong>Approved</strong> event on the Master Calendar.
                </p>

                <form method="POST" action="a_manual_booking.php">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Client Full Name *</label>
                            <input type="text" name="client_name" id="manualClientName" class="form-control" placeholder="e.g. Robert Tan" required oninput="validateManualField(this)">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number *</label>
                            <input type="tel" name="client_phone" id="manualClientPhone" class="form-control" placeholder="09XXXXXXXXX (11 digits)" required inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" oninput="validatePhoneOnly(this)" onkeypress="return isNumberKey(event)" onpaste="handlePhonePaste(event)">
                            <small id="manualPhoneFeedback" style="display: none; margin-top: 5px; font-size: 11.5px; font-weight: 600;"></small>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="client_email" class="form-control" placeholder="client@gmail.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Client Address / City</label>
                            <input type="text" name="client_address" class="form-control" placeholder="e.g. Pasig City">
                        </div>
                    </div>

                    <hr style="border: none; border-top: 1px solid var(--border-color); margin: 20px 0;">

                    <div class="form-group">
                        <label class="form-label">Event Title *</label>
                        <input type="text" name="event_title" id="manualEventTitle" class="form-control" placeholder="e.g. Tan & Lim Silver Anniversary" required oninput="validateManualField(this)">
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Event Type *</label>
                            <select name="event_type" id="manualEventType" class="form-control" required onchange="validateManualField(this)">
                                <option value="Kids Party">Kids Party</option>
                                <option value="Weddings">Weddings</option>
                                <option value="Birthday Parties">Birthday Parties (Legacy)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expected Guest Count</label>
                            <input type="number" name="guest_count" class="form-control" value="100" min="5">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Event Date *</label>
                            <input type="date" name="event_date" id="manualEventDate" class="form-control" required oninput="validateManualField(this)">
                            <small id="manualLeadTimeHint" style="display: block; margin-top: 6px; font-size: 11px; line-height: 1.35; color: var(--text-secondary);"></small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="event_time" class="form-control" value="14:00">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Venue / Location *</label>
                        <input type="text" name="location_venue" id="manualLocationVenue" class="form-control" placeholder="e.g. Shangri-La Grand Ballroom" required oninput="validateManualField(this)">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Service Requirements (Equipment & Amenities)</label>
                        <div class="checklist-grid">
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="Sound System" checked>
                                <span>High-End Sound System</span>
                            </label>
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="Wireless Microphones" checked>
                                <span>Wireless Microphones</span>
                            </label>
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="Mood Lighting" checked>
                                <span>Mood & Stage Lighting</span>
                            </label>
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="Stage & Backdrop" checked>
                                <span>Stage & Backdrop Setup</span>
                            </label>
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="Photo/Video Coverage">
                                <span>Photo & Video Team</span>
                            </label>
                            <label class="checklist-card">
                                <input type="checkbox" name="service_requirements[]" value="LED Screen Wall">
                                <span>LED Video Wall</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Special Notes / Program Details</label>
                        <textarea name="special_notes" class="form-control" rows="3" placeholder="Additional details, setup notes, or custom arrangements..."></textarea>
                    </div>

                    <div style="margin: 20px 0;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer;">
                            <input type="checkbox" name="send_notice" value="1" checked style="accent-color: var(--primary); width: 16px; height: 16px;">
                            <span>Send confirmation notice (Email) to client immediately</span>
                        </label>
                    </div>

                    <!-- Visible Validation Alert Box (Requirement 9) -->
                    <div id="manualValidationAlert" style="display: none; background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 13.5px;">
                        <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-exclamation" style="color: #dc2626;"></i>
                            <span>Please complete the required fields with valid details:</span>
                        </div>
                        <ul id="manualValidationErrorsList" style="margin: 0; padding-left: 24px; font-size: 13px; line-height: 1.5;"></ul>
                    </div>

                    <!-- Lower Right Button Container (Requirement 7) -->
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border-color);">
                        <a href="a_calendar.php" class="btn-secondary" style="padding: 11px 20px; font-size: 14px; text-decoration: none;">Cancel</a>
                        <button type="submit" id="btnSubmitManualEntry" class="btn-primary" style="padding: 12px 28px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.25);">
                            <i class="fa-solid fa-calendar-check"></i> Save Manual Entry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Task 8: Contact number field should not accept alphabets
        function isNumberKey(evt) {
            const charCode = (evt.which) ? evt.which : evt.keyCode;
            if (charCode > 31 && (charCode < 48 || charCode > 57)) {
                evt.preventDefault();
                return false;
            }
            return true;
        }

        function handlePhonePaste(e) {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text');
            const numbersOnly = paste.replace(/[^0-9]/g, '').slice(0, 11);
            e.target.value = numbersOnly;
            validatePhoneOnly(e.target);
        }

        function validatePhoneOnly(input) {
            // Strictly strip any non-numeric character
            input.value = input.value.replace(/[^0-9]/g, '').slice(0, 11);
            const feedback = document.getElementById('manualPhoneFeedback');
            if (!feedback) return;

            if (input.value.length === 0) {
                feedback.style.display = 'none';
                input.style.borderColor = '#d1d5db';
            } else if (input.value.length < 11 || !input.value.startsWith('09')) {
                feedback.style.display = 'block';
                feedback.style.color = '#dc2626';
                feedback.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Must be 11 numeric digits starting with 09 (e.g., 09171234567)';
                input.style.borderColor = '#ef4444';
            } else {
                feedback.style.display = 'block';
                feedback.style.color = '#059669';
                feedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> Valid Philippine numerical contact number';
                input.style.borderColor = '#10b981';
            }
        }

        function validateManualField(input) {
            if (input.hasAttribute('required')) {
                if (input.value.trim().length === 0) {
                    input.style.borderColor = '#ef4444';
                } else {
                    input.style.borderColor = '#10b981';
                }
            }
        }

        // Task 9: Visible validations on form submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const errors = [];
            const name = document.getElementById('manualClientName');
            const phone = document.getElementById('manualClientPhone');
            const title = document.getElementById('manualEventTitle');
            const date = document.getElementById('manualEventDate');
            const venue = document.getElementById('manualLocationVenue');

            if (!name || name.value.trim().length === 0) {
                errors.push('Client Full Name is required.');
                if (name) name.style.borderColor = '#ef4444';
            }
            if (!phone || phone.value.trim().length !== 11 || !phone.value.startsWith('09')) {
                errors.push('Contact Number must be exactly 11 digits starting with 09.');
                if (phone) phone.style.borderColor = '#ef4444';
            }
            if (!title || title.value.trim().length === 0) {
                errors.push('Event Title is required.');
                if (title) title.style.borderColor = '#ef4444';
            }
            if (!date || date.value.trim().length === 0) {
                errors.push('Event Date is required.');
                if (date) date.style.borderColor = '#ef4444';
            } else if (date.min && date.value < date.min) {
                errors.push(`Event Date must meet advance booking notice (minimum: ${date.min}).`);
                date.style.borderColor = '#ef4444';
            }
            if (!venue || venue.value.trim().length === 0) {
                errors.push('Venue / Location is required.');
                if (venue) venue.style.borderColor = '#ef4444';
            }

            const alertBox = document.getElementById('manualValidationAlert');
            const list = document.getElementById('manualValidationErrorsList');
            if (errors.length > 0) {
                e.preventDefault();
                list.innerHTML = errors.map(err => `<li>${err}</li>`).join('');
                alertBox.style.display = 'block';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                alertBox.style.display = 'none';
            }
        });

        function calculateMinimumDate(eventType) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const isWedding = (eventType || '').toLowerCase().includes('wedding');
            const minDate = new Date(today);

            if (isWedding) {
                // Weddings require at least 6 months advance notice
                const curMonth = minDate.getMonth();
                minDate.setMonth(curMonth + 6);
                if (minDate.getMonth() !== (curMonth + 6) % 12) {
                    minDate.setDate(0);
                }
            } else {
                // Birthday Parties: at least 2 weeks (14 days)
                minDate.setDate(minDate.getDate() + 14);
            }
            return { minDate, isWedding };
        }

        function updateManualLeadTime() {
            const typeSelect = document.getElementById('manualEventType');
            const dateInput = document.getElementById('manualEventDate');
            const hint = document.getElementById('manualLeadTimeHint');
            if (!typeSelect || !dateInput) return;

            const eventType = typeSelect.value || 'Weddings';
            const { minDate, isWedding } = calculateMinimumDate(eventType);

            const yyyy = minDate.getFullYear();
            const mm = String(minDate.getMonth() + 1).padStart(2, '0');
            const dd = String(minDate.getDate()).padStart(2, '0');
            const minDateStr = `${yyyy}-${mm}-${dd}`;

            dateInput.min = minDateStr;

            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            const formattedMin = minDate.toLocaleDateString('en-US', options);

            if (hint) {
                if (isWedding) {
                    hint.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-clock"></i> Weddings: min. 6 months advance notice.</span><br>Earliest available date: <strong>${formattedMin}</strong>`;
                } else {
                    hint.innerHTML = `<span style="color:#364735;font-weight:600;"><i class="fa-solid fa-calendar-check"></i> ${eventType}: min. 2 weeks advance notice.</span><br>Earliest available date: <strong>${formattedMin}</strong>`;
                }
            }

            if (dateInput.value && dateInput.value < minDateStr) {
                dateInput.value = minDateStr;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateManualLeadTime();
            const typeSelect = document.getElementById('manualEventType');
            if (typeSelect) {
                typeSelect.addEventListener('change', updateManualLeadTime);
            }
            const dateInput = document.getElementById('manualEventDate');
            if (dateInput) {
                dateInput.addEventListener('change', () => {
                    if (dateInput.value && dateInput.min && dateInput.value < dateInput.min) {
                        dateInput.value = dateInput.min;
                    }
                });
            }
        });
    </script>
</body>
</html>

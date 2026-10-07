<?php
require_once __DIR__ . '/db.php';

// Auth check
require_login();

$admin_username = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyoy Creation - Master Calendar</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- FullCalendar v6.1.11 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/main.min.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <style>
        .calendar-wrapper {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 24px;
        }

        /* FullCalendar Custom Theme Styles */
        .fc-toolbar-title {
            font-size: 20px !important;
            font-weight: 800 !important;
            color: var(--text-primary);
        }

        .fc-button-primary {
            background-color: #ffffff !important;
            border-color: var(--border-color) !important;
            color: var(--text-secondary) !important;
            font-weight: 600 !important;
            border-radius: 8px !important;
            padding: 8px 16px !important;
            text-transform: capitalize !important;
            box-shadow: none !important;
        }

        .fc-button-primary:hover, .fc-button-primary.fc-button-active {
            background-color: var(--primary) !important;
            border-color: var(--primary) !important;
            color: #ffffff !important;
        }

        .fc-event {
            border-radius: 6px !important;
            padding: 2px 6px !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            border: none !important;
        }

        .cal-legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title">Master Calendar</h1>
            <div style="display: flex; align-items: center; gap: 16px;">
                <a href="a_manual_booking.php" class="btn-primary" style="padding: 8px 18px; font-size: 13px;">
                    <i class="fa-solid fa-plus"></i> Manual Entry
                </a>
                <div class="admin-profile">
                    <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                    <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
                </div>
            </div>
        </header>

        <div class="admin-body">
            <!-- Calendar Legend -->
            <div class="cal-legend">
                <div class="legend-item">
                    <span class="legend-dot" style="background: #10b981;"></span>
                    <span>Weddings</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #ec4899;"></span>
                    <span>Kids Party</span>
                </div>
            </div>

            <div class="calendar-wrapper">
                <div id="calendar"></div>
            </div>
        </div>
    </main>

    <!-- Quick View Popup Modal -->
    <div class="modal-backdrop" id="eventQuickViewModal">
        <div class="modal-card" style="max-width: 520px; flex-direction: column;">
            <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px;" id="qvTitle">Event Overview</h3>
                <button type="button" onclick="closeQuickView()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding: 24px;" id="qvBody">
                <!-- Populated by JS -->
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between;">
                <a href="a_notifications.php" id="qvNotifyLink" class="btn-secondary" style="font-size: 13px;">
                    <i class="fa-solid fa-paper-plane"></i> Send Reminder
                </a>
                <button type="button" class="btn-primary" onclick="closeQuickView()">Close</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: 'event_load.php',
                editable: false,
                selectable: true,
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    meridiem: 'short'
                },
                eventClick: function(info) {
                    const props = info.event.extendedProps;
                    document.getElementById('qvTitle').textContent = info.event.title;
                    
                    const startStr = info.event.start ? info.event.start.toLocaleString() : 'N/A';
                    const endStr = info.event.end ? info.event.end.toLocaleString() : 'N/A';

                    const html = `
                        <div style="margin-bottom: 16px;">
                            <span class="badge" style="background: var(--status-approved-bg); color: var(--status-approved-text); margin-bottom: 10px;">
                                <span class="badge-dot"></span> Confirmed / Approved
                            </span>
                            <div style="font-size: 12px; color: var(--text-muted); font-family: monospace;">Reference: ${props.ref_no}</div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-user" style="color: var(--primary); width: 20px;"></i>
                                <div><strong>Client:</strong> ${props.client_name}</div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-phone" style="color: var(--primary); width: 20px;"></i>
                                <div><strong>Contact:</strong> ${props.client_phone} • ${props.client_email}</div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-clock" style="color: var(--primary); width: 20px;"></i>
                                <div><strong>Schedule:</strong> ${startStr}</div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-location-dot" style="color: var(--primary); width: 20px;"></i>
                                <div><strong>Venue:</strong> ${props.venue}</div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-users" style="color: var(--primary); width: 20px;"></i>
                                <div><strong>Guests:</strong> ${props.guests} expected</div>
                            </div>
                        </div>

                        ${props.services ? `
                            <div style="margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 12px;">
                                <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px;">Services Included</div>
                                <div style="font-size: 13px; color: var(--text-secondary);">${props.services}</div>
                            </div>
                        ` : ''}

                        ${props.notes ? `
                            <div style="margin-top: 12px; background: #f8fafc; padding: 10px; border-radius: 8px; font-size: 13px; border: 1px solid var(--border-color);">
                                <strong>Notes:</strong> ${props.notes}
                            </div>
                        ` : ''}
                    `;

                    document.getElementById('qvBody').innerHTML = html;
                    document.getElementById('eventQuickViewModal').classList.add('active');
                }
            });
            calendar.render();
        });

        function closeQuickView() {
            document.getElementById('eventQuickViewModal').classList.remove('active');
        }
    </script>
</body>
</html>

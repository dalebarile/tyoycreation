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

        /* FullCalendar Custom Theme Styles (Requirement 6: Smaller Event Font for UX) */
        .fc-toolbar-title {
            font-size: 19px !important;
            font-weight: 800 !important;
            color: var(--text-primary);
        }

        .fc-button-primary {
            background-color: #ffffff !important;
            border-color: var(--border-color) !important;
            color: var(--text-secondary) !important;
            font-weight: 600 !important;
            border-radius: 8px !important;
            padding: 7px 14px !important;
            font-size: 12.5px !important;
            text-transform: capitalize !important;
            box-shadow: none !important;
        }

        .fc-button-primary:hover, .fc-button-primary.fc-button-active {
            background-color: var(--primary) !important;
            border-color: var(--primary) !important;
            color: #ffffff !important;
        }

        /* Smaller UX event font & compact padding (Requirement 6) */
        .fc-event {
            border-radius: 5px !important;
            padding: 1.5px 5px !important;
            font-weight: 600 !important;
            font-size: 11px !important;
            line-height: 1.25 !important;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: none !important;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .fc-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0,0,0,0.12);
        }

        .fc-event-title {
            font-size: 10.5px !important;
            font-weight: 600 !important;
            letter-spacing: -0.01em !important;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .fc-event-time {
            font-size: 10px !important;
            font-weight: 700 !important;
            opacity: 0.95;
            margin-right: 3px !important;
        }

        .fc-daygrid-dot-event .fc-event-title {
            font-size: 10.5px !important;
        }

        .fc-daygrid-event-dot {
            margin: 0 4px 0 1px !important;
            border-width: 3.5px !important;
        }

        /* Highlight tapped date cell */
        .fc-day-tapped-highlight {
            background: rgba(24, 57, 43, 0.08) !important;
            outline: 2px solid var(--primary, #18392b) !important;
            outline-offset: -2px;
            transition: background 0.2s ease;
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

        /* ==========================================================================
           Bottom Appointments Pop-up Drawer (Requirement 4)
           ========================================================================== */
        .date-appointments-drawer {
            position: fixed;
            bottom: 0;
            left: 260px;
            right: 0;
            max-height: 52vh;
            background: #ffffff;
            border-top: 3px solid var(--primary, #18392b);
            box-shadow: 0 -12px 35px -5px rgba(0, 0, 0, 0.22);
            z-index: 1050;
            transform: translateY(110%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            border-top-left-radius: 18px;
            border-top-right-radius: 18px;
            overflow: hidden;
        }

        .date-appointments-drawer.active {
            transform: translateY(0);
        }

        @media (max-width: 991px) {
            .date-appointments-drawer {
                left: 0;
            }
        }

        .drawer-header {
            padding: 14px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            flex-shrink: 0;
        }

        .drawer-content {
            padding: 18px 22px;
            overflow-y: auto;
            flex: 1;
        }

        .apt-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid var(--primary, #18392b);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .apt-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.07);
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <div>
                <h1 class="admin-page-title" style="margin-bottom: 2px;">Master Calendar</h1>
                <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Tap any date to pop up scheduled appointments at the bottom</p>
            </div>
            <div class="admin-profile">
                <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
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
                <div class="legend-item">
                    <span class="legend-dot" style="background: #3b82f6;"></span>
                    <span>Corporate</span>
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background: #364735;"></span>
                    <span>Debut &amp; Celebrations</span>
                </div>
            </div>

            <div class="calendar-wrapper">
                <div id="calendar"></div>
            </div>
        </div>

        <!-- Lower Right Floating Manual Entry Button (Requirement 7) -->
        <a href="a_manual_booking.php" class="btn-primary" style="position: fixed; bottom: 28px; right: 28px; z-index: 1040; padding: 12px 22px; font-size: 13.5px; font-weight: 700; border-radius: 99px; box-shadow: 0 10px 25px -5px rgba(24, 57, 43, 0.45); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; border: 2px solid #ffffff; transition: transform 0.2s ease, box-shadow 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <i class="fa-solid fa-plus"></i>
            <span>Manual Entry</span>
        </a>
    </main>

    <!-- Bottom Appointments Pop-up Drawer (Requirement 4) -->
    <div class="date-appointments-drawer" id="dateAppointmentsDrawer">
        <div class="drawer-header">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: #eaf2ec; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div>
                    <h3 style="font-size: 15.5px; margin: 0; font-weight: 700; color: #0f172a;" id="drawerDateTitle">
                        Appointments
                    </h3>
                    <span style="font-size: 12px; color: #64748b;" id="drawerDateSubtitle">Scheduled bookings for selected date</span>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="a_manual_booking.php" id="drawerManualEntryBtn" class="btn-primary" style="padding: 7px 14px; font-size: 12px; border-radius: 6px;">
                    <i class="fa-solid fa-plus"></i> Add Event
                </a>
                <button type="button" onclick="closeDateDrawer()" style="background: transparent; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; padding: 4px 8px; border-radius: 6px;" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        <div class="drawer-content" id="drawerEventsList">
            <!-- Populated dynamically by JS -->
        </div>
    </div>

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
        let currentCalendar = null;
        let activeHighlightedCell = null;

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
                // Requirement 4: Pop up appointments when a date is tapped/clicked
                dateClick: function(info) {
                    handleDateTap(info);
                },
                eventClick: function(info) {
                    openEventDetailsModal(info.event);
                }
            });
            calendar.render();
            currentCalendar = calendar;
        });

        // Function to handle tapping/clicking any calendar date
        function handleDateTap(info) {
            const targetDateStr = info.dateStr; // "YYYY-MM-DD"
            
            // Highlight tapped day cell
            if (activeHighlightedCell) {
                activeHighlightedCell.classList.remove('fc-day-tapped-highlight');
            }
            if (info.dayEl) {
                info.dayEl.classList.add('fc-day-tapped-highlight');
                activeHighlightedCell = info.dayEl;
            }

            // Format date for title
            const dateObj = new Date(targetDateStr + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            document.getElementById('drawerDateTitle').innerHTML = `Appointments for <strong>${formattedDate}</strong>`;
            
            // Link Add Event button with pre-selected date
            const manualBtn = document.getElementById('drawerManualEntryBtn');
            if (manualBtn) {
                manualBtn.href = `a_manual_booking.php?event_date=${targetDateStr}`;
            }

            // Retrieve all matching events on that date
            const allEvents = currentCalendar ? currentCalendar.getEvents() : [];
            const dayEvents = allEvents.filter(ev => {
                if (!ev.start) return false;
                const y = ev.start.getFullYear();
                const m = String(ev.start.getMonth() + 1).padStart(2, '0');
                const d = String(ev.start.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}` === targetDateStr;
            });

            const subtitle = document.getElementById('drawerDateSubtitle');
            if (subtitle) {
                subtitle.textContent = `${dayEvents.length} event${dayEvents.length === 1 ? '' : 's'} scheduled on this day`;
            }

            const listContainer = document.getElementById('drawerEventsList');
            if (dayEvents.length === 0) {
                listContainer.innerHTML = `
                    <div style="text-align: center; padding: 24px 16px; color: #64748b;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 0 auto 10px auto;">
                            <i class="fa-solid fa-calendar-xmark"></i>
                        </div>
                        <h4 style="font-size: 15px; margin: 0 0 6px 0; color: #334155; font-weight: 700;">No Appointments Scheduled</h4>
                        <p style="font-size: 13px; margin: 0 0 16px 0; color: #64748b;">There are currently no confirmed bookings scheduled for this date.</p>
                        <a href="a_manual_booking.php?event_date=${targetDateStr}" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 18px; font-size: 13px;">
                            <i class="fa-solid fa-plus"></i> Book an Event on this Date
                        </a>
                    </div>
                `;
            } else {
                let html = `<div style="display: flex; flex-direction: column; gap: 10px;">`;
                dayEvents.forEach(ev => {
                    const p = ev.extendedProps || {};
                    const startFormatted = ev.start ? ev.start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : 'All Day';
                    const endFormatted = ev.end ? ' - ' + ev.end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) : '';
                    const timeRange = startFormatted + endFormatted;
                    const typeColor = ev.backgroundColor || '#18392b';

                    html += `
                        <div class="apt-card" style="border-left-color: ${typeColor};">
                            <div style="flex: 1; min-width: 240px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                                    <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #eaf2ec; color: var(--primary);">
                                        <i class="fa-solid fa-clock" style="font-size: 10px;"></i> ${timeRange}
                                    </span>
                                    <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #f1f5f9; color: #475569; font-family: monospace;">
                                        ${p.ref_no || '#TC'}
                                    </span>
                                    <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: ${typeColor}20; color: ${typeColor};">
                                        ${p.event_type || 'Event'}
                                    </span>
                                </div>
                                <h4 style="font-size: 15px; font-weight: 700; margin: 0 0 6px 0; color: #0f172a;">${ev.title}</h4>
                                <div style="display: flex; align-items: center; gap: 14px; font-size: 12.5px; color: #475569; flex-wrap: wrap;">
                                    <span><i class="fa-solid fa-user" style="color: var(--primary); margin-right: 4px;"></i><strong>${p.client_name || 'Client'}</strong></span>
                                    <span><i class="fa-solid fa-phone" style="color: var(--primary); margin-right: 4px;"></i>${p.client_phone || '—'}</span>
                                    <span><i class="fa-solid fa-location-dot" style="color: var(--primary); margin-right: 4px;"></i>${p.venue || 'TBD'}</span>
                                    <span><i class="fa-solid fa-users" style="color: var(--primary); margin-right: 4px;"></i>${p.guests ? p.guests + ' guests' : '—'}</span>
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn-primary" onclick="showEventDetailsById('${ev.id}')" style="padding: 7px 14px; font-size: 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-eye"></i> Full Details
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
                listContainer.innerHTML = html;
            }

            // Pop up the bottom drawer
            const drawer = document.getElementById('dateAppointmentsDrawer');
            if (drawer) {
                drawer.classList.add('active');
            }
        }

        function closeDateDrawer() {
            const drawer = document.getElementById('dateAppointmentsDrawer');
            if (drawer) {
                drawer.classList.remove('active');
            }
            if (activeHighlightedCell) {
                activeHighlightedCell.classList.remove('fc-day-tapped-highlight');
                activeHighlightedCell = null;
            }
        }

        function showEventDetailsById(eventId) {
            if (!currentCalendar) return;
            const ev = currentCalendar.getEventById(eventId);
            if (ev) {
                openEventDetailsModal(ev);
            }
        }

        function openEventDetailsModal(event) {
            const props = event.extendedProps || {};
            document.getElementById('qvTitle').textContent = event.title;
            
            const startStr = event.start ? event.start.toLocaleString() : 'N/A';

            const html = `
                <div style="margin-bottom: 16px;">
                    <span class="badge" style="background: var(--status-approved-bg); color: var(--status-approved-text); margin-bottom: 10px;">
                        <span class="badge-dot"></span> Confirmed / Approved
                    </span>
                    <div style="font-size: 12px; color: var(--text-muted); font-family: monospace;">Reference: ${props.ref_no || ''}</div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-user" style="color: var(--primary); width: 20px;"></i>
                        <div><strong>Client:</strong> ${props.client_name || ''}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-phone" style="color: var(--primary); width: 20px;"></i>
                        <div><strong>Contact:</strong> ${props.client_phone || ''} • ${props.client_email || ''}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-clock" style="color: var(--primary); width: 20px;"></i>
                        <div><strong>Schedule:</strong> ${startStr}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-location-dot" style="color: var(--primary); width: 20px;"></i>
                        <div><strong>Venue:</strong> ${props.venue || ''}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-users" style="color: var(--primary); width: 20px;"></i>
                        <div><strong>Guests:</strong> ${props.guests || ''} expected</div>
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

        function closeQuickView() {
            document.getElementById('eventQuickViewModal').classList.remove('active');
        }
    </script>
</body>
</html>


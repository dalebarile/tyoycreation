<?php
// SCHEDFIX AI Public Concierge & Direct Booking Backend
ob_start();
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/booking_verification_helper.php';

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// Official Google Gemini Free Tier Protection Engine
// - Official Free Limit: 1,500 Requests Per Day (RPD), 15 Requests Per Minute (RPM)
// - System Safety Hard Cap: 1,400 RPD (leaves buffer), 12 RPM, 30 per user session
// ============================================================================
$GLOBAL_DAILY_LIMIT = 1400; // System-wide daily maximum
$RPM_LIMIT = 12;            // Burst rate limit per minute
$USER_SESSION_LIMIT = 30;   // Fair-share limit per individual browser session

// Database-driven Global Daily API Quota Tracker with offline fallback
function getGlobalDailyApiCount($conn) {
    try {
        $today = date('Y-m-d');
        $stored_date = get_setting($conn, 'gemini_daily_api_date', $today);
        if ($stored_date !== $today) {
            set_setting($conn, 'gemini_daily_api_date', $today);
            set_setting($conn, 'gemini_daily_api_count', '0');
            return 0;
        }
        return (int)get_setting($conn, 'gemini_daily_api_count', 0);
    } catch (\Throwable $e) {
        return (int)($_SESSION['gemini_fallback_count'] ?? 0);
    }
}

function incrementGlobalDailyApiCount($conn) {
    try {
        $current = getGlobalDailyApiCount($conn);
        set_setting($conn, 'gemini_daily_api_count', (string)($current + 1));
    } catch (\Throwable $e) {
        $_SESSION['gemini_fallback_count'] = ((int)($_SESSION['gemini_fallback_count'] ?? 0)) + 1;
    }
}

$global_daily_used = getGlobalDailyApiCount($conn);
$global_daily_remaining = max(0, $GLOBAL_DAILY_LIMIT - $global_daily_used);

if (!isset($_SESSION['chat_count'])) {
    $_SESSION['chat_count'] = 0;
}
if (!isset($_SESSION['chat_timestamps']) || !is_array($_SESSION['chat_timestamps'])) {
    $_SESSION['chat_timestamps'] = [];
}

// Support both JSON payload and standard POST
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);

$message = trim($json_data['message'] ?? $_POST['message'] ?? '');
$action = trim($json_data['action'] ?? $_POST['action'] ?? '');

// Return current chat quota status
if ($action === 'get_status') {
    ob_clean();
    echo json_encode([
        'chat_count' => $_SESSION['chat_count'],
        'remaining_chats' => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT || $global_daily_remaining <= 0),
        'daily_used' => $global_daily_used,
        'daily_remaining' => $global_daily_remaining,
        'daily_cap' => $GLOBAL_DAILY_LIMIT
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// Reset/Clear History Action
if ($action === 'clear_history' || strtolower($message) === 'clear' || strtolower($message) === 'reset') {
    $_SESSION['chat_history'] = [];
    $_SESSION['booking_draft'] = [];
    $_SESSION['chat_count'] = 0; // Reset user session counter on clear
    $_SESSION['chat_timestamps'] = [];
    ob_clean();
    echo json_encode([
        'reply' => "Chat and booking draft have been reset. You have **{$USER_SESSION_LIMIT} messages** available for this session! How may I assist you today?",
        'response' => "Chat and booking draft have been reset. You have {$USER_SESSION_LIMIT} messages available! How can I assist you today?",
        'chat_count' => 0,
        'remaining_chats' => $USER_SESSION_LIMIT,
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => false,
        'daily_remaining' => $global_daily_remaining
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Check Global System-Wide Daily Limit
if ($global_daily_used >= $GLOBAL_DAILY_LIMIT) {
    ob_clean();
    echo json_encode([
        'reply' => "Our assistant is currently experiencing high volume today. You can still easily book your celebration right now by clicking the **'Book Now'** button to submit your inquiry directly to our styling team!",
        'response' => "Our team is available to assist you. Please use the Book Now button.",
        'chat_count' => $_SESSION['chat_count'],
        'remaining_chats' => 0,
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => true,
        'global_limit_reached' => true
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Check Individual User Session Limit (Fair share to prevent single user abuse)
if ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT) {
    ob_clean();
    echo json_encode([
        'reply' => "You have completed your session inquiry. To finalize your event booking, please click the **'Book Now'** button, or click the restart icon to start a new chat.",
        'response' => "Session complete. Please use the Book Now button or restart.",
        'chat_count' => $USER_SESSION_LIMIT,
        'remaining_chats' => 0,
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => true
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. RPM Burst Rate Protection (Never exceed Gemini Free 15 requests per minute)
$now = time();
$_SESSION['chat_timestamps'] = array_filter($_SESSION['chat_timestamps'], function($t) use ($now) {
    return ($now - $t) < 60;
});

if (count($_SESSION['chat_timestamps']) >= $RPM_LIMIT) {
    ob_clean();
    echo json_encode([
        'reply' => "⏳ **Cooldown Protection:** You are sending messages too quickly (Gemini Free Tier limit: 15 messages/minute). Please wait a few seconds before asking your next question!",
        'response' => "Please wait a moment before sending another message.",
        'chat_count' => $_SESSION['chat_count'],
        'remaining_chats' => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => false,
        'cooldown' => true
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($message)) {
    ob_clean();
    echo json_encode([
        'reply' => 'How can I assist you with your event planning today? Feel free to ask about our packages, check date availability, or book an event directly with me!',
        'response' => 'How can I assist you with your event planning today? Feel free to ask about our packages, check date availability, or book an event directly with me!',
        'chat_count' => $_SESSION['chat_count'],
        'remaining_chats' => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => false
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// Increment chat usage counts for this valid message
$_SESSION['chat_count']++;
$_SESSION['chat_timestamps'][] = time();

if (!isset($_SESSION['chat_history']) || !is_array($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}
if (!isset($_SESSION['booking_draft']) || !is_array($_SESSION['booking_draft'])) {
    $_SESSION['booking_draft'] = [];
}

// 1. Gather live upcoming approved dates to prevent booking conflicts
$booked_dates = [];
$res = $conn->query("SELECT DATE(event_start) as dt, event_type, location_venue FROM bookings WHERE status = 'approved' AND event_start >= CURDATE() ORDER BY event_start ASC LIMIT 20");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $booked_dates[] = "{$row['dt']} ({$row['event_type']} at {$row['location_venue']})";
    }
}
$booked_str = !empty($booked_dates) ? implode("; ", $booked_dates) : "No approved events yet in the upcoming months.";
$current_date_str = date('Y-m-d (l, F j, Y)');

$ai_b_name = get_setting($conn, 'business_name', 'Tyoy Creation');

// Fetch live packages and pricing catalogs so chatbot is always synchronized with a_packages.php
$theme_pricing = get_packages_pricing($conn, 'theme_party');
$wedding_pricing = get_packages_pricing($conn, 'wedding');
$pricing_brief = "";
if (!empty($wedding_pricing) && is_array($wedding_pricing)) {
    $pricing_brief .= "\nLIVE WEDDING PACKAGES & PRICING:\n";
    foreach ($wedding_pricing as $cat_key => $cat) {
        $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
        $items_str = [];
        foreach ($cat['items'] ?? [] as $it) {
            $items_str[] = ($it['label'] ?? '') . " (₱" . number_format($it['price'] ?? 0) . ")";
        }
        if (!empty($items_str)) {
            $pricing_brief .= "- {$cat_title}: " . implode(", ", array_slice($items_str, 0, 4)) . "\n";
        }
    }
}
if (!empty($theme_pricing) && is_array($theme_pricing)) {
    $pricing_brief .= "\nLIVE KIDS & THEME PARTY PACKAGES & PRICING:\n";
    foreach ($theme_pricing as $cat_key => $cat) {
        $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
        $items_str = [];
        foreach ($cat['items'] ?? [] as $it) {
            $items_str[] = ($it['label'] ?? '') . " (₱" . number_format($it['price'] ?? 0) . ")";
        }
        if (!empty($items_str)) {
            $pricing_brief .= "- {$cat_title}: " . implode(", ", array_slice($items_str, 0, 4)) . "\n";
        }
    }
}

// 2. Build system instructions with direct booking capability
$system_prompt = "You are the friendly, professional AI Event Styling & Planning Concierge for {$ai_b_name}, specializing in custom flower styling, balloon arrangements, and full celebration coordination.
Today's Date: {$current_date_str}

Business Information:
- We offer bespoke floral design, balloon arches & backdrop styling, and event coordination for:
  1. Weddings (Ceremony floral arches, bridal bouquets, entourage flowers, aisle styling, full reception decor)
  2. Kids Party (Creative balloon arches, festive character backdrops, theme setups, entertainment & catering)
- Currently booked/reserved dates: {$booked_str}
{$pricing_brief}

ADVANCE BOOKING LEAD TIME POLICY (STRICT):
- Weddings: Require at least 6 months advance booking lead time.
- Kids Party: Require at least 2 weeks (14 days) advance booking lead time.
- If a client asks for a date sooner than these required lead times, politely explain our lead time policy and recommend an available date on or after the required lead time.

DIRECT BOOKING FEATURE:
You can directly book events for clients right here in this chat!
To book an event, the following details are REQUIRED:
1. client_name (Full Name)
2. client_email (Email address)
3. client_phone (Mobile number)
4. event_title (e.g., 'Santos & Reyes Wedding', 'Liam 7th Superhero Kids Party')
5. event_type (Must be one of: 'Weddings', 'Kids Party')
6. event_date (Date in YYYY-MM-DD format. If user says relative dates like 'next Friday' or 'Nov 20', convert it based on today's date {$current_date_str})
7. location_venue (Venue name or address)

Optional details:
- guest_count (Default to 50 if unspecified)
- service_requirements (e.g., 'Sound System, Mood Lighting, Stage & Backdrop')
- special_notes (Any themes, color motifs, or specific requests)

CONVERSATIONAL BOOKING RULES:
- If a user expresses an intent to book (e.g. 'I want to book an event', 'Book a party for me', 'Can you schedule my wedding?'):
  Check what required details are missing. Courteously ask the user for the missing details in a friendly, organized bulleted list.
- If the user provides details piece by piece, remember them and ask only for the remaining missing fields.
- Check requested dates against booked dates. If a date is already booked at that venue, suggest another date or ask if they have another venue in mind.
- ONCE ALL 7 REQUIRED FIELDS ARE PROVIDED by the user (client_name, client_email, client_phone, event_title, event_type, event_date, location_venue):
  You MUST include a structured JSON block in your response formatted EXACTLY as:
  ```json
  {
    \"action\": \"create_booking\",
    \"client_name\": \"...\",
    \"client_email\": \"...\",
    \"client_phone\": \"...\",
    \"client_address\": \"...\",
    \"event_title\": \"...\",
    \"event_type\": \"...\",
    \"event_date\": \"YYYY-MM-DD\",
    \"event_time\": \"14:00\",
    \"guest_count\": 50,
    \"location_venue\": \"...\",
    \"service_requirements\": \"...\",
    \"special_notes\": \"...\"
  }
  ```
  Followed by a warm message confirming that you are submitting their booking inquiry for admin review!

BOOKING STATUS LOOKUP:
Clients can ask you to check the status of their booking using their reference number (format: EV-YYYY-XXXX).
- If a client mentions a reference number, the system will automatically look it up from the database and show them the result directly — you do NOT need to do anything, the status will already appear in this conversation.
- If a client asks about their booking status WITHOUT providing a reference number, ask them: 'Sure! Please share your reference number (format: EV-2026-XXXX) and I will look it up for you right away!'
- NEVER say you cannot check booking status. You CAN, as long as the client provides their reference number.

IMPORTANT AI SYSTEM LIMITATIONS:
When a user asks about your limitations, policies, or what you can or cannot do, inform them clearly:
1. **Pending Approval Only:** Any booking created via chat is a *pending inquiry/reservation* that must be officially reviewed and approved by an administrator before it is final.
2. **No Direct Payment Processing:** You cannot receive payments, downpayments, GCash, credit cards, or cash transfers.
3. **No Modification or Cancellation of Existing Bookings:** You cannot edit or cancel bookings that have already been created (clients must contact the office or admin to make changes).
4. **Session-Based Chat:** Conversations are saved for the current session only.
5. **Human Escalation:** For complex contracts or custom venue negotiations, users should contact our office directly.

- Keep conversational answers courteous, clear, and structured.";

// Helper function to create booking in database via Two-Step Verification
function executeDirectBooking($conn, $data) {
    $client_name = trim($data['client_name'] ?? '');
    $client_email = trim($data['client_email'] ?? '');
    $client_phone = trim($data['client_phone'] ?? '');
    $client_address = trim($data['client_address'] ?? 'Online Chat Inquiry');
    $event_title = trim($data['event_title'] ?? 'Special Event');
    $event_type = trim($data['event_type'] ?? 'Weddings');
    $event_date = trim($data['event_date'] ?? date('Y-m-d', strtotime('+7 days')));
    $event_time = trim($data['event_time'] ?? '14:00');
    $guest_count = (int)($data['guest_count'] ?? 50);
    $location_venue = trim($data['location_venue'] ?? 'To be specified');
    $service_requirements = trim($data['service_requirements'] ?? 'Sound System, Mood Lighting');
    $special_notes = trim($data['special_notes'] ?? '');
    $special_notes = !empty($special_notes) ? $special_notes . " [Booked via AI Chatbot Concierge]" : "[Booked via AI Chatbot Concierge]";

    // Normalize event type to permitted enum values
    $valid_types = ['Weddings', 'Kids Party', 'Birthday Parties'];
    if (!in_array($event_type, $valid_types)) {
        if (stripos($event_type, 'wedding') !== false) $event_type = 'Weddings';
        else $event_type = 'Kids Party';
    }

    // Two-Step Verification: Stage booking in temporary session and dispatch 6-digit OTP code
    // (DO NOT insert into main database bookings table until verified)
    $stageResult = BookingVerificationHelper::stageBooking($conn, [
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
        'source'               => 'chatbot_ai'
    ]);

    if (!$stageResult['success']) {
        return $stageResult;
    }

    // Reset draft
    $_SESSION['booking_draft'] = [];

    return $stageResult;
}

// 3. Update Conversation History in Session
$_SESSION['chat_history'][] = ['role' => 'user', 'content' => $message];
// Limit history to last 10 turns to maintain low latency
if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

// Format conversation history for Gemini API
$conversation_contents = [];
$history_text = "";
foreach ($_SESSION['chat_history'] as $item) {
    $role_label = ($item['role'] === 'user') ? 'User' : 'Assistant';
    $history_text .= "{$role_label}: {$item['content']}\n";
}

$prompt_with_context = $system_prompt . "\n\nConversation So Far:\n" . $history_text . "\nAssistant:";

// Fast check for AI limitations query
$raw_reply = '';
$lower_msg = strtolower($message);
if (strpos($lower_msg, 'limitation') !== false || strpos($lower_msg, 'what can you not do') !== false || strpos($lower_msg, 'what can\'t you do') !== false || strpos($lower_msg, 'cannot do') !== false) {
    $raw_reply = "Here are the **Scope & Limitations** of using our AI Assistant: 🤖\n\n"
        . "• **Pending Review Only:** Any reservation submitted through this chat is recorded as a **Pending Inquiry**. It is not officially confirmed until verified and approved by our management team.\n"
        . "• **No Payment Processing:** The AI cannot receive money, downpayments, credit cards, or GCash transfers. Payments are settled directly with our office upon approval.\n"
        . "• **No Editing of Existing Bookings:** For security and contract safety, the AI cannot alter, reschedule, or cancel existing bookings. Please reach out to our team to make changes.\n"
        . "• **Session-Based Chat:** Conversation context is retained during your active browsing session only.\n"
        . "• **Human Support:** For custom package quotes or complex requests, our event coordinators are always available to help!";
}

// ─── BOOKING TWO-STEP VERIFICATION OTP (Fast-path — zero API tokens) ────────
if (empty($raw_reply) && !empty($_SESSION['pending_booking_verification'])) {
    $pending_data = $_SESSION['pending_booking_verification'];
    $lower_otp_msg = strtolower($message);

    // Case A: User requests a new code
    if (strpos($lower_otp_msg, 'resend') !== false || strpos($lower_otp_msg, 'send again') !== false || strpos($lower_otp_msg, 'new code') !== false || strpos($lower_otp_msg, 'bagong code') !== false) {
        $resend = BookingVerificationHelper::resendCode($conn);
        if ($resend['success']) {
            $raw_reply = "📧 **New Verification Code Sent!**\n\n"
                       . "A fresh 6-digit verification code has been dispatched to `" . htmlspecialchars($resend['email'] ?? $resend['masked_email']) . "`.\n\n"
                       . "⚠️ *Double-check your email spelling carefully. If a letter was missing or mistyped, reply with: `change email to name@example.com`.*\n\n"
                       . "👉 Please enter the **6-digit code** here in the chat to confirm and place your reservation inquiry!";
        } else {
            $raw_reply = "⏳ **Resend Notice:** " . htmlspecialchars($resend['message']) . "\n\nPlease enter your existing 6-digit code or wait before requesting a new one.";
        }
    }
    // Case B: User wants to change email because of typo or missing letter
    elseif (preg_match('/(?:change|update|palitan|wrong|maling|typo|correct)\s+email\s*(?:to|sa)?\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $message, $email_match)
         || (strpos($lower_otp_msg, '@') !== false && preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $message, $email_match) && strpos($lower_otp_msg, 'resend') === false)) {
        $new_email = trim($email_match[1]);
        $update = BookingVerificationHelper::updateEmail($conn, $new_email);
        if ($update['success']) {
            $raw_reply = "📧 **Email Corrected & Verification Code Dispatched!**\n\n"
                       . "We updated your recipient email address to:\n`" . htmlspecialchars($new_email) . "`\n\n"
                       . "A fresh 6-digit verification code has been sent to this new address. Please enter the **6-digit code** here to confirm your booking inquiry!";
        } else {
            $raw_reply = "⚠️ **Email Update Notice:** " . htmlspecialchars($update['message']);
        }
    }
    // Case C: User enters 6-digit verification code
    elseif (preg_match('/\b(\d{6})\b/', $message, $otp_match)) {
        $entered_code = $otp_match[1];
        $verify = BookingVerificationHelper::verifyBooking($conn, $entered_code);
        if ($verify['success']) {
            $booking_info = $verify;
            $raw_reply = "🎉 **Booking Verified & Officially Placed!**\n\n"
                       . "Thank you, **" . htmlspecialchars($verify['client_name']) . "**! Your event reservation has been successfully verified and saved in our system:\n\n"
                       . "• **Reference Number:** `" . htmlspecialchars($verify['reference_no']) . "`\n"
                       . "• **Event:** " . htmlspecialchars($verify['event_title']) . "\n"
                       . "• **Date:** " . htmlspecialchars($verify['event_date']) . "\n"
                       . "• **Status:** PENDING ADMIN REVIEW\n\n"
                       . "An inquiry confirmation notice has been dispatched to your email. Our event coordination team will review your reservation shortly!";
        } else {
            $target_email = htmlspecialchars($pending_data['email'] ?? 'your email');
            $raw_reply = "⚠️ **Verification Notice:** " . htmlspecialchars($verify['message']) . "\n\n"
                       . "• Target email: `{$target_email}`\n"
                       . "• If there is a missing letter or typo in this email, reply: `change email to yourcorrect@gmail.com`\n"
                       . "• Or reply **resend code** if you need a new code sent.";
        }
    }
}

// ─── BOOKING STATUS LOOKUP (Fast-path — zero API tokens) ────────────────────
// Triggered when the user provides a reference number or asks for status.
if (empty($raw_reply)) {
    // Match reference numbers like EV-2026-001, EV-2026-0001, EV-2026-M0001, EV2026-0001, EV-2025-12345
    $has_ref = preg_match('/\b(EV[-_]?[0-9]{4}[-_]?[A-Z0-9]{1,8})\b/i', $message, $ref_match)
            || preg_match('/\b(EV[-_][A-Z0-9-]{3,15})\b/i', $message, $ref_match)
            || preg_match('/\b([0-9]{4}-[0-9]{3,5})\b/i', $message, $ref_match);

    $lower_msg_status = strtolower($message);
    $is_status_query = $has_ref ||
        strpos($lower_msg_status, 'booking status') !== false ||
        strpos($lower_msg_status, 'check my booking') !== false ||
        strpos($lower_msg_status, 'check booking') !== false ||
        strpos($lower_msg_status, 'my inquiry') !== false ||
        strpos($lower_msg_status, 'track my booking') !== false ||
        strpos($lower_msg_status, 'track my inquiry') !== false ||
        strpos($lower_msg_status, 'status of my') !== false ||
        strpos($lower_msg_status, 'ref no') !== false ||
        (strpos($lower_msg_status, 'status') !== false && strpos($lower_msg_status, 'booking') !== false) ||
        (strpos($lower_msg_status, 'status') !== false && strpos($lower_msg_status, 'ev-') !== false);

    if ($is_status_query) {
        if ($has_ref) {
            $ref_raw = strtoupper(trim($ref_match[1]));
            $ref_variants = [$ref_raw];
            
            // Generate normalized variants to maximize lookup success
            if (strpos($ref_raw, 'EV-') !== 0 && strpos($ref_raw, 'EV') === 0) {
                $ref_variants[] = preg_replace('/^EV-?([0-9]{4})-?([A-Z0-9]+)$/i', 'EV-$1-$2', $ref_raw);
            } elseif (!preg_match('/^EV-/i', $ref_raw)) {
                $ref_variants[] = 'EV-' . $ref_raw;
            }

            $var0 = $ref_variants[0];
            $var1 = $ref_variants[1] ?? $var0;

            $stmt_s = $conn->prepare(
                "SELECT reference_no, client_name, event_title, event_type, event_start,
                        location_venue, guest_count, status, created_at
                 FROM bookings 
                 WHERE reference_no = ? OR reference_no = ?
                 LIMIT 1"
            );

            if ($stmt_s) {
                $stmt_s->bind_param("ss", $var0, $var1);
                $stmt_s->execute();
                $res_s = $stmt_s->get_result();
                $row_s = $res_s ? qes_decrypt_booking($res_s->fetch_assoc()) : null;
                $stmt_s->close();
            } else {
                $row_s = null;
            }

            if ($row_s) {
                $status_map = [
                    'pending'   => '🟡 **Pending Review** — Your inquiry is in the queue and awaiting admin approval.',
                    'approved'  => '🟢 **Approved** — Your booking has been officially confirmed! Our styling coordinator will reach out for final details.',
                    'rejected'  => '🔴 **Not Approved** — Unfortunately your booking was not approved. Please contact our office directly to discuss alternative dates.',
                    'cancelled' => '⚫ **Cancelled** — This booking has been cancelled. Feel free to book a new date!',
                    'completed' => '🎉 **Completed** — This event has successfully concluded. Thank you for celebrating with us!'
                ];
                $status_label   = $status_map[$row_s['status']] ?? ('**' . ucfirst($row_s['status']) . '**');
                $event_date_fmt = (!empty($row_s['event_start']) && strtotime($row_s['event_start'])) ? date('F j, Y \a\t g:i A', strtotime($row_s['event_start'])) : 'Date to be specified';
                $submitted_fmt  = (!empty($row_s['created_at']) && strtotime($row_s['created_at'])) ? date('F j, Y', strtotime($row_s['created_at'])) : date('F j, Y');
                $guest_display  = !empty($row_s['guest_count']) ? (int)$row_s['guest_count'] : 'To be confirmed';
                $venue_display  = !empty($row_s['location_venue']) ? $row_s['location_venue'] : 'To be specified';

                $raw_reply = "📋 **Booking Status Lookup**\n\n"
                    . "Here are the details for reference **{$row_s['reference_no']}**:\n\n"
                    . "• **Client:** {$row_s['client_name']}\n"
                    . "• **Event:** {$row_s['event_title']} ({$row_s['event_type']})\n"
                    . "• **Date & Time:** {$event_date_fmt}\n"
                    . "• **Venue:** {$venue_display}\n"
                    . "• **Guests:** {$guest_display}\n"
                    . "• **Submitted:** {$submitted_fmt}\n"
                    . "• **Status:** {$status_label}\n\n"
                    . "Is there anything else I can help you with? 😊";
            } else {
                $raw_reply = "❌ **Reference Not Found**\n\n"
                    . "I couldn't find any booking with reference number **{$ref_raw}** in our system. "
                    . "Please double-check the format (e.g. `EV-2026-001` or `EV-2026-0001`) and try again, or contact our team directly!";
            }
        } else {
            // User asked about status but didn't provide a reference number yet
            $raw_reply = "Sure! To look up your booking status, please provide your **Reference Number** — it looks like this: `EV-2026-001` or `EV-2026-0001`.\n\n"
                . "You can find it in your booking confirmation. Just reply with your reference code and I'll look it up for you right away! 😊";
        }
    }
}

// 4. Query Gemini API (if not already answered by fast response)
if (empty($raw_reply) && (defined('GEMINI_KEY') || defined('GEMINI_API_KEY'))) {
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : GEMINI_KEY;

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt_with_context]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature'     => 0.7,
            'maxOutputTokens' => 1000
        ]
    ];
    $payload_json = json_encode($payload);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
    $ch = curl_init($url);

    $curl_options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload_json,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $local_ca = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (empty(ini_get('curl.cainfo')) && file_exists($local_ca)) {
        $curl_options[CURLOPT_CAINFO] = $local_ca;
    }
    curl_setopt_array($ch, $curl_options);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $json = json_decode($response, true);
        $ai_text = '';
        if (!empty($json['candidates'][0]['content']['parts'])) {
            foreach ($json['candidates'][0]['content']['parts'] as $part) {
                if (empty($part['thought']) && !empty($part['text'])) {
                    $ai_text .= $part['text'];
                }
            }
            if (empty($ai_text)) {
                foreach ($json['candidates'][0]['content']['parts'] as $part) {
                    if (!empty($part['text'])) {
                        $ai_text .= $part['text'];
                    }
                }
            }
        }
        if (!empty($ai_text)) {
            $raw_reply = trim($ai_text);
            incrementGlobalDailyApiCount($conn);
        }
    } elseif ($http_code === 429) {
        $raw_reply = "⏳ The AI service is momentarily busy under the Google Free Tier limit (15 requests/minute). Please wait a few moments or click **'Book Now'** above to submit your booking inquiry directly to our team!";
    }
}

// 5. Fallback rule-based logic if API is unreachable, offline, or rate-limited
if (empty($raw_reply)) {
    $lower = strtolower($message);
    if (strpos($lower, 'availab') !== false || strpos($lower, 'booked date') !== false || strpos($lower, 'upcoming') !== false || (strpos($lower, 'booked') !== false && strpos($lower, 'date') !== false)) {
        $raw_reply = "🗓️ **Upcoming Booked Dates & Availability:**\n\n"
            . "Here are our currently reserved event dates:\n"
            . (!empty($booked_dates) ? "• " . implode("\n• ", $booked_dates) . "\n\n" : "No approved events booked yet in upcoming months! All dates are currently open.\n\n")
            . "Please note our advance booking notice requirements:\n"
            . "• **Weddings:** At least 6 months advance notice\n"
            . "• **Kids Party:** At least 2 weeks (14 days) advance notice\n\n"
            . "Would you like to reserve a date? Click **'Book Now'** or tell me your preferred event details!";
    } elseif (strpos($lower, 'balloon') !== false || strpos($lower, 'flower') !== false || strpos($lower, 'styling') !== false || strpos($lower, 'arch') !== false) {
        $raw_reply = "🎈 **Floral & Balloon Styling Services:**\n\n"
            . "We specialize in handcrafted flower styling, themed balloon arches, stage backdrops, and event decor for:\n"
            . "• **Weddings:** Ceremony floral arches, bridal bouquets, entourage styling, aisle decor, and reception styling\n"
            . "• **Kids Party:** Organic balloon garlands, character backdrops, cake table styling, and themed setups\n\n"
            . "Tell me your preferred theme or click **'Book Now'** above to customize your setup!";
    } elseif (strpos($lower, 'book') !== false || strpos($lower, 'reserve') !== false || strpos($lower, 'schedule') !== false) {
        $raw_reply = "I would be happy to help you book your event with Tyoy Creation! 📅\n\nTo reserve your celebration date, please provide me with:\n1. **Your Full Name**\n2. **Email & Mobile Number**\n3. **Event Title & Type** (Weddings or Kids Party)\n4. **Preferred Date & Venue Location**\n\nOnce you provide these details, I will instantly generate your booking reservation!";
    } elseif (strpos($lower, 'package') !== false || strpos($lower, 'offer') !== false || strpos($lower, 'service') !== false) {
        $raw_reply = "We offer comprehensive styling and event coordination packages for **Weddings** and **Kids Party** celebrations! Our packages include custom floral design, themed balloon setups, sound systems, mood lighting, food carts, and full coordination. Let me know your event details and I can assist you directly!";
    } else {
        $raw_reply = "Thank you for messaging Tyoy Creation Concierge! I can answer questions about our event styling packages, check date availability, check booking status with your reference number, or help you book your event directly right now. How can I help you today? 😊";
    }
}

// 6. Check for Action JSON Block in AI reply
$booking_info = null;
$clean_reply = $raw_reply;

if (preg_match('/```(?:json)?\s*(\{\s*"action"\s*:\s*"create_booking".*?\})\s*```/s', $raw_reply, $matches) ||
    preg_match('/(\{\s*"action"\s*:\s*"create_booking"[^}]+\})/s', $raw_reply, $matches)) {
    
    $booking_json = json_decode($matches[1], true);
    if ($booking_json && isset($booking_json['client_name'], $booking_json['client_phone'], $booking_json['location_venue'])) {
        $booking_res = executeDirectBooking($conn, $booking_json);
        $clean_reply = trim(str_replace($matches[0], '', $raw_reply));
        if ($booking_res['success'] && !empty($booking_res['requires_verification'])) {
            // DO NOT insert or treat as finished booking until verified!
            $booking_info = null;
            $summary = $booking_res['summary'] ?? [];
            $clean_reply .= "\n\n🔐 **Two-Step Verification Required!**\n"
                         . "We have received your event details for **" . htmlspecialchars($summary['event_title'] ?? 'Special Event') . "** on **" . htmlspecialchars($summary['event_date'] ?? '') . "** at **" . htmlspecialchars($summary['location_venue'] ?? '') . "**.\n\n"
                         . "To protect your reservation and prevent spam, we just dispatched a **6-digit verification code** to `" . htmlspecialchars($booking_res['masked_email']) . "` (valid for 10 minutes).\n\n"
                         . "👉 **Please enter your 6-digit verification code here in the chat** to confirm and place your booking inquiry!";
        } elseif ($booking_res['success']) {
            $booking_info = $booking_res;
            
            // Enhance confirmation message
            $clean_reply .= "\n\n🎉 **Booking Inquiry Successfully Submitted!**\n"
                         . "• **Reference Number:** `{$booking_res['reference_no']}`\n"
                         . "• **Event:** {$booking_res['event_title']}\n"
                         . "• **Date:** {$booking_res['event_date']}\n"
                         . "• **Venue:** {$booking_res['location_venue']}\n\n"
                         . "Our administrator will review your reservation and reach out via Email shortly.";
        } else {
            $clean_reply .= "\n\n⚠️ **Notice Regarding Your Booking Request:**\n"
                         . ($booking_res['message'] ?? 'Please check the requested details.')
                         . "\n\nPlease let me know if you would like to pick an alternative date or adjust your event details!";
        }
    }
}

// Save bot response to conversation history
$_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $clean_reply];

$updated_daily_count = getGlobalDailyApiCount($conn);
$pending_check = BookingVerificationHelper::getPendingStatus();

$output = [
    'reply'                 => $clean_reply,
    'response'              => $clean_reply,
    'booking_created'       => $booking_info !== null,
    'booking'               => $booking_info,
    'requires_verification' => ($pending_check !== null),
    'verification_email'    => $pending_check['masked_email'] ?? '',
    'verification_token'    => $pending_check['token'] ?? '',
    'expires_in'            => $pending_check['expires_in'] ?? 0,
    'chat_count'            => $_SESSION['chat_count'],
    'remaining_chats'       => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
    'max_chats'             => $USER_SESSION_LIMIT,
    'limit_reached'         => ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT || $updated_daily_count >= $GLOBAL_DAILY_LIMIT),
    'daily_remaining'       => max(0, $GLOBAL_DAILY_LIMIT - $updated_daily_count)
];

ob_clean();
echo json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
exit;
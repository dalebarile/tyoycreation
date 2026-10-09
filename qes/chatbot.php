<?php
// ============================================================================
// Tyoy Creation - AI Public Event Styling & Booking Assistance Concierge
// ============================================================================
ob_start();
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto_helper.php';

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// Google Gemini Free Tier Protection Engine
// - Official Free Limit: 1,500 Requests Per Day (RPD), 15 Requests Per Minute (RPM)
// - System Safety Hard Cap: 1,400 RPD, 12 RPM, 30 per user session
// ============================================================================
$GLOBAL_DAILY_LIMIT = 1400; // System-wide daily maximum
$RPM_LIMIT = 12;            // Burst rate limit per minute
$USER_SESSION_LIMIT = 30;   // Fair-share limit per individual browser session

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

// ============================================================================
// CONCIERGE INTENT & SCOPE HELPERS (TAGALOG / ENGLISH)
// ============================================================================
function qes_detect_tagalog(string $msg): bool {
    return (bool)preg_match('/\b(kumain|kain|kamusta|kumusta|musta|ano|anong|sino|bakit|paano|saan|san|meron|mayroon|wala|ulam|ka\s*na|kana|mo\s*ba|po|opo|ba|naman|tayo|lahat|ninyo|inyo|kita|ako|ikaw|siya|sila|magkano|presyo|kasal|binyag|kaarawan|luto|uulan|ganda|pangit|bata|tao|jowa|syota|babae|lalaki|taga|pa-?book|mag-?book)\b/i', $msg);
}

function qes_is_pure_greeting(string $msg): bool {
    $clean = trim(preg_replace('/[!?.,]/', '', strtolower($msg)));
    $greetings = [
        'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'good day',
        'magandang umaga', 'magandang hapon', 'magandang gabi', 'magandang araw',
        'hi po', 'hello po', 'good morning po', 'good afternoon po', 'good evening po'
    ];
    return in_array($clean, $greetings, true);
}

function qes_is_event_related(string $msg): bool {
    $patterns = [
        '/\b(book|booking|reserve|reservation|schedule|appointment|inquire|inquiry|mag-?book|pa-?book|magpareserve|pareserve|how\s+to\s+book|paano\s+mag-?book)\b/i',
        '/\b(event|events|wedding|weddings|kasal|party|parties|birthday|bday|debut|debutante|baptism|binyag|anniversary|celebration|okasyon)\b/i',
        '/\b(package|packages|price|prices|pricing|rate|rates|cost|magkano|presyo|bayad|downpayment|payment|budget|quote|quotation|fee|fees)\b/i',
        '/\b(date|dates|available|availability|open|petsa|kailan|kelan|calendar|lead\s*time|notice|advance|slot|slots|minimum\s*lead)\b/i',
        '/\b(decor|decors|decoration|decorations|styling|style|flower|flowers|bulaklak|balloon|balloons|arch|arches|backdrop|backdrops|theme|motif|venue|location|place|reception|ceremony|aisle|bouquet|entourage|centerpiece|stage|catering|food\s*cart|sound\s*system|lighting|lights|host|emcee|coordinator|coordination)\b/i',
        '/\b(status|reference|ref\s*no|ref\s*number|track|follow\s*up|ev-[0-9a-z-]+|pending|approved|rejected)\b/i',
        '/\b(tyoy|creation|business|contact|office|address|guidelines|rules|policy|policies|requirement|requirements|service|services|offer|offers)\b/i'
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $msg)) return true;
    }
    return false;
}

function qes_is_unnecessary_question(string $msg): bool {
    // 1. Technical, programming, hacking, server, essay tasks
    if (preg_match('/\b(code|coding|programmer|programming|python|javascript|php\s*script|html|css|sql\s*query|react|github|c\+\+|java|database\s*schema|database\s*password|api\s*key|secret\s*key)\b/i', $msg)) {
        return true;
    }
    if (preg_match('/\b(write\s+(?:an?\s+)?essay|write\s+(?:a\s+)?story|write\s+(?:a\s+)?poem|homework|solve\s+(?:this\s+)?math|assignment)\b/i', $msg)) {
        return true;
    }

    // 2. Personal, casual chit-chat, small talk
    $casual_patterns = [
        '/\b(kumain|kain|lunch|dinner|breakfast|almusal|agahan|hapunan|merienda)\s*(ka\s*na|kana|tayo|mo|ka\s*pa)?\b/i',
        '/\b(ano|anong)\s+(ang\s+)?ulam\b/i',
        '/\b(gutom|busog|uhaw)\b/i',
        '/\b(have\s+you\s+eaten|did\s+you\s+eat|are\s+you\s+hungry|what\s+did\s+you\s+eat)\b/i',
        '/\b(how\s+are\s+you|how\s+r\s+u|how\'s\s+it\s+going|what\'s\s+up|sup)\b/i',
        '/\b(kumusta|kamusta|musta)\s*(ka|po)?\b/i',
        '/\b(may\s+(?:jowa|boyfriend|girlfriend|bf|gf|syota|asawa))\b/i',
        '/\b(single\s+ka|crush\s+kita|mahal\s+kita|love\s+you|will\s+you\s+marry\s+me|do\s+you\s+love\s+me)\b/i',
        '/\b(sino\s+ka|who\s+are\s+you|what\s+is\s+your\s+name|anong\s+pangalan\s+mo|ilan\s+taon\s+ka|how\s+old\s+are\s+you|taga\s*saan\s+ka|where\s+do\s+you\s+live)\b/i',
        '/\b(babae\s+ka|lalaki\s+ka|what\s+is\s+your\s+gender|are\s+you\s+(?:human|a\s+robot|real))\b/i',
        '/\b(joke|magbiro|tell\s+me\s+a\s+joke|sing|kanta|dance|sayaw|tula|poem|kwento|story)\b/i',
        '/\b(weather|uulan|panahon|forecast|temperature)\b/i',
        '/\b(recipe|cooking|luto|recipe\s+ng)\b/i',
        '/\b(crypto|bitcoin|stocks|invest|trading)\b/i',
        '/\b(president|presidente|politika|politics|election|boto)\b/i',
        '/\b(movie|film|celebrity|artista|nba|basketball|sports|game|gaming)\b/i',
        '/\b(medical|sakit|gamot|doctor|medicine|car|kotse|motor)\b/i',
        '/\b(what\s+is\s+the\s+meaning\s+of\s+life|ano\s+ang\s+pag-ibig|what\s+is\s+love)\b/i'
    ];

    foreach ($casual_patterns as $p) {
        if (preg_match($p, $msg)) {
            return true;
        }
    }

    return false;
}

// Data Privacy / PII probe detection
function qes_is_privacy_violation_attempt(string $msg): bool {
    return (bool)preg_match('/\b(other\s+client|other\s+clients|ibang\s+client|list\s+of\s+clients|client\s+list|names\s+of\s+clients|client\s+details|customer\s+info|who\s+booked|sino\s+ang\s+nag-?book|sino\s+nag-?book|who\s+is\s+the\s+client|client\s+phone|client\s+email|phone\s+number\s+of|email\s+of|database\s+records|users\s+table|all\s+bookings|show\s+me\s+all\s+bookings|admin\s+password|passwords)\b/i', $msg);
}

function qes_get_privacy_refusal(string $msg = ''): string {
    if (qes_detect_tagalog($msg)) {
        return "🔒 **Paumanhin po:** Alinsunod sa **Data Privacy Act ng Pilipinas (RA 10173)** at sa mahigpit na patakaran ng aming kumpanya para sa proteksyon ng aming mga kliyente, **mahigpit na kumpidensyal** ang lahat ng impormasyon, pangalan, contact details, at mga detalye ng ibang kliyente at hindi po ito maaaring ibahagi. Paano ko po kayo matutulungan sa mga packages, serbisyo, o pag-book ng inyong sariling okasyon?";
    }
    return "🔒 **Privacy Notice:** In strict compliance with the **Data Privacy Act of 2012 (RA 10173)** and our client confidentiality policies, all personal information, names, contact numbers, and booking details of other clients are strictly confidential and cannot be disclosed. How may I assist you with our styling packages, available dates, or guiding you on how to book your own celebration?";
}

function qes_get_concierge_scope_refusal(string $msg = '', string $b_name = 'Tyoy Creation'): string {
    if (qes_detect_tagalog($msg)) {
        return "Paumanhin po, ako ay naka-program lamang upang sumagot sa mga katanungan tungkol sa event styling packages, serbisyo, date availability, at sa paggabay sa inyo kung paano mag-book sa website ng {$b_name}. Paano po kita matutulungan sa inyong event booking ngayon?";
    }
    return "I'm sorry, I am programmed exclusively to assist with event styling packages, services, date availability, and guiding clients on how to book with {$b_name}. How may I help you with your celebration today?";
}

// Mask client name for privacy (e.g. "Daniel Barile" -> "D***** B*****")
function qes_mask_name(string $name): string {
    $name = trim($name);
    if (empty($name)) return '[Client Protected]';
    $parts = explode(' ', $name);
    $masked = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (strlen($p) <= 1) {
            $masked[] = $p;
        } else {
            $masked[] = mb_substr($p, 0, 1) . str_repeat('*', min(5, max(2, mb_strlen($p) - 1)));
        }
    }
    return implode(' ', $masked);
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
    $_SESSION['chat_count'] = 0;
    $_SESSION['chat_timestamps'] = [];
    ob_clean();
    echo json_encode([
        'reply' => "Conversation has been reset. You have **{$USER_SESSION_LIMIT} messages** available for this session! How may I assist you with our event packages or booking instructions today?",
        'response' => "Conversation has been reset. You have {$USER_SESSION_LIMIT} messages available! How can I assist you today?",
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
        'reply' => "Our assistant is currently experiencing high volume today. You can easily book your celebration right now by clicking the **'Book Now'** button on our website to submit your inquiry directly to our coordination team!",
        'response' => "Our team is available to assist you. Please use the Book Now button on the website.",
        'chat_count' => $_SESSION['chat_count'],
        'remaining_chats' => 0,
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => true,
        'global_limit_reached' => true
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Check Individual User Session Limit
if ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT) {
    ob_clean();
    echo json_encode([
        'reply' => "You have reached your session chat limit. To submit your event booking inquiry, please click the **'Book Now'** button on our website, or click the restart icon to begin a new chat session.",
        'response' => "Session complete. Please use the Book Now button or restart.",
        'chat_count' => $USER_SESSION_LIMIT,
        'remaining_chats' => 0,
        'max_chats' => $USER_SESSION_LIMIT,
        'limit_reached' => true
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. RPM Burst Rate Protection
$now = time();
$_SESSION['chat_timestamps'] = array_filter($_SESSION['chat_timestamps'], function($t) use ($now) {
    return ($now - $t) < 60;
});

if (count($_SESSION['chat_timestamps']) >= $RPM_LIMIT) {
    ob_clean();
    echo json_encode([
        'reply' => "⏳ **Please wait a moment:** Messages are being sent too quickly. Please pause for a few seconds before asking your next question!",
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
        'reply' => 'Hello! How can I assist you with your event planning today? Feel free to ask about our packages, services, date availability, or ask me how to book your celebration on our website!',
        'response' => 'Hello! How can I assist you with your event planning today? Feel free to ask about our packages, services, date availability, or ask me how to book your celebration on our website!',
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

// 1. Gather live upcoming approved dates to prevent booking conflicts
// PRIVACY SAFE: Only date and event category are exposed. NO client names, venues, or contact info!
$booked_dates = [];
$res = $conn->query("SELECT DATE(event_start) as dt, event_type FROM bookings WHERE status = 'approved' AND event_start >= CURDATE() ORDER BY event_start ASC LIMIT 20");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $booked_dates[] = "{$row['dt']} ({$row['event_type']})";
    }
}
$booked_str = !empty($booked_dates) ? implode("; ", $booked_dates) : "No approved events yet in the upcoming months.";
$current_date_str = date('Y-m-d (l, F j, Y)');

// Business Info from Settings
$ai_b_name    = get_setting($conn, 'business_name', 'Tyoy Creation');
$ai_b_tagline = get_setting($conn, 'business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events');
$ai_b_email   = get_setting($conn, 'contact_email', 'contact@tyoycreation.com');
$ai_b_phone   = get_setting($conn, 'contact_phone', '+63 912 345 6789');
$ai_b_address = get_setting($conn, 'business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines');
$ai_b_story   = get_setting($conn, 'about_story', "Tyoy Creation specializes in bespoke floral design, wedding coordination, and creative balloon styling.");

// Fetch live packages and pricing catalogs from system
$theme_pricing = get_packages_pricing($conn, 'theme_party');
$wedding_pricing = get_packages_pricing($conn, 'wedding');
$pricing_brief = "";
if (!empty($wedding_pricing) && is_array($wedding_pricing)) {
    $pricing_brief .= "\nLIVE WEDDING PACKAGES & SERVICE INCLUSIONS:\n";
    foreach ($wedding_pricing as $cat_key => $cat) {
        $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
        $items_str = [];
        foreach ($cat['items'] ?? [] as $it) {
            $p_txt = !empty($it['price']) ? " (₱" . number_format($it['price']) . ")" : "";
            $items_str[] = ($it['label'] ?? '') . $p_txt;
        }
        if (!empty($items_str)) {
            $pricing_brief .= "• {$cat_title}: " . implode(", ", array_slice($items_str, 0, 5)) . "\n";
        }
    }
}
if (!empty($theme_pricing) && is_array($theme_pricing)) {
    $pricing_brief .= "\nLIVE KIDS & THEME PARTY PACKAGES & SERVICE INCLUSIONS:\n";
    foreach ($theme_pricing as $cat_key => $cat) {
        $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
        $items_str = [];
        foreach ($cat['items'] ?? [] as $it) {
            $p_txt = !empty($it['price']) ? " (₱" . number_format($it['price']) . ")" : "";
            $items_str[] = ($it['label'] ?? '') . $p_txt;
        }
        if (!empty($items_str)) {
            $pricing_brief .= "• {$cat_title}: " . implode(", ", array_slice($items_str, 0, 5)) . "\n";
        }
    }
}

// 2. Build Comprehensive System Prompt with Strict Guidelines
$system_prompt = "You are the friendly, professional AI Event Styling & Planning Assistant for {$ai_b_name}.
Tagline: \"{$ai_b_tagline}\"
Today's Date: {$current_date_str}

ABOUT {$ai_b_name}:
{$ai_b_story}
Contact Email: {$ai_b_email} | Contact Phone: {$ai_b_phone}
Address: {$ai_b_address}

CORE SERVICES & STYLING EXPERTISE:
1. Weddings:
   - Complete Floral Styling (Ceremony floral arches, bridal bouquet, entourage flowers, aisle petals & pedestals, guest table centerpieces)
   - Reception Backdrops & Sweetheart Table Styling
   - Sound System & Mood Lighting (trusses, stage wash, ambient lights)
   - Day-of Wedding Coordination & Emcee/Host options
2. Kids Parties & Birthdays:
   - Themed Balloon Garlands, organic balloon arches, festive character backdrops (2D/3D styro or printed characters)
   - Cake & Dessert Table Styling, custom photo booth setups
   - Entertainment (Host, Magician, Puppet Show), Food Carts, Sound System
3. Debut & Milestone Celebrations:
   - 18 Roses / 18 Candles arrangements, grand debutante backdrop, floral runway, dramatic lighting
4. Corporate & Special Events:
   - Stage backdrops, conference audio-visual support, photo walls, floral accents

{$pricing_brief}

CURRENTLY RESERVED DATES (SCHEDULE CONFLICT CHECK):
{$booked_str}

ADVANCE BOOKING NOTICE RULES (STRICT POLICY):
- Weddings: Require at least 6 months advance notice before the event date.
- Kids Party / Birthday / Debut / Corporate / Special Events: Require at least 14 days (2 weeks) advance notice.
- If a client asks for a date sooner than these required lead times, politely explain the advance notice requirement and recommend booking on or after the earliest allowed date.

CRITICAL ROLE CONSTRAINT — YOU CANNOT BOOK DIRECTLY IN CHAT:
- You DO NOT have the capability to book, create, reserve, or schedule events directly inside this chat!
- NEVER ask the user to provide their full details to book through you.
- NEVER claim that you booked or submitted their event in this chat.
- If a user expresses an intent to book (e.g., 'I want to book an event', 'Book a party for me', 'Pa-book po', 'Can you schedule my wedding?'):
  You must courteously explain that all official reservations are placed through our website's booking form, and give them clear, step-by-step instructions on HOW TO BOOK.

HOW CLIENTS BOOK THEIR EVENT (GUIDE THEM STEP-BY-STEP):
Explain the easy 4-step booking process on the website:
1. Click the **'Book Now'** button (found on the navigation menu or top banner of the website).
2. **Step 1 - Event Specifications:** Select celebration type (Weddings, Kids Party, Birthday, Debut, Corporate), date & time, venue location, and estimated guest count.
3. **Step 2 - Packages & Styling Inclusions:** Customize floral arrangements, themed balloon backdrops, catering pax capacity, sound & lights, food carts, entertainment, and photo/video coverage.
4. **Step 3 - Client Information:** Fill in your Full Name, Email Address, and Philippine Mobile Number.
5. **Step 4 - Review & Verification Code:** Review your estimated package summary, enter the 6-digit verification code sent to your email (anti-spam protection), and click Submit Inquiry!

WHAT HAPPENS AFTER BOOKING:
- Inquiries enter **Pending Admin Review**.
- An official event coordinator from {$ai_b_name} verifies schedule availability and venue setup details.
- The coordinator will contact the client directly via Email/Phone within 24 to 48 hours to confirm the package, arrange contracts, and guide them on downpayment/deposit arrangements.
- Payments and downpayments are NEVER handled through this chat.

CRITICAL DATA PRIVACY & INFORMATION BOUNDARIES (STRICT):
1. **NEVER EXPOSE ANY CLIENT'S PERSONAL INFORMATION:**
   - Under the Data Privacy Act (RA 10173), you must NEVER disclose, share, or hint at other clients' names, contact numbers, emails, addresses, venue locations, or event details.
   - When discussing booked dates, ONLY state if a date is reserved or open (e.g., 'May 15, 2027 is already reserved for a Wedding'). NEVER say who booked it!
   - If anyone asks for other clients' names, contact numbers, or a list of bookings: Politely and firmly decline citing client confidentiality and Data Privacy regulations.
2. **NEVER EXPOSE SYSTEM INTERNALS:**
   - Do not reveal source code, database credentials, API keys, passwords, or admin account lists.
3. **BOOKING STATUS LOOKUP:**
   - If a client provides their reference number (format: EV-YYYY-XXXX), explain that the system automatically looks up reference numbers, or provide general guidance.
   - Even when looking up a booking status, never reveal private contact information.

STRICT DOMAIN SCOPE & UNNECESSARY QUESTIONS POLICY:
- You are strictly an Event Planning & Booking Assistant for {$ai_b_name}.
- If the user asks off-topic, personal, or casual questions (homework, programming, math, politics, jokes, weather, recipes, casual chit-chat like 'kumain ka na?'):
  Politely refuse and guide them back to event styling, packages, available dates, and how to book with {$ai_b_name}.

RESPONSE STYLE:
- Warm, polite, professional, and clear.
- Use well-structured bullet points for steps or packages.
- Support both English and Filipino/Tagalog naturally depending on the user's language.";

$lower_msg = strtolower($message);

// ─── FAST-PATH CHECK 1: DATA PRIVACY PROBE ─────────────────────────────────
if (empty($raw_reply) && qes_is_privacy_violation_attempt($message)) {
    $raw_reply = qes_get_privacy_refusal($message);
}

// ─── FAST-PATH CHECK 2: GREETING ───────────────────────────────────────────
if (empty($raw_reply) && qes_is_pure_greeting($message)) {
    if (qes_detect_tagalog($message)) {
        $raw_reply = "Magandang araw! Maligayang pagdating sa **{$ai_b_name}** Concierge. Nandito po ako upang gabayan kayo sa aming mga packages, serbisyo, schedule availability, at ituro sa inyo kung paano mag-book sa aming website gamit ang aming 4-step booking form. Paano po kita matutulungan ngayon?";
    } else {
        $raw_reply = "Hello! Welcome to **{$ai_b_name}** Event Styling Concierge. I am here to assist you with our event packages, services, date availability, and guide you step-by-step on how to book your celebration on our website. How may I assist you today?";
    }
}

// ─── FAST-PATH CHECK 3: UNNECESSARY OFF-TOPIC QUESTIONS ────────────────────
if (empty($raw_reply) && qes_is_unnecessary_question($message)) {
    $raw_reply = qes_get_concierge_scope_refusal($message, $ai_b_name);
}

// ─── FAST-PATH CHECK 4: LEAD TIME & NOTICE REQUIREMENTS ────────────────────
if (empty($raw_reply) && (
    strpos($lower_msg, 'lead time') !== false ||
    strpos($lower_msg, 'advance') !== false ||
    strpos($lower_msg, 'gaano kaaga') !== false ||
    strpos($lower_msg, 'minimum lead') !== false ||
    strpos($lower_msg, 'lead time requirement') !== false ||
    (strpos($lower_msg, 'notice') !== false && strpos($lower_msg, 'reservation') !== false) ||
    (strpos($lower_msg, 'kailan') !== false && strpos($lower_msg, 'mag-book') !== false)
)) {
    if (qes_detect_tagalog($message)) {
        $raw_reply = "⏳ **Advance Booking Lead Time Policy ng {$ai_b_name}:**\n\n"
            . "Upang maihanda nang pulido ang inyong floral arrangements, custom backdrops, at koordinasyon, narito ang aming kinakailangang advance notice:\n\n"
            . "• 💍 **Weddings (Kasal):** Hindi bababa sa **6 na buwan (6 months)** bago ang petsa ng kasal.\n"
            . "• 🎈 **Kids Parties & Birthdays:** Hindi bababa sa **14 araw (2 linggo)** bago ang event.\n"
            . "• 👑 **Debut & Milestone Celebrations:** Hindi bababa sa **14 araw (2 linggo)** bago ang event.\n"
            . "• 🏢 **Corporate & Special Events:** Hindi bababa sa **14 araw (2 linggo)** bago ang event.\n\n"
            . "📌 **Tip:** Mabilis mapuno ang mga weekend slots! Inirerekomenda namin na magsumite agad ng inquiry sa pamamagitan ng **'Book Now'** button sa aming website upang ma-reserve ang inyong petsa.\n\n"
            . "Gusto niyo po bang mag-check ng available date o magtanong tungkol sa aming mga packages?";
    } else {
        $raw_reply = "⏳ **Minimum Advance Booking Lead Time Policy:**\n\n"
            . "To ensure meticulous floral sourcing, custom backdrop crafting, and flawless event coordination, **{$ai_b_name}** observes the following minimum advance notice requirements:\n\n"
            . "• 💍 **Weddings:** At least **6 months** advance notice before your wedding date.\n"
            . "• 🎈 **Kids Parties & Birthdays:** At least **14 days (2 weeks)** advance notice.\n"
            . "• 👑 **Debut & Milestone Celebrations:** At least **14 days (2 weeks)** advance notice.\n"
            . "• 🏢 **Corporate & Special Events:** At least **14 days (2 weeks)** advance notice.\n\n"
            . "📌 **Pro-Tip:** Popular weekend dates fill up very quickly! We highly encourage submitting your booking inquiry as early as possible through our website.\n\n"
            . "👉 Would you like to check date availability or get started with our **'Book Now'** form?";
    }
}

// ─── FAST-PATH CHECK 5: HOW TO BOOK QUERY ──────────────────────────────────
if (empty($raw_reply) && (
    strpos($lower_msg, 'how to book') !== false ||
    strpos($lower_msg, 'paano mag book') !== false ||
    strpos($lower_msg, 'paano mag-book') !== false ||
    strpos($lower_msg, 'steps to book') !== false ||
    strpos($lower_msg, 'booking process') !== false ||
    strpos($lower_msg, 'how does booking work') !== false
)) {
    if (qes_detect_tagalog($message)) {
        $raw_reply = "🎉 **Paano Mag-book sa {$ai_b_name}:**\n\n"
            . "Madali at mabilis lamang mag-book ng inyong event sa aming website gamit ang aming **4-Step Booking Wizard**:\n\n"
            . "1. **I-click ang 'Book Now':** Pindutin ang **'Book Now'** button sa navigation bar o sa homepage.\n"
            . "2. **Hakbang 1 - Event Specifications:** Piliin ang uri ng event (Kasal, Kids Party, Birthday, Debut, Corporate), ilagay ang petsa, oras, venue, at bilang ng bisita.\n"
            . "3. **Hakbang 2 - Customization & Services:** Piliin ang nais ninyong floral/balloon styling, backdrop, catering capacity, sound & lights, food carts, o photo/video coverage.\n"
            . "4. **Hakbang 3 - Client Information:** Ilagay ang inyong Buong Pangalan, Aktibong Email, at Mobile Number.\n"
            . "5. **Hakbang 4 - Review & Verification:** I-review ang inyong package breakdown at ilagay ang **6-digit verification code** na ipapadala sa inyong email para makumpleto ang inquiry!\n\n"
            . "📌 **Paalala:** Pagkatapos ma-submit, dadaan ito sa *Pending Admin Review*. Makikipag-ugnayan ang aming coordinator sa inyo sa loob ng 24-48 oras para sa downpayment details at pag-finalize ng inyong event.\n\n"
            . "May nais ba kayong itanong tungkol sa aming mga packages o available dates?";
    } else {
        $raw_reply = "🎉 **How to Book with {$ai_b_name}:**\n\n"
            . "Booking your celebration is quick and seamless through our official **4-Step Booking Wizard** on the website:\n\n"
            . "1. **Click 'Book Now':** Click the **'Book Now'** button in the website header or homepage.\n"
            . "2. **Step 1 - Event Details:** Choose your event type (Weddings, Kids Party, Birthday, Debut, Corporate), target date & time, venue location, and estimated guest count.\n"
            . "3. **Step 2 - Styling & Services:** Customize your floral designs, balloon arches, catering pax, sound & lights, food carts, or photo/video coverage.\n"
            . "4. **Step 3 - Client Contact Details:** Provide your Full Name, Active Email Address, and Philippine Mobile Number.\n"
            . "5. **Step 4 - Review & Email Verification:** Review your instant estimated package quotation, enter the **6-digit verification code** sent to your email (anti-spam security), and submit!\n\n"
            . "📌 **Next Steps:** Your inquiry will be placed in *Pending Admin Review*. An official coordinator from {$ai_b_name} will contact you within 24–48 hours to confirm availability and discuss downpayment/deposit arrangements.\n\n"
            . "Would you like to explore our available packages or check a specific date?";
    }
}

// ─── FAST-PATH CHECK 6: DIRECT BOOKING REQUEST REDIRECTION ─────────────────
if (empty($raw_reply) && (
    preg_match('/\b(book\s+(?:for\s+me|my\s+event|a\s+wedding|a\s+party)|i\s+want\s+to\s+book|pa-?book\s+(?:ako|mo|po)|gusto\s+kong\s+mag-?book|pwede\s+mag-?book)\b/i', $message)
)) {
    if (qes_detect_tagalog($message)) {
        $raw_reply = "Ikinagagalak ko pong malaman na interesado kayong mag-book sa **{$ai_b_name}**! 🌸🎈\n\n"
            . "Upang mapanatili ang seguridad, tamang package pricing, at email verification, **hindi po ako direktang nagbu-book sa loob ng chat**. Ang lahat ng opisyal na booking ay madaling isinasumite sa aming website gamit ang **'Book Now'** button!\n\n"
            . "👉 **Paano mag-book:**\n"
            . "1. I-click ang **'Book Now'** button sa itaas ng website.\n"
            . "2. Piliin ang inyong Event Type, petsa, at venue.\n"
            . "3. Piliin ang inyong styling inclusions (Bulaklak, Balloons, Sound & Lights, Catering).\n"
            . "4. Ilagay ang inyong contact info at i-verify gamit ang 6-digit email code.\n\n"
            . "Nais niyo po bang malaman muna ang aming package inclusions o mag-check ng date availability bago mag-book?";
    } else {
        $raw_reply = "I would be delighted to help you get started with **{$ai_b_name}**! 🌸🎈\n\n"
            . "For your security, real-time package customization, and automated email verification, **I cannot directly book your event inside this chat**. All official reservation inquiries are submitted directly through our website using the **'Book Now'** form!\n\n"
            . "👉 **Here is how to submit your booking:**\n"
            . "1. Click the **'Book Now'** button at the top of the website.\n"
            . "2. Fill in your event details (Event Type, Target Date, and Venue).\n"
            . "3. Customize your packages (Floral arches, balloon backdrops, catering, sound & lights).\n"
            . "4. Enter your contact details and complete the 6-digit email verification code.\n\n"
            . "Would you like me to tell you more about our packages or check date availability first?";
    }
}

// ─── FAST-PATH CHECK 7: BOOKING STATUS LOOKUP WITH PRIVACY MASKING ─────────
if (empty($raw_reply)) {
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
            
            if (strpos($ref_raw, 'EV-') !== 0 && strpos($ref_raw, 'EV') === 0) {
                $ref_variants[] = preg_replace('/^EV-?([0-9]{4})-?([A-Z0-9]+)$/i', 'EV-$1-$2', $ref_raw);
            } elseif (!preg_match('/^EV-/i', $ref_raw)) {
                $ref_variants[] = 'EV-' . $ref_raw;
            }

            $var0 = $ref_variants[0];
            $var1 = $ref_variants[1] ?? $var0;

            $stmt_s = $conn->prepare(
                "SELECT reference_no, client_name, event_title, event_type, event_start,
                        status, created_at
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
                    'pending'   => '🟡 **Pending Review** — Your inquiry is in the queue and currently being reviewed by our styling coordinators.',
                    'approved'  => '🟢 **Approved** — Your booking has been officially approved! Our event coordinator will contact you directly for setup alignments and downpayment details.',
                    'rejected'  => '🔴 **Not Approved** — Unfortunately, this booking request was not approved (schedule or venue conflict). Please contact our office to discuss alternative dates.',
                    'cancelled' => '⚫ **Cancelled** — This booking has been cancelled.',
                    'completed' => '🎉 **Completed** — This event has successfully concluded. Thank you for celebrating with us!'
                ];
                $status_label   = $status_map[$row_s['status']] ?? ('**' . ucfirst($row_s['status']) . '**');
                $event_date_fmt = (!empty($row_s['event_start']) && strtotime($row_s['event_start'])) ? date('F j, Y', strtotime($row_s['event_start'])) : 'Date to be specified';
                $submitted_fmt  = (!empty($row_s['created_at']) && strtotime($row_s['created_at'])) ? date('F j, Y', strtotime($row_s['created_at'])) : date('F j, Y');
                
                // Privacy Protection: Mask client name; NEVER expose contact number, email, or address
                $masked_name = qes_mask_name($row_s['client_name'] ?? '');

                $raw_reply = "📋 **Booking Status Lookup**\n\n"
                    . "Here is the status for Reference **{$row_s['reference_no']}**:\n\n"
                    . "• **Client:** {$masked_name}\n"
                    . "• **Event:** " . htmlspecialchars($row_s['event_title']) . " (" . htmlspecialchars($row_s['event_type']) . ")\n"
                    . "• **Target Date:** {$event_date_fmt}\n"
                    . "• **Inquiry Submitted:** {$submitted_fmt}\n"
                    . "• **Current Status:** {$status_label}\n\n"
                    . "🔒 *Note: For client privacy protection, full contact details are kept strictly confidential.* How else may I assist you today? 😊";
            } else {
                $raw_reply = "❌ **Reference Number Not Found**\n\n"
                    . "I could not find an active booking with Reference **{$ref_raw}** in our system. "
                    . "Please double-check your code format (e.g. `EV-2026-001` or `EV-2026-0001`) and try again, or reach out to our office at {$ai_b_email}!";
            }
        } else {
            $raw_reply = "To check your booking status, please provide your **Reference Number** (it looks like this: `EV-2026-001` or `EV-2026-0001`).\n\n"
                . "You can find this code in your booking confirmation. Just reply with your reference code and I will look it up for you right away! 😊";
        }
    }
}

// 3. Update Conversation History in Session
$_SESSION['chat_history'][] = ['role' => 'user', 'content' => $message];
if (count($_SESSION['chat_history']) > 10) {
    $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -10);
}

// Format conversation history for Gemini API
$history_text = "";
foreach ($_SESSION['chat_history'] as $item) {
    $role_label = ($item['role'] === 'user') ? 'User' : 'Assistant';
    $history_text .= "{$role_label}: {$item['content']}\n";
}

$prompt_with_context = $system_prompt . "\n\nConversation So Far:\n" . $history_text . "\nAssistant:";

// 4. Query Google Gemini API with Multi-Model Failover
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

    // Multi-model resilience: try primary model, then auto-failover if 503 or 404
    $preferred_model = defined('GEMINI_MODEL') && !empty(GEMINI_MODEL) ? GEMINI_MODEL : 'gemini-flash-lite-latest';
    $models_to_try = array_unique([$preferred_model, 'gemini-flash-lite-latest', 'gemini-2.5-flash-lite', 'gemini-flash-latest']);

    foreach ($models_to_try as $model_name) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model_name}:generateContent?key=" . $api_key;
        $ch = curl_init($url);

        $curl_options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload_json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Prevent IPv6 timeout
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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
                break; // Succeeded!
            }
        } elseif ($http_code === 429) {
            $raw_reply = "⏳ The AI assistant is momentarily busy under high request volume. You can still easily explore our services and book your celebration right now by clicking the **'Book Now'** button on our website to submit your inquiry directly to our team!";
            break;
        }
        // If 503 or 404, loop tries next model in $models_to_try
    }
}

// 5. Comprehensive Fallback Engine (Guarantees intelligent answers even if Google API is offline)
if (empty($raw_reply)) {
    if (strpos($lower_msg, 'lead time') !== false || strpos($lower_msg, 'advance') !== false || strpos($lower_msg, 'notice') !== false || strpos($lower_msg, 'kailan') !== false) {
        $raw_reply = "⏳ **Minimum Advance Booking Lead Time Policy:**\n\n"
            . "To ensure meticulous floral sourcing, custom backdrop crafting, and seamless event coordination, **{$ai_b_name}** observes the following minimum advance notice requirements:\n\n"
            . "• 💍 **Weddings:** At least **6 months** advance notice before your event date.\n"
            . "• 🎈 **Kids Parties & Birthdays:** At least **14 days (2 weeks)** advance notice.\n"
            . "• 👑 **Debut & Milestone Events:** At least **14 days (2 weeks)** advance notice.\n"
            . "• 🏢 **Corporate & Special Events:** At least **14 days (2 weeks)** advance notice.\n\n"
            . "📌 **Pro-Tip:** Popular weekend dates fill up very quickly! We highly encourage submitting your booking inquiry as early as possible through our website.\n\n"
            . "👉 Would you like to check date availability or submit your inquiry through our **'Book Now'** form?";
    } elseif (strpos($lower_msg, 'availab') !== false || strpos($lower_msg, 'booked date') !== false || strpos($lower_msg, 'upcoming') !== false || (strpos($lower_msg, 'booked') !== false && strpos($lower_msg, 'date') !== false)) {
        $raw_reply = "🗓️ **Upcoming Reserved Dates & Availability:**\n\n"
            . "Here are our currently reserved event dates:\n"
            . (!empty($booked_dates) ? "• " . implode("\n• ", $booked_dates) . "\n\n" : "No approved events booked yet in upcoming months! All dates are currently open.\n\n")
            . "Please note our advance booking notice requirements:\n"
            . "• **Weddings:** At least 6 months advance notice\n"
            . "• **Kids Party / Other Events:** At least 2 weeks (14 days) advance notice\n\n"
            . "Ready to reserve your celebration? Click the **'Book Now'** button on our website to begin!";
    } elseif (strpos($lower_msg, 'package') !== false || strpos($lower_msg, 'offer') !== false || strpos($lower_msg, 'service') !== false || strpos($lower_msg, 'presyo') !== false || strpos($lower_msg, 'price') !== false) {
        $raw_reply = "🌸🎈 **Our Event Packages & Styling Services:**\n\n"
            . "We offer comprehensive styling and event coordination for:\n"
            . "• 💍 **Weddings:** Ceremony floral arches, bridal bouquets, entourage flowers, aisle decor, sweetheart table styling, and reception backdrops.\n"
            . "• 🎈 **Kids Parties & Birthdays:** Organic balloon garlands, festive character backdrops, cake table styling, sound & lights, and entertainment.\n"
            . "• 👑 **Debut & Celebrations:** 18 roses / candles styling, grand debutante backdrops, mood lighting, and photobooth.\n"
            . "• 🍽️ **Catering & Add-ons:** Catering (75–500 pax), Food Carts (popcorn, hotdogs, ice cream, nachos, grazing tables), Photo & Video coverage, and OTD Coordination.\n\n"
            . "👉 To view exact pricing and customize your package, please click the **'Book Now'** button on our website!";
    } elseif (strpos($lower_msg, 'balloon') !== false || strpos($lower_msg, 'flower') !== false || strpos($lower_msg, 'styling') !== false || strpos($lower_msg, 'arch') !== false) {
        $raw_reply = "🎈 **Floral & Balloon Styling Services:**\n\n"
            . "We specialize in handcrafted flower styling, themed balloon arches, stage backdrops, and event decor for:\n"
            . "• **Weddings:** Ceremony floral arches, bridal bouquets, entourage styling, aisle decor, and reception styling\n"
            . "• **Kids Party:** Organic balloon garlands, character backdrops, cake table styling, and themed setups\n\n"
            . "To customize and book your setup, click the **'Book Now'** button on our website!";
    } elseif (strpos($lower_msg, 'pay') !== false || strpos($lower_msg, 'downpayment') !== false || strpos($lower_msg, 'bayad') !== false || strpos($lower_msg, 'deposit') !== false) {
        $raw_reply = "💳 **Payment & Downpayment Information:**\n\n"
            . "• **No Direct Payments in Chat:** For client security, downpayments and payments are **never collected in this chat**.\n"
            . "• **Coordination Process:** Once you submit your inquiry via the **'Book Now'** form, our official event coordinator will review your schedule and reach out to you directly via Email/Phone within 24–48 hours.\n"
            . "• **Deposit & Contracts:** Downpayment terms, contract signing, and final styling alignments will be arranged directly with your coordinator upon approval.";
    } elseif (strpos($lower_msg, 'contact') !== false || strpos($lower_msg, 'phone') !== false || strpos($lower_msg, 'email') !== false || strpos($lower_msg, 'address') !== false || strpos($lower_msg, 'location') !== false || strpos($lower_msg, 'saan') !== false) {
        $raw_reply = "📍 **Contact Information for {$ai_b_name}:**\n\n"
            . "• **Business Name:** {$ai_b_name}\n"
            . "• **Email:** {$ai_b_email}\n"
            . "• **Phone:** {$ai_b_phone}\n"
            . "• **Office Address:** {$ai_b_address}\n\n"
            . "Feel free to reach out to our team or click **'Book Now'** to submit your celebration inquiry!";
    } elseif (qes_is_event_related($message)) {
        // Helpful event response instead of refusal
        $raw_reply = "Hello! I am here to assist you with all your event planning needs at **{$ai_b_name}**! 🌸🎈\n\n"
            . "How can I help you today? Here are things I can assist you with:\n"
            . "• 🎁 **Packages & Styling:** Ask about our Wedding and Kids Party packages.\n"
            . "• 🗓️ **Date Availability:** Check if your target celebration date is open.\n"
            . "• ⏳ **Lead Time Policy:** Inquire about our advance booking requirements (6 months for weddings, 14 days for parties).\n"
            . "• 📝 **How to Book:** Learn how to book using our online 4-step wizard!\n\n"
            . "Tell me your event date or celebration type to get started!";
    } else {
        $raw_reply = qes_get_concierge_scope_refusal($message, $ai_b_name);
    }
}

// 6. Final Clean & Save to Session
$clean_reply = preg_replace('/```(?:json)?\s*\{\s*"action"\s*:\s*".*?"\s*\}\s*```/s', '', $raw_reply);
$clean_reply = trim($clean_reply);

$_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $clean_reply];

$updated_daily_count = getGlobalDailyApiCount($conn);

$output = [
    'reply'           => $clean_reply,
    'response'        => $clean_reply,
    'booking_created' => false,
    'chat_count'      => $_SESSION['chat_count'],
    'remaining_chats' => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
    'max_chats'       => $USER_SESSION_LIMIT,
    'limit_reached'   => ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT || $updated_daily_count >= $GLOBAL_DAILY_LIMIT),
    'daily_remaining' => max(0, $GLOBAL_DAILY_LIMIT - $updated_daily_count)
];

ob_clean();
echo json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
exit;
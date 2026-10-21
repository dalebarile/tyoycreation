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
require_once __DIR__ . '/src/ChatbotBusinessKnowledge.php';

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
    return (bool)preg_match('/\b(kumain|kain|kamusta|kumusta|musta|ano|anong|sino|bakit|paano|saan|san|meron|mayroon|wala|ulam|ka\s*na|kana|mo\s*ba|po|opo|ba|naman|tayo|lahat|ninyo|inyo|kita|ako|ikaw|siya|sila|ko|mo|ang|mga|namin|atin|i-?track|magkano|presyo|kasal|binyag|kaarawan|luto|uulan|ganda|pangit|bata|tao|jowa|syota|babae|lalaki|taga|pa-?book|mag-?book)\b/i', $msg);
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
        '/\b(ilan\s+taon\s+ka|how\s+old\s+are\s+you|taga\s*saan\s+ka|where\s+do\s+you\s+live)\b/i',
        '/\b(babae\s+ka|lalaki\s+ka|what\s+is\s+your\s+gender|are\s+you\s+(?:human|a\s+robot|real))\b/i',
        '/\b(joke|magbiro|tell\s+me\s+a\s+joke|sing|kanta|dance|sayaw|tula|poem|kwento|story)\b/i',
        '/\b(recipe|cooking|luto|recipe\s+ng)\b/i',
        '/\b(crypto|bitcoin|stocks|invest|trading)\b/i',
        '/\b(president|presidente|politika|politics|election|boto)\b/i',
        '/\b(movie|film|celebrity|artista|nba|basketball|sports|game|gaming)\b/i',
        '/\b(medical|sakit|gamot|doctor|medicine|car|kotse|motor)\b/i',
        '/\b(what\s+is\s+the\s+meaning\s+of\s+life|ano\s+ang\s+pag-ibig|what\s+is\s+love)\b/i'
    ];

    // Weather forecast inquiry is casual ONLY if not related to event planning / policies
    if (preg_match('/\b(weather|forecast|temperature)\b/i', $msg)) {
        if (!preg_match('/\b(policy|policies|outdoor|event|wedding|party|reschedule|cancel|backup|garden|venue)\b/i', $msg)) {
            return true;
        }
    }

    foreach ($casual_patterns as $p) {
        if (preg_match($p, $msg)) {
            return true;
        }
    }

    return false;
}

// Gibberish / keyboard smash detection
function qes_is_gibberish(string $msg): bool {
    $clean = trim($msg);
    if (empty($clean)) return true;

    // Don't flag valid short queries or greetings
    if (strlen($clean) <= 2) {
        return !preg_match('/^(hi|ha|ok|oo|no|yo|uy|up|in|on|at|by|to|we|me|my|he|pa|ba|po|ha|eh|di)$/i', $clean);
    }

    // Repeated identical character 4+ times (e.g. "aaaaa", ".....", "?????")
    if (preg_match('/(.)\1{3,}/u', $clean)) {
        return true;
    }

    // Only special characters or symbols with no alphanumeric letters
    if (!preg_match('/[a-z0-9]/i', $clean)) {
        return true;
    }

    // Keyboard smash / common home-row patterns
    if (preg_match('/\b(asdf|fdsa|asdfg|asdfgh|asdfghjkl|qwerty|qwertyuiop|zxcv|zxcvbnm|hjkl|jkl;|qazwsx|wsxedc)\b/i', $clean)) {
        return true;
    }

    // Check words
    $words = preg_split('/\s+/', $clean);
    $total_words = count($words);
    $gibberish_words = 0;

    foreach ($words as $w) {
        $w = preg_replace('/[^a-zA-Z]/', '', strtolower($w));
        $len = strlen($w);
        if ($len >= 6) {
            $vowels = preg_match_all('/[aeiou]/i', $w);
            $consonants = $len - $vowels;

            // Zero vowels in 6+ letter word (e.g. "sdfghj", "brbbrb")
            if ($vowels === 0) {
                $gibberish_words++;
                continue;
            }

            // Extreme consonant ratio: e.g. 14 letters with only 1-2 vowels, or >80% consonants
            if ($consonants > 0 && ($consonants / $len) > 0.80 && $vowels <= 2) {
                $gibberish_words++;
                continue;
            }

            // 4+ impossible consecutive consonants (excluding legitimate English clusters like "ngth", "str", "rthr", "nstr", "ndst")
            if (preg_match('/[bcdfghjklmnpqrstvwxyz]{4,}/i', $w)) {
                if (!preg_match('/(ngth|str|rthr|nstr|ndst|mpstr|ghts)/i', $w)) {
                    $gibberish_words++;
                    continue;
                }
            }

            // Alternating random key mash patterns (e.g. "asbdjasbdk", "sdjklfsd")
            if (preg_match('/(sbdj|bdja|bdka|djas|jasb|kasb|asbd|jkas|kjas|djka|askd|ksdj)/i', $w)) {
                $gibberish_words++;
                continue;
            }
        }
    }

    // If all or majority of words are gibberish
    if ($total_words > 0 && ($gibberish_words / $total_words) >= 0.5) {
        return true;
    }

    return false;
}

function qes_get_gibberish_response(string $msg = '', string $b_name = 'Tyoy Creation'): string {
    if (qes_detect_tagalog($msg)) {
        return "Paumanhin po, hindi ko po lubos na naintindihan ang inyong mensahe. Maaari niyo po bang linawin o ulitin ang inyong katanungan?\n\n"
            . "Handa po akong tumulong sa inyo tungkol sa:\n"
            . "• 🌸 **Event Packages & Pricing:** Weddings, Birthdays, Debut, at Kids Parties\n"
            . "• 🗓️ **Date Availability:** Pag-check ng bukas na petsa sa aming kalendaryo\n"
            . "• 📋 **Staff Review Policy:** Impormasyon sa pag-verify ng booking\n"
            . "• 📝 **Booking Guide:** Paggabay kung paano mag-book sa aming website!";
    }
    return "I'm sorry, I couldn't quite understand that message. Could you please rephrase or clarify your question?\n\n"
        . "I am here to assist you with:\n"
        . "• 🌸 **Event Packages & Pricing:** Weddings, Birthdays, Debut, and Kids Parties\n"
        . "• 🗓️ **Date Availability:** Checking open celebration dates\n"
        . "• 📋 **Staff Review Policy:** Verification guidelines for your booking\n"
        . "• 📝 **Booking Guide:** Step-by-step assistance on booking through our website!";
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

function qes_get_concierge_scope_refusal(string $msg = '', string $b_name = 'Tyoy Creation', bool $is_quota_mode = false): string {
    if ($is_quota_mode) {
        if (qes_detect_tagalog($msg)) {
            return "ℹ️ **Pabatid: Nakaabot na sa Pang-araw-araw na Libreng Quota ang Cloud AI**\n\n"
                . "Pansamantalang nagpapahinga ang external cloud AI para sa general conversational chat hanggang sa mag-reset ang daily quota. Subalit, ang aming **Local Business Knowledge Engine** ay 100% aktibo upang sagutin ang inyong event inquiries!\n\n"
                . "**Narito ang mga katanungang masasagot ko agad ngayon (Offline / Local Mode):**\n"
                . "• 💍 **Packages at Presyo:** Mga detalye sa Wedding (Intimate/Grand/Arch), Kids Party, Debut, Catering, at Food Carts.\n"
                . "• 📋 **Staff Review Policy:** Bakit kailangang suriin ng tao/coordinator ang booking bago ang downpayment.\n"
                . "• ⏳ **Preparation Reminders:** Recommended 3 months para sa kasal, 2 weeks para sa kids party (malayang makakapili ng petsa simula ngayong araw).\n"
                . "• 💳 **Bayad at Downpayment:** 30–50% reservation terms, GCash, Maya, Bank Transfer.\n"
                . "• 📍 **Service Coverage:** Metro Manila, Rizal, Cavite, Laguna, Batangas, Bulacan, Tagaytay.\n"
                . "• 🗓️ **Reschedule at Cancellation:** 30-day notice policy at outdoor weather backup plan.\n"
                . "• 🎨 **Custom Themes at BYO:** Pwede sariling venue at sariling caterer.\n"
                . "• 🗓️ **Calendar Availability:** Real-time check ng mga approved reserved dates.\n"
                . "• 📝 **Paano Mag-book:** Paggabay sa aming online 4-Step Booking Wizard.\n\n"
                . "Maaari ninyong itanong ang alinman sa mga ito, o i-click ang **'Book Now'** button sa website upang mag-inquire!";
        }
        return "ℹ️ **Notice: External Cloud AI Daily Quota Reached**\n\n"
            . "External conversational AI is resting until the daily quota resets. However, our **Local Business Knowledge Engine** is 100% active to assist you with event planning!\n\n"
            . "**Here are the questions I can answer right now (Offline / Local Mode):**\n"
            . "• 💍 **Packages & Live Pricing:** Wedding, Kids Party, Debut, Catering, and Add-on rates.\n"
            . "• 📋 **Staff Review Policy:** Why bookings require coordinator verification before deposit.\n"
            . "• ⏳ **Preparation Reminders:** 3 months for weddings, 2 weeks for parties (clients have free will to choose any date from today onwards).\n"
            . "• 💳 **Payment & Downpayment:** 30–50% deposit terms, GCash, Maya, Bank Transfer.\n"
            . "• 📍 **Service Areas:** Metro Manila and nearby destination provinces.\n"
            . "• 🗓️ **Rescheduling & Cancellation:** 30-day notice and outdoor weather backup guidelines.\n"
            . "• 🎨 **Custom Themes & BYO:** Bringing your own venue or caterer.\n"
            . "• 🗓️ **Calendar Availability:** Real-time lookup of currently reserved dates.\n"
            . "• 📝 **How to Book:** Step-by-step guidance on our online booking wizard.\n\n"
            . "Feel free to ask any of these business questions, or click the **'Book Now'** button on our website to begin!";
    }
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

// 1. Check Global System-Wide Daily Limit (Soft-cap for external Gemini API)
// If the third-party API limit is reached, external AI calls are paused, but our
// Local Business Knowledge Engine CONTINUES to serve all business inquiries seamlessly!
$is_daily_api_exhausted = ($global_daily_used >= $GLOBAL_DAILY_LIMIT);

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
$ai_b_phone   = get_setting($conn, 'contact_phone', '0956 035 3731');
$ai_b_address = get_setting($conn, 'business_address', 'San Jose del Monte, Bulacan');
$ai_b_story   = get_setting($conn, 'about_story', "Tyoy Creation specializes in bespoke floral design, wedding coordination, and creative balloon styling.");

$biz_info = [
    'name'    => $ai_b_name,
    'tagline' => $ai_b_tagline,
    'email'   => $ai_b_email,
    'phone'   => $ai_b_phone,
    'address' => $ai_b_address,
    'story'   => $ai_b_story
];

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
Location: San Jose del Monte, Bulacan
Service Model: Strictly ON-SITE event styling & management (No physical walk-in office - our styling crew brings setups directly to venues across Bulacan, Metro Manila & nearby provinces).
Contact Email: {$ai_b_email} | Contact Phone: {$ai_b_phone}
Official Facebook Page: https://www.facebook.com/tyoycreation
Operating Hours: Monday to Saturday, 8:00 AM – 6:00 PM (Website booking inquiries accepted 24/7)
Primary Service Areas: Bulacan, Metro Manila, and nearby provinces (Rizal, Cavite, Laguna, Batangas, etc.).

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

BOOKING PREPARATION REMINDERS & SCHEDULING POLICY:
- Weddings: Recommended 3 months preparation reminder for floral procurement and bespoke coordination.
- Kids Party / Birthday / Debut / Corporate / Special Events: Recommended 2 weeks (14 days) preparation reminder.
- Note: Restrictions have been removed. Clients have complete free will to select any date starting from today onwards. Explain the preparation reminder gracefully without blocking them.

CRITICAL THIRD-PARTY AI SERVICE DEPENDENCY & MANDATORY STAFF REVIEW (AUDIT RULE):
- This chatbot is powered by a third-party AI service and relies on an internet connection. Its answers and pricing figures may not always be 100% accurate and are provided for initial estimation and planning guidance only.
- ALL bookings, package customizations, and date inquiries submitted through our website MUST undergo official Staff Review by Tyoy Creation's human event coordinators within 24 to 48 hours.
- When users ask about bookings, packages, or pricing: Always remind them that quotes are estimates and that our human coordination team will review all venue logistics, date availability, and final styling choices before issuing an official contract and payment instructions.
- Never state or imply that an event has been finalized or confirmed directly inside this chat.

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

WHAT HAPPENS AFTER BOOKING (STAFF REVIEW):
- Inquiries enter **Pending Admin Review**.
- An official event coordinator from {$ai_b_name} verifies schedule availability, venue guidelines, and setup details.
- The coordinator will contact the client directly via Email/Phone within 24 to 48 hours to confirm the package, arrange contracts, and guide them on downpayment/deposit arrangements.
- Payments and downpayments are NEVER handled through this chat.

CRITICAL DATA PRIVACY & INFORMATION BOUNDARIES (STRICT):
1. **NEVER EXPOSE ANY CLIENT'S PERSONAL INFORMATION:**
   - Under the Data Privacy Act (RA 10173), you must NEVER disclose, share, or hint at other clients' names, contact numbers, emails, addresses, venue locations, or event details.
   - When discussing booked dates, ONLY state if a date is reserved or open (e.g., 'May 15, 2027 is already reserved for a Wedding'). NEVER say who booked it!
   - If anyone asks for other clients' names, contact numbers, or a list of bookings: Politely and firmly decline citing client confidentiality and Data Privacy regulations.
2. **NEVER EXPOSE SYSTEM INTERNALS:**
   - Do not reveal source code, database credentials, API keys, passwords, or admin account lists.
3. **BOOKING STATUS & DETAILS WALKTHROUGH:**
   - The system is equipped with a direct database walkthrough to look up and track booking reference numbers (e.g., EV-YYYY-XXXX or 4-digit code).
   - If a client asks to check or track their booking status, encourage them to provide their Reference Number (e.g., EV-2026-3212).
   - Client privacy is strictly protected by masking personal information (names, emails, phone numbers). Never reveal unmasked contact details.

STRICT DOMAIN SCOPE & UNNECESSARY QUESTIONS POLICY:
- You are strictly an Event Planning & Booking Assistant for {$ai_b_name}.
- If the user sends gibberish, keyboard smash, random letter strings (e.g. 'asbdjasbdkasbd', 'asdfghjkl'), or incomprehensible text:
  Politely inform the user that you did not understand their message and invite them to clarify their event questions. Do NOT output a full welcoming sales pitch or long generic intro.
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

// ─── FAST-PATH CHECK 2A: CONCIERGE IDENTITY / INTRO ───────────────────────
if (empty($raw_reply) && preg_match('/^(who\s+are\s+you|what\s+is\s+your\s+name|sino\s+ka|anong\s+pangalan\s+mo|what\s+can\s+you\s+do|ano\s+ang\s+kaya\s+mong\s+gawin)\b/i', trim($message))) {
    if (qes_detect_tagalog($message)) {
        $raw_reply = "Ako po ang opisyal na **AI Event Planning Concierge ng {$ai_b_name}**! 🌸🎈\n\n"
            . "Nandito po ako upang tulungan kayo sa:\n"
            . "• 💍 **Packages at Presyo:** Mga detalye para sa Kasal, Kids Party, Debut, at Catering\n"
            . "• 🗓️ **Date Availability:** Real-time lookup ng reserved at available celebration dates\n"
            . "• 📋 **Staff Review Policy:** Pagpapaliwanag kung bakit sinusuri ng human coordinator ang bawat booking\n"
            . "• 🔍 **Booking Tracking:** Pag-check ng status ng inyong inquiry gamit ang Reference Number\n"
            . "• 📝 **Booking Guide:** Paggabay kung paano gamitin ang aming online 4-Step Booking Wizard!\n\n"
            . "Paano ko po kayo matutulungan sa inyong okasyon ngayon?";
    } else {
        $raw_reply = "Hello! I am the official **Virtual Event Concierge for {$ai_b_name}**! 🌸🎈\n\n"
            . "I am designed to help you plan your celebration seamlessly by providing:\n"
            . "• 💍 **Packages & Live Pricing:** Accurate rates for Weddings, Kids Parties, Debut, and Catering\n"
            . "• 🗓️ **Date Availability:** Checking open vs. reserved celebration dates\n"
            . "• 📋 **Staff Review Policy:** Explaining our coordinator verification and scheduling workflow\n"
            . "• 🔍 **Booking Status Tracking:** Looking up inquiry progress using your Reference Number\n"
            . "• 📝 **Booking Wizard Guide:** Step-by-step assistance with our online booking form!\n\n"
            . "How may I assist you with your celebration today?";
    }
}

// ─── FAST-PATH CHECK 2B: GIBBERISH / MEANINGLESS KEYSTROKES ────────────────
if (empty($raw_reply) && qes_is_gibberish($message)) {
    $raw_reply = qes_get_gibberish_response($message, $ai_b_name);
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
        $raw_reply = "⏳ **Preparation Reminders & Scheduling Policy ng {$ai_b_name}:**\n\n"
            . "Malaya kayong makakapili ng anumang petsa mula sa araw na ito! Upang magabayan kayo sa sapat na oras ng paghahanda, narito ang aming inirerekomenda:\n\n"
            . "• 💍 **Weddings (Kasal):** **3 Months Preparation Reminder** (inirerekomenda ang 3 buwang preparasyon para sa floral procurement at styling).\n"
            . "• 🎈 **Kids Parties & Birthdays:** **2 Weeks Preparation Reminder** (inirerekomenda ang 2 linggong preparasyon para sa themed props at balloon styling).\n"
            . "• 👑 **Debut & Milestone Celebrations:** **2 Weeks Preparation Reminder**.\n"
            . "• 🏢 **Corporate & Special Events:** **2 Weeks Preparation Reminder**.\n\n"
            . "📌 **Tip:** Walang date restrictions—maaari kayong pumili ng inyong nais na petsa sa aming **'Book Now'** form simula ngayong araw!\n\n"
            . "Gusto niyo po bang mag-check ng available date o magtanong tungkol sa aming mga packages?";
    } else {
        $raw_reply = "⏳ **Booking Preparation Reminders & Scheduling Policy:**\n\n"
            . "You have complete free will to choose any event date from today onwards! To help you plan smoothly, we observe the following preparation reminders:\n\n"
            . "• 💍 **Weddings:** **3 Months Preparation Reminder** (recommended 3 months for meticulous floral sourcing and coordination).\n"
            . "• 🎈 **Kids Parties & Birthdays:** **2 Weeks Preparation Reminder** (recommended 2 weeks for custom props and balloons).\n"
            . "• 👑 **Debut & Milestone Celebrations:** **2 Weeks Preparation Reminder**.\n"
            . "• 🏢 **Corporate & Special Events:** **2 Weeks Preparation Reminder**.\n\n"
            . "📌 **Flexible Booking:** There are no lock-out restrictions—you are free to choose any date starting from today onwards.\n\n"
            . "👉 Would you like to check date availability or get started with our **'Book Now'** form?";
    }
}

// ─── FAST-PATH CHECK 5: HOW TO BOOK QUERY ──────────────────────────────────
if (empty($raw_reply) && (
    strpos($lower_msg, 'how to book') !== false ||
    strpos($lower_msg, 'how do i book') !== false ||
    strpos($lower_msg, 'how can i book') !== false ||
    strpos($lower_msg, 'how to reserve') !== false ||
    strpos($lower_msg, 'how do i reserve') !== false ||
    strpos($lower_msg, 'paano mag book') !== false ||
    strpos($lower_msg, 'paano mag-book') !== false ||
    strpos($lower_msg, 'paano magpareserve') !== false ||
    strpos($lower_msg, 'paano magpa-reserve') !== false ||
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
    $lower_msg_status = strtolower($message);
    $clean_msg_trim = trim($message);

    // 1. Robust Reference Number Extraction across various formats:
    // EV-2026-3212, EV20263212, EV 2026 3212, 2026-3212, or pure standalone 3212 / 3959
    $has_ref = false;
    $ref_raw = '';

    if (preg_match('/\b(EV[-_ ]?[0-9]{4}[-_ ]?[A-Z0-9]{1,8})\b/i', $message, $ref_match)) {
        $has_ref = true;
        $ref_raw = strtoupper(trim($ref_match[1]));
    } elseif (preg_match('/\b(EV[-_ ][A-Z0-9-]{3,15})\b/i', $message, $ref_match)) {
        $has_ref = true;
        $ref_raw = strtoupper(trim($ref_match[1]));
    } elseif (preg_match('/\b([0-9]{4}-[0-9]{3,5})\b/i', $message, $ref_match)) {
        $has_ref = true;
        $ref_raw = 'EV-' . strtoupper(trim($ref_match[1]));
    } elseif (preg_match('/^(?:ref(?:erence)?|track|check|status|booking|code)?(?:\s*(?:no|num|number))?[:\s#]*\s*(EV[-_ ]?[0-9]{3,8}|[0-9]{4,5})\s*$/i', $clean_msg_trim, $ref_match)) {
        $has_ref = true;
        $val = strtoupper(trim($ref_match[1]));
        $ref_raw = (strpos($val, 'EV') === 0) ? $val : ('EV-' . date('Y') . '-' . $val);
    } elseif (preg_match('/\b(EV[-_ ]?[0-9]{3,8}|[0-9]{4,5})\b/i', $message, $ref_match) && (
        strpos($lower_msg_status, 'track') !== false ||
        strpos($lower_msg_status, 'check') !== false ||
        strpos($lower_msg_status, 'status') !== false ||
        strpos($lower_msg_status, 'booking') !== false ||
        strpos($lower_msg_status, 'ref') !== false
    )) {
        $has_ref = true;
        $val = strtoupper(trim($ref_match[1]));
        $ref_raw = (strpos($val, 'EV') === 0) ? $val : ('EV-' . date('Y') . '-' . $val);
    }

    $is_status_query = $has_ref ||
        strpos($lower_msg_status, 'booking status') !== false ||
        strpos($lower_msg_status, 'check my booking') !== false ||
        strpos($lower_msg_status, 'check booking') !== false ||
        strpos($lower_msg_status, 'my inquiry') !== false ||
        strpos($lower_msg_status, 'track my booking') !== false ||
        strpos($lower_msg_status, 'track my inquiry') !== false ||
        strpos($lower_msg_status, 'track booking') !== false ||
        strpos($lower_msg_status, 'track status') !== false ||
        strpos($lower_msg_status, 'status of my') !== false ||
        strpos($lower_msg_status, 'ref no') !== false ||
        strpos($lower_msg_status, 'reference number') !== false ||
        (strpos($lower_msg_status, 'status') !== false && strpos($lower_msg_status, 'booking') !== false) ||
        (strpos($lower_msg_status, 'status') !== false && strpos($lower_msg_status, 'ev-') !== false);

    if ($is_status_query) {
        if ($has_ref && !empty($ref_raw)) {
            $clean_ref = preg_replace('/[_\s]+/', '-', $ref_raw);
            $ref_variants = [$clean_ref, $ref_raw];
            
            if (strpos($clean_ref, 'EV-') !== 0 && strpos($clean_ref, 'EV') === 0) {
                $ref_variants[] = preg_replace('/^EV-?([0-9]{4})-?([A-Z0-9]+)$/i', 'EV-$1-$2', $clean_ref);
            } elseif (!preg_match('/^EV-/i', $clean_ref)) {
                $ref_variants[] = 'EV-' . $clean_ref;
            }

            if (preg_match('/^EV-?([0-9]{4})-?([A-Z0-9]+)$/i', $clean_ref, $m)) {
                $ref_variants[] = "EV-{$m[1]}-{$m[2]}";
                $ref_variants[] = "EV{$m[1]}{$m[2]}";
            }

            $var0 = $ref_variants[0];
            $var1 = $ref_variants[1] ?? $var0;
            $var2 = $ref_variants[2] ?? $var0;

            // Suffix pattern for matching (e.g. '%-3212')
            $suffix_like = '%' . $var0;
            if (preg_match('/([0-9]{3,5})$/', $clean_ref, $sm)) {
                $suffix_like = '%' . $sm[1];
            }

            $stmt_s = $conn->prepare(
                "SELECT id, reference_no, client_name, client_email, client_phone, event_title, 
                        event_type, event_start, event_end, guest_count, location_venue, 
                        service_requirements, special_notes, status, rejection_reason, created_at, updated_at
                 FROM bookings 
                 WHERE reference_no = ? OR reference_no = ? OR reference_no = ? OR reference_no LIKE ?
                 ORDER BY id DESC LIMIT 1"
            );

            if ($stmt_s) {
                $stmt_s->bind_param("ssss", $var0, $var1, $var2, $suffix_like);
                $stmt_s->execute();
                $res_s = $stmt_s->get_result();
                $row_s = $res_s ? qes_decrypt_booking($res_s->fetch_assoc()) : null;
                $stmt_s->close();
            } else {
                $row_s = null;
            }

            if ($row_s) {
                $is_tl = qes_detect_tagalog($message);
                $event_title = htmlspecialchars($row_s['event_title'] ?? 'Celebration');
                $event_type = htmlspecialchars($row_s['event_type'] ?? 'Event');
                $venue = !empty($row_s['location_venue']) ? htmlspecialchars($row_s['location_venue']) : ($is_tl ? 'Pag-uusapan pa / On-site' : 'To be coordinated / On-site');
                $guests = !empty($row_s['guest_count']) ? (intval($row_s['guest_count']) . ' pax') : ($is_tl ? 'Hindi tinukoy' : 'Not specified');

                // Schedule formatting
                $event_date_fmt = $is_tl ? 'Petsang pag-uusapan pa' : 'Date to be specified';
                $event_time_fmt = '';
                if (!empty($row_s['event_start']) && strtotime($row_s['event_start'])) {
                    $start_ts = strtotime($row_s['event_start']);
                    $event_date_fmt = date('l, F j, Y', $start_ts);
                    $start_time = date('g:i A', $start_ts);
                    if (!empty($row_s['event_end']) && strtotime($row_s['event_end'])) {
                        $end_time = date('g:i A', strtotime($row_s['event_end']));
                        $event_time_fmt = " ({$start_time} – {$end_time})";
                    } else {
                        $event_time_fmt = " ({$start_time})";
                    }
                }
                $submitted_fmt = (!empty($row_s['created_at']) && strtotime($row_s['created_at'])) ? date('F j, Y', strtotime($row_s['created_at'])) : date('F j, Y');

                // Parse package details & estimated total from service_requirements
                $service_req = $row_s['service_requirements'] ?? '';
                $estimated_total = '';
                $styling_summary = '';

                if (!empty($service_req)) {
                    if (preg_match('/ESTIMATED TOTAL:\s*(₱?[\d,]+)/i', $service_req, $tot_m)) {
                        $estimated_total = trim($tot_m[1]);
                        if (strpos($estimated_total, '₱') !== 0) $estimated_total = '₱' . $estimated_total;
                    }
                    $req_parts = explode('|', $service_req);
                    $items_raw = trim($req_parts[0]);
                    if (!empty($items_raw)) {
                        $items_list = array_filter(array_map('trim', explode(';', $items_raw)));
                        if (!empty($items_list)) {
                            $formatted_items = [];
                            foreach (array_slice($items_list, 0, 5) as $it) {
                                $formatted_items[] = "  • " . htmlspecialchars($it);
                            }
                            $styling_summary = implode("\n", $formatted_items);
                            if (count($items_list) > 5) {
                                $extra_cnt = count($items_list) - 5;
                                $styling_summary .= "\n  • *" . ($is_tl ? "+ {$extra_cnt} pang karagdagang inclusions" : "+ {$extra_cnt} more styling inclusions") . "*";
                            }
                        }
                    }
                }

                // Privacy protection: Mask client name and email per Data Privacy Act (RA 10173)
                $masked_name = qes_mask_name($row_s['client_name'] ?? '');
                $masked_email = '';
                if (!empty($row_s['client_email'])) {
                    $e_parts = explode('@', $row_s['client_email']);
                    if (count($e_parts) === 2) {
                        $u = $e_parts[0];
                        $masked_u = (strlen($u) <= 2) ? $u : (substr($u, 0, 1) . '***' . substr($u, -1));
                        $masked_email = $masked_u . '@' . $e_parts[1];
                    }
                }

                // Walkthrough based on Status
                $status = strtolower($row_s['status'] ?? 'pending');
                if ($status === 'approved') {
                    $status_badge = "🟢 **Approved & Reserved**";
                    $status_desc = $is_tl
                        ? "Opisyal nang **apruba at naka-reserve** ang inyong okasyon sa aming kalendaryo!\n"
                          . "• **Susunod na Hakbang:** Makikipag-ugnayan ang aming event styling coordinator sa inyo nang direkta upang i-align ang venue setup logistics, styling mockups, at downpayment terms (30%–50% reservation deposit)."
                        : "Your booking is **officially approved and reserved** on our calendar!\n"
                          . "• **Next Step:** Our event styling coordinator will reach out directly to align on venue logistics, design mockups, and reservation downpayment (30%–50% deposit).";
                } elseif ($status === 'pending') {
                    $status_badge = "🟡 **Pending Admin Review**";
                    $status_desc = $is_tl
                        ? "Kasalukuyang sinusuri ng aming mga coordinators ang inyong inquiry sa queue.\n"
                          . "• **Proseso:** Sinusuri ang schedule availability, venue guidelines, at supply ng bulaklak/materials.\n"
                          . "• **Kailan Makakatanggap ng Update:** Makakatanggap kayo ng tawag o email sa loob ng **24 hanggang 48 oras** para sa kumpirmasyon at contract details."
                        : "Your inquiry is in the queue and currently undergoing official review by our coordination team.\n"
                          . "• **Verification Process:** We check schedule availability, venue logistics, and styling material sourcing.\n"
                          . "• **Expected Turnaround:** You will be contacted via email/phone within **24 to 48 hours** with official verification and next steps.";
                } elseif ($status === 'rejected') {
                    $rej_note = !empty($row_s['rejection_reason']) ? ("\n• **Coordinator Note:** " . htmlspecialchars($row_s['rejection_reason'])) : "";
                    $status_badge = "🔴 **Not Approved (Schedule / Venue Conflict)**";
                    $status_desc = $is_tl
                        ? "Ikinalulungkot po namin ngunit hindi maaprubahan ang request na ito{$rej_note}.\n"
                          . "• **Mungkahi:** Nais po naming tulungan kayong maghanap ng ibang bakanteng petsa! Maaari po kayong mag-inquire muli o tumawag sa aming opisina sa {$ai_b_phone}."
                        : "Unfortunately, this booking inquiry could not be accommodated{$rej_note}.\n"
                          . "• **Next Step:** We would love to help you find an alternative date! Please contact our coordination team at {$ai_b_email} or call {$ai_b_phone}.";
                } elseif ($status === 'completed') {
                    $status_badge = "🎉 **Event Successfully Completed**";
                    $status_desc = $is_tl
                        ? "Matagumpay na naisakatuparan ang okasyong ito. Maraming salamat po sa pagtitiwala sa {$ai_b_name}!"
                        : "This celebration has successfully taken place. Thank you for celebrating your milestones with {$ai_b_name}!";
                } elseif ($status === 'cancelled') {
                    $status_badge = "⚫ **Cancelled**";
                    $status_desc = $is_tl ? "Ang booking na ito ay kinansela." : "This reservation inquiry was marked as cancelled.";
                } else {
                    $status_badge = "🔵 **" . ucfirst($status) . "**";
                    $status_desc = "Current status: " . ucfirst($status);
                }

                if ($is_tl) {
                    $raw_reply = "🔍 **Database Walkthrough — Reference: `{$row_s['reference_no']}`**\n\n"
                        . "Natagpuan ko po sa aming database ang inyong booking records:\n\n"
                        . "📋 **Impormasyon ng Event:**\n"
                        . "• **Kliyente:** {$masked_name}" . ($masked_email ? " ({$masked_email})" : "") . "\n"
                        . "• **Okasyon:** {$event_title} ({$event_type})\n"
                        . "• **Target Schedule:** {$event_date_fmt}{$event_time_fmt}\n"
                        . "• **Lugar / Venue:** {$venue}\n"
                        . "• **Tinatayang Bisita:** {$guests}\n"
                        . "• **Petsa ng Pagsumite:** {$submitted_fmt}\n\n";

                    if (!empty($estimated_total)) {
                        $raw_reply .= "💰 **Tinatayang Halaga:** {$estimated_total}\n";
                    }
                    if (!empty($styling_summary)) {
                        $raw_reply .= "🎨 **Piniling Styling & Inclusions:**\n" . $styling_summary . "\n\n";
                    } else {
                        $raw_reply .= "\n";
                    }

                    $raw_reply .= "📊 **Status at Gabay mula sa Coordinator:**\n"
                        . "• **Status:** {$status_badge}\n"
                        . "{$status_desc}\n\n"
                        . "🔒 *Paalala: Protektado ang buong personal info alinsunod sa Data Privacy Act (RA 10173).* Paano pa po kita matutulungan sa inyong okasyon? 😊";
                } else {
                    $raw_reply = "🔍 **Database Walkthrough — Reference: `{$row_s['reference_no']}`**\n\n"
                        . "Here are the live event details and status retrieved from our system database:\n\n"
                        . "📋 **Event Summary:**\n"
                        . "• **Client:** {$masked_name}" . ($masked_email ? " ({$masked_email})" : "") . "\n"
                        . "• **Celebration:** {$event_title} ({$event_type})\n"
                        . "• **Target Schedule:** {$event_date_fmt}{$event_time_fmt}\n"
                        . "• **Venue / Location:** {$venue}\n"
                        . "• **Estimated Guests:** {$guests}\n"
                        . "• **Submitted On:** {$submitted_fmt}\n\n";

                    if (!empty($estimated_total)) {
                        $raw_reply .= "💰 **Estimated Investment:** {$estimated_total}\n";
                    }
                    if (!empty($styling_summary)) {
                        $raw_reply .= "🎨 **Selected Styling & Inclusions:**\n" . $styling_summary . "\n\n";
                    } else {
                        $raw_reply .= "\n";
                    }

                    $raw_reply .= "📊 **Current Status & Coordinator Walkthrough:**\n"
                        . "• **Status:** {$status_badge}\n"
                        . "{$status_desc}\n\n"
                        . "🔒 *Note: Sensitive client PII is strictly protected under the Data Privacy Act (RA 10173).* How else may I assist you with your celebration today? 😊";
                }
            } else {
                if (qes_detect_tagalog($message)) {
                    $raw_reply = "❌ **Hindi Natagpuan ang Reference Number**\n\n"
                        . "Paumanhin po, hindi ko natagpuan ang Reference **{$ref_raw}** sa aming database.\n\n"
                        . "📌 **Mungkahi:**\n"
                        . "• Pakisuri po ang format sa inyong booking confirmation (halimbawa: `EV-2026-3212` o `EV-2026-001`).\n"
                        . "• Siguraduhing tama ang mga numero.\n"
                        . "• Kung kakasumite niyo lamang nito, maghintay ng ilang sandali o makipag-ugnayan sa aming opisina sa {$ai_b_email} o tumawag sa {$ai_b_phone}!";
                } else {
                    $raw_reply = "❌ **Reference Number Not Found**\n\n"
                        . "I could not find an active booking with Reference **{$ref_raw}** in our database.\n\n"
                        . "📌 **Tips:**\n"
                        . "• Please verify the reference code format in your booking confirmation (e.g., `EV-2026-3212` or `EV-2026-001`).\n"
                        . "• Double-check that there are no typographical errors in the digits.\n"
                        . "• If you just submitted your booking a moment ago, please allow a minute or contact our coordination team at {$ai_b_email} or call {$ai_b_phone}!";
                }
            }
        } else {
            if (qes_detect_tagalog($message)) {
                $raw_reply = "🔍 **I-track ang Inyong Booking Status at Detalye (Live Database Walkthrough)**\n\n"
                    . "Maaari ko pong hanapin sa aming database ang real-time status, piniling styling packages, petsa ng okasyon, at update mula sa aming coordinator!\n\n"
                    . "👉 **Pakisulat o i-paste ang inyong Booking Reference Number** (halimbawa: `EV-2026-3212` o `EV-2026-001`).\n\n"
                    . "💡 **Saan makikita ang inyong Reference Number:**\n"
                    . "• Sa confirmation screen pagkatapos ninyong isumite ang booking sa website.\n"
                    . "• Sa inyong confirmation email o text inquiry summary.\n\n"
                    . "I-reply lamang po ang inyong reference number dito sa chat at ilalabas ko agad ang kumpletong detalye ng inyong booking! 😊";
            } else {
                $raw_reply = "🔍 **Track Your Booking Status & Details (Live Database Walkthrough)**\n\n"
                    . "I can connect directly to our database to trace your reference number, check your coordinator review status, and display your celebration details!\n\n"
                    . "👉 **Please enter your Booking Reference Number** (it looks like `EV-2026-XXXX`, e.g., `EV-2026-3212` or `EV-2026-001`).\n\n"
                    . "💡 **Where to find your Reference Number:**\n"
                    . "• On your screen immediately after submitting your booking inquiry.\n"
                    . "• In your confirmation email summary.\n\n"
                    . "Simply type or paste your reference number in this chat and I will pull up your event walkthrough right away! 😊";
            }
        }
    }
}

// ─── FAST-PATH CHECK 8: HIGH-CONFIDENCE SYSTEM BUSINESS KNOWLEDGE ENGINE ───
// Directly answers all business questions (Staff review policy, packages & pricing,
// lead times, downpayment & payment channels, office location & service areas,
// rescheduling/cancellations, and custom themes/BYO) accurately with ZERO external dependency!
if (empty($raw_reply)) {
    $biz_reply = ChatbotBusinessKnowledge::resolveBusinessIntent(
        $message,
        $conn,
        $biz_info,
        $wedding_pricing,
        $theme_pricing,
        $booked_dates
    );
    if (!empty($biz_reply)) {
        $raw_reply = $biz_reply;
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

// 4. Query Google Gemini API with Multi-Model Failover (Soft-gated by daily quota)
if (empty($raw_reply) && !$is_daily_api_exhausted && (defined('GEMINI_KEY') || defined('GEMINI_API_KEY'))) {
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
            'maxOutputTokens' => 500
        ]
    ];
    $payload_json = json_encode($payload);

    // Multi-model resilience: try high-speed primary model, then auto-failover if 503 or 404
    $preferred_model = defined('GEMINI_MODEL') && !empty(GEMINI_MODEL) ? GEMINI_MODEL : 'gemini-3.5-flash-lite';
    $models_to_try = array_unique([$preferred_model, 'gemini-3.5-flash-lite', 'gemini-3.5-flash']);

    foreach ($models_to_try as $model_name) {
        // API key is sent via header (x-goog-api-key), NOT the URL, so it never
        // appears in Referer/access logs or error traces.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model_name}:generateContent";
        $ch = curl_init($url);

        $curl_options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload_json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $api_key,
            ],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Prevent IPv6 timeout
            // SECURITY: Always verify the server's TLS certificate and hostname.
            // Never disable these — doing so allows man-in-the-middle interception
            // of the API key and conversation data.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // Resolve a CA bundle so verification has a trust store to work against.
        // Prefer PHP's configured bundle; otherwise fall back to common local paths.
        if (!empty(ini_get('curl.cainfo')) && file_exists(ini_get('curl.cainfo'))) {
            $curl_options[CURLOPT_CAINFO] = ini_get('curl.cainfo');
        } else {
            foreach ([
                'C:/xampp/apache/bin/curl-ca-bundle.crt',
                'C:/xampp/php/extras/ssl/cacert.pem',
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
            ] as $ca_candidate) {
                if (file_exists($ca_candidate)) {
                    $curl_options[CURLOPT_CAINFO] = $ca_candidate;
                    break;
                }
            }
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
            // Google quota/rate limit exhausted: break out so Local Business Knowledge Engine answers!
            break;
        }
        // If 503 or 404, loop tries next model in $models_to_try
    }
}

// 5. Comprehensive Fallback Engine (Guarantees intelligent answers even if Google API is offline)
if (empty($raw_reply)) {
    $fallback_biz = ChatbotBusinessKnowledge::resolveBusinessIntent(
        $message,
        $conn,
        $biz_info,
        $wedding_pricing,
        $theme_pricing,
        $booked_dates,
        true // is_offline_mode active
    );
    if (!empty($fallback_biz)) {
        $raw_reply = $fallback_biz;
    }
}
if (empty($raw_reply)) {
    if (strpos($lower_msg, 'lead time') !== false || strpos($lower_msg, 'advance') !== false || strpos($lower_msg, 'notice') !== false || strpos($lower_msg, 'reminder') !== false || strpos($lower_msg, 'preparation') !== false || strpos($lower_msg, 'kailan') !== false) {
        $raw_reply = "⏳ **Booking Preparation Reminders & Policy:**\n\n"
            . "Clients have free will to select any event date from today onwards! Here are our recommended preparation reminders:\n\n"
            . "• 💍 **Weddings:** 3 Months Preparation Reminder\n"
            . "• 🎈 **Kids Parties & Birthdays:** 2 Weeks Preparation Reminder\n"
            . "• 👑 **Debut & Milestone Events:** 2 Weeks Preparation Reminder\n"
            . "• 🏢 **Corporate & Special Events:** 2 Weeks Preparation Reminder\n\n"
            . "👉 Would you like to check date availability or submit your inquiry through our **'Book Now'** form?";
    } elseif (strpos($lower_msg, 'availab') !== false || strpos($lower_msg, 'booked date') !== false || strpos($lower_msg, 'upcoming') !== false || (strpos($lower_msg, 'booked') !== false && strpos($lower_msg, 'date') !== false)) {
        $raw_reply = "🗓️ **Upcoming Reserved Dates & Availability:**\n\n"
            . "Here are our currently reserved event dates:\n"
            . (!empty($booked_dates) ? "• " . implode("\n• ", $booked_dates) . "\n\n" : "No approved events booked yet in upcoming months! All dates are currently open.\n\n")
            . "Recommended preparation reminders (free date selection from today onwards):\n"
            . "• **Weddings:** 3 Months Preparation Reminder\n"
            . "• **Kids Party / Other Events:** 2 Weeks Preparation Reminder\n\n"
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
            . "• ⏳ **Preparation Reminders:** Inquire about our recommended preparation reminders (3 months for weddings, 2 weeks for parties; open date selection from today onwards).\n"
            . "• 📝 **How to Book:** Learn how to book using our online 4-step wizard!\n\n"
            . "Tell me your event date or celebration type to get started!";
    } else {
        $raw_reply = qes_get_concierge_scope_refusal($message, $ai_b_name, $is_daily_api_exhausted);
    }
}

// 6. Prepend Quota Exhausted / Offline Notice if first time in session
if ($is_daily_api_exhausted && empty($_SESSION['notified_quota_exhausted'])) {
    $_SESSION['notified_quota_exhausted'] = true;
    $quota_notice = qes_detect_tagalog($message)
        ? "⚡ *[Pabatid: Naka-Local Business Mode dahil naabot na ang free daily API limit. Patuloy naming sasagutin ang lahat ng packages, presyo, at booking inquiries mula sa aming verified database.]*\n\n"
        : "⚡ *[Notice: Operating in Local Business Mode — External AI daily quota reached. Answering all packages, pricing, policies, and booking questions directly from our database!]*\n\n";
    $raw_reply = $quota_notice . $raw_reply;
}

// 7. Final Clean & Save to Session
$clean_reply = preg_replace('/```(?:json)?\s*\{\s*"action"\s*:\s*".*?"\s*\}\s*```/s', '', $raw_reply);
$clean_reply = trim($clean_reply);

$_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $clean_reply];

$updated_daily_count = getGlobalDailyApiCount($conn);

$output = [
    'reply'                  => $clean_reply,
    'response'               => $clean_reply,
    'booking_created'        => false,
    'chat_count'             => $_SESSION['chat_count'],
    'remaining_chats'        => max(0, $USER_SESSION_LIMIT - $_SESSION['chat_count']),
    'max_chats'              => $USER_SESSION_LIMIT,
    'limit_reached'          => ($_SESSION['chat_count'] >= $USER_SESSION_LIMIT),
    'daily_remaining'        => max(0, $GLOBAL_DAILY_LIMIT - $updated_daily_count),
    'is_offline_mode'        => $is_daily_api_exhausted,
    'is_api_quota_exhausted' => ($updated_daily_count >= $GLOBAL_DAILY_LIMIT),
    'engine_mode'            => $is_daily_api_exhausted ? 'Local Business Knowledge Base (Offline/Quota Mode)' : 'Online Cloud AI (Google Gemini)'
];

ob_clean();
echo json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
exit;
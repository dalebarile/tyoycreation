<?php
/**
 * Tyoy Creation - High-Intelligence Business Knowledge & Reasoning Engine
 * 
 * Provides comprehensive, deterministic, offline-capable answers for ALL business questions:
 * - Staff review & accuracy verification workflow
 * - Full wedding & theme party packages with live pricing
 * - Preparation reminders (3 months recommended for weddings, 2 weeks for parties; clients have free will to choose any date from today onwards)
 * - Payment, downpayment & security terms
 * - Office location, service coverage (Metro Manila & provinces), operating hours
 * - Rescheduling, cancellation, weather & refund policies
 * - Custom themes & BYO venue/catering policies
 * - Real-time date availability & booking status lookup
 * 
 * Functions offline without requiring third-party AI or an internet connection.
 */

class ChatbotBusinessKnowledge {

    public static function isTagalog(string $msg): bool {
        return (bool)preg_match('/\b(kumain|kain|kamusta|kumusta|musta|ano|anong|sino|bakit|paano|saan|san|meron|mayroon|wala|ulam|ka\s*na|kana|mo\s*ba|po|opo|ba|naman|tayo|lahat|ninyo|inyo|kita|ako|ikaw|siya|sila|magkano|presyo|kasal|binyag|kaarawan|luto|uulan|ganda|pangit|bata|tao|jowa|syota|babae|lalaki|taga|pa-?book|mag-?book)\b/i', $msg);
    }

    /**
     * Resolves user question against comprehensive business knowledge base.
     * Returns formatted markdown string or null if not a recognized business intent.
     */
    public static function resolveBusinessIntent(
        string $msg,
        $conn,
        array $biz,
        array $wedding_pricing,
        array $theme_pricing,
        array $booked_dates,
        bool $is_offline_mode = false
    ): ?string {
        $lower = strtolower(trim($msg));
        $is_tagalog = self::isTagalog($msg);

        $b_name    = htmlspecialchars($biz['name'] ?? 'Tyoy Creation');
        $b_tagline = htmlspecialchars($biz['tagline'] ?? 'TURNING YOUR MOMENTS INTO Unforgettable Events');
        $b_email   = htmlspecialchars($biz['email'] ?? 'contact@tyoycreation.com');
        $b_phone   = htmlspecialchars($biz['phone'] ?? '0956 035 3731');
        $b_address = htmlspecialchars($biz['address'] ?? 'San Jose del Monte, Bulacan');
        $b_story   = htmlspecialchars($biz['story'] ?? 'Tyoy Creation specializes in bespoke floral design, wedding coordination, and creative balloon styling.');

        // ── 1. STAFF REVIEW & BOOKING VERIFICATION (PRIMARY AUDIT INTENT) ──────
        if (preg_match('/\b(staff\s*review|admin\s*review|pending\s*review|coordinator\s*review|who\s*reviews|human\s*review|why\s*pending|bakit\s*pending|why\s*.*?\breview|bakit\s*.*?\b(?:i-?review|suriin|review)|kailangan\s*.*?\b(?:i-?review|suriin|review)|i-?review\s*ng\s*tao|accuracy|not\s*(?:always\s*)?accurate|third[- ]party|ai\s*service|verification\s*process|how\s*is\s*booking\s*confirmed|bakit\s*dapat\s*i-?review)\b/i', $lower)) {
            if ($is_tagalog) {
                return "📋 **Bakit Dumadaan sa Opisyal na Staff Review ang Bawat Booking:**\n\n"
                    . "Sa **{$b_name}**, ang aming AI Concierge ay nagbibigay ng mabilisang gabay at package estimates. Subalit, dahil ang AI service at internet ay may limitasyon at ang availability ng aming mga stylist at venue ay patuloy na nagbabago, **ang lahat ng booking inquiry sa aming website ay dumadaan sa mahigpit na Staff Review** bago opisyal na makumpirma.\n\n"
                    . "Narito ang sinisiyasat ng aming Event Coordinators habang nasa *Pending Review*:\n\n"
                    . "1. 🗓️ **Schedule at Availability ng Crew:** Sinisiguro namin na walang schedule conflict at 100% available ang aming senior floral stylists at setup crew sa inyong napiling petsa.\n"
                    . "2. 🏛️ **Venue Guidelines at Feasibility:** Bine-verify ang ingress/egress load-in schedule ng venue, clearance height ng kisame para sa ceiling treatments, at electrical power capacity para sa sound & lighting.\n"
                    . "3. 🎨 **Custom Package Tailoring:** Tinitiyak ang inyong motif, color palette, imported o local blooms, custom 2D/3D balloon standees, at tamang catering headcount.\n"
                    . "4. 📝 **Opisyal na Kontrata at Downpayment:** Naghahanda ang coordinator ng pinal na quotation, kontrata, at verified deposit instructions.\n\n"
                    . "⏱️ **Timeline:** Isang opisyal na coordinator mula sa {$b_name} ang direktang tatawag o mag-e-email sa inyo sa loob ng **24 hanggang 48 oras** pagkatapos ninyong isumite ang inyong inquiry sa website!\n\n"
                    . "Nais niyo po bang malaman kung paano mag-inquire gamit ang aming 4-step booking form?";
            }
            return "📋 **Why All Bookings & Inquiries Undergo Official Staff Review:**\n\n"
                . "At **{$b_name}**, our AI Concierge provides quick guidance, package information, and estimated computations. However, because automated AI answers depend on external services and live calendar availability is dynamic, **all bookings and package options submitted through our website MUST undergo official Staff Review** by our dedicated human event coordinators.\n\n"
                . "Here is what our coordination team verifies during the *Pending Review* phase:\n\n"
                . "1. 🗓️ **Calendar & Team Availability:** We cross-check our master schedule to guarantee that our senior floral designers, balloon artisans, and setup crews are exclusively reserved for your celebration.\n"
                . "2. 🏛️ **Venue Guidelines & Setup Feasibility:** We confirm venue ingress/egress load-in times, ceiling heights for draped trusses, power load capacity for sound & lights, and sound curfew policies.\n"
                . "3. 🎨 **Custom Package Tailoring:** We align your exact theme motif, imported vs. local floral selections, custom 2D/3D character standees, and catering headcount to ensure flawless execution.\n"
                . "4. 📝 **Official Quotation, Contract & Deposit:** Your coordinator issues the verified quotation breakdown, signed service contract, and official downpayment deposit instructions.\n\n"
                . "⏱️ **Outreach Window:** An assigned coordinator will contact you directly via **Email or Phone within 24–48 hours** after your inquiry is submitted!\n\n"
                . "👉 Ready to begin? Click the **'Book Now'** button on our website to start your inquiry!";
        }

        // ── 1B. ONLINE CLOUD AI VS. OFFLINE LOCAL ENGINE & API QUOTA POLICIES ──
        // SAKA LANG LALABAS kapag nag-offline ang chatbot / API quota exhausted!
        if ($is_offline_mode && preg_match('/\b(online\s*(?:vs\.?|o|and|versus)\s*offline|difference\s*(?:between\s*)?online|online\s*mode|offline\s*mode|api\s*limit|free\s*api|quota|consumed|fully\s*consume|naubos\s*(?:ang\s*)?api|limit\s*reached|offline\s*capabilities|what\s*(?:questions?|can|things?)\s*(?:can|could)?\s*(?:be)?\s*(?:answered|asked)|what\s*(?:can\s*you\s*answer|questions?\s*can\s*be\s*answered)|ano\s*(?:ang\s*)?(?:mga\s*)?(?:pwede|masasagot|itanong)|capabilities|anong\s*kaya\s*mong\s*sagutin|anong\s*masasagot)\b/i', $lower)) {
            if ($is_tagalog) {
                return "🌐⚡ **Pagkakaiba ng Online Cloud AI at Offline Local Mode sa {$b_name}:**\n\n"
                    . "Kapag **naubos ang libreng daily API limit** ng Google Gemini (hal. 1,400 daily requests) o kapag walang internet connection, **hindi titigil ang chatbot**. Awtomatiko itong lilipat sa aming **Local Business Knowledge Base** upang patuloy na maglingkod sa inyo nang 100% tumpak at maaasahan.\n\n"
                    . "---\n\n"
                    . "### 📊 Pagkukumpara: Online AI vs. Offline Local Mode\n\n"
                    . "| Katangian | 🌐 Online Cloud AI (Google Gemini) | ⚡ Offline Local Engine (Business Base) |\n"
                    . "| :--- | :--- | :--- |\n"
                    . "| **Pangunahing Gamit** | Malawak na usapan, creative brainstorming, malayang kwentuhan. | Direkta, opisyal, at tumpak na impormasyon ukol sa serbisyo at presyo. |\n"
                    . "| **Pinagmumulan ng Datos** | Cloud Language Model (Google AI) | Direktang konektado sa **Live MySQL Database** at company policies. |\n"
                    . "| **Koneksyon at Quota** | Nangangailangan ng internet at may limitasyon sa daily API quota. | **Walang quota limit**, zero downtime, at gumagana kahit offline. |\n"
                    . "| **Katumpakan ng Presyo** | Tumatantya (Estimate) | **100% Garantisado at Tumpak** (Mula sa opisyal na booking system). |\n"
                    . "| **Staff Review Requirement** | Required bago kumpirmahin ang booking | Required pa rin upang masuri ng coordinator ang inyong event. |\n\n"
                    . "---\n\n"
                    . "### 💡 Anong mga Katanungan ang Masasagot sa Offline / Quota Mode?\n"
                    . "Kahit ubos na ang API credits o walang internet, masasagot pa rin ng chatbot ang **lahat ng mahalagang impormasyon sa inyong okasyon**:\n\n"
                    . "1. 💍 **Mga Packages at Presyo:** Live pricing ng Wedding (Intimate, Grand, Arch, Entourage), Kids Parties, Debut, at Catering.\n"
                    . "2. 📋 **Staff Review Policy:** Bakit kailangang suriin ng coordinator ang booking bago magbayad ng downpayment.\n"
                    . "3. ⏳ **Preparation Reminders:** Inirerekomenda ang 3 months para sa kasal at 2 weeks para sa kids party, ngunit malayang makakapili ng petsa ang kliyente simula ngayong araw.\n"
                    . "4. 💳 **Downpayment at Pagbabayad:** 30–50% reservation terms, GCash, Maya, BDO, BPI, at patakaran sa paniningil.\n"
                    . "5. 📍 **Lokasyon at Service Areas:** Metro Manila, Rizal, Cavite, Laguna, Batangas, Bulacan, at Tagaytay.\n"
                    . "6. 🗓️ **Rescheduling at Cancellation:** 30-day notice policy at weather backup para sa outdoor events.\n"
                    . "7. 🎨 **Sariling Venue o Caterer (BYO):** Pag-customize ng motif at koordinasyon sa inyong sariling suppliers.\n"
                    . "8. 🗓️ **Calendar Availability:** Real-time na approved reserved dates mula sa aming database.\n"
                    . "9. 📝 **Paano Mag-book:** Gabay sa 4-Step Booking Wizard sa website.\n\n"
                    . "👉 Handa na ba kayong simulan ang inyong inquiry? I-click ang **'Book Now'** button sa aming website!";
            }
            return "🌐⚡ **Online Cloud AI vs. Offline Local Mode at {$b_name}:**\n\n"
                . "When the **free daily API quota** for Google Gemini is fully consumed or if an internet outage occurs, **the chatbot does not shut down**. It automatically transitions into our **Local Business Knowledge Base** to continue answering all inquiries accurately and reliably.\n\n"
                . "---\n\n"
                . "### 📊 Comparison: Online Cloud AI vs. Offline Local Mode\n\n"
                . "| Feature | 🌐 Online Cloud AI (Google Gemini) | ⚡ Offline Local Engine (Business Base) |\n"
                . "| :--- | :--- | :--- |\n"
                . "| **Primary Role** | Conversational chit-chat, creative theme brainstorming, open-ended guidance. | Official, deterministic, and instant business and package information. |\n"
                . "| **Data Source** | Google Generative Language Model | Direct connection to our **Live MySQL Database** & official policies. |\n"
                . "| **Connectivity & Quota** | Requires active internet & limited by daily free API quotas. | **Zero quota limits**, zero downtime, and operates 100% offline. |\n"
                . "| **Pricing Accuracy** | Estimated / guidance based | **100% Exact & Verified** (Synced with official website packages). |\n"
                . "| **Staff Review Requirement** | Required before booking confirmation | Required to ensure human coordination and schedule locking. |\n\n"
                . "---\n\n"
                . "### 💡 What Certain Questions CAN Be Answered in Offline / Quota Mode?\n"
                . "Even when external AI credits are depleted, our chatbot answers **all essential event planning questions**:\n\n"
                . "1. 💍 **Packages & Live Pricing:** Weddings (Intimate, Grand, Arch, Entourage), Kids Parties, Debut, and Catering tiers.\n"
                . "2. 📋 **Staff Review Policy:** Why bookings require coordinator verification before downpayment is collected.\n"
                . "3. ⏳ **Preparation Reminders:** Recommended 3 months for weddings and 2 weeks for kids parties, though clients have free will to choose any date from today onwards.\n"
                . "4. 💳 **Payment & Downpayment:** 30–50% reservation terms, accepted channels (GCash, Maya, Bank Transfer, Cash).\n"
                . "5. 📍 **Office & Service Coverage:** Metro Manila and nearby provinces (Cavite, Laguna, Batangas, Rizal, Bulacan, Tagaytay).\n"
                . "6. 🗓️ **Rescheduling & Cancellations:** 30-day notice rules and outdoor weather contingency procedures.\n"
                . "7. 🎨 **Custom Themes & External Suppliers:** Bringing your own venue or caterer (BYO policies).\n"
                . "8. 🗓️ **Calendar Availability:** Real-time lookup of currently reserved dates.\n"
                . "9. 📝 **How to Book:** Step-by-step guidance on our website's 4-Step Booking Wizard.\n\n"
                . "👉 Ready to plan your event? Click the **'Book Now'** button on our website to begin!";
        }

        // ── 2. SERVICE AREAS, OFFICE LOCATION & OPERATING HOURS ───────────────
        if (preg_match('/\b(service\s*area|service\s*areas|areas?\s*covered|location\s*covered|where\s*do\s*you\s*(?:cater|serve|service)|saan\s*(?:kayo\s*)?nagse-?serve|coverage|out\s*of\s*town|out-of-town|office\s*location|where\s*(?:is\s*)?(?:your\s*)?office|saan\s*(?:po\s*)?(?:ang\s*)?office|saan\s*(?:po\s*)?(?:ang\s*)?opisina|nasaan\s*(?:ang\s*)?(?:office|opisina)|nasaan\s*kayo|saan\s*kayo\s*matatagpuan|where\s*are\s*you\s*located|address|\boffice\b|\bopisina\b|taga\s*saan|taga\s*saan\s*kayo|saan\s*po\s*kayo|saan\s*kayo\s*galing|saan\s*ang\s*shop|saan\s*ang\s*pwesto|meron\s*ba\s*kayong\s*office|may\s*office\s*ba|may\s*physical\s*office|operating\s*hours|business\s*hours|office\s*hours|anong\s*oras\s*(?:ang\s*)?(?:bukas|office|sarado|operasyon)|anong\s*oras\s*kayo\s*bukas|what\s*time\s*(?:are\s*you\s*)?(?:open|closed)|working\s*hours)\b/i', $lower)) {
            if ($is_tagalog) {
                return "📍 **Lokasyon at Serbisyo ng {$b_name}:**\n\n"
                    . "• 🏠 **Lokasyon:** Kami po ay taga-**San Jose del Monte, Bulacan**.\n"
                    . "• 🚗 **On-Site Service Only:** **On-site lamang po ang aming serbisyo at wala po kaming physical walk-in office.** Ang aming team ay direktang pumupunta at nag-i-istilo sa venue ng inyong event!\n"
                    . "• 🗺️ **Coverage Areas:** Nagse-serbisyo kami sa buong **Bulacan, Metro Manila, at mga kalapit na probinsya** (tulad ng Rizal, Cavite, Laguna, Batangas, atbp.).\n"
                    . "• 🕒 **Operating Hours:** Lunes hanggang Sabado, 8:00 AM – 6:00 PM (Bukas ang online booking inquiry 24/7 sa website!)\n"
                    . "• 🌐 **Official Facebook Page:** [facebook.com/tyoycreation](https://www.facebook.com/tyoycreation) | 📞 **Mobile:** {$b_phone}\n\n"
                    . "📌 **Inquiry Note:** Para sa mga katanungan o mag-book ng slot, maaari kayong mag-inquire gamit ang **'Book Now'** button sa aming website!";
            }
            return "📍 **Location & Service Coverage for {$b_name}:**\n\n"
                . "• 🏠 **Location:** We are based in **San Jose del Monte, Bulacan**.\n"
                . "• 🚗 **On-Site Service Only:** **We operate strictly on-site and do not have a walk-in physical office.** Our team travels directly to your event venue to manage all styling, setups, and decorations!\n"
                . "• 🗺️ **Coverage Areas:** We cater to events across **Bulacan, Metro Manila, and nearby provinces** (such as Rizal, Cavite, Laguna, Batangas, etc.).\n"
                . "• 🕒 **Business Hours:** Monday to Saturday, 8:00 AM – 6:00 PM (Our online booking wizard accepts inquiries 24/7!)\n"
                . "• 🌐 **Official Facebook Page:** [facebook.com/tyoycreation](https://www.facebook.com/tyoycreation) | 📞 **Phone:** {$b_phone}\n\n"
                . "👉 Would you like to check date availability or explore our styling packages?";
        }

        // ── 3. RESCHEDULING, CANCELLATION, WEATHER & REFUND POLICIES ───────────
        if (preg_match('/\b(reschedule|rescheduling|cancel|cancellation|refund|refunds|rain|weather|uulan|umulan|ulan|lipat\s*ng\s*date|palit\s*ng\s*petsa|mababalik\s*ba|deposit\s*refund|postpone|cancellation\s*policy|weather\s*policy|rain\s*policy|outdoor\s*weather|outdoor\s*rain|masamang\s*panahon|bagyo)\b/i', $lower)) {
            if ($is_tagalog) {
                return "🗓️ **Patakaran sa Rescheduling, Cancellation, at Panahon:**\n\n"
                    . "• 🔄 **Rescheduling (Paglipat ng Petsa):** Naiintindihan namin na may mga hindi inaasahang pangyayari. Pinapayagan ang rescheduling basta't may available slot sa aming calendar. Humihingi kami ng hindi bababa sa **30 araw na pasabi (written notice)** bago ang event upang maiayos ang procurement ng mga bulaklak at materyales.\n"
                    . "• 🚫 **Cancellation at Downpayment:** Ang reservation deposit ay non-refundable dahil ito ay agad na nagba-block ng inyong petsa sa calendar at nagpopondo sa pagbili ng custom materials. Gayunpaman, sa maagang pasabi, maaaring i-credit ang inyong deposit sa bagong na-reschedule na petsa alinsunod sa pagsang-ayon ng coordinator.\n"
                    . "• 🌧️ **Panahon at Outdoor Setups:** Para sa mga garden o open-air venue, may nakahandang backup plan (tulad ng paglipat sa indoor hall o covered pavilion) na makikipag-ugnayan ang aming team upang maprotektahan ang floral arches at balloon backdrops.\n\n"
                    . "Para sa mga katanungan sa inyong schedule, maaari ninyong tawagan ang aming tanggapan sa {$b_phone} o mag-email sa {$b_email}.";
            }
            return "🗓️ **Rescheduling, Cancellation & Weather Policies:**\n\n"
                . "• 🔄 **Rescheduling Policy:** We understand unexpected circumstances arise! Date rescheduling is permitted subject to master calendar availability. We require at least **30 days advance written notice** so our styling team can adjust floral orders and fabrication schedules.\n"
                . "• 🚫 **Cancellation & Deposit Terms:** Reservation deposits are non-refundable as they immediately lock your date on our calendar and fund custom material procurement. However, with advance notice, approved deposits can often be credited toward a future rescheduled celebration date upon coordinator review.\n"
                . "• 🌧️ **Weather Contingency (Outdoor Events):** For outdoor garden or open-air celebrations, our coordination team prepares a pre-agreed weather backup plan (such as transferring to a covered pavilion or indoor hall) to protect fresh floral arches, balloon backdrops, and electrical equipment.\n\n"
                . "For schedule adjustments or policy inquiries, please contact our team directly at **{$b_phone}** or **{$b_email}**.";
        }

        // ── 4. PAYMENT TERMS, DOWNPAYMENT, DEPOSIT & SECURITY ─────────────────
        if (preg_match('/\b(payment|downpayment|deposit|bayad|magkano\s*downpayment|how\s*to\s*pay|payment\s*method|payment\s*channel|gcash|bank\s*transfer|cash|installment|terms\s*of\s*payment|balance|paano\s*magbayad)\b/i', $lower)) {
            if ($is_tagalog) {
                return "💳 **Patakaran sa Pagbabayad at Downpayment sa {$b_name}:**\n\n"
                    . "• 🔒 **Proteksyon at Seguridad:** Para sa inyong kaligtasan, **HINDI kami tumatanggap ng bayad sa loob ng chat na ito**. Ang lahat ng transaksyon ay dumadaan sa Staff Review.\n"
                    . "• 📑 **Reservation Downpayment:** Karaniwang **30% hanggang 50%** reservation deposit ang kinakailangan upang opisyal na mai-lock ang inyong petsa sa aming calendar pagkatapos maaprubahan ang inyong inquiry sa Staff Review.\n"
                    . "• 💰 **Balanseng Bayad:** Ang nalalabing balanse ay babayaran bago o sa mismong araw ng okasyon alinsunod sa inyong kontrata.\n"
                    . "• 🏦 **Mga Tinatanggap na Paraan ng Pagbabayad:**\n"
                    . "  - Bank Transfer (BDO, BPI, Metrobank)\n"
                    . "  - E-Wallets (GCash, Maya)\n"
                    . "  - On-site cash settlement sa araw ng event sa assigned event coordinator\n\n"
                    . "📌 **Paano Simulan:** I-click ang **'Book Now'** sa website upang isumite ang inyong event details. Makikipag-ugnayan ang aming coordinator sa loob ng 24–48 oras para sa opisyal na quotation at verified payment instructions!";
            }
            return "💳 **Payment Terms, Downpayment & Security Policies:**\n\n"
                . "• 🔒 **Chat Security Guarantee:** For client protection and anti-fraud security, **payments and deposits are NEVER collected directly inside this chat**.\n"
                . "• 📑 **Reservation Downpayment:** A reservation deposit of typically **30% to 50%** is required to officially secure and lock your date on our calendar once your inquiry passes Staff Review.\n"
                . "• 💰 **Remaining Balance:** The balance is payable prior to or on the event date according to the milestone payment schedule in your official contract.\n"
                . "• 🏦 **Accepted Payment Channels:**\n"
                . "  - Online Bank Transfer (BDO, BPI, Metrobank)\n"
                . "  - E-Wallets (GCash, Maya QR)\n"
                . "  - On-site cash handover to your assigned lead event coordinator on event day\n\n"
                . "📌 **How to Proceed:** Click the **'Book Now'** button on our website to submit your customized event package. Once our team reviews your schedule, your coordinator will provide your official invoice and payment guidelines!";
        }

        // ── 5. CUSTOM THEMES & EXTERNAL SUPPLIERS (OWN CATERER / VENUE / BYO) ─
        if (preg_match('/\b(customize|customization|sariling\s*theme|own\s*venue|own\s*caterer|sariling\s*caterer|ibang\s*venue|custom\s*theme|pwede\s*ba\s*magdala|external\s*supplier|corkage|outside\s*caterer)\b/i', $lower)) {
            if ($is_tagalog) {
                return "🎨 **Custom Styling at External Suppliers sa {$b_name}:**\n\n"
                    . "• 🌸 **100% Custom Styling:** Oo, pwedeng-pwede! Bawat okasyon sa {$b_name} ay bespoke. Maaari kayong pumili ng sariling color motif, floral varieties, o 2D/3D character standees para sa mga bata.\n"
                    . "• 🏛️ **Sariling Venue:** Malugod naming ini-istilo ang kahit anong venue—maging private hall, hotel ballroom, garden resort, church, o aming partner venue na Emerald Courtyard.\n"
                    . "• 🍽️ **Sariling Caterer:** Pwedeng-pwede po kayong magdala ng sarili ninyong caterer! Ang aming styling team ay direktang makikipag-coordinate sa inyong caterer para sa ayos ng buffet table at dining presentation.\n\n"
                    . "Gusto niyo po bang mag-inquire gamit ang aming **'Book Now'** button upang ma-customize ang inyong okasyon?";
            }
            return "🎨 **Customization & External Suppliers (Venue & Catering):**\n\n"
                . "• 🌸 **100% Bespoke Event Styling:** Yes, absolutely! Every celebration styled by **{$b_name}** is tailored to your unique vision. You can select custom color palettes, imported floral varieties, unique balloon installations, or themed 2D/3D character backdrops.\n"
                . "• 🏛️ **Using Your Own Venue:** We style events at all venues—including private residences, hotel ballrooms, clubhouses, garden venues, churches, and partner spaces like Emerald Courtyard.\n"
                . "• 🍽️ **Bringing Your Own Caterer:** You are welcome to use your preferred caterer! Our styling team will collaborate directly with your caterer to ensure seamless buffet table decor and dining room aesthetics.\n\n"
                . "👉 Ready to design your event? Click the **'Book Now'** button on our website to begin!";
        }

        // ── 6. ADVANCE BOOKING NOTICE & LEAD TIME POLICY ─────────────────────
        if (preg_match('/\b(lead\s*time|advance\s*(?:notice|booking|reservation)?|minimum\s*lead|gaano\s*kaaga|how\s*early|kailan\s*(?:dapat\s*)?(?:mag-?book|kailangan\s*magpareserve)|notice\s*period|reminder|preparation)\b/i', $lower)) {
            if ($is_tagalog) {
                return "⏳ **Patakaran at Preparation Reminder ng {$b_name}:**\n\n"
                    . "Maaari kayong malayang pumili ng anumang petsa mula sa araw na ito! Upang magabayan kayo sa sapat na oras ng paghahanda, narito ang aming inirerekomendang preparation reminders:\n\n"
                    . "• 💍 **Weddings (Kasal):** **3 Months Preparation Reminder** (inirerekomenda ang 3 buwang paghahanda para sa floral procurement at coordination).\n"
                    . "• 🎈 **Kids Parties & Birthdays:** **2 Weeks Preparation Reminder** (inirerekomenda ang 2 linggong paghahanda para sa backdrops at props).\n"
                    . "• 👑 **Debut & Milestone Celebrations:** **2 Weeks Preparation Reminder**.\n"
                    . "• 🏢 **Corporate & Special Events:** **2 Weeks Preparation Reminder**.\n\n"
                    . "📌 **Tandaan:** Malaya kayong pumili ng inyong target date sa aming booking form simula sa araw na ito.\n\n"
                    . "👉 Nais niyo po bang mag-check ng available date o magsimula sa aming **'Book Now'** form?";
            }
            return "⏳ **Booking Preparation Reminders & Scheduling Policy ({$b_name}):**\n\n"
                . "You have complete free will to select any event date from today onwards! To help you plan ahead, we observe the following preparation reminders:\n\n"
                . "• 💍 **Weddings:** **3 Months Preparation Reminder** (recommended 3 months buffer for floral sourcing, custom fabrication, and coordination).\n"
                . "• 🎈 **Kids Parties & Birthdays:** **2 Weeks Preparation Reminder** (recommended 2 weeks for props, balloons, and theme crafting).\n"
                . "• 👑 **Debut & Milestone Events:** **2 Weeks Preparation Reminder**.\n"
                . "• 🏢 **Corporate & Special Events:** **2 Weeks Preparation Reminder**.\n\n"
                . "📌 **Flexible Scheduling:** There are no lock-out restrictions—you are free to choose any celebration date starting from today onwards.\n\n"
                . "👉 Ready to reserve? Click the **'Book Now'** button on our website to begin!";
        }

        // ── 7. WEDDING PACKAGES & FLORAL STYLING ──────────────────────────────
        if (preg_match('/\b(wedding|weddings|kasal|wedding\s*package|wedding\s*packages|ikakasal|bridal|ceremony\s*styling|entourage|reception\s*styling|sweetheart\s*table|aisle\s*decor)\b/i', $lower)) {
            $wed_text = "";
            if (!empty($wedding_pricing) && is_array($wedding_pricing)) {
                foreach ($wedding_pricing as $cat_key => $cat) {
                    $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
                    $items_str = [];
                    foreach ($cat['items'] ?? [] as $it) {
                        $p_txt = !empty($it['price']) ? " (₱" . number_format($it['price']) . ")" : " (Included/Free)";
                        $items_str[] = ($it['label'] ?? '') . $p_txt;
                    }
                    if (!empty($items_str)) {
                        $wed_text .= "• **{$cat_title}:** " . implode(", ", array_slice($items_str, 0, 4)) . "\n";
                    }
                }
            }
            if (empty($wed_text)) {
                $wed_text = "• **Ceremony Styling:** Church & Garden setups (₱17,000 – ₱25,000)\n"
                    . "• **Reception Styling:** Backdrops & Sweetheart table (₱15,000 – ₱99,000)\n"
                    . "• **Entourage Flowers:** Bridal bouquet, entourage florals (₱10,000 – ₱17,000)\n"
                    . "• **Ceiling Treatment:** With or without trusses (₱25,000 – ₱85,000)\n"
                    . "• **OTD Coordination:** Basic to Full Planning (₱25,000 – ₱35,000)\n";
            }

            if ($is_tagalog) {
                return "💍 **Mga Package at Presyo sa Kasal ({$b_name}):**\n\n"
                    . "Eksperto kami sa mga nakabibighaning bulaklak para sa kasal, ceremony aisle styling, at grand reception backdrops:\n\n"
                    . $wed_text . "\n"
                    . "⏳ **Preparation Reminder:** Inirerekomenda ang **3 buwan (3 months)** bago ang kasal para sa floral procurement at custom fabrication, ngunit malaya kayong pumili ng petsa simula ngayong araw.\n"
                    . "📋 **Staff Review:** Ang mga presyo ay susuriin at kukumpirmahin ng aming senior coordinator sa loob ng 24–48 oras pagkatapos mag-inquire.\n\n"
                    . "👉 Pindutin ang **'Book Now'** sa website upang buuin ang inyong pangarap na wedding package!";
            }
            return "💍 **Wedding Styling Packages & Inclusions ({$b_name}):**\n\n"
                . "We specialize in unforgettable wedding florals, ceremony aisle styling, and grand reception transformations:\n\n"
                . $wed_text . "\n"
                . "⏳ **Preparation Reminder:** Recommended **3 months preparation time** for weddings (clients are free to choose any date from today onwards).\n"
                . "📋 **Staff Review Notice:** Package estimates are verified by an assigned senior coordinator within 24–48 hours after submitting your inquiry.\n\n"
                . "👉 Click **'Book Now'** on our website to customize your dream wedding package!";
        }

        // ── 7. KIDS PARTY, BIRTHDAY & THEME STYLING PACKAGES ─────────────────
        if (preg_match('/\b(birthday|bday|kids\s*party|kiddie\s*party|theme\s*party|balloon\s*package|little\s*celebration|character|styro|balloon\s*garland|balloon\s*arch|birthday\s*package)\b/i', $lower)) {
            $theme_text = "";
            if (!empty($theme_pricing) && is_array($theme_pricing)) {
                foreach ($theme_pricing as $cat_key => $cat) {
                    $cat_title = $cat['category_title'] ?? ucfirst($cat_key);
                    $items_str = [];
                    foreach ($cat['items'] ?? [] as $it) {
                        $p_txt = !empty($it['price']) ? " (₱" . number_format($it['price']) . ")" : " (Included/Free)";
                        $items_str[] = ($it['label'] ?? '') . $p_txt;
                    }
                    if (!empty($items_str)) {
                        $theme_text .= "• **{$cat_title}:** " . implode(", ", array_slice($items_str, 0, 4)) . "\n";
                    }
                }
            }
            if (empty($theme_text)) {
                $theme_text = "• **Theme Party Styling:** Little Celebration (₱8,500), Regular (₱15,000), Upgraded Backdrop (₱25,000), Upgraded with Ceiling (₱40,000)\n"
                    . "• **Character Props:** 2D/3D Styro Characters & Printed Foam Standees\n"
                    . "• **Ceiling Treatments:** Basic (₱25,000) to Premium (₱60,000)\n"
                    . "• **Sound & Lights:** Basic (₱6,500) to Premium (₱15,000)\n";
            }

            if ($is_tagalog) {
                return "🎈 **Kids Party at Birthday Styling Packages ({$b_name}):**\n\n"
                    . "Binibigyang-buhay namin ang inyong selebrasyon sa pamamagitan ng makukulay na organic balloon installations, custom backdrops, at paboritong character props:\n\n"
                    . $theme_text . "\n"
                    . "⏳ **Preparation Reminder:** Inirerekomenda ang **2 linggo (14 days)** para sa paghahanda ng themed props at balloons (malayang makakapili ng petsa simula ngayong araw).\n"
                    . "📋 **Staff Review:** Susuriin ng aming coordination team ang inyong theme at character standees sa loob ng 24–48 oras.\n\n"
                    . "👉 Pindutin ang **'Book Now'** sa website upang pumili ng tema at makakuha ng instant quotation!";
            }
            return "🎈 **Kids Party & Birthday Styling Packages ({$b_name}):**\n\n"
                . "We bring vibrant celebrations to life with bespoke organic balloon installations, custom backdrops, and festive character props:\n\n"
                . $theme_text . "\n"
                . "⏳ **Preparation Reminder:** Recommended **2 weeks preparation time** for kids parties and birthdays (clients are free to choose any date from today onwards).\n"
                . "📋 **Staff Review Notice:** All packages and character details are reviewed by our coordination team within 24–48 hours.\n\n"
                . "👉 Click **'Book Now'** on our website to choose your theme and get an instant quotation!";
        }

        // ── 8. DEBUT & 18TH MILESTONE CELEBRATIONS ────────────────────────────
        if (preg_match('/\b(debut|debutante|18th\s*birthday|18\s*roses|18\s*candles|milestone\s*celebration)\b/i', $lower)) {
            if ($is_tagalog) {
                return "👑 **Debut & 18th Milestone Styling Packages ({$b_name}):**\n\n"
                    . "Gawing pambihira at di-malilimutan ang inyong ika-18 kaarawan sa aming marangyang debutante styling:\n\n"
                    . "• **Grand Debutante Stage:** Dramatic photo backdrop, floral accents, at personalized monogram.\n"
                    . "• **18 Roses & 18 Candles Setup:** Magandang floral runway, ceremonial candle holders, at staging.\n"
                    . "• **Sound & Mood Lighting:** Ambient wash lights, spotlights, at audio system (₱6,500 – ₱15,000).\n"
                    . "• **Add-ons:** 360 Spin Video Booth, Photo & Video coverage, at OTD Coordination.\n\n"
                    . "⏳ **Lead Time:** Minimum **14 araw (2 linggo)** bago ang okasyon.\n"
                    . "👉 Handa nang magplano? I-click ang **'Book Now'** sa website upang magsimula!";
            }
            return "👑 **Debut & 18th Milestone Styling Packages:**\n\n"
                . "Make your 18th milestone extraordinary with {$b_name}'s grand debutante styling:\n\n"
                . "• **Grand Debutante Stage:** Dramatic photo backdrop, bespoke floral accents, and personalized monogram.\n"
                . "• **18 Roses & 18 Candles Setup:** Beautiful floral runway, candle holders, and ceremonial staging.\n"
                . "• **Sound & Mood Lighting:** Ambient wash lights, spotlighting, and audio equipment (₱6,500 – ₱15,000).\n"
                . "• **Add-ons:** 360 Spin Video Booth, Photo & Video coverage, and OTD Coordination.\n\n"
                . "⏳ **Lead Time:** Minimum **14 days (2 weeks)** advance notice required.\n"
                . "👉 Ready to plan? Click **'Book Now'** on our website to get started!";
        }

        // ── 9. CATERING SERVICES & HEADCOUNT / PAX CAPACITIES ──────────────────
        if (preg_match('/\b(catering|food|pax|menu|handa|pagkain|75\s*pax|100\s*pax|150\s*pax|200\s*pax|300\s*pax|400\s*pax|500\s*pax|buffet)\b/i', $lower)) {
            if ($is_tagalog) {
                return "🍽️ **Mga Package sa Catering at Headcount Capacity ({$b_name}):**\n\n"
                    . "Naghahain kami ng masarap at kumpletong buffet catering kasama ang eleganteng table setups, dinnerware, at mga propesyonal na waiter:\n\n"
                    . "• **75 pax:** ₱37,000\n"
                    . "• **100 pax:** ₱49,000\n"
                    . "• **150 pax:** ₱72,000\n"
                    . "• **200 pax:** ₱96,000\n"
                    . "• **300 pax:** ₱141,000\n"
                    . "• **400 pax:** ₱188,000\n"
                    . "• **500 pax:** ₱235,000\n\n"
                    . "🥗 **Kasama sa Package:** 4 hanggang 6 na ulam, kanin, dessert, unli drinks, at libreng Buffet table floral styling!\n\n"
                    . "👉 Maaari ninyong piliin ang inyong menu at bilang ng bisita sa Hakbang 2 ng aming **'Book Now'** form!";
            }
            return "🍽️ **Catering Packages & Pax Capacities ({$b_name}):**\n\n"
                . "We provide full-service buffet catering complete with elegant table setups, dinnerware, and professional servers:\n\n"
                . "• **75 pax:** ₱37,000\n"
                . "• **100 pax:** ₱49,000\n"
                . "• **150 pax:** ₱72,000\n"
                . "• **200 pax:** ₱96,000\n"
                . "• **300 pax:** ₱141,000\n"
                . "• **400 pax:** ₱188,000\n"
                . "• **500 pax:** ₱235,000\n\n"
                . "🥗 **Inclusions:** 4 to 6 main courses, steamed rice, dessert, bottomless drinks, and Basic or Premium Buffet table styling presentation!\n\n"
                . "👉 Customize your menu and pax count directly in Step 2 of our **'Book Now'** form!";
        }

        // ── 10. FOOD CARTS & REFRESHMENTS ──────────────────────────────────────
        if (preg_match('/\b(food\s*cart|food\s*carts|popcorn|hotdog|ice\s*cream|fries|nachos|sweet\s*corner|donuts|grazing\s*table|cold\s*cuts)\b/i', $lower)) {
            return "🍿🍦 **Food Carts & Refreshment Add-ons:**\n\n"
                . "Delight your guests with our popular interactive party food carts:\n\n"
                . "• 🍿 **PopCorn Cart:** ₱5,000\n"
                . "• 🌭 **Hotdog Cart:** ₱5,000\n"
                . "• 🍦 **Ice Cream Cart:** ₱5,000\n"
                . "• 🍟 **French Fries Cart:** ₱5,000\n"
                . "• 🧀 **Nachos Cart:** ₱5,000\n"
                . "• 🍬 **Sweet Corner:** ₱5,000\n"
                . "• 🍩 **Donuts Cart:** ₱5,000\n"
                . "• 🧀 **Luxury Grazing Table w/ Cold Cuts (100 pax):** ₱10,500\n\n"
                . "👉 You can select any combination of food carts in Step 2 of our **'Book Now'** form!";
        }

        // ── 11. SOUND SYSTEM & INTELLIGENT LIGHTING ────────────────────────────
        if (preg_match('/\b(sound|lights|sound\s*system|lighting|ilaw|tugtog|speaker|audio|mood\s*lights|truss|trusses)\b/i', $lower)) {
            return "🎶💡 **Sound & Intelligent Lighting Setups:**\n\n"
                . "Set the perfect ambiance for your celebration with our audio-visual packages:\n\n"
                . "• 🔊 **Basic Setup (₱6,500):** High-clarity speakers, microphones, and basic mood lights.\n"
                . "• 🎛️ **Medium Setup (₱8,500):** Enhanced audio, powered subwoofers, par lights, and wireless mics.\n"
                . "• ✨ **Premium Setup (₱15,000):** Full intelligent moving heads, stage wash, laser effects, and sound technician.\n"
                . "• 🏗️ **Ceiling Trusses:** Available for grand ceiling drapes and hanging lighting (₱45,000 – ₱70,000).\n\n"
                . "👉 Add Sound & Lights to your package inquiry through our **'Book Now'** form!";
        }

        // ── 12. ENTERTAINMENT, HOSTS, MAGICIANS & PUPPET SHOWS ─────────────────
        if (preg_match('/\b(host|emcee|magician|puppet|puppet\s*show|entertainment|clown|program|ruth|jen|jam)\b/i', $lower)) {
            return "🎭🎤 **Party Entertainment & Professional Hosts:**\n\n"
                . "Keep your guests entertained with our professional performers:\n\n"
                . "• 🎤 **Regular Host / Emcee:** ₱5,000\n"
                . "• 🎩 **Magician:** ₱5,000\n"
                . "• 🎉 **Host & Magician Tandem:** ₱7,500\n"
                . "• 🎪 **Host, Magician & Puppet Show Package:** ₱15,000\n"
                . "• 💍 **Professional Wedding Hosts:** Host \"Ruth\" (₱7,500), Host \"Jen\" (₱25,000), Host \"Jam\" (₱60,000)\n\n"
                . "👉 Select your entertainment options in Step 2 of our website booking wizard!";
        }

        // ── 13. PHOTO & VIDEO COVERAGE & PHOTOBOOTHS ───────────────────────────
        if (preg_match('/\b(photo|video|photographer|videographer|sde|same\s*day\s*edit|mtv|photobooth|photo\s*booth|360|magnet)\b/i', $lower)) {
            return "📸🎥 **Photo, Video & Photobooth Coverage:**\n\n"
                . "Capture every precious memory of your celebration with our media partners:\n\n"
                . "• 📷 **Photo Coverage Only:** ₱4,500 (Birthday) / ₱10,000 (Wedding)\n"
                . "• 🎬 **Photo & Video Coverage:** ₱12,000 (Birthday) / ₱18,000 (Wedding)\n"
                . "• 🎞️ **MTV Highlights Video:** ₱15,000 (Birthday) / ₱25,000 (Wedding)\n"
                . "• 🌟 **Same Day Edit (SDE):** ₱35,000 (Birthday) / ₱45,000 (Wedding)\n"
                . "• 🖼️ **Photobooth Basic Paper Frame:** ₱3,500\n"
                . "• 🧲 **Photobooth Premium Ref Magnet:** ₱4,500\n"
                . "• 🔄 **Photobooth Premium 360 Spin Video:** ₱12,000\n\n"
                . "👉 Add photo & video coverage directly when booking on our website!";
        }

        // ── 15. CALENDAR AVAILABILITY & BOOKED DATES ───────────────────────────
        if (preg_match('/\b(availab[a-z]*|available|availability|open\s*dates?|petsa|may\s*slot\s*pa|booked\s*dates?|kailan\s*available|calendar|schedule\s*check)\b/i', $lower)) {
            $booked_list = !empty($booked_dates)
                ? "• " . implode("\n• ", $booked_dates) . "\n\n"
                : "All upcoming dates are currently open for booking inquiry!\n\n";

            return "🗓️ **Real-Time Calendar Availability & Reserved Dates:**\n\n"
                . "Here are our currently approved celebration dates:\n"
                . $booked_list
                . "⏳ **Recommended Preparation Reminders:**\n"
                . "• 💍 **Weddings:** 3 Months Preparation Reminder\n"
                . "• 🎈 **Kids Party / Other Events:** 2 Weeks Preparation Reminder\n\n"
                . "*(Note: Clients have free will to select any date from today onwards)*\n\n"
                . "📋 **Staff Review Notice:** Because slot availability is dynamic, dates are officially secured once our coordinators complete Staff Review and your deposit is confirmed.\n\n"
                . "👉 Would you like to check a specific date or start your inquiry via **'Book Now'**?";
        }

        // ── 15. GENERAL PACKAGES & PRICING OVERVIEW ───────────────────────────
        if (preg_match('/\b(package|packages|pricing|prices|presyo|magkano|how\s*much|cost|costs|what\s*do\s*you\s*offer|services|anong\s*meron|rates|anong\s*ino-?offer|ano\s*ang\s*mga\s*package)\b/i', $lower)) {
            if ($is_tagalog) {
                return "🌸🎈 **Ang Aming Mga Event Packages at Styling Services ({$b_name}):**\n\n"
                    . "Kami ang inyong katuwang sa paggawa ng di-malilimutang selebrasyon:\n\n"
                    . "1. 💍 **Weddings (Kasal):** Ceremony floral arches, bridal bouquets, entourage flowers, aisle styling, sweetheart table, at grand reception backdrops (₱10,000 – ₱99,000+).\n"
                    . "2. 🎈 **Kids Parties at Birthdays:** Organic balloon garlands, themed arches, 2D/3D character standees, at cake backdrop tables (₱8,500 – ₱40,000+).\n"
                    . "3. 👑 **Debut at Milestones:** Grand debutante backdrop, floral runway, 18 roses/candles staging, at mood lights.\n"
                    . "4. 🍽️ **Catering at Add-ons:** Buffet catering (75–500 pax), Food Carts (₱5,000 bawat isa), Sound & Lights (₱6,500 – ₱15,000), at Photobooths.\n\n"
                    . "📋 **Staff Review:** Ang lahat ng package options at custom quotation ay sinusuri ng aming event coordinators sa loob ng 24–48 oras upang matiyak ang venue requirements at schedule.\n\n"
                    . "👉 I-click ang **'Book Now'** button sa website upang buuin ang inyong custom event quotation!";
            }
            return "🌸🎈 **Our Complete Event Packages & Styling Services ({$b_name}):**\n\n"
                . "We are your all-in-one event styling and coordination partner:\n\n"
                . "1. 💍 **Weddings:** Full ceremony floral arches, bridal bouquets, entourage flowers, aisle styling, sweetheart tables, and reception backdrops (₱10,000 – ₱99,000+).\n"
                . "2. 🎈 **Kids Parties & Birthdays:** Themed balloon garlands, organic arches, character standees (2D/3D), and cake tables (₱8,500 – ₱40,000+).\n"
                . "3. 👑 **Debut & Celebrations:** Grand backdrops, floral runway, 18 roses/candles, and ambient lighting.\n"
                . "4. 🍽️ **Catering & Add-ons:** Tiered catering (75–500 pax), Food Carts (₱5,000 each), Sound & Lights (₱6,500 – ₱15,000), and Photobooths.\n\n"
                . "📋 **Staff Review Guarantee:** All quotations and customized inclusions submitted on our website undergo Staff Review within 24–48 hours to confirm scheduling and logistics.\n\n"
                . "👉 Click the **'Book Now'** button on our website to build your custom package quotation!";
        }

        return null;
    }
}

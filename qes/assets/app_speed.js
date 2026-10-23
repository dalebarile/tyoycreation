// Global Instant Loading Bar & Navigation Speed Engine for Tyoy Creation
(function() {
    let loader = document.getElementById('qes-top-loader');
    let progressTimer = null;
    let isProgressActive = false;

    function getOrCreateLoader() {
        if (!loader && document.body) {
            loader = document.getElementById('qes-top-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'qes-top-loader';
                document.body.appendChild(loader);
            }
        }
        return loader;
    }

    function startProgress() {
        const el = getOrCreateLoader();
        if (!el) return;
        if (progressTimer) clearInterval(progressTimer);
        isProgressActive = true;
        el.style.opacity = '1';
        el.style.width = '30%';
        let current = 30;
        progressTimer = setInterval(() => {
            if (current < 85) {
                current += Math.random() * 12;
                el.style.width = Math.min(85, current) + '%';
            }
        }, 80);
    }

    function completeProgress() {
        if (!isProgressActive) return;
        if (progressTimer) clearInterval(progressTimer);
        const el = getOrCreateLoader();
        if (!el) return;
        el.style.width = '100%';
        setTimeout(() => {
            el.style.opacity = '0';
            setTimeout(() => {
                el.style.width = '0%';
                isProgressActive = false;
            }, 180);
        }, 100);
    }

    // Only complete if progress was explicitly triggered
    window.addEventListener('load', () => {
        if (isProgressActive) {
            completeProgress();
        }
    });

    // Immediate visual feedback on true navigation clicks
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link || !link.href) return;

        // Give in-page modal/custom click handlers time to call preventDefault
        setTimeout(() => {
            if (e.defaultPrevented) return;
            const hrefAttr = link.getAttribute('href') || '';
            if (!hrefAttr || hrefAttr.startsWith('#') || hrefAttr.startsWith('javascript:') || hrefAttr.startsWith('mailto:') || hrefAttr.startsWith('tel:') || link.target === '_blank') {
                return;
            }

            try {
                const targetUrl = new URL(link.href, window.location.href);
                if (targetUrl.origin !== window.location.origin) return;
                // Skip if same page hash jump (e.g. index.php#home or #services)
                if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) {
                    return;
                }
            } catch (_) {
                return;
            }

            startProgress();
        }, 0);
    });

    // Immediate visual feedback on form submit (e.g. login, filters)
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || form.target === '_blank') return;
        setTimeout(() => {
            if (!e.defaultPrevented) {
                startProgress();
            }
        }, 0);
    });

    // Expose progress controls globally for interactive actions & modals
    window.startQesProgress = startProgress;
    window.completeQesProgress = completeProgress;

    // Smart background hover/touch prefetching & Speculation Rules prerender
    const prefetchedUrls = new Set();

    // 1. Speculation Rules API for zero-latency page transitions in supported browsers (Chrome, Edge)
    try {
        if (HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
            const isAdmin = window.location.pathname.includes('a_') || window.location.pathname.includes('admin') || window.location.pathname.includes('loginadmin');
            const targetUrls = isAdmin ? [
                'a_home.php',
                'a_events.php?status=pending',
                'a_events.php?status=bookings',
                'a_events.php?status=all',
                'a_calendar.php',
                'a_clients.php',
                'a_manual_booking.php',
                'a_packages.php',
                'a_settings.php',
                'a_notifications.php',
                'a_sitemanager.php'
            ] : [
                'index.php',
                'booking.php',
                'login.php',
                'loginadmin.php'
            ];

            const specScript = document.createElement('script');
            specScript.type = 'speculationrules';
            specScript.textContent = JSON.stringify({
                prerender: [{
                    source: "list",
                    urls: targetUrls,
                    eagerness: "moderate"
                }]
            });
            document.head.appendChild(specScript);
        }
    } catch (_) {}

    function prefetchUrl(link) {
        if (!link || !link.href) return;
        const hrefAttr = link.getAttribute('href') || '';
        if (!hrefAttr || hrefAttr.startsWith('#') || hrefAttr.startsWith('javascript:') || hrefAttr.startsWith('mailto:') || hrefAttr.startsWith('tel:') || link.target === '_blank') {
            return;
        }

        try {
            const targetUrl = new URL(link.href, window.location.href);
            if (targetUrl.origin !== window.location.origin) return;
            // Never re-prefetch current document if just hash differences
            if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search) {
                return;
            }
            // Skip auth/mutating/logout endpoints
            const path = targetUrl.pathname.toLowerCase();
            if (path.includes('logout') || path.includes('cleanup') || path.includes('delete') || path.includes('export')) {
                return;
            }

            const cleanUrl = targetUrl.origin + targetUrl.pathname + targetUrl.search;
            if (prefetchedUrls.has(cleanUrl)) return;
            prefetchedUrls.add(cleanUrl);

            // Fetch cache pre-warming
            if (window.fetch) {
                fetch(cleanUrl, { priority: 'low', credentials: 'same-origin' }).catch(function(){});
            } else {
                const linkElem = document.createElement('link');
                linkElem.rel = 'prefetch';
                linkElem.as = 'document';
                linkElem.href = cleanUrl;
                document.head.appendChild(linkElem);
            }
        } catch (_) {}
    }

    document.addEventListener('mouseover', function(e) {
        const link = e.target.closest('a');
        if (link && link.href) {
            prefetchUrl(link);
        }
    }, { passive: true });

    document.addEventListener('touchstart', function(e) {
        const link = e.target.closest('a');
        if (link && link.href) {
            prefetchUrl(link);
        }
    }, { passive: true });

    // Idle pre-warm for primary navigation links (paced sequentially to avoid request congestion)
    const scheduleIdle = window.requestIdleCallback || function(cb) { setTimeout(cb, 1200); };
    scheduleIdle(function() {
        const navLinks = Array.from(document.querySelectorAll('.admin-nav-item, .admin-sidebar a, .public-nav a'));
        let idx = 0;
        function prefetchNext() {
            if (idx >= navLinks.length) return;
            prefetchUrl(navLinks[idx++]);
            setTimeout(prefetchNext, 180);
        }
        prefetchNext();
    });
})();

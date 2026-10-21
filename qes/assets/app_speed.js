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

    // Smart background hover/touch prefetching (ignores same page anchors and stateful routes)
    const prefetchedUrls = new Set();
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

            const linkElem = document.createElement('link');
            linkElem.rel = 'prefetch';
            linkElem.as = 'document';
            linkElem.href = cleanUrl;
            document.head.appendChild(linkElem);
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
})();

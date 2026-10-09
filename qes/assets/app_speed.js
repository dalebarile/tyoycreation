// Global Instant Loading Bar & Navigation Speed Engine for Tyoy Creation
(function() {
    let loader = document.getElementById('qes-top-loader');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'qes-top-loader';
        document.body.appendChild(loader);
    }

    let progressTimer = null;
    function startProgress() {
        if (progressTimer) clearInterval(progressTimer);
        loader.style.opacity = '1';
        loader.style.width = '25%';
        let current = 25;
        progressTimer = setInterval(() => {
            if (current < 85) {
                current += Math.random() * 12;
                loader.style.width = Math.min(85, current) + '%';
            }
        }, 100);
    }

    function completeProgress() {
        if (progressTimer) clearInterval(progressTimer);
        loader.style.width = '100%';
        setTimeout(() => {
            loader.style.opacity = '0';
            setTimeout(() => { loader.style.width = '0%'; }, 200);
        }, 100);
    }

    if (document.readyState === 'complete') {
        completeProgress();
    } else {
        window.addEventListener('load', completeProgress);
    }

    // Immediate 0ms visual feedback on click
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link || !link.href) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:') || link.target === '_blank') return;
        
        startProgress();
    });

    // Background hover / touch prefetching
    const prefetchedUrls = new Set();
    function prefetchUrl(url) {
        if (!url || prefetchedUrls.has(url) || url.startsWith('#') || url.startsWith('javascript:')) return;
        prefetchedUrls.add(url);
        const linkElem = document.createElement('link');
        linkElem.rel = 'prefetch';
        linkElem.href = url;
        document.head.appendChild(linkElem);
    }

    document.addEventListener('mouseover', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && link.origin === window.location.origin) {
            prefetchUrl(link.href);
        }
    }, { passive: true });

    document.addEventListener('touchstart', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && link.origin === window.location.origin) {
            prefetchUrl(link.href);
        }
    }, { passive: true });
})();

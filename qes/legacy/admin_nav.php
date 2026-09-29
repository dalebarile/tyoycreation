<header>
    <div class="navbar">
        <div class="navbar-content">
            <h1 class="lcc">LCC</h1>
            <div class="nav-links" style="position:relative;">
                <a href="a_home.php">HOME</a>
                <a href="a_trash.php">🗑 TRASH</a>
                <button id="navToggleBtn" onclick="toggleNavMenu()" title="More options" style="background:none;border:none;cursor:pointer;padding:6px 10px;color:white;font-size:20px;line-height:1;display:inline-flex;align-items:center;">
                    <span id="navToggleIcon">⋯</span>
                </button>
                <div id="extraNavLinks" style="display:none;position:absolute;top:48px;right:0;background:#1a73e8;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.2);z-index:1000;min-width:160px;overflow:hidden;">
                    <a href="a_user.php" style="display:block;padding:12px 20px;color:white;text-decoration:none;font-size:13px;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.15);">USERS</a>
                    <a href="a_calendar.php" style="display:block;padding:12px 20px;color:white;text-decoration:none;font-size:13px;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.15);">CALENDAR</a>
                    <a href="a_facilities.php" style="display:block;padding:12px 20px;color:white;text-decoration:none;font-size:13px;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.15);">FACILITIES</a>
                    <a href="a_events.php" style="display:block;padding:12px 20px;color:white;text-decoration:none;font-size:13px;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.15);">EVENTS</a>
                    <a href="logout.php" style="display:block;padding:12px 20px;color:white;text-decoration:none;font-size:13px;font-weight:600;">LOGOUT</a>
                </div>
            </div>
        </div>
    </div>
</header>
<script>
function toggleNavMenu() {
    const menu = document.getElementById('extraNavLinks');
    const icon = document.getElementById('navToggleIcon');
    if (menu.style.display === 'none') {
        menu.style.display = 'block';
        icon.textContent = '✕';
    } else {
        menu.style.display = 'none';
        icon.textContent = '⋯';
    }
}
document.addEventListener('click', function(e) {
    const btn = document.getElementById('navToggleBtn');
    const menu = document.getElementById('extraNavLinks');
    if (menu && btn && !btn.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
        document.getElementById('navToggleIcon').textContent = '⋯';
    }
});
</script>

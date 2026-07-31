</main>
    </div>
</div>
<script>
(function () {
    var KEY = 'sa-theme';
    var root = document.documentElement;
    var themeBtn = document.getElementById('sa-theme-toggle');

    function currentTheme() {
        return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    function applyTheme(theme) {
        if (theme !== 'light' && theme !== 'dark') theme = 'dark';
        root.setAttribute('data-theme', theme);
        try { localStorage.setItem(KEY, theme); } catch (e) {}
        if (themeBtn) {
            themeBtn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            themeBtn.title = theme === 'dark' ? 'Light mode' : 'Dark mode';
        }
    }

    applyTheme(currentTheme());
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        });
    }

    var app = document.getElementById('sa-app');
    var btn = document.getElementById('sa-menu-btn');
    var backdrop = document.getElementById('sa-backdrop');
    if (!app || !btn) return;
    function closeNav() { app.classList.remove('is-nav-open'); }
    function toggleNav() { app.classList.toggle('is-nav-open'); }
    btn.addEventListener('click', toggleNav);
    if (backdrop) backdrop.addEventListener('click', closeNav);
})();
</script>
</body>
</html>

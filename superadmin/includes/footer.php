</main>
    </div>
</div>
<script>
(function () {
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

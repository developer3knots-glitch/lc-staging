        </main>
    </div>
</div>
<script>
window.LC_BASE_URL = <?= json_encode(LC_BASE_URL) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_setting('poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
window.LC_TIMEZONE = <?= json_encode(lc_app_timezone()) ?>;
window.LC_UTC_OFFSET = <?= json_encode(lc_utc_offset()) ?>;
</script>
<script src="<?= LC_BASE_URL ?>/assets/js/admin-core.js"></script>
<?php if (!empty($extraScript)): ?>
<script src="<?= LC_BASE_URL ?>/assets/js/<?= lc_sanitize($extraScript) ?>"></script>
<?php endif; ?>
</body>
</html>

        </main>
    </div>
</div>
<script>
window.LC_BASE_URL = <?= json_encode(LC_BASE_URL) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_setting('poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
</script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-time.js?v=1"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/admin-core.js"></script>
<?php if (!empty($extraScript)): ?>
<script src="<?= LC_BASE_URL ?>/assets/js/<?= lc_sanitize($extraScript) ?>"></script>
<?php endif; ?>
</body>
</html>

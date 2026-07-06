<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_admin();
lc_ensure_brands_schema();

$db = lc_db();
$brands = lc_get_all_brands(true);
$message = '';
$error = '';
$presets = lc_avatar_presets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'] === 'admin' ? 'admin' : 'agent';
        $brandId = !empty($_POST['brand_id']) ? (int) $_POST['brand_id'] : null;
        if ($role === 'admin') {
            $brandId = null;
        }
        $avatar = lc_avatar_resolve_from_request($_POST, $_FILES);
        if ($name && $email && $pass) {
            $stmt = $db->prepare('INSERT INTO agents (name, email, password, role, brand_id, avatar) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $brandId, $avatar]);
            $message = 'Agent added successfully.';
        } else {
            $error = 'Name, email and password are required.';
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $db->prepare('UPDATE agents SET is_active = IF(is_active=1,0,1) WHERE id = ? AND id != ?')
            ->execute([(int)$_POST['id'], (int)$_SESSION['agent_id']]);
        $message = 'Agent updated.';
    }
}

$agents = $db->query('
    SELECT a.id, a.name, a.email, a.role, a.status, a.is_active, a.last_seen, a.created_at, a.brand_id, a.avatar, b.name AS brand_name
    FROM agents a
    LEFT JOIN brands b ON b.id = a.brand_id
    ORDER BY a.id ASC
')->fetchAll();
$agent = lc_auth_agent();
$pageTitle = 'Agents';
$activeNav = 'agents';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-users"></i> Team Agents</h3></div>
    <div class="lc-card-body" style="padding:0">
        <table class="lc-table">
            <thead><tr><th width="56"></th><th>Name</th><th>Email</th><th>Role</th><th>Brand</th><th>Status</th><th>Last Seen</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($agents as $a): ?>
                <tr>
                    <td><?= lc_avatar_html($a['avatar'] ?? null, $a['name'], 'lc-avatar lc-avatar-sm') ?></td>
                    <td><strong><?= lc_sanitize($a['name']) ?></strong></td>
                    <td><?= lc_sanitize($a['email']) ?></td>
                    <td><?= ucfirst($a['role']) ?></td>
                    <td><?= $a['role'] === 'admin' ? 'All brands' : lc_sanitize($a['brand_name'] ?? '—') ?></td>
                    <td><span class="lc-status-dot <?= $a['status'] ?>"></span> <?= ucfirst($a['status']) ?></td>
                    <td><?= $a['last_seen'] ? lc_time_span($a['last_seen']) : '—' ?></td>
                    <td>
                        <?php if ((int)$a['id'] !== (int)$agent['id']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-<?= $a['is_active'] ? 'ban' : 'check' ?>"></i> <?= $a['is_active'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <?php else: ?><em>You</em><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-user-plus"></i> Add New Agent</h3></div>
    <div class="lc-card-body">
        <form method="post" class="lc-form" enctype="multipart/form-data" id="lc-agent-form">
            <input type="hidden" name="action" value="add">

            <label>Profile Avatar</label>
            <div class="lc-avatar-picker">
                <div class="lc-avatar-picker-preview" id="lc-avatar-preview">
                    <img src="<?= lc_sanitize(LC_BASE_URL . '/assets/img/avatars/preset-01.svg') ?>" alt="Avatar preview" class="lc-avatar lc-avatar-lg" id="lc-avatar-preview-img">
                </div>
                <div class="lc-avatar-picker-body">
                    <p class="lc-avatar-picker-hint">Choose a preset avatar or upload your own photo (JPG, PNG, WebP — max 2MB).</p>
                    <div class="lc-avatar-presets" id="lc-avatar-presets">
                        <?php foreach ($presets as $id => $meta): ?>
                        <label class="lc-avatar-preset<?= $id === '01' ? ' selected' : '' ?>" title="<?= lc_sanitize($meta['label']) ?>">
                            <input type="radio" name="avatar_preset" value="<?= lc_sanitize($id) ?>"<?= $id === '01' ? ' checked' : '' ?>>
                            <img src="<?= lc_sanitize(LC_BASE_URL . '/assets/img/avatars/preset-' . $id . '.svg') ?>" alt="<?= lc_sanitize($meta['label']) ?>" class="lc-avatar lc-avatar-md">
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="lc-avatar-upload">
                        <label class="lc-btn lc-btn-secondary lc-btn-sm" for="lc-avatar-upload-input">
                            <i class="ti ti-upload"></i> Upload Photo
                        </label>
                        <input type="file" name="avatar_upload" id="lc-avatar-upload-input" accept="image/jpeg,image/png,image/webp" hidden>
                        <span class="lc-avatar-upload-name" id="lc-avatar-upload-name">No file selected</span>
                        <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-avatar-clear-upload" style="display:none"><i class="ti ti-x"></i> Clear upload</button>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem">
                <div><label>Name</label><input type="text" name="name" required></div>
                <div><label>Email</label><input type="email" name="email" required></div>
                <div><label>Password</label><input type="password" name="password" required></div>
                <div><label>Role</label>
                    <select name="role" id="lc-agent-role"><option value="agent">Agent</option><option value="admin">Admin</option></select>
                </div>
                <div id="lc-agent-brand-wrap">
                    <label>Assign Brand</label>
                    <select name="brand_id">
                        <option value="">— Select brand —</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= lc_sanitize($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="lc-btn lc-btn-primary" style="margin-top:1rem"><i class="ti ti-user-plus"></i> Add Agent</button>
        </form>
        <script>
        (function () {
            var base = <?= json_encode(LC_BASE_URL) ?>;
            var previewImg = document.getElementById('lc-avatar-preview-img');
            var uploadInput = document.getElementById('lc-avatar-upload-input');
            var uploadName = document.getElementById('lc-avatar-upload-name');
            var clearUpload = document.getElementById('lc-avatar-clear-upload');
            var presetLabels = document.querySelectorAll('.lc-avatar-preset');

            function selectPreset(label) {
                presetLabels.forEach(function (el) { el.classList.remove('selected'); });
                label.classList.add('selected');
                var radio = label.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
                if (!uploadInput.files.length) {
                    previewImg.src = base + '/assets/img/avatars/preset-' + radio.value + '.svg';
                }
            }

            presetLabels.forEach(function (label) {
                label.addEventListener('click', function () { selectPreset(label); });
            });

            uploadInput.addEventListener('change', function () {
                var file = uploadInput.files[0];
                if (!file) return;
                uploadName.textContent = file.name;
                clearUpload.style.display = 'inline-flex';
                var reader = new FileReader();
                reader.onload = function (e) { previewImg.src = e.target.result; };
                reader.readAsDataURL(file);
            });

            clearUpload.addEventListener('click', function () {
                uploadInput.value = '';
                uploadName.textContent = 'No file selected';
                clearUpload.style.display = 'none';
                var checked = document.querySelector('input[name="avatar_preset"]:checked');
                previewImg.src = base + '/assets/img/avatars/preset-' + (checked ? checked.value : '01') + '.svg';
            });

            document.getElementById('lc-agent-role').addEventListener('change', function () {
                document.getElementById('lc-agent-brand-wrap').style.display = this.value === 'admin' ? 'none' : 'block';
            });
        })();
        </script>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

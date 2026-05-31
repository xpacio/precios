<?php

$pageTitle = 'Usuarios';

$pdo = getDB();
$mensaje = '';
$error = '';

function fmtFecha($ts) {
    if (!$ts) return '-';
    $t = strtotime($ts);
    return substr(date('Y', $t), -1) . date('md.Hi', $t);
}

function timeago($ts) {
    if (!$ts) return '-';
    $diff = time() - strtotime($ts);
    if ($diff < 0) return '0s';
    $s = $diff;
    $m = intdiv($s, 60);
    $h = intdiv($s, 3600);
    $d = intdiv($s, 86400);
    $M = intdiv($d, 30);
    $a = intdiv($d, 365);
    if ($s < 60) return $s . 's';
    if ($m < 60) return $m . 'm';
    if ($h < 2) return $h . 'h' . ($m % 60 ? ($m % 60) . 'm' : '');
    if ($h < 24) return $h . 'h+';
    if ($d === 1) return '1d';
    if ($d === 2) return '2d+';
    if ($d < 30) return $d . 'd+';
    if ($M === 1) return '1M';
    if ($M < 12) return $M . 'M+';
    if ($a === 1) return '1a';
    return $a . 'a+';
}

// Editar usuario
$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT id, nombre, nickname, email, clavecorta, enabled, can_dsblind, err_notif FROM usuarios WHERE id = ?");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch();
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle-enabled') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $enabled = !empty($_POST['enabled']);
        if ($id) {
            try {
                $pdo->prepare("UPDATE usuarios SET enabled = ? WHERE id = ?")->execute([$enabled ? 't' : 'f', $id]);
                echo json_encode(['ok' => true]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        }
        exit;
    }

    if ($_POST['action'] === 'toggle-dsblind') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $can_dsblind = !empty($_POST['can_dsblind']);
        if ($id) {
            try {
                $pdo->prepare("UPDATE usuarios SET can_dsblind = ? WHERE id = ?")->execute([$can_dsblind ? 't' : 'f', $id]);
                echo json_encode(['ok' => true]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        }
        exit;
    }

    if ($_POST['action'] === 'toggle-errnotif') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $err_notif = !empty($_POST['err_notif']);
        if ($id) {
            try {
                $pdo->prepare("UPDATE usuarios SET err_notif = ? WHERE id = ?")->execute([$err_notif ? 't' : 'f', $id]);
                echo json_encode(['ok' => true]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
        }
        exit;
    }

    if ($_POST['action'] === 'editar' && $editId) {
        $nombre = trim($_POST['nombre'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $clavecorta = strtoupper(trim($_POST['clavecorta'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $err_notif = !empty($_POST['err_notif']);
        $can_dsblind = !empty($_POST['can_dsblind']);
        $enabled = !empty($_POST['enabled']);

        if (empty($nombre) || empty($nickname)) {
            $error = 'Nombre y nickname son obligatorios.';
        } elseif (!empty($clavecorta) && !preg_match('/^[A-Z0-9]{5}$/', $clavecorta)) {
            $error = 'La clave corta debe tener exactamente 5 caracteres alfanuméricos.';
        } else {
            try {
                $params = [$nombre, $nickname];
                $sql = "UPDATE usuarios SET nombre=?, nickname=?";
                
                if ($password) {
                    $sql .= ", password=?";
                    $params[] = password_hash($password, PASSWORD_BCRYPT);
                }
                
                if ($clavecorta) {
                    $sql .= ", clavecorta=?";
                    $params[] = password_hash($clavecorta, PASSWORD_BCRYPT);
                } else {
                    $sql .= ", clavecorta=NULL";
                }
                
                $sql .= ", email=?, err_notif=?, can_dsblind=?, enabled=? WHERE id=?";
                $params[] = $email;
                $params[] = $err_notif ? 't' : 'f';
                $params[] = $can_dsblind ? 't' : 'f';
                $params[] = $enabled ? 't' : 'f';
                $params[] = $editId;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                $mensaje = "Usuario '$nombre' actualizado.";
                $editData['nombre'] = $nombre;
                $editData['nickname'] = $nickname;
                $editData['email'] = $email;
                $editData['clavecorta'] = $clavecorta;
                $editData['err_notif'] = $err_notif ? 't' : 'f';
                $editData['can_dsblind'] = $can_dsblind ? 't' : 'f';
                $editData['enabled'] = $enabled ? 't' : 'f';
            } catch (Exception $e) {
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $password = $_POST['password'] ?? '';
        $clavecorta = strtoupper(trim($_POST['clavecorta'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $err_notif = !empty($_POST['err_notif']);

        if (empty($nombre) || empty($nickname) || empty($password)) {
            $error = 'Nombre, nickname y contraseña son obligatorios.';
        } elseif (!empty($clavecorta) && !preg_match('/^[A-Z0-9]{5}$/', $clavecorta)) {
            $error = 'La clave corta debe tener exactamente 5 caracteres alfanuméricos.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $hashClaveCorta = $clavecorta ? password_hash($clavecorta, PASSWORD_BCRYPT) : null;
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, nickname, password, clavecorta, email, err_notif) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $nickname, $hash, $hashClaveCorta, $email, $err_notif ? 't' : 'f']);
                $mensaje = "Usuario '$nombre' creado exitosamente.";
            } catch (Exception $e) {
                $error = 'Error al crear usuario: ' . $e->getMessage();
            }
        }
    }
}

$usuarios = $pdo->query("SELECT id, nombre, nickname, email, enabled, can_dsblind, err_notif, created_at FROM usuarios ORDER BY id")->fetchAll();

require __DIR__ . '/header.php';
?>

<h1>Usuarios</h1>

<?php if ($mensaje): ?>
    <div class="flash flash-success"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($editData): ?>
    <article>
        <header><strong>Editar Usuario: <?= htmlspecialchars($editData['nombre']) ?></strong></header>
        <form method="POST" action="/dashboard/usuarios?edit=<?= $editId ?>">
            <input type="hidden" name="action" value="editar">
            <div class="grid">
                <label>
                    Nombre completo
                    <input type="text" name="nombre" value="<?= htmlspecialchars($editData['nombre']) ?>" required>
                </label>
                <label>
                    Nickname
                    <input type="text" name="nickname" value="<?= htmlspecialchars($editData['nickname']) ?>" required>
                </label>
                <label>
                    Contraseña <small>(dejar vacío para mantener actual)</small>
                    <input type="password" name="password" placeholder="••••••••">
                </label>
                <label>
                    Clave corta <small>(5 alfanuméricos)</small>
                    <input type="text" name="clavecorta" placeholder="AAAAA" maxlength="5">
                </label>
                <label>
                    Email
                    <input type="email" name="email" value="<?= htmlspecialchars($editData['email'] ?? '') ?>" placeholder="Opcional">
                </label>
            </div>
            <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?= ($editData['enabled'] === 't' || $editData['enabled'] === true) ? 'checked' : '' ?>>
                    Habilitado
                </label>
                <label>
                    <input type="checkbox" name="can_dsblind" value="1" <?= ($editData['can_dsblind'] === 't' || $editData['can_dsblind'] === true) ? 'checked' : '' ?>>
                    Puede ver ciegos (DSBLIND)
                </label>
                <label>
                    <input type="checkbox" name="err_notif" value="1" <?= ($editData['err_notif'] === 't' || $editData['err_notif'] === true) ? 'checked' : '' ?>>
                    Notificar errores
                </label>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit">Guardar Cambios</button>
                <a href="/dashboard/usuarios" role="button" class="secondary outline">Cancelar</a>
            </div>
        </form>
    </article>
<?php else: ?>
    <article>
        <header><strong>Crear Nuevo Usuario</strong></header>
        <form method="POST" action="/dashboard/usuarios">
            <input type="hidden" name="action" value="crear">
            <div class="grid">
                <label>
                    Nombre completo
                    <input type="text" name="nombre" required>
                </label>
                <label>
                    Nickname
                    <input type="text" name="nickname" required>
                </label>
                <label>
                    Contraseña
                    <input type="password" name="password" required>
                </label>
                <label>
                    Clave corta <small>(5 alfanuméricos)</small>
                    <input type="text" name="clavecorta" placeholder="AAAAA" maxlength="5">
                </label>
                <label>
                    Email
                    <input type="email" name="email" placeholder="Opcional">
                </label>
                <label>
                    <input type="checkbox" name="err_notif" value="1">
                    Notificar errores
                </label>
            </div>
            <button type="submit">Crear Usuario</button>
        </form>
    </article>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Nickname</th>
                <th>Email</th>
                <th>Estado</th>
                <th>DBD</th>
                <th>Err.Notif</th>
                <th>Creado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['nombre']) ?></td>
                    <td><?= htmlspecialchars($u['nickname']) ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td>
                        <input type="checkbox" class="toggle-enabled" data-id="<?= $u['id'] ?>"<?= ($u['enabled'] === 't' || $u['enabled'] === true) ? ' checked' : '' ?>>
                    </td>
                    <td>
                        <input type="checkbox" class="toggle-dsblind" data-id="<?= $u['id'] ?>"<?= ($u['can_dsblind'] === 't' || $u['can_dsblind'] === true) ? ' checked' : '' ?>>
                    </td>
                    <td>
                        <input type="checkbox" class="toggle-errnotif" data-id="<?= $u['id'] ?>"<?= ($u['err_notif'] === 't' || $u['err_notif'] === true) ? ' checked' : '' ?>>
                    </td>
                    <td><?= fmtFecha($u['created_at']) ?> (<?= timeago($u['created_at']) ?>)</td>
                    <td class="actions">
                        <a href="/dashboard/usuarios?edit=<?= $u['id'] ?>" role="button" class="secondary outline" style="padding: 0.25rem 0.5rem;">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.toggle-enabled, .toggle-dsblind, .toggle-errnotif').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var cls = this.classList;
        var field, action;
        if (cls.contains('toggle-enabled')) { field = 'enabled'; action = 'toggle-enabled'; }
        else if (cls.contains('toggle-dsblind')) { field = 'can_dsblind'; action = 'toggle-dsblind'; }
        else { field = 'err_notif'; action = 'toggle-errnotif'; }
        var formData = new FormData();
        formData.append('action', action);
        formData.append('id', this.dataset.id);
        formData.append(field, this.checked ? '1' : '');
        fetch('/dashboard/usuarios', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) cb.checked = !cb.checked;
            })
            .catch(function () { cb.checked = !cb.checked; });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>

<?php
/*
README: admin.php — Admin panel to manage users and run the Secret Santa draw.
- Login via admin/admin.php (admin: `admin` / `secret`).
- Add users (auto-generates password), list and delete users (before draw).
- Run Draw once: creates derangement and saves to data/pairs.json.
- Does NOT reveal matches; only users can see their own assignment.
*/

require_once __DIR__ . '/inc.php';
if (empty($_SESSION['admin'])) { header('Location: index.php'); exit; }

$usersPath = __DIR__ . '/data/users.json';
$pairsPath = __DIR__ . '/data/pairs.json';

// JSON functions are defined in inc.php with retry logic and corruption recovery

// Groups helpers
function load_groups($path){
  $raw = read_json_assoc($path);
  if (isset($raw['groups']) && is_array($raw['groups'])) return $raw['groups'];
  // Back-compat: if it was a flat pairs map, convert to one legacy group
  if ($raw && !isset($raw['groups'])) {
    $pairs = $raw; $parts = array_keys($pairs);
    return [['name'=>'Legacy','active'=>true,'participants'=>$parts,'pairs'=>$pairs,'purchased'=>[], 'created'=>date('c')]];
  }
  return [];
}
function save_groups($path, $groups){ write_json_assoc($path, ['groups'=>$groups]); }
function get_active_group(&$groups){ foreach ($groups as $g) { if (!empty($g['active'])) return $g; } return null; }

$users = read_json_assoc($usersPath);
$groups = load_groups($pairsPath);
$message = '';
$error = '';
// Flash messages
if (!empty($_SESSION['flash_ok'])) { $message = $_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }
if (!empty($_SESSION['flash_err'])) { $error = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

// Add user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='add') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $newUser = normalize_username($_POST['username'] ?? '');
        $plainIn = (string)($_POST['password'] ?? '');
        if (!validate_username($newUser)) {
            $error = 'Invalid username. Use 2-32 chars: a-z, 0-9, _ or -';
        } else {
            foreach ($users as $u) { 
                if (normalize_username($u['username']) === $newUser) { 
                    $error = 'User already exists.'; 
                    break; 
                } 
            }
            if (!$error) {
                $plain = trim($plainIn) !== '' ? $plainIn : substr(bin2hex(random_bytes(8)), 0, 8);
                if (!validate_password($plain)) { 
                    $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.'; 
                }
            }
            if (!$error) {
                $hash = password_hash($plain, PASSWORD_DEFAULT);
                // Ensure username is normalized before storing
                $users[] = ['username'=>$newUser, 'password'=>$hash, 'interests'=>'', 'active'=>true];
                write_json_assoc($usersPath, $users);
                log_activity('USER_ADDED', "Username: {$newUser}", 'admin');
                $message = 'User "' . htmlspecialchars($newUser) . '" added with password: ' . htmlspecialchars($plain);
            }
        }
    }
}

// Delete user
if (isset($_GET['del'])) {
    $del = normalize_username($_GET['del']);
    if ($del === 'admin') {
        $error = 'Cannot delete admin user.';
    } else {
        $before = count($users);
        $users = array_values(array_filter($users, function($u) use ($del){ 
            return normalize_username($u['username']) !== $del; 
        }));
        if (count($users) !== $before) { 
            write_json_assoc($usersPath, $users); 
            log_activity('USER_DELETED', "Username: {$del}", 'admin');
            $message = 'Deleted user: ' . htmlspecialchars($del); 
        } else { 
            $error = 'User not found.'; 
        }
    }
}

// Change/reset password for a user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resetpw') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $uname = normalize_username($_POST['username'] ?? '');
        $plainIn = (string)($_POST['password'] ?? '');
        $found = false;
        for ($i=0; $i<count($users); $i++) {
            if (normalize_username($users[$i]['username'] ?? '') === $uname) {
                $found = true;
                $plain = trim($plainIn) !== '' ? $plainIn : substr(bin2hex(random_bytes(8)), 0, 8);
                if (!validate_password($plain)) { 
                    $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.'; 
                    break; 
                }
                $users[$i]['password'] = password_hash($plain, PASSWORD_DEFAULT);
                write_json_assoc($usersPath, $users);
                log_activity('PASSWORD_CHANGED', "Username: {$uname}", 'admin');
                $message = 'Password for "' . htmlspecialchars($uname) . '" set to: ' . htmlspecialchars($plain);
                break;
            }
        }
        if (!$found) { $error = 'User not found.'; }
    }
}

// Group actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='set_active') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        for ($i=0; $i<count($groups); $i++) { $groups[$i]['active'] = ($groups[$i]['name'] === $name); }
        save_groups($pairsPath, $groups);
        log_activity('DRAW_ACTIVATED', "Draw: {$name}", 'admin');
        $message = 'Active draw set to ' . htmlspecialchars($name);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='archive') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $found = false;
        for ($i=0; $i<count($groups); $i++) {
            if ($groups[$i]['name'] === $name) { $groups[$i]['active'] = false; $found = true; }
        }
        if ($found) { 
            save_groups($pairsPath, $groups); 
            log_activity('DRAW_ARCHIVED', "Draw: {$name}", 'admin');
            $message = 'Archived draw ' . htmlspecialchars($name); 
        } else { 
            $error = 'Draw not found.'; 
        }
    }
}
if (isset($_GET['delgroup'])) {
    $name = trim((string)$_GET['delgroup']);
    $before = count($groups);
    $groups = array_values(array_filter($groups, function($g) use ($name){ return $g['name'] !== $name; }));
    if (count($groups) !== $before) { 
        save_groups($pairsPath, $groups); 
        log_activity('DRAW_DELETED', "Draw: {$name}", 'admin');
        $message = 'Deleted draw ' . htmlspecialchars($name); 
    } else { 
        $error = 'Draw not found.'; 
    }
}

// Activate/Deactivate user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $uname = normalize_username($_POST['username'] ?? '');
        if ($uname === 'admin') {
            $error = 'Cannot change admin account status.';
        } else {
            $found = false;
            for ($i=0; $i<count($users); $i++) {
                if (normalize_username($users[$i]['username']) === $uname) {
                    $wasActive = isset($users[$i]['active']) && $users[$i]['active'] === true;
                    $users[$i]['active'] = !$wasActive;
                    write_json_assoc($usersPath, $users);
                    log_activity($wasActive ? 'USER_DEACTIVATED' : 'USER_ACTIVATED', "Username: {$uname}", 'admin');
                    $message = ($wasActive ? 'Deactivated' : 'Activated') . ' user: ' . htmlspecialchars($uname);
                    $found = true;
                    break;
                }
            }
            if (!$found) { $error = 'User not found.'; }
        }
    }
}

// Handle password reset approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_reset') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $uname = normalize_username($_POST['username'] ?? '');
        $plainIn = (string)($_POST['password'] ?? '');
        
        if (!$uname) {
            $error = 'Username required.';
        } else {
            $plain = trim($plainIn) !== '' ? $plainIn : substr(bin2hex(random_bytes(8)), 0, 8);
            if (!validate_password($plain)) { 
                $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.'; 
            } else {
                $found = false;
                for ($i=0; $i<count($users); $i++) {
                    if (normalize_username($users[$i]['username']) === $uname) {
                        $users[$i]['password'] = password_hash($plain, PASSWORD_DEFAULT);
                        write_json_assoc($usersPath, $users);
                        remove_reset_request($uname);
                        log_activity('PASSWORD_RESET_APPROVED', "Username: {$uname}", 'admin');
                        $message = 'Password reset approved for "' . htmlspecialchars($uname) . '". New password: ' . htmlspecialchars($plain);
                        $found = true;
                        break;
                    }
                }
                if (!$found) { $error = 'User not found.'; }
            }
        }
    }
}

// Handle password reset rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_reset') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $uname = normalize_username($_POST['username'] ?? '');
        remove_reset_request($uname);
        log_activity('PASSWORD_RESET_REJECTED', "Username: {$uname}", 'admin');
        $message = 'Password reset request rejected for "' . htmlspecialchars($uname) . '".';
    }
}

// Reload after mutations
$users = read_json_assoc($usersPath);
$groups = load_groups($pairsPath);
$active = get_active_group($groups);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= t('app_title') ?> — <?= t('admin_panel') ?></title>
  <style>
    :root { --bg:#f7f7fb; --card:#fff; --fg:#222; --muted:#666; --accent:#4a67ff; --danger:#c0392b; }
    * { box-sizing: border-box; }
    body { margin:0; font:16px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--fg); }
    .wrap { min-height:100vh; display:grid; place-items:start center; padding:24px; }
    .card { width:100%; max-width:820px; background:var(--card); border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.06); padding:24px; }
    h1 { margin:0 0 8px; font-size:22px; }
    p.sub { margin:0 0 16px; color:var(--muted); font-size:14px; }
    form.inline { display:flex; gap:8px; flex-wrap:wrap; margin: 0 0 16px; }
    input[type=text] { flex:1 1 200px; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:16px; }
    .btn { padding:10px 14px; border:0; background:var(--accent); color:#fff; border-radius:8px; font-weight:600; cursor:pointer; }
    .btn[disabled] { opacity:.5; cursor:not-allowed; }
    .btn.danger { background:var(--danger); }
    .btn.secondary { background:#888; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { text-align:left; padding:10px; border-bottom:1px solid #eee; font-size:14px; }
    td.actions { display:flex; gap:8px; align-items:center; white-space:nowrap; }
    .row { display:flex; justify-content:space-between; align-items:center; margin-top:12px; }
    .msg { margin-top:10px; color:#2e7d32; font-size:14px; }
    .err { margin-top:10px; color:var(--danger); font-size:14px; }
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
    a { color:var(--accent); text-decoration:none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="topbar">
        <div>
          <h1><?= t('admin_panel') ?></h1>
          <p class="sub"><?= t('manage_participants') ?></p>
        </div>
        <div><a href="index.php?logout=1"><?= t('logout') ?></a></div>
      </div>

      <form class="inline" method="post" onsubmit="return addUserValid()">
        <input type="hidden" name="action" value="add">
        <?= csrf_field() ?>
        <input type="text" name="username" id="nu" placeholder="<?= t('username') ?>" required>
        <input type="text" name="password" id="np" placeholder="<?= t('password_optional') ?>">
        <button class="btn" type="submit"><?= t('add_user') ?></button>
      </form>
      <?php if ($message): ?><div class="msg"><?= $message ?></div><?php endif; ?>
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php
      // Separate active and pending users
      $activeUsers = [];
      $pendingUsers = [];
      foreach ($users as $u) {
          $isActive = !isset($u['active']) || $u['active'] === true; // Default to active for backward compatibility
          if ($isActive) {
              $activeUsers[] = $u;
          } else {
              $pendingUsers[] = $u;
          }
      }
      ?>
      
      <?php
      // Get pending password reset requests
      $resetRequests = get_pending_reset_requests();
      ?>
      
      <?php if (count($resetRequests) > 0): ?>
        <div style="background:#ffe6e6; border:1px solid #ff6b6b; border-radius:8px; padding:12px; margin-bottom:16px;">
          <h2 style="margin:0 0 8px;font-size:18px;">🔐 Password Reset Requests (<?= count($resetRequests) ?>)</h2>
          <table>
            <thead><tr><th><?= t('username') ?></th><th>Requested</th><th style="width:300px;"><?= t('action') ?></th></tr></thead>
            <tbody>
            <?php foreach ($resetRequests as $req): 
              $reqTime = isset($req['requested_at']) ? date('Y-m-d H:i', strtotime($req['requested_at'])) : 'Unknown';
            ?>
              <tr>
                <td><?= htmlspecialchars($req['username']) ?></td>
                <td><?= htmlspecialchars($reqTime) ?></td>
                <td class="actions">
                  <form method="post" style="display:inline" onsubmit="return approveResetPrompt('<?= htmlspecialchars($req['username']) ?>')">
                    <input type="hidden" name="action" value="approve_reset">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($req['username']) ?>">
                    <input type="hidden" name="password" id="reset-pw-<?= htmlspecialchars($req['username']) ?>">
                    <?= csrf_field() ?>
                    <button class="btn" type="submit">✅ Approve & Set Password</button>
                  </form>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="reject_reset">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($req['username']) ?>">
                    <?= csrf_field() ?>
                    <button class="btn danger" type="submit">❌ Reject</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (count($pendingUsers) > 0): ?>
        <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:12px; margin-bottom:16px;">
          <h2 style="margin:0 0 8px;font-size:18px;"><?= t('pending_users') ?> (<?= count($pendingUsers) ?>)</h2>
          <table>
            <thead><tr><th><?= t('username') ?></th><th style="width:220px;"><?= t('action') ?></th></tr></thead>
            <tbody>
            <?php foreach ($pendingUsers as $u): ?>
              <tr>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td class="actions">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                    <?= csrf_field() ?>
                    <button class="btn" type="submit"><?= t('activate_user') ?></button>
                  </form>
                  <a class="btn danger" href="admin.php?del=<?= urlencode($u['username']) ?>" onclick="return confirm('<?= t('delete') ?> user <?= htmlspecialchars($u['username']) ?>?')"><?= t('delete') ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="row">
        <h2 style="margin:0;font-size:18px;"><?= t('users') ?> (<?= count($activeUsers) ?>)</h2>
      </div>

      <table>
        <thead><tr><th><?= t('username') ?></th><th style="width:220px;"><?= t('action') ?></th></tr></thead>
        <tbody>
        <?php if (!$activeUsers): ?>
          <tr><td colspan="2">No active users yet.</td></tr>
        <?php else: foreach ($activeUsers as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['username']) ?><?php if (normalize_username($u['username']) === 'admin'): ?> <span style="color:var(--muted); font-size:12px;">(admin)</span><?php endif; ?></td>
            <td class="actions">
              <form method="post" style="display:inline" onsubmit="return changePwPrompt('<?= htmlspecialchars($u['username']) ?>')">
                <input type="hidden" name="action" value="resetpw">
                <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                <input type="hidden" name="password" id="pw-<?= htmlspecialchars($u['username']) ?>">
                <?= csrf_field() ?>
                <button class="btn" type="submit"><?= t('change_password') ?></button>
              </form>
              <?php if (normalize_username($u['username']) !== 'admin'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                  <?= csrf_field() ?>
                  <button class="btn secondary" type="submit"><?= t('deactivate_user') ?></button>
                </form>
                <a class="btn danger" href="admin.php?del=<?= urlencode($u['username']) ?>" onclick="return confirm('<?= t('delete') ?> user <?= htmlspecialchars($u['username']) ?>?')"><?= t('delete') ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <p class="sub" style="margin-top:12px;"><?= t('tip_draws') ?></p>

      <hr style="border:none;border-top:1px solid #eee;margin:18px 0;">
      <h2 style="margin:0 0 8px;font-size:18px;"><?= t('create_draw') ?></h2>
      <form method="post" action="draw.php" onsubmit="return drawValid()" style="margin:8px 0 12px;">
        <input type="hidden" name="action" value="draw_group">
        <?= csrf_field() ?>
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:10px;">
          <div style="flex:1 1 200px; min-width:150px;">
            <label for="gname" style="display:block;margin:0 0 6px; font-size:14px; font-weight:600;"><?= t('draw_name') ?></label>
            <input type="text" id="gname" name="group_name" placeholder="e.g., 2025" required style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:16px;">
          </div>
          <div style="flex:1 1 150px; min-width:120px;">
            <label for="budget" style="display:block;margin:0 0 6px; font-size:14px; font-weight:600;"><?= t('max_cost') ?></label>
            <input type="number" id="budget" name="budget" min="0" step="0.01" placeholder="e.g., 500" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:16px;">
          </div>
          <div style="flex:1 1 180px; min-width:150px;">
            <label for="deadline" style="display:block;margin:0 0 6px; font-size:14px; font-weight:600;"><?= t('deadline') ?></label>
            <input type="date" id="deadline" name="deadline" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:16px;">
          </div>
          <div style="flex:0 0 auto; padding-bottom:0;">
            <button class="btn" type="submit" style="margin-top:0;"><?= t('run_draw') ?></button>
          </div>
        </div>
        <div style="margin-top:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <label style="margin:0;"><?= t('select_participants') ?></label>
            <a href="#" onclick="toggleAll(true);return false;"><?= t('select_all') ?></a>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;">
            <?php foreach ($users as $u): 
              $n = $u['username']; 
              if (normalize_username($n) === 'admin') continue; 
            ?>
              <label style="border:1px solid #eee;padding:6px 10px;border-radius:8px;font-size:14px;">
                <input type="checkbox" class="mem" name="members[]" value="<?= htmlspecialchars($n) ?>"> <?= htmlspecialchars($n) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </form>

      <h2 style="margin:12px 0 8px;font-size:18px;"><?= t('existing_draws') ?> (<?= count($groups) ?>)</h2>
      <table>
        <thead><tr><th><?= t('name') ?></th><th><?= t('participants') ?></th><th><?= t('active') ?></th><th><?= t('actions') ?></th></tr></thead>
        <tbody>
          <?php if (!$groups): ?>
            <tr><td colspan="4">No draws yet. Create one above.</td></tr>
          <?php else: foreach ($groups as $g): ?>
            <tr>
              <td><?= htmlspecialchars($g['name']) ?></td>
              <td><?= isset($g['participants']) ? count($g['participants']) : 0 ?></td>
              <td><?= !empty($g['active']) ? 'Yes' : 'No' ?></td>
              <td class="actions">
                <?php $isActive = !empty($g['active']); ?>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= t('set_active') ?> <?= htmlspecialchars($g['name']) ?>?')">
                  <input type="hidden" name="action" value="set_active">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($g['name']) ?>">
                  <?= csrf_field() ?>
                  <button class="btn" type="submit" <?php if ($isActive) echo 'disabled'; ?>><?= t('set_active') ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= t('archive') ?> <?= htmlspecialchars($g['name']) ?>?')">
                  <input type="hidden" name="action" value="archive">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($g['name']) ?>">
                  <?= csrf_field() ?>
                  <button class="btn secondary" type="submit" <?php if (!$isActive) echo 'disabled'; ?>><?= t('archive') ?></button>
                </form>
                <a class="btn danger" href="admin.php?delgroup=<?= urlencode($g['name']) ?>" onclick="return confirm('<?= t('delete_draw') ?> <?= htmlspecialchars($g['name']) ?>?')"><?= t('delete_draw') ?></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      
      <hr style="border:none;border-top:1px solid #eee;margin:18px 0;">
      <h2 style="margin:0 0 8px;font-size:18px;"><?= t('current_status') ?></h2>
      <p class="sub" style="margin:0 0 8px;"><?= t('status_sub') ?></p>
      <?php if (!$active): ?>
        <div class="sub"><?= t('no_active_draw') ?></div>
      <?php else: 
        if (isset($active['budget']) || isset($active['deadline'])): ?>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <?php if (isset($active['budget'])): ?>
              <div style="background:#fff9e6; border:1px solid #ffe066; border-radius:8px; padding:10px 14px; font-size:14px; color:#856404;">
                <strong style="display:block; margin-bottom:2px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#856404;"><?= t('budget') ?></strong>
                <span style="font-weight:600; font-size:15px;"><?= number_format($active['budget'], 0, ',', ' ') ?> kr</span>
              </div>
            <?php endif; ?>
            <?php if (isset($active['deadline'])): 
              $deadlineDate = strtotime($active['deadline']);
              $today = time();
              $daysLeft = floor(($deadlineDate - $today) / (60 * 60 * 24));
              $dateStr = date('Y-m-d', $deadlineDate);
              $isUrgent = $daysLeft < 7;
              $bgColor = $isUrgent ? '#ffe6e6' : '#fff9e6';
              $borderColor = $isUrgent ? '#ff9999' : '#ffe066';
              $textColor = $isUrgent ? '#721c24' : '#856404';
            ?>
              <div style="background:<?= $bgColor ?>; border:1px solid <?= $borderColor ?>; border-radius:8px; padding:10px 14px; font-size:14px; color:<?= $textColor ?>;">
                <strong style="display:block; margin-bottom:2px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:<?= $textColor ?>;"><?= t('deadline') ?></strong>
                <span style="font-weight:600; font-size:15px;"><?= $dateStr ?><?php if ($daysLeft >= 0): ?> <span style="font-weight:500;">(<?= $daysLeft === 0 ? t('today') : ($daysLeft === 1 ? t('day_left') : sprintf(t('days_left'), $daysLeft)) ?>)</span><?php endif; ?></span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <table>
          <thead>
            <tr>
              <th><?= t('user') ?></th>
              <th><?= t('assigned_recipient') ?></th>
              <th><?= t('interests') ?></th>
              <th><?= t('purchased') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php
              $pairs = isset($active['pairs']) ? $active['pairs'] : [];
              $purchased = isset($active['purchased']) && is_array($active['purchased']) ? $active['purchased'] : [];
              foreach ($active['participants'] as $name) {
                // Normalize username for lookup
                $normalizedName = normalize_username($name);
                $u = null; 
                foreach ($users as $ux) { 
                    if (normalize_username($ux['username']) === $normalizedName) { 
                        $u = $ux; 
                        break; 
                    } 
                }
                $ints = $u ? ($u['interests'] ?? '') : '';
                // Find recipient with normalized lookup
                $rec = '—';
                foreach ($pairs as $key => $value) {
                    if (normalize_username($key) === $normalizedName) {
                        $rec = $value;
                        break;
                    }
                }
                // Find purchased status with normalized lookup
                $done = false;
                foreach ($purchased as $key => $val) {
                    if (normalize_username($key) === $normalizedName) {
                        $done = !empty($val);
                        break;
                    }
                }
                echo '<tr>';
                echo '<td>'.htmlspecialchars($name).'</td>';
                echo '<td>'.htmlspecialchars((string)$rec).'</td>';
                echo '<td>'.nl2br(htmlspecialchars($ints)).'</td>';
                echo '<td style="font-size:18px">'.($done ? '✅' : '❌').'</td>';
                echo '</tr>';
              }
            ?>
          </tbody>
        </table>
      <?php endif; ?>
      
      <hr style="border:none;border-top:1px solid #eee;margin:18px 0;">
      <h2 style="margin:0 0 8px;font-size:18px;"><?= t('activity_log') ?></h2>
      <div style="background:#f5f5f5; border-radius:8px; padding:12px; max-height:300px; overflow-y:auto; font-family:monospace; font-size:12px;">
        <?php
        $logEntries = get_activity_log(50);
        if (empty($logEntries)):
        ?>
          <div style="color:var(--muted);"><?= t('no_activity') ?></div>
        <?php else: ?>
          <?php foreach (array_reverse($logEntries) as $entry): ?>
            <div style="margin-bottom:4px; color:var(--fg);"><?= htmlspecialchars($entry) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script>
    function addUserValid(){
      var v = document.getElementById('nu').value.trim();
      if(!v){ alert('Enter a username.'); return false; }
      if(!/^[a-z0-9_\-]{2,32}$/.test(v)){ alert('Username must be 2-32 chars: a-z, 0-9, _ or -'); return false; }
      var p = document.getElementById('np').value;
      if(p && p.length < 4){ alert('Password must be at least 4 characters, or leave blank for a random one.'); return false; }
      return true;
    }
    function changePwPrompt(user){
      var p = prompt('Enter new password for ' + user + ' (leave blank for random):','');
      if(p === null) return false;
      if(p && p.length < 4){ alert('Password must be at least 4 characters, or leave blank.'); return false; }
      var el = document.getElementById('pw-' + user);
      if(el) el.value = p;
      return true;
    }
    function approveResetPrompt(user){
      var p = prompt('Enter new password for ' + user + ' (leave blank for random):','');
      if(p === null) return false;
      if(p && p.length < 4){ alert('Password must be at least 4 characters, or leave blank.'); return false; }
      var el = document.getElementById('reset-pw-' + user);
      if(el) el.value = p;
      return true;
    }
    function drawValid(){
      var g = document.getElementById('gname').value.trim();
      if(!g){ alert('Please enter a draw name.'); return false; }
      var boxes = document.querySelectorAll('.mem:checked');
      if(boxes.length < 2){ alert('Select at least two participants.'); return false; }
      return confirm('Create draw "'+g+'" for '+boxes.length+' participants?');
    }
    function toggleAll(v){ document.querySelectorAll('.mem').forEach(cb=>cb.checked=v); }
  </script>
</body>
</html>

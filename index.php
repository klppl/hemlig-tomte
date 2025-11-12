<?php
/*
README: index.php — Login page for admin and users.
- Admin: username `admin`, password stored in users.json (default: `secret` on first run).
- Users: authenticate against `data/users.json` (passwords hashed) → goes to view page.
- Uses PHP sessions; includes minimal HTML/CSS and JS validation.
*/

session_start();
require_once __DIR__ . '/inc.php';

// Simple helpers
function redirect($to) { header("Location: $to"); exit; }
function read_json($path) { if (!file_exists($path)) return []; $j = file_get_contents($path); $d = json_decode($j, true); return is_array($d) ? $d : []; }
function write_json($path, $data) { file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX); }

// Ensure data directory exists (first run convenience)
if (!is_dir(__DIR__ . '/data')) { @mkdir(__DIR__ . '/data', 0777, true); }
if (!file_exists(__DIR__ . '/data/users.json')) { write_json(__DIR__ . '/data/users.json', []); }
if (!file_exists(__DIR__ . '/data/pairs.json')) { file_put_contents(__DIR__ . '/data/pairs.json', json_encode(new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

// Check if admin user exists
$usersPath = __DIR__ . '/data/users.json';
$users = read_json($usersPath);
$adminExists = false;
foreach ($users as $u) {
    if (isset($u['username']) && $u['username'] === 'admin') {
        $adminExists = true;
        break;
    }
}

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    redirect('index.php');
}

$error = '';
$isSetup = !$adminExists;

// Handle admin account creation (setup)
if ($isSetup && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup') {
    $username = trim(strtolower($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    // Force username to be 'admin'
    if ($username !== 'admin') {
        $error = 'Username must be "admin" for admin privileges.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } else {
        // Create admin user
        $users = read_json($usersPath);
        $users[] = [
            'username' => 'admin',
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'interests' => ''
        ];
        write_json($usersPath, $users);
        // Auto-login as admin
        $_SESSION['admin'] = true;
        redirect('admin.php');
    }
}

// Handle login
if (!$isSetup && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    // All users (including admin) authenticate via users.json
    $users = read_json($usersPath);
    $found = null;
    foreach ($users as $u) {
        if (isset($u['username']) && $u['username'] === $username) { $found = $u; break; }
    }
    if ($found && isset($found['password']) && password_verify($password, $found['password'])) {
        if ($username === 'admin') {
            $_SESSION['admin'] = true;
            redirect('admin.php');
        } else {
            $_SESSION['user'] = $username;
            redirect('view.php');
        }
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= t('app_title') ?> — <?= $isSetup ? t('setup_title') : t('login_title') ?></title>
  <style>
    :root { --bg:#f7f7fb; --card:#fff; --fg:#222; --muted:#666; --accent:#4a67ff; --red:#e74c3c; --green:#2ecc71; }
    * { box-sizing: border-box; }
    body { margin:0; font:16px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:linear-gradient(180deg,#f8fbff 0%,#ffffff 60%); color:var(--fg); overflow-x:hidden; }
    .wrap { position:relative; min-height: 100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:420px; background:var(--card); border-radius:14px; box-shadow:0 16px 35px rgba(0,0,0,.08); padding:26px; border:2px solid transparent; background-clip:padding-box; position:relative; }
    .card:before{ content:""; position:absolute; inset:-2px; border-radius:16px; padding:2px; background:linear-gradient(135deg,var(--red),var(--green)); -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0); -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none; }
    h1 { margin:0 0 8px; font-size:24px; display:flex; align-items:center; gap:8px; }
    .title-emoji{font-size:26px}
    p.sub { margin:0 0 20px; color:var(--muted); font-size:14px; }
    .field { margin-bottom:14px; }
    label { display:block; margin-bottom:6px; font-size:14px; }
    input[type=text], input[type=password] { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:16px; }
    .btn { width:100%; display:inline-block; padding:10px 14px; border:0; background:linear-gradient(135deg, #4666ff, #6a8bff); color:#fff; border-radius:10px; font-weight:700; cursor:pointer; box-shadow:0 6px 14px rgba(74,103,255,.25); }
    .btn:hover{ filter:brightness(1.05); }
    .msg { margin-top:10px; color:#c0392b; font-size:14px; }
    .hint { margin-top:14px; color:var(--muted); font-size:12px; }
    .foot { margin-top:18px; font-size:13px; color:var(--muted); text-align:center; }
    .foot a { color:var(--accent); text-decoration:none; }
    .lang { position:absolute; top:16px; right:16px; }
    select { padding:6px 8px; border:1px solid #ddd; border-radius:8px; background:#fff; }
    /* Garland lights */
    .garland{position:absolute; top:8px; left:50%; transform:translateX(-50%); width:90%; height:8px; background:repeating-linear-gradient(90deg, #e74c3c 0 12px, #27ae60 12px 24px, #f1c40f 24px 36px, #3498db 36px 48px); border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); pointer-events:none;}    
    /* Snowflakes */
    .flake{position:fixed; top:-2vh; color:#9cc3ff; opacity:.9; pointer-events:none; z-index:0; animation: fall linear forwards;}
    @keyframes fall { to { transform: translateY(105vh) rotate(360deg); opacity:.95; } }
    .card, .lang{ z-index: 1; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="garland"></div>
    <div class="card">
      <form class="lang" method="get">
        <label style="font-size:12px;color:#666;margin-right:6px;">Language</label>
        <select name="lang" onchange="this.form.submit()">
          <option value="sv" <?= lang()==='sv'?'selected':'' ?>>Svenska</option>
          <option value="en" <?= lang()==='en'?'selected':'' ?>>English</option>
        </select>
      </form>
      <h1><span class="title-emoji">🎄</span> <?= t('app_title') ?> <span class="title-emoji">🎁</span></h1>
      <?php if ($isSetup): ?>
        <p class="sub" style="font-size:18px; font-weight:600; color:var(--accent); margin-bottom:8px;">✨ <?= t('welcome') ?> ✨</p>
        <p class="sub"><?= t('setup_sub') ?></p>
        <form id="setup" method="post" novalidate>
          <input type="hidden" name="action" value="setup">
          <div class="field">
            <label for="username"><?= t('username') ?></label>
            <input id="username" name="username" type="text" required autocomplete="username" value="admin" readonly style="background:#f5f5f5; cursor:not-allowed;"/>
            <div class="hint" style="margin-top:4px; font-size:12px; color:var(--muted);"><?= t('admin_username_hint') ?></div>
          </div>
          <div class="field">
            <label for="password"><?= t('password') ?></label>
            <input id="password" name="password" type="password" required autocomplete="new-password"/>
          </div>
          <div class="field">
            <label for="password_confirm"><?= t('password_confirm') ?></label>
            <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"/>
          </div>
          <button class="btn" type="submit">✨ <?= t('create_admin') ?> ✨</button>
          <?php if ($error): ?><div class="msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        </form>
      <?php else: ?>
        <p class="sub"><?= t('login_sub') ?></p>
        <form id="login" method="post" novalidate>
          <div class="field">
            <label for="username"><?= t('username') ?></label>
            <input id="username" name="username" type="text" required autocomplete="username"/>
          </div>
          <div class="field">
            <label for="password"><?= t('password') ?></label>
            <input id="password" name="password" type="password" required autocomplete="current-password"/>
          </div>
          <button class="btn" type="submit">✨ <?= t('login') ?> ✨</button>
          <?php if ($error): ?><div class="msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        </form>
        <div class="foot">🎅 <?= t('new_here') ?></div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    // Basic client-side validation
    var formId = <?= $isSetup ? "'setup'" : "'login'" ?>;
    document.getElementById(formId).addEventListener('submit', function(e){
      var u = document.getElementById('username');
      var p = document.getElementById('password');
      if (!u.value.trim() || !p.value.trim()) { e.preventDefault(); alert('Please fill in all required fields.'); return false; }
      <?php if ($isSetup): ?>
      var pc = document.getElementById('password_confirm');
      if (p.value !== pc.value) { e.preventDefault(); alert('Passwords do not match.'); return false; }
      if (p.value.length < 4) { e.preventDefault(); alert('Password must be at least 4 characters.'); return false; }
      if (u.value !== 'admin') { e.preventDefault(); alert('Username must be "admin".'); return false; }
      <?php endif; ?>
    });
    // Simple snow effect (lightweight)
    (function(){
      const make = () => {
        const f = document.createElement('div');
        f.className = 'flake';
        f.textContent = Math.random() < 0.2 ? '❄️' : '✦';
        const size = 12 + Math.random()*12; // px
        f.style.left = (Math.random()*100) + 'vw';
        f.style.fontSize = size + 'px';
        f.style.animationDuration = (6 + Math.random()*6) + 's';
        f.style.animationDelay = (Math.random()*4) + 's';
        document.body.appendChild(f);
        setTimeout(()=>{ f.remove(); }, 12000);
      };
      for (let i=0;i<24;i++) make();
      setInterval(make, 800);
    })();
  </script>
</body>
</html>

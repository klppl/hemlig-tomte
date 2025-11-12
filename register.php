<?php
/*
README: register.php — User self-registration
- Users can register themselves
- Accounts are created with active=false
- Admin must activate accounts before users can login
*/

require_once __DIR__ . '/inc.php';

function redirect($to) { header("Location: $to"); exit; }
function read_json($path) { 
    if (function_exists('read_json_assoc')) {
        return read_json_assoc($path);
    }
    if (!file_exists($path)) return []; 
    $j = file_get_contents($path); 
    $d = json_decode($j, true); 
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    return is_array($d) ? $d : []; 
}
function write_json($path, $data) { 
    if (function_exists('write_json_assoc')) {
        return write_json_assoc($path, $data);
    }
    $result = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($result !== false && file_exists($path)) {
        chmod($path, DATA_FILE_PERMISSIONS);
    }
    return $result;
}

$usersPath = __DIR__ . '/data/users.json';
$error = '';
$message = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = normalize_username($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        
        if (!validate_username($username)) {
            $error = 'Invalid username. Use 2-32 chars: a-z, 0-9, _ or -';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } elseif (!validate_password($password)) {
            $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        } else {
            // Check if user exists
            $users = read_json($usersPath);
            $exists = false;
            foreach ($users as $u) {
                if (normalize_username($u['username']) === $username) {
                    $exists = true;
                    break;
                }
            }
            
            if ($exists) {
                $error = 'Username already exists.';
            } else {
                // Create user with active=false
                $users[] = [
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'interests' => '',
                    'active' => false,
                    'created' => date('c')
                ];
                write_json($usersPath, $users);
                log_activity('USER_REGISTERED', "Username: {$username}", $username);
                $message = t('register_success');
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= t('app_title') ?> — <?= t('register_title') ?></title>
  <style>
    :root { --bg:#f7f7fb; --card:#fff; --fg:#222; --muted:#666; --accent:#4a67ff; --red:#e74c3c; --green:#2ecc71; }
    * { box-sizing: border-box; }
    body { margin:0; font:16px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:linear-gradient(180deg,#f8fbff 0%,#ffffff 60%); color:var(--fg); overflow-x:hidden; }
    .wrap { position:relative; min-height: 100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:420px; background:var(--card); border-radius:14px; box-shadow:0 16px 35px rgba(0,0,0,.08); padding:26px; border:2px solid transparent; background-clip:padding-box; position:relative; }
    .card:before{ content:""; position:absolute; inset:-2px; border-radius:16px; padding:2px; background:linear-gradient(135deg,var(--red),var(--green)); -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0); -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none; }
    h1 { margin:0 0 8px; font-size:24px; display:flex; align-items:center; gap:8px; }
    p.sub { margin:0 0 20px; color:var(--muted); font-size:14px; }
    .field { margin-bottom:14px; }
    label { display:block; margin-bottom:6px; font-size:14px; }
    input[type=text], input[type=password] { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:16px; }
    .btn { width:100%; display:inline-block; padding:10px 14px; border:0; background:linear-gradient(135deg, #4666ff, #6a8bff); color:#fff; border-radius:10px; font-weight:700; cursor:pointer; box-shadow:0 6px 14px rgba(74,103,255,.25); }
    .btn:hover{ filter:brightness(1.05); }
    .msg { margin-top:10px; color:#2e7d32; font-size:14px; }
    .err { margin-top:10px; color:#c0392b; font-size:14px; }
    .foot { margin-top:18px; font-size:13px; color:var(--muted); text-align:center; }
    .foot a { color:var(--accent); text-decoration:none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>✨ <?= t('register_title') ?> ✨</h1>
      <p class="sub"><?= t('register_sub') ?></p>
      
      <?php if ($message): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
        <div class="foot"><a href="index.php">← <?= t('login') ?></a></div>
      <?php else: ?>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="username"><?= t('username') ?></label>
            <input id="username" name="username" type="text" required autocomplete="username"/>
          </div>
          <div class="field">
            <label for="password"><?= t('password') ?></label>
            <input id="password" name="password" type="password" required autocomplete="new-password"/>
          </div>
          <div class="field">
            <label for="password_confirm"><?= t('password_confirm') ?></label>
            <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"/>
          </div>
          <button class="btn" type="submit">✨ <?= t('register') ?> ✨</button>
          <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        </form>
        <div class="foot"><a href="index.php">← <?= t('login') ?></a></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>


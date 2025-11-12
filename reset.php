<?php
/*
README: reset.php — Password reset request
- Users can request password reset by username
- Request is sent to admin for approval
- Admin can approve and set new password in admin panel
*/

require_once __DIR__ . '/inc.php';

function redirect($to) { header("Location: $to"); exit; }

$usersPath = __DIR__ . '/data/users.json';
$error = '';
$message = '';

// Handle reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = normalize_username($_POST['username'] ?? '');
        
        if (!$username) {
            $error = 'Please enter your username.';
        } else {
            // Check if user exists
            $users = read_json_assoc($usersPath);
            $userExists = false;
            foreach ($users as $u) {
                if (normalize_username($u['username']) === $username) {
                    $userExists = true;
                    break;
                }
            }
            
            if (!$userExists) {
                // Don't reveal if user exists or not (security)
                $message = 'If the username exists, a password reset request has been sent to the admin.';
            } else {
                $result = create_password_reset_request($username, $usersPath);
                if ($result) {
                    $message = 'Password reset request has been sent to the admin. You will be notified once it is approved.';
                } else {
                    $message = 'A password reset request is already pending for this username.';
                }
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
  <title><?= t('app_title') ?> — <?= t('reset_password') ?></title>
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
    input[type=text] { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; font-size:16px; }
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
      <h1>🔐 <?= t('reset_password') ?></h1>
      <p class="sub"><?= t('reset_password_sub') ?></p>
      
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
          <button class="btn" type="submit">✨ <?= t('reset_password') ?> ✨</button>
          <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        </form>
        <div class="foot"><a href="index.php">← <?= t('login') ?></a></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

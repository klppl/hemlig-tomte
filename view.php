<?php
/*
README: view.php — User view to see assigned recipient.
- Requires user session. Reads groups from data/pairs.json and shows only the current user’s recipient for the active draw.
- Lets user edit interests and mark purchase status (per active draw).
*/

require_once __DIR__ . '/inc.php';
if (empty($_SESSION['user'])) { header('Location: index.php'); exit; }

$username = normalize_username($_SESSION['user']);
$pairsPath = __DIR__ . '/data/pairs.json';
$usersPath = __DIR__ . '/data/users.json';

// JSON functions are defined in inc.php with retry logic and corruption recovery
function load_groups($path){ $raw = read_json_assoc($path); return (isset($raw['groups']) && is_array($raw['groups'])) ? $raw['groups'] : []; }
function save_groups($path, $groups){ write_json_assoc($path, ['groups'=>$groups]); }

// Handle profile + purchased updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!validate_csrf()) {
        // Silently fail or show error - for now redirect back
        header('Location: view.php');
        exit;
    }
    // Interests are global (users.json)
    $users = read_json_assoc($usersPath);
    for ($i=0; $i<count($users); $i++) {
        if (normalize_username($users[$i]['username'] ?? '') === $username) {
            $interests = trim((string)($_POST['interests'] ?? ''));
            if (strlen($interests) > 1000) { $interests = substr($interests, 0, 1000); }
            $users[$i]['interests'] = $interests;
            write_json_assoc($usersPath, $users);
            log_activity('INTERESTS_UPDATED', "Username: {$username}", $username);
            break;
        }
    }
    // Purchased is per-active-draw (pairs.json)
    $groups = load_groups($pairsPath);
    for ($i=0; $i<count($groups); $i++) {
        if (!empty($groups[$i]['active'])) {
            if (!isset($groups[$i]['purchased']) || !is_array($groups[$i]['purchased'])) $groups[$i]['purchased'] = [];
            $purchased = isset($_POST['purchased']) && $_POST['purchased'] === '1';
            // Normalize username key in purchased array - remove old key if exists with different case
            foreach ($groups[$i]['purchased'] as $key => $val) {
                if (normalize_username($key) === $username && $key !== $username) {
                    unset($groups[$i]['purchased'][$key]);
                    break;
                }
            }
            $groups[$i]['purchased'][$username] = $purchased;
            save_groups($pairsPath, $groups);
            log_activity('PURCHASE_STATUS_UPDATED', "Username: {$username}, Purchased: " . ($purchased ? 'Yes' : 'No'), $username);
            break;
        }
    }
}

$groups = load_groups($pairsPath);
$active = null; foreach ($groups as $g){ if (!empty($g['active'])) { $active = $g; break; } }
// Normalize username for lookup (pairs keys might not be normalized)
$assigned = null;
if ($active && isset($active['pairs'])) {
    foreach ($active['pairs'] as $key => $value) {
        if (normalize_username($key) === $username) {
            $assigned = $value;
            break;
        }
    }
}
// Fetch user record for current values
$allUsers = read_json_assoc($usersPath);
$me = ['interests'=>''];
foreach ($allUsers as $u) { 
    if (normalize_username($u['username'] ?? '') === $username) { 
        $me['interests'] = $u['interests'] ?? ''; 
        break; 
    } 
}
// Purchased current value - check with normalized username
$purchased = false;
if ($active && isset($active['purchased']) && is_array($active['purchased'])) {
    foreach ($active['purchased'] as $key => $val) {
        if (normalize_username($key) === $username) {
            $purchased = !empty($val);
            break;
        }
    }
}
// Recipient interests
$recipientInterests = '';
if ($assigned) {
    foreach ($allUsers as $u) { 
        if (normalize_username($u['username'] ?? '') === normalize_username($assigned)) { 
            $recipientInterests = $u['interests'] ?? ''; 
            break; 
        } 
    }
}
?>
<!doctype html>
<html lang="<?= lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= t('app_title') ?> — <?= t('your_match') ?></title>
  <style>
    :root { --bg:#f7f7fb; --card:#fff; --fg:#222; --muted:#666; --accent:#4a67ff; --red:#e74c3c; --green:#2ecc71; }
    * { box-sizing: border-box; }
    body { margin:0; font:16px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:linear-gradient(180deg,#f8fbff 0%,#ffffff 60%); color:var(--fg); overflow-x:hidden; }
    .wrap { position:relative; min-height:100vh; display:grid; place-items:center; padding:24px; }
    .card { width:100%; max-width:820px; background:var(--card); border-radius:14px; box-shadow:0 16px 35px rgba(0,0,0,.08); padding:32px; border:2px solid transparent; background-clip:padding-box; position:relative; }
    .card:before{ content:""; position:absolute; inset:-2px; border-radius:16px; padding:2px; background:linear-gradient(135deg,var(--red),var(--green)); -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0); -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none; }
    .greeting { font-size:32px; font-weight:800; margin:0 0 24px; text-align:center; letter-spacing:1px; }
    .greeting-text { background:linear-gradient(135deg, #4666ff, #e74c3c); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
    .section-title { font-size:14px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--muted); margin:0 0 8px; }
    .christmas-section { background:linear-gradient(135deg, #dc2626 0%, #16a34a 100%); border-radius:12px; padding:16px 20px; margin:0 0 24px; position:relative; overflow:hidden; box-shadow:0 4px 15px rgba(220,38,38,0.2); }
    .christmas-section:before { content:''; position:absolute; top:-50%; left:-50%; width:200%; height:200%; background:repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,0.05) 10px, rgba(255,255,255,0.05) 20px); animation:shimmer 3s linear infinite; }
    @keyframes shimmer { 0% { transform:translate(0,0); } 100% { transform:translate(50px,50px); } }
    .christmas-title { font-size:16px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#fff; margin:0 0 8px; text-shadow:2px 2px 4px rgba(0,0,0,0.3); display:flex; align-items:center; gap:8px; }
    .christmas-title:before { content:'🎄'; font-size:20px; }
    .christmas-title:after { content:'🎅'; font-size:20px; }
    .draw-name { font-size:24px; font-weight:800; color:#fff; margin:0; text-shadow:2px 2px 4px rgba(0,0,0,0.3); display:flex; align-items:center; gap:8px; }
    .draw-name:before { content:'🎁'; font-size:28px; }
    .draw-name:after { content:'✨'; font-size:20px; }
    .giftee-card { background:linear-gradient(135deg, #f8fbff 0%, #ffffff 100%); border:2px solid #e8ecff; border-radius:12px; padding:24px; margin:16px 0 24px; text-align:center; }
    .giftee-name { font-size:36px; font-weight:800; color:var(--accent); margin:12px 0; letter-spacing:1px; }
    .status-section { margin-top:16px; }
    .status-badge { display:inline-block; padding:8px 16px; border-radius:20px; font-size:14px; font-weight:600; margin-bottom:8px; }
    .status-purchased { background:#d4edda; color:#155724; }
    .status-not-purchased { background:#f8d7da; color:#721c24; }
    .purchase-form { margin-top:8px; }
    .purchase-toggle { display:flex; align-items:center; justify-content:center; gap:10px; }
    .purchase-toggle input[type=checkbox] { width:22px; height:22px; cursor:pointer; accent-color:var(--accent); }
    .purchase-toggle label { margin:0; font-size:15px; font-weight:500; cursor:pointer; color:var(--fg); }
    .interests-box { background:#fafbff; border-left:4px solid var(--accent); border-radius:8px; padding:16px; margin:16px 0; }
    .interests-title { font-size:15px; font-weight:500; color:var(--fg); margin:0 0 12px; line-height:1.5; }
    .interests-content { font-size:16px; line-height:1.6; color:var(--fg); white-space:pre-wrap; }
    .interests-empty { color:var(--muted); font-style:italic; }
    .btn { display:inline-block; margin-top:12px; padding:12px 24px; background:linear-gradient(135deg, #4666ff, #6a8bff); color:#fff; border-radius:10px; text-decoration:none; border:0; cursor:pointer; box-shadow:0 6px 14px rgba(74,103,255,.25); font-weight:600; }
    .btn:hover{ filter:brightness(1.05); transform:translateY(-1px); }
    textarea { width:100%; min-height:120px; padding:12px; border:2px solid #e0e0e0; border-radius:8px; font-size:15px; font-family:inherit; transition:border-color 0.2s; }
    textarea:focus { outline:none; border-color:var(--accent); }
    label { font-size:14px; display:block; margin:16px 0 8px; font-weight:600; color:var(--fg); }
    .checkbox-label { display:flex; align-items:center; gap:8px; margin:16px 0; cursor:pointer; }
    .checkbox-label input[type=checkbox] { width:20px; height:20px; cursor:pointer; }
    .row { display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; }
    .col { flex: 1 1 300px; }
    .col-main { flex: 1 1 400px; }
    .col-side { flex: 1 1 300px; }
    .settings-section { background:#fafbff; border-radius:12px; padding:20px; margin-top:24px; }
    .garland{position:absolute; top:8px; left:50%; transform:translateX(-50%); width:94%; height:8px; background:repeating-linear-gradient(90deg, #e74c3c 0 12px, #27ae60 12px 24px, #f1c40f 24px 36px, #3498db 36px 48px); border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); pointer-events:none;}    
    .flake{position:fixed; top:-2vh; color:#9cc3ff; opacity:.9; pointer-events:none; z-index:0; animation: fall linear forwards;}
    @keyframes fall { to { transform: translateY(105vh) rotate(360deg); opacity:.95; } }
    .card{ z-index:1 }
    hr { border:none; border-top:1px solid #eee; margin:32px 0; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:12px; border-bottom:1px solid #eee; }
    th { font-weight:600; color:var(--muted); font-size:13px; text-transform:uppercase; letter-spacing:0.5px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="garland"></div>
    <div class="card">
      <div class="greeting"><span class="greeting-text"><?= t('hey') ?> <?= strtoupper(htmlspecialchars($username)) ?>!</span> 🎁</div>
      
      <?php if ($active): ?>
        <div class="christmas-section">
          <div class="christmas-title"><?= t('current_active_drawing') ?></div>
          <div class="draw-name"><?= htmlspecialchars($active['name']) ?></div>
        </div>
        <?php if (isset($active['budget']) || isset($active['deadline'])): ?>
          <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
            <?php if (isset($active['budget'])): ?>
              <div style="background:#fff9e6; border:1px solid #ffe066; border-radius:8px; padding:10px 14px; font-size:14px; color:#856404;">
                <strong style="display:block; margin-bottom:2px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:#856404;"><?= t('budget') ?></strong>
                <span style="font-weight:600; font-size:15px;"><?= number_format($active['budget'], 0, ',', ' ') ?> kr</span>
              </div>
            <?php endif; ?>
            <?php if (isset($active['deadline'])): 
              try {
                // Parse deadline with timezone awareness
                $deadlineDt = new DateTime($active['deadline']);
                $today = new DateTime('now', new DateTimeZone('UTC'));
                $diff = $today->diff($deadlineDt);
                $daysLeft = $diff->invert ? -$diff->days : $diff->days;
                $dateStr = $deadlineDt->format('Y-m-d');
                $isUrgent = $daysLeft >= 0 && $daysLeft < 7;
                $bgColor = $isUrgent ? '#ffe6e6' : '#fff9e6';
                $borderColor = $isUrgent ? '#ff9999' : '#ffe066';
                $textColor = $isUrgent ? '#721c24' : '#856404';
              } catch (Exception $e) {
                // Fallback for invalid dates
                $dateStr = $active['deadline'];
                $daysLeft = null;
                $isUrgent = false;
                $bgColor = '#fff9e6';
                $borderColor = '#ffe066';
                $textColor = '#856404';
              }
            ?>
              <div style="background:<?= $bgColor ?>; border:1px solid <?= $borderColor ?>; border-radius:8px; padding:10px 14px; font-size:14px; color:<?= $textColor ?>;">
                <strong style="display:block; margin-bottom:2px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px; color:<?= $textColor ?>;"><?= t('deadline') ?></strong>
                <span style="font-weight:600; font-size:15px;"><?= htmlspecialchars($dateStr) ?><?php if ($daysLeft !== null && $daysLeft >= 0): ?> <span style="font-weight:500;">(<?= $daysLeft === 0 ? t('today') : ($daysLeft === 1 ? t('day_left') : sprintf(t('days_left'), $daysLeft)) ?>)</span><?php endif; ?></span>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        
        <?php if ($assigned): ?>
          <div class="giftee-card">
            <div class="section-title" style="margin-bottom:8px;"><?= t('your_chosen_giftee') ?></div>
            <div class="giftee-name">🎁 <?= htmlspecialchars($assigned) ?></div>
            <div class="status-section">
              <div class="status-badge <?= $purchased ? 'status-purchased' : 'status-not-purchased' ?>">
                <?= $purchased ? '✅ ' . t('purchased_status') : '❌ ' . t('not_purchased_yet') ?>
              </div>
              <form method="post" class="purchase-form" onsubmit="this.querySelector('input[type=submit]').style.display='none';">
                <?= csrf_field() ?>
                <input type="hidden" name="interests" value="<?= htmlspecialchars($me['interests']) ?>">
                <div class="purchase-toggle">
                  <input type="checkbox" name="purchased" value="1" id="purchased-check" <?php if ($purchased) echo 'checked'; ?> onchange="this.form.submit();">
                  <label for="purchased-check"><?= t('ive_purchased') ?></label>
                </div>
                <input type="submit" style="display:none;">
              </form>
            </div>
          </div>
          
          <div class="interests-box">
            <div class="interests-title" style="margin-bottom:12px;"><?= sprintf(t('gift_inspiration'), htmlspecialchars($assigned)) ?></div>
            <div class="interests-content <?= $recipientInterests === '' ? 'interests-empty' : '' ?>">
              <?= $recipientInterests !== '' ? nl2br(htmlspecialchars($recipientInterests)) : '<span style="color:var(--muted); font-style:italic;">' . sprintf(t('no_interests_yet'), htmlspecialchars($assigned)) . '</span>' ?>
            </div>
          </div>
        <?php else: ?>
          <div class="giftee-card">
            <div style="font-size:18px; color:var(--muted);"><?= t('not_in_draw') ?></div>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="giftee-card">
          <div style="font-size:18px; color:var(--muted);"><?= t('no_draw_yet') ?></div>
        </div>
      <?php endif; ?>

      <div class="settings-section">
        <form method="post">
          <?= csrf_field() ?>
          <div style="margin-bottom:12px; font-size:15px; font-weight:500; color:var(--fg); line-height:1.5;">
            <?= t('your_interests_help') ?>
          </div>
          <textarea id="interests" name="interests" maxlength="1000" placeholder="e.g., coffee, hiking, books, board games..."><?= htmlspecialchars($me['interests']) ?></textarea>
          <?php if ($assigned): ?>
            <input type="hidden" name="purchased" value="<?= $purchased ? '1' : '0' ?>">
          <?php endif; ?>
          <button class="btn" type="submit">✨ <?= t('save') ?> ✨</button>
        </form>
      </div>

      <?php if ($groups && count($groups) > 1): ?>
        <hr>
        <div class="section-title"><?= t('past_draws') ?></div>
        <table>
          <thead>
            <tr>
              <th><?= t('name') ?></th>
              <th><?= t('assigned_recipient') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($groups as $g): if (!empty($g['active'])) continue; $rec = $g['pairs'][$username] ?? null; ?>
            <tr>
              <td><?= htmlspecialchars($g['name']) ?></td>
              <td><?= $rec ? htmlspecialchars($rec) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div style="margin-top:14px;"><a class="btn" href="index.php?logout=1">✨ <?= t('logout') ?> ✨</a></div>
    </div>
  </div>
  <script>
    // Simple snow effect
    (function(){
      const make = () => {
        const f = document.createElement('div');
        f.className = 'flake';
        f.textContent = Math.random() < 0.2 ? '❄️' : '✦';
        const size = 10 + Math.random()*12;
        f.style.left = (Math.random()*100) + 'vw';
        f.style.fontSize = size + 'px';
        f.style.animationDuration = (6 + Math.random()*7) + 's';
        f.style.animationDelay = (Math.random()*3) + 's';
        document.body.appendChild(f);
        setTimeout(()=>{ f.remove(); }, 13000);
      };
      for (let i=0;i<20;i++) make();
      setInterval(make, 900);
    })();
  </script>
</body>
</html>

<?php
/*
README: draw.php — Creates a named Secret Santa draw (group).
- Requires admin session. POST fields:
  - action=draw_group, group_name, members[]
- Generates a derangement for selected members and saves into data/pairs.json as
  a list of groups with fields: name, active, participants, pairs, purchased, created.
*/

session_start();
if (empty($_SESSION['admin'])) { header('Location: index.php'); exit; }

$usersPath = __DIR__ . '/data/users.json';
$pairsPath = __DIR__ . '/data/pairs.json';

function read_json_assoc($p){ if (!file_exists($p)) return []; $d = json_decode(file_get_contents($p), true); return is_array($d) ? $d : []; }
function write_json_assoc($p, $d){ file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX); }
function load_groups($path){ $raw = read_json_assoc($path); return (isset($raw['groups']) && is_array($raw['groups'])) ? $raw['groups'] : []; }
function save_groups($path, $groups){ write_json_assoc($path, ['groups'=>$groups]); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'draw_group') {
    header('Location: admin.php'); exit;
}

$gname = trim((string)($_POST['group_name'] ?? ''));
$members = isset($_POST['members']) && is_array($_POST['members']) ? array_values(array_map('strval', $_POST['members'])) : [];
$budget = isset($_POST['budget']) && $_POST['budget'] !== '' ? floatval($_POST['budget']) : null;
$deadline = isset($_POST['deadline']) && $_POST['deadline'] !== '' ? trim($_POST['deadline']) : null;

// Remove admin from members (admin cannot participate in draws)
$members = array_values(array_filter($members, function($m) { return strtolower($m) !== 'admin'; }));

if (!$gname) { $_SESSION['flash_err'] = 'Please supply a draw name.'; header('Location: admin.php'); exit; }
if (count($members) < 2) { $_SESSION['flash_err'] = 'Select at least two participants.'; header('Location: admin.php'); exit; }

// Ensure members exist in users list
$usersArr = read_json_assoc($usersPath);
$usernames = array_map(function($u){ return $u['username']; }, $usersArr);
foreach ($members as $m) { if (!in_array($m, $usernames, true)) { $_SESSION['flash_err'] = 'Unknown participant: '.$m; header('Location: admin.php'); exit; } }

// Load groups and ensure unique name
$groups = load_groups($pairsPath);
foreach ($groups as $g) { if (strcasecmp($g['name'], $gname) === 0) { $_SESSION['flash_err'] = 'A draw with that name already exists.'; header('Location: admin.php'); exit; } }

// Create derangement
$users = $members;
$recipients = $users;
shuffle($recipients);
$n = count($users);
for ($i = 0; $i < $n; $i++) {
    if ($users[$i] === $recipients[$i]) {
        $j = ($i + 1) % $n;
        $tmp = $recipients[$i];
        $recipients[$i] = $recipients[$j];
        $recipients[$j] = $tmp;
    }
}
$map = [];
for ($i=0;$i<$n;$i++){ $map[$users[$i]] = $recipients[$i]; }
foreach ($map as $a=>$b){ if ($a === $b) { $_SESSION['flash_err'] = 'Draw failed. Try again.'; header('Location: admin.php'); exit; } }

// Deactivate previous active, add new as active
for ($i=0; $i<count($groups); $i++){ $groups[$i]['active'] = false; }
$groupData = [
  'name'=>$gname,
  'active'=>true,
  'participants'=>$users,
  'pairs'=>$map,
  'purchased'=>array_fill_keys($users, false),
  'created'=>date('c')
];
if ($budget !== null) { $groupData['budget'] = $budget; }
if ($deadline !== null) { $groupData['deadline'] = $deadline; }
$groups[] = $groupData;
save_groups($pairsPath, $groups);

$_SESSION['flash_ok'] = 'Draw "'.$gname.'" created for '.count($users).' participants.';
header('Location: admin.php');
exit;

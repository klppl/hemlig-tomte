<?php
/*
README: draw.php — Creates a named Secret Santa draw (group).
- Requires admin session. POST fields:
  - action=draw_group, group_name, members[]
- Generates a derangement for selected members and saves into data/pairs.json as
  a list of groups with fields: name, active, participants, pairs, purchased, created.
*/

require_once __DIR__ . '/inc.php';
if (empty($_SESSION['admin'])) { header('Location: index.php'); exit; }

$usersPath = __DIR__ . '/data/users.json';
$pairsPath = __DIR__ . '/data/pairs.json';

// These functions are now defined in inc.php with retry logic and corruption recovery
// Keep local definitions only if inc.php functions aren't available
if (!function_exists('read_json_assoc')) {
    function read_json_assoc($p){ if (!file_exists($p)) return []; $d = json_decode(file_get_contents($p), true); return is_array($d) ? $d : []; }
}
if (!function_exists('write_json_assoc')) {
    function write_json_assoc($p, $d){ 
        $result = file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($result !== false && file_exists($p)) {
            chmod($p, DATA_FILE_PERMISSIONS);
        }
        return $result;
    }
}
function load_groups($path){ $raw = read_json_assoc($path); return (isset($raw['groups']) && is_array($raw['groups'])) ? $raw['groups'] : []; }
function save_groups($path, $groups){ write_json_assoc($path, ['groups'=>$groups]); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'draw_group') {
    header('Location: admin.php'); exit;
}

// CSRF protection
if (!validate_csrf()) {
    $_SESSION['flash_err'] = 'Invalid security token. Please try again.';
    header('Location: admin.php'); exit;
}

$gname = trim((string)($_POST['group_name'] ?? ''));
$members = isset($_POST['members']) && is_array($_POST['members']) ? array_values(array_map('normalize_username', $_POST['members'])) : [];

// Budget validation
$budget = null;
if (isset($_POST['budget']) && $_POST['budget'] !== '') {
    $budgetVal = floatval($_POST['budget']);
    if ($budgetVal < 0) {
        $_SESSION['flash_err'] = 'Budget cannot be negative.';
        header('Location: admin.php');
        exit;
    }
    if ($budgetVal > 1000000) { // Reasonable max limit
        $_SESSION['flash_err'] = 'Budget is too large. Maximum is 1,000,000.';
        header('Location: admin.php');
        exit;
    }
    $budget = $budgetVal;
}

// Deadline validation with timezone
$deadline = null;
if (isset($_POST['deadline']) && $_POST['deadline'] !== '') {
    $deadlineInput = trim($_POST['deadline']);
    // Validate date format (YYYY-MM-DD)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadlineInput)) {
        // Create DateTime object with timezone
        try {
            $dt = new DateTime($deadlineInput, new DateTimeZone('UTC'));
            // Store as ISO 8601 with timezone
            $deadline = $dt->format('c'); // ISO 8601 format includes timezone
        } catch (Exception $e) {
            $_SESSION['flash_err'] = 'Invalid date format.';
            header('Location: admin.php');
            exit;
        }
    } else {
        $_SESSION['flash_err'] = 'Invalid date format. Use YYYY-MM-DD.';
        header('Location: admin.php');
        exit;
    }
}

// Remove admin from members (admin cannot participate in draws)
$members = array_values(array_filter($members, function($m) { return normalize_username($m) !== 'admin'; }));

if (!$gname) { $_SESSION['flash_err'] = 'Please supply a draw name.'; header('Location: admin.php'); exit; }
if (count($members) < 2) { $_SESSION['flash_err'] = 'Select at least two participants.'; header('Location: admin.php'); exit; }

// Ensure members exist in users list
$usersArr = read_json_assoc($usersPath);
$usernames = array_map(function($u){ return normalize_username($u['username']); }, $usersArr);
foreach ($members as $m) { 
    if (!in_array($m, $usernames, true)) { 
        $_SESSION['flash_err'] = 'Unknown participant: '.htmlspecialchars($m); 
        header('Location: admin.php'); 
        exit; 
    } 
}

// Load groups and ensure unique name (case-insensitive)
$groups = load_groups($pairsPath);
$normalizedGname = strtolower(trim($gname));
foreach ($groups as $g) { 
    $normalizedExisting = strtolower(trim($g['name'] ?? ''));
    if ($normalizedExisting === $normalizedGname) { 
        $_SESSION['flash_err'] = 'A draw with that name already exists.'; 
        header('Location: admin.php'); 
        exit; 
    } 
}

// Create derangement using Sattolo's algorithm (guaranteed valid derangement)
// This ensures no one gets themselves as recipient
function generate_derangement($array) {
    $n = count($array);
    if ($n < 2) {
        return $array; // Can't derange less than 2 items
    }
    
    // Create a copy to shuffle
    $shuffled = $array;
    
    // Sattolo's algorithm: generates a random derangement
    // It's a modified Fisher-Yates that ensures no fixed points
    for ($i = $n - 1; $i > 0; $i--) {
        // Pick random index from 0 to i-1 (not i, ensuring no fixed point)
        $j = random_int(0, $i - 1);
        // Swap
        $tmp = $shuffled[$i];
        $shuffled[$i] = $shuffled[$j];
        $shuffled[$j] = $tmp;
    }
    
    return $shuffled;
}

// Generate derangement - Sattolo's algorithm guarantees valid result
$users = $members;
$recipients = generate_derangement($users);

// Build mapping
$map = [];
for ($i = 0; $i < count($users); $i++) {
    $map[$users[$i]] = $recipients[$i];
}

// Validation: verify derangement is correct
// (Sattolo's algorithm guarantees this, but we validate for safety/logging)
$validationErrors = [];

// Check 1: No self-matches (no one gets themselves)
foreach ($map as $giver => $recipient) {
    if ($giver === $recipient) {
        $validationErrors[] = "Self-match detected: {$giver}";
    }
}

// Check 2: All users have unique recipients (no duplicates)
$recipientCounts = array_count_values($recipients);
foreach ($recipientCounts as $recipient => $count) {
    if ($count > 1) {
        $validationErrors[] = "Duplicate recipient: {$recipient} appears {$count} times";
    }
}

// Check 3: All users are assigned
if (count($map) !== count($users)) {
    $validationErrors[] = "Mapping incomplete: " . count($map) . " pairs for " . count($users) . " users";
}

// If validation fails, log and error (should never happen with Sattolo's algorithm)
if (!empty($validationErrors)) {
    $errorMsg = implode('; ', $validationErrors);
    log_activity('DRAW_VALIDATION_FAILED', "Draw: {$gname}, Errors: {$errorMsg}", 'admin');
    $_SESSION['flash_err'] = 'Draw validation failed. Please try again.';
    header('Location: admin.php');
    exit;
}

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

log_activity('DRAW_CREATED', "Draw: {$gname}, Participants: " . count($users), 'admin');
$_SESSION['flash_ok'] = 'Draw "'.$gname.'" created for '.count($users).' participants.';
header('Location: admin.php');
exit;

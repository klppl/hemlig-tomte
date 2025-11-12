<?php
// inc.php — minimal i18n helper + security functions
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}
if (isset($_GET['lang'])) { $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'sv'; }
if (empty($_SESSION['lang'])) { $_SESSION['lang'] = 'sv'; }

// Security constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('MIN_PASSWORD_LENGTH', 4);
define('DATA_DIR_PERMISSIONS', 0755);
define('DATA_FILE_PERMISSIONS', 0644);

// CSRF Protection
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function validate_csrf($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Rate Limiting
function check_rate_limit($identifier, $max_attempts = MAX_LOGIN_ATTEMPTS, $lockout_time = LOGIN_LOCKOUT_TIME) {
    $key = 'rate_limit_' . $identifier;
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'locked_until' => 0];
    
    // Check if locked
    if ($attempts['locked_until'] > time()) {
        $remaining = $attempts['locked_until'] - time();
        return ['allowed' => false, 'remaining' => $remaining];
    }
    
    // Reset if lockout expired
    if ($attempts['locked_until'] > 0 && $attempts['locked_until'] <= time()) {
        $attempts = ['count' => 0, 'locked_until' => 0];
    }
    
    return ['allowed' => true, 'attempts' => $attempts];
}

function record_failed_attempt($identifier, $max_attempts = MAX_LOGIN_ATTEMPTS, $lockout_time = LOGIN_LOCKOUT_TIME) {
    $key = 'rate_limit_' . $identifier;
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'locked_until' => 0];
    
    $attempts['count']++;
    
    if ($attempts['count'] >= $max_attempts) {
        $attempts['locked_until'] = time() + $lockout_time;
    }
    
    $_SESSION[$key] = $attempts;
    return $attempts;
}

function clear_rate_limit($identifier) {
    $key = 'rate_limit_' . $identifier;
    unset($_SESSION[$key]);
}

// Session Security
function secure_session_regenerate() {
    session_regenerate_id(true);
    // Regenerate CSRF token on session regeneration
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Input Validation
function normalize_username($username) {
    return trim(strtolower($username));
}

function validate_username($username) {
    $normalized = normalize_username($username);
    return preg_match('/^[a-z0-9_\-]{2,32}$/', $normalized);
}

function validate_password($password) {
    return strlen($password) >= MIN_PASSWORD_LENGTH;
}

// Activity Logging
function log_activity($action, $details = '', $username = null) {
    $logPath = __DIR__ . '/data/activity.log';
    if ($username === null) {
        if (isset($_SESSION['admin']) && $_SESSION['admin']) {
            $username = 'admin';
        } elseif (isset($_SESSION['user'])) {
            $username = $_SESSION['user'];
        } else {
            $username = 'system';
        }
    }
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logEntry = "[{$timestamp}] [{$ip}] [{$username}] {$action}";
    if ($details) {
        $logEntry .= " - " . $details;
    }
    $logEntry .= "\n";
    
    // Append to log file
    @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    if (file_exists($logPath)) {
        chmod($logPath, DATA_FILE_PERMISSIONS);
    }
}

function get_activity_log($lines = 100) {
    $logPath = __DIR__ . '/data/activity.log';
    if (!file_exists($logPath)) {
        return [];
    }
    
    $file = file($logPath);
    if ($file === false) {
        return [];
    }
    
    // Get last N lines
    $file = array_slice($file, -$lines);
    return array_map('trim', $file);
}

// Helper functions for reading/writing JSON (only declare if not already defined)
if (!function_exists('read_json_assoc')) {
    function read_json_assoc($path) {
        if (!file_exists($path)) return [];
        
        // Try to read with retry logic
        $maxRetries = 3;
        $retryDelay = 100000; // 100ms in microseconds
        
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $content = @file_get_contents($path);
            if ($content !== false) {
                $d = json_decode($content, true);
                
                // Check for JSON errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // JSON corruption detected - try to recover
                    log_activity('JSON_CORRUPTION_DETECTED', "File: {$path}, Error: " . json_last_error_msg(), 'system');
                    
                    // Try to restore from backup if available
                    $backupPath = $path . '.backup';
                    if (file_exists($backupPath)) {
                        $backupContent = @file_get_contents($backupPath);
                        if ($backupContent !== false) {
                            $backupData = json_decode($backupContent, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($backupData)) {
                                log_activity('JSON_RECOVERED_FROM_BACKUP', "File: {$path}", 'system');
                                return $backupData;
                            }
                        }
                    }
                    
                    // If no backup or backup also corrupted, return empty array
                    log_activity('JSON_RECOVERY_FAILED', "File: {$path}", 'system');
                    return [];
                }
                
                return is_array($d) ? $d : [];
            }
            
            // Wait before retry
            if ($attempt < $maxRetries - 1) {
                usleep($retryDelay);
            }
        }
        
        return [];
    }
}

if (!function_exists('write_json_assoc')) {
    function write_json_assoc($path, $data) {
        $maxRetries = 5;
        $retryDelay = 100000; // 100ms in microseconds
        
        // Create backup before writing
        if (file_exists($path)) {
            $backupPath = $path . '.backup';
            @copy($path, $backupPath);
            if (file_exists($backupPath)) {
                chmod($backupPath, DATA_FILE_PERMISSIONS);
            }
        }
        
        $jsonData = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if ($jsonData === false) {
            log_activity('JSON_ENCODE_FAILED', "File: {$path}, Error: " . json_last_error_msg(), 'system');
            return false;
        }
        
        // Retry logic for file locking
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $result = @file_put_contents($path, $jsonData, LOCK_EX);
            
            if ($result !== false) {
                // Verify the write was successful by reading it back
                $verify = @file_get_contents($path);
                if ($verify === $jsonData) {
                    chmod($path, DATA_FILE_PERMISSIONS);
                    return $result;
                }
            }
            
            // Wait before retry (exponential backoff)
            if ($attempt < $maxRetries - 1) {
                usleep($retryDelay * ($attempt + 1));
            }
        }
        
        log_activity('FILE_WRITE_FAILED', "File: {$path} after {$maxRetries} attempts", 'system');
        return false;
    }
}

// Password Reset Requests
if (!function_exists('create_password_reset_request')) {
    function create_password_reset_request($username, $usersPath) {
        $resetRequestsPath = __DIR__ . '/data/reset_requests.json';
        $requests = read_json_assoc($resetRequestsPath);
        
        // Check if request already exists
        foreach ($requests as $req) {
            if (normalize_username($req['username']) === normalize_username($username) && isset($req['status']) && $req['status'] === 'pending') {
                return false; // Request already exists
            }
        }
        
        // Add new request
        $requests[] = [
            'username' => normalize_username($username),
            'requested_at' => date('c'),
            'status' => 'pending'
        ];
        
        write_json_assoc($resetRequestsPath, $requests);
        log_activity('PASSWORD_RESET_REQUESTED', "Username: {$username}", $username);
        return true;
    }
}

if (!function_exists('get_pending_reset_requests')) {
    function get_pending_reset_requests() {
        $resetRequestsPath = __DIR__ . '/data/reset_requests.json';
        $requests = read_json_assoc($resetRequestsPath);
        return array_filter($requests, function($req) {
            return isset($req['status']) && $req['status'] === 'pending';
        });
    }
}

if (!function_exists('remove_reset_request')) {
    function remove_reset_request($username) {
        $resetRequestsPath = __DIR__ . '/data/reset_requests.json';
        $requests = read_json_assoc($resetRequestsPath);
        $requests = array_filter($requests, function($req) use ($username) {
            return normalize_username($req['username']) !== normalize_username($username) || 
                   (isset($req['status']) && $req['status'] !== 'pending');
        });
        write_json_assoc($resetRequestsPath, array_values($requests));
    }
}

$TR = [
  'sv' => [
    'app_title' => 'Secret Santa',
    'login_title' => 'Logga in',
    'login_sub' => 'Logga in som admin eller deltagare.',
    'username' => 'Användarnamn',
    'password' => 'Lösenord',
    'login' => 'Logga in',
    'new_here' => 'Ny användare? Be admin skapa ett konto.',
    'logout' => 'Logga ut',
    'admin_panel' => 'Adminpanel',
    'manage_participants' => 'Hantera deltagare och skapa namngivna dragningar.',
    'add_user' => 'Lägg till användare',
    'password_optional' => 'Lösenord (lämna tomt för slumpat)',
    'users' => 'Användare',
    'action' => 'Åtgärd',
    'delete' => 'Radera',
    'change_password' => 'Byt lösenord',
    'create_draw' => 'Skapa dragning',
    'draw_name' => 'Namn på dragning',
    'run_draw' => 'Kör dragning',
    'select_participants' => 'Välj deltagare',
    'select_all' => 'Markera alla',
    'existing_draws' => 'Befintliga dragningar',
    'name' => 'Namn',
    'participants' => 'Deltagare',
    'active' => 'Aktiv',
    'actions' => 'Åtgärder',
    'set_active' => 'Aktivera',
    'archive' => 'Arkivera',
    'delete_draw' => 'Radera',
    'tip_draws' => 'Tips: Skapa flera namngivna dragningar som "2025", "2026". Admin kan byta aktiv eller radera.',
    'current_status' => 'Aktuell status',
    'status_sub' => 'Resultat, intressen och inköpt-status för aktiv dragning.',
    'no_active_draw' => 'Ingen aktiv dragning vald.',
    'user' => 'Användare',
    'assigned_recipient' => 'Tilldelad mottagare',
    'interests' => 'Intressen / Hobbys',
    'purchased' => 'Inköpt',
    'hello' => 'Hej',
    'current_draw' => 'Aktuell dragning',
    'your_match' => 'Din mottagare',
    'no_draw_yet' => 'Ingen aktiv dragning ännu. Titta tillbaka senare.',
    'not_in_draw' => 'Du är inte med i den aktiva dragningen.',
    'your_interests' => 'Dina intressen / hobbys (tips till din Secret Santa)',
    'ive_purchased' => 'Jag har köpt min present',
    'save' => 'Spara',
    'past_draws' => 'Tidigare dragningar',
    'recipient_interests' => 'Mottagarens intressen / hobbys',
    'purchased_q' => 'Inköpt?',
    'welcome' => 'Välkommen',
    'setup_title' => 'Skapa admin-konto',
    'setup_sub' => 'Detta är första gången du besöker denna sida. Skapa ditt admin-konto för att komma igång.',
    'create_admin' => 'Skapa admin-konto',
    'password_confirm' => 'Bekräfta lösenord',
    'admin_username_hint' => 'Admin-användarnamnet är fastställt som "admin"',
    'hey' => 'HEJ',
    'current_active_drawing' => 'Hemlig tomte',
    'your_chosen_giftee' => 'Din utvalda mottagare',
    'this_persons_hobbies' => 'Denna persons intressen och hobbys',
    'gift_inspiration' => 'Här är lite inspiration för ditt köp. Detta är vad %s har sagt om sina intressen och hobbys:',
    'no_interests_yet' => '%s har inte angett några intressen ännu.',
    'your_interests_help' => 'Hjälp din Secret Santa med några idéer! Berätta gärna mer om dina intressen och hobbys. Allt hjälper!',
    'gift_status' => 'Presentstatus',
    'not_purchased_yet' => 'Inte köpt ännu',
    'purchased_status' => 'Köpt',
    'budget' => 'Budget',
    'max_cost' => 'Maximal kostnad',
    'deadline' => 'Deadline',
    'gift_deadline' => 'Presentdeadline',
    'budget_info' => 'Budget: %s',
    'deadline_info' => 'Deadline: %s',
    'days_left' => '%d dagar kvar',
    'day_left' => '1 dag kvar',
    'today' => 'Idag',
    'forgot_password' => 'Glömt lösenord?',
    'reset_password' => 'Återställ lösenord',
    'reset_password_title' => 'Återställ lösenord',
    'reset_password_sub' => 'Ange ditt användarnamn för att skicka en återställningsbegäran till admin.',
    'reset_request_sent' => 'Återställningsbegäran har skickats till admin. Du kommer att meddelas när den är godkänd.',
    'new_password' => 'Nytt lösenord',
    'password_reset_success' => 'Lösenordet har återställts. Du kan nu logga in.',
    'register' => 'Registrera',
    'register_title' => 'Registrera ny användare',
    'register_sub' => 'Skapa ett konto. Admin måste aktivera ditt konto innan du kan logga in.',
    'register_success' => 'Registrering lyckades! Ditt konto väntar på admin-aktivering.',
    'account_pending' => 'Ditt konto väntar på aktivering av admin.',
    'account_inactive' => 'Ditt konto är inte aktiverat ännu. Kontakta admin.',
    'activate_user' => 'Aktivera',
    'deactivate_user' => 'Inaktivera',
    'pending_users' => 'Väntande användare',
    'activity_log' => 'Aktivitetslogg',
    'view_activity_log' => 'Visa aktivitetslogg',
    'no_activity' => 'Ingen aktivitet att visa.',
    'user_registered' => 'Användare registrerad',
    'user_activated' => 'Användare aktiverad',
    'user_deactivated' => 'Användare inaktiverad',
    'password_changed' => 'Lösenord ändrat',
    'draw_created' => 'Dragning skapad',
    'draw_archived' => 'Dragning arkiverad',
    'draw_deleted' => 'Dragning raderad'
  ],
  'en' => [
    'app_title' => 'Secret Santa',
    'login_title' => 'Log in',
    'login_sub' => 'Log in as admin or participant.',
    'username' => 'Username',
    'password' => 'Password',
    'login' => 'Log In',
    'new_here' => 'New here? Ask admin to create your account.',
    'logout' => 'Log out',
    'admin_panel' => 'Admin Panel',
    'manage_participants' => 'Manage participants and create named draws.',
    'add_user' => 'Add User',
    'password_optional' => 'Password (leave blank for random)',
    'users' => 'Users',
    'action' => 'Action',
    'delete' => 'Delete',
    'change_password' => 'Change Password',
    'create_draw' => 'Create Draw',
    'draw_name' => 'Draw name',
    'run_draw' => 'Run Draw',
    'select_participants' => 'Select participants',
    'select_all' => 'Select all',
    'existing_draws' => 'Existing Draws',
    'name' => 'Name',
    'participants' => 'Participants',
    'active' => 'Active',
    'actions' => 'Actions',
    'set_active' => 'Set Active',
    'archive' => 'Archive',
    'delete_draw' => 'Delete',
    'tip_draws' => 'Tip: Create named draws like "2025", "2026". Admin can switch active or delete.',
    'current_status' => 'Current Status',
    'status_sub' => 'Active draw results with interests and purchase status.',
    'no_active_draw' => 'No active draw selected.',
    'user' => 'User',
    'assigned_recipient' => 'Assigned Recipient',
    'interests' => 'Interests / Hobbies',
    'purchased' => 'Purchased',
    'hello' => 'Hello',
    'current_draw' => 'Current draw',
    'your_match' => 'Your match',
    'no_draw_yet' => 'No active draw yet. Please check back later.',
    'not_in_draw' => 'You are not part of the active draw.',
    'your_interests' => 'Your interests / hobbies (tips for your Secret Santa)',
    'ive_purchased' => 'I’ve purchased my gift',
    'save' => 'Save',
    'past_draws' => 'Past Draws',
    'recipient_interests' => 'Recipient interests / hobbies',
    'purchased_q' => 'Purchased?',
    'welcome' => 'Welcome',
    'setup_title' => 'Create Admin Account',
    'setup_sub' => 'This is your first visit. Create your admin account to get started.',
    'create_admin' => 'Create Admin Account',
    'password_confirm' => 'Confirm Password',
    'admin_username_hint' => 'Admin username is fixed as "admin"',
    'hey' => 'HEY',
    'current_active_drawing' => 'Secret Santa',
    'your_chosen_giftee' => 'Your Chosen Giftee',
    'this_persons_hobbies' => 'This Person\'s Hobbies or Interests',
    'gift_inspiration' => 'Here\'s some inspiration for your purchase. This is what %s said about their hobbies and interests:',
    'no_interests_yet' => '%s hasn\'t provided any interests yet.',
    'your_interests_help' => 'Please help your Secret Santa with some ideas! Tell us more about your hobbies and interests. Anything helps!',
    'gift_status' => 'Gift Status',
    'not_purchased_yet' => 'Not Purchased Yet',
    'purchased_status' => 'Purchased',
    'budget' => 'Budget',
    'max_cost' => 'Maximum Cost',
    'deadline' => 'Deadline',
    'gift_deadline' => 'Gift Deadline',
    'budget_info' => 'Budget: %s',
    'deadline_info' => 'Deadline: %s',
    'days_left' => '%d days left',
    'day_left' => '1 day left',
    'today' => 'Today',
    'forgot_password' => 'Forgot password?',
    'reset_password' => 'Reset Password',
    'reset_password_title' => 'Reset Password',
    'reset_password_sub' => 'Enter your username to send a reset request to admin.',
    'reset_request_sent' => 'Reset request has been sent to admin. You will be notified once it is approved.',
    'new_password' => 'New Password',
    'password_reset_success' => 'Password has been reset. You can now log in.',
    'register' => 'Register',
    'register_title' => 'Register New User',
    'register_sub' => 'Create an account. Admin must activate your account before you can log in.',
    'register_success' => 'Registration successful! Your account is pending admin activation.',
    'account_pending' => 'Your account is pending activation by admin.',
    'account_inactive' => 'Your account is not activated yet. Contact admin.',
    'activate_user' => 'Activate',
    'deactivate_user' => 'Deactivate',
    'pending_users' => 'Pending Users',
    'activity_log' => 'Activity Log',
    'view_activity_log' => 'View Activity Log',
    'no_activity' => 'No activity to display.',
    'user_registered' => 'User registered',
    'user_activated' => 'User activated',
    'user_deactivated' => 'User deactivated',
    'password_changed' => 'Password changed',
    'draw_created' => 'Draw created',
    'draw_archived' => 'Draw archived',
    'draw_deleted' => 'Draw deleted'
  ]
];

function lang() { return $_SESSION['lang'] ?? 'sv'; }
function t($key) { global $TR; $l = lang(); return $TR[$l][$key] ?? ($TR['en'][$key] ?? $key); }


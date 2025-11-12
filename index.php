<?php
require_once 'config.php';
session_name(SESSION_NAME);
session_start();

// ----------------- LOGOUT -----------------
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ----------------- LOGIN -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim(strtolower($_POST['email']));
    $password = $_POST['password'];

    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT username, password, name FROM mailbox WHERE username = ? AND active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "E-Mail nicht gefunden oder deaktiviert!";
        } else {
            $hash = $user['password'];
            $valid = false;

            if (strpos($hash, '{SHA512}') === 0) {
                $expected_b64 = substr($hash, 8);
                $calculated = base64_encode(hash('sha512', $password, true));
                $valid = hash_equals($expected_b64, $calculated);
            } elseif (strpos($hash, '{SHA512-CRYPT}') === 0) {
                $crypt_hash = substr($hash, 14);
                $calculated = crypt($password, $crypt_hash);
                $valid = hash_equals($crypt_hash, $calculated);
            } elseif (strpos($hash, '{BLF-CRYPT}') === 0) {
                $crypt_hash = substr($hash, 11);
                $calculated = crypt($password, $crypt_hash);
                $valid = hash_equals($crypt_hash, $calculated);
            }

            if ($valid) {
                $_SESSION['user'] = $email;
                $_SESSION['name'] = $user['name'] ?? explode('@', $email)[0];
                $_SESSION['is_admin'] = in_array($email, array_map('trim', explode(',', strtolower(ADMIN_EMAILS))));
                header('Location: index.php');
                exit;
            } else {
                $error = "Falsches Passwort!";
            }
        }
    } catch (Exception $e) {
        $error = "DB-Fehler: " . $e->getMessage();
    }
}

// ----------------- LOGIN FORMULAR -----------------
if (!isset($_SESSION['user'])) {
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Quarantäne Login</title>
    <style>
        body{font-family:Arial;background:#f0f2f5;padding:50px;text-align:center;}
        .box{max-width:420px;margin:50px auto;background:#fff;padding:40px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.15);}
        input,button{width:100%;padding:14px;margin:12px 0;border-radius:8px;font-size:16px;}
        button{background:#007cba;color:white;border:none;cursor:pointer;}
        .error{color:#d32f2f;background:#ffebee;padding:12px;border-radius:8px;margin:15px 0;}
    </style></head><body>
        <div class="box">
            <h2>Quarantäne Login</h2>
            <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
            <form method="post">
                <input type="email" name="email" value="<?=htmlspecialchars($_POST['email']??'')?>" placeholder="christian@hummel-web.at" required autofocus>
                <input type="password" name="password" placeholder="Passwort" required>
                <button type="submit" name="login">Anmelden</button>
            </form>
        </div>
    </body></html>
    <?php
    exit;
}

// ----------------- EINGELOGGT -----------------
$user_email = $_SESSION['user'];
$is_admin = $_SESSION['is_admin'];
$user_name = $_SESSION['name'];

// SERVER-ZEITZONE FIXIEREN (z. B. Europe/Vienna)
date_default_timezone_set('Europe/Vienna');  // <-- DEINE ZEITZONE HIER!

$files = glob(QUARANTINE_DIR . "/*.eml");
$quarantine_mails = [];

foreach ($files as $file) {
    $content = @file_get_contents($file);
    if ($content === false) continue;

    preg_match('/^To:.*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/mi', $content, $to_match);
    $to = strtolower($to_match[1] ?? 'unbekannt');

    if ($is_admin || $to === $user_email) {
        preg_match('/^From:.*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/mi', $content, $from_match);
        $from = $from_match[1] ?? 'unbekannt';

        preg_match('/^Subject:\s*(.*)$/mi', $content, $subject_match);
        $subject = $subject_match[1] ?? '(kein Betreff)';

        // DATE AUS HEADER ODER DATEI – IMMER IN SERVER-ZEIT!
        preg_match('/^Date:\s*(.*)$/mi', $content, $date_match);
        $raw_date = $date_match[1] ?? '';

        if ($raw_date && strtotime($raw_date)) {
            $timestamp = strtotime($raw_date);
            $server_date = date('d.m.Y H:i:s', $timestamp);  // DEIN FORMAT!
        } else {
            $server_date = date('d.m.Y H:i:s', filemtime($file));
        }

        $quarantine_mails[] = [
            'file' => $file,
            'to' => $to,
            'from' => $from,
            'subject' => $subject,
            'date' => $server_date,
            'size' => formatBytes(filesize($file)),
            'filename' => basename($file)
        ];
    }
}

// SORTIERUNG: NEUESTE ZUERST
usort($quarantine_mails, function($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});

// ----------------- AKTIONEN -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['file'])) {
    $file = $_POST['file'];
    if (!str_starts_with($file, QUARANTINE_DIR . '/')) {
        $msg = "Ungültiger Pfad!";
        goto end_action;
    }

    if ($_POST['action'] === 'deliver') {
        $content = @file_get_contents($file);
        if ($content === false) {
            $msg = "Datei nicht lesbar!";
            goto end_action;
        }

        $content = preg_replace('/^X-Spam.*\r\n/im', '', $content);
        $content = preg_replace('/^X-Rspamd.*\r\n/im', '', $content);
        $content = "X-Quarantine-Release: yes\r\nX-Spam: No\r\nPrecedence: list\r\n" . $content;

        $tmp = tempnam(sys_get_temp_dir(), 'quar_');
        file_put_contents($tmp, $content);

        $cmd = "/usr/sbin/sendmail -t -i < " . escapeshellarg($tmp);
        exec($cmd . " 2>&1", $output, $return);

        @unlink($tmp);
        @unlink($file);

        if ($return === 0) {
            $msg = "Mail ZUGESTELLT! 100 % ORIGINAL + Server-Zeit!";
        } else {
            $msg = "Fehler: " . implode(" | ", $output);
        }
    }

    if ($_POST['action'] === 'delete') {
        @unlink($file);
        $msg = "Mail gelöscht!";
    }

    end_action:
    header('Location: index.php?msg=' . urlencode($msg));
    exit;
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    for ($i = 0; $bytes > 1024 && $i < count($units)-1; $i++) $bytes /= 1024;
    return round($bytes, 2) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Quarantäne – <?=htmlspecialchars($user_name)?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{font-family:'Segoe UI',Arial,sans-serif;margin:0;background:#f5f7fa;color:#333;}
        .header{background:#007cba;color:white;padding:20px;text-align:center;position:relative;box-shadow:0 4px 10px rgba(0,0,0,0.1);}
        .refresh-btn{background:#fff;color:#007cba;padding:10px 20px;border-radius:30px;font-weight:bold;font-size:15px;cursor:pointer;box-shadow:0 4px 15px rgba(0,0,0,0.2);transition:0.3s;border:2px solid #007cba;}
        .refresh-btn:hover{background:#007cba;color:white;transform:scale(1.05);}
        .container{max-width:1400px;margin:20px auto;padding:20px;background:white;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.1);}
        table{width:100%;border-collapse:collapse;margin-top:20px;font-size:14px;}
        th{background:#007cba;color:white;padding:12px;text-align:left;}
        td{padding:10px;border-bottom:1px solid #eee;}
        tr:hover{background:#f8f9fc;}
        .btn{padding:9px 16px;margin:2px;border:none;border-radius:6px;cursor:pointer;font-weight:bold;font-size:13px;}
        .btn-deliver{background:#28a745;color:white;}
        .btn-delete{background:#dc3545;color:white;}
        .btn-logout{float:right;background:#666;color:white;margin-top:8px;}
        .msg{background:#d4edda;color:#155724;padding:15px;border-radius:8px;margin:15px 0;font-weight:bold;}
        .error{background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin:15px 0;}
        .footer{text-align:center;margin:40px 0 20px;color:#777;font-size:0.9em;}
        code{background:#eee;padding:2px 6px;border-radius:4px;font-family:monospace;}
        .to {color:#d32f2f;font-weight:bold;}
        .from {color:#155724;font-weight:bold;}
        .date {font-family:monospace;font-weight:bold;color:#444;}
    </style>
</head>
<body>

<div class="header">
    <h1>Quarantäne – <?=htmlspecialchars($user_name)?> <?=$is_admin?'<sup style="background:#ff9800;padding:2px 6px;border-radius:4px;font-size:0.7em;">ADMIN</sup>':''?></h1>

    <button onclick="location.reload();" class="refresh-btn">
        Aktualisieren
    </button>

    <a href="index.php?logout=1" class="btn btn-logout">Abmelden</a>
</div>

<div class="container">
    <?php if (isset($_GET['msg'])): ?>
        <div class="<?=strpos($_GET['msg'], 'ZUGESTELLT')!==false ? 'msg' : 'error'?>">
            <?=htmlspecialchars(urldecode($_GET['msg']))?>
        </div>
    <?php endif; ?>

    <p><strong><?=count($quarantine_mails)?></strong> Mail<?=count($quarantine_mails)!==1?'s':''?> in Quarantäne</p>

    <?php if (empty($quarantine_mails)): ?>
        <p class="msg">Perfekt! Keine Mails – alles sauber!</p>
    <?php else: ?>
    <table>
        <tr>
            <th>Datum (Server)</th>
            <th>Empfänger</th>
            <th>Absender</th>
            <th>Betreff</th>
            <th>Größe</th>
            <th>Datei</th>
            <th>Aktionen</th>
        </tr>
        <?php foreach ($quarantine_mails as $mail): ?>
        <tr>
            <td><span class="date"><?=$mail['date']?></span></td>
            <td><span class="to"><?=htmlspecialchars($mail['to'])?></span></td>
            <td><span class="from"><?=htmlspecialchars($mail['from'])?></span></td>
            <td><?=htmlspecialchars($mail['subject'])?></td>
            <td><?=$mail['size']?></td>
            <td><code><?=$mail['filename']?></code></td>
            <td>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="file" value="<?=$mail['file']?>">
                    <input type="hidden" name="action" value="deliver">
                    <button type="button" onclick="deliver(this)" class="btn btn-deliver">Zustellen</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Wirklich löschen?');">
                    <input type="hidden" name="file" value="<?=$mail['file']?>">
                    <button type="submit" name="action" value="delete" class="btn btn-delete">Löschen</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="footer">
    Pfad: <code><?=htmlspecialchars(QUARANTINE_DIR)?></code><br>
    &copy; <?=date('Y')?> – Quarantäne Tool v1.0 FINAL
</div>

<script>
    function deliver(btn) {
        btn.innerHTML = 'Zustellen...';
        btn.disabled = true;
        btn.form.submit();
    }
</script>

</body>
</html>

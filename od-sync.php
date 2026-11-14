<?php
/*
 * TDC Lab Tracker – Open Dental sync endpoint
 *
 * Only the office's Open Dental gateway talks to this file. Every request must be
 * signed with OD_SYNC_SECRET (set in config.php, same value as the gateway's
 * LAB_SYNC_SECRET); anything else is refused. Nothing here is reachable with the
 * office password, and this file never reaches into the office.
 *
 * Requests (POST, JSON):
 *   {"op":"push","cases":[...]}  gateway sends recent Open Dental lab cases.
 *                                 New ones are created here with no case type; staff
 *                                 pick the type and log steps. Patient, lab, doctor,
 *                                 sent, due and delivery dates follow Open Dental when
 *                                 they change there.
 *   {"op":"updates"}             gateway asks what to write back to Open Dental:
 *                                 "ready" (every step done, or delivered) and "sent"
 *                                 (outside-lab case given a sent date here).
 *   {"op":"ack","done":[...],"failed":[...]}
 *                                 gateway reports what it wrote to Open Dental.
 *
 * Patient data received: first initial + last name (or initials) only. No date of
 * birth, chart number or Open Dental record numbers; cases are matched by an
 * opaque key ("od-" + 24 hex characters).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const SYNC_WINDOW = 300;       // signed requests are valid for 5 minutes
const MAX_BODY    = 4194304;   // 4 MB
const MAX_CASES   = 2000;
const KEY_RE      = '/^od-[a-f0-9]{24}$/';
const DATE_RE     = '/^\d{4}-\d{2}-\d{2}$/';

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $code, string $error, string $message): void {
    out($code, ['ok' => false, 'error' => $error, 'message' => $message]);
}

/* ---------- configuration ---------- */
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) fail(503, 'not_configured', 'The tracker is not set up yet.');
require $cfgFile;
if (!defined('OD_SYNC_SECRET') || strlen((string)OD_SYNC_SECRET) < 32) {
    fail(503, 'not_configured', 'Open Dental sync is off: set OD_SYNC_SECRET in config.php.');
}

/* ---------- signature ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method', 'Use POST.');
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MAX_BODY) fail(413, 'too_large', 'Request too large.');
$ts  = (string)($_SERVER['HTTP_X_ODGW_TIMESTAMP'] ?? '');
$sig = strtolower((string)($_SERVER['HTTP_X_ODGW_SIGNATURE'] ?? ''));
if (!preg_match('/^\d{9,11}$/', $ts) || !preg_match('/^[a-f0-9]{64}$/', $sig)) fail(401, 'unsigned', 'Missing signature.');
if (abs(time() - (int)$ts) > SYNC_WINDOW) fail(401, 'stale', 'Request too old; check the clocks on the gateway computer and the server.');
if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, (string)OD_SYNC_SECRET), $sig)) fail(401, 'bad_signature', 'Signature does not match.');

$req = json_decode($raw, true);
if (!is_array($req) || !isset($req['op']) || !is_string($req['op'])) fail(400, 'bad_request', 'Bad request.');

/* ---------- database (same tables as api.php) ---------- */
$pdo = null; $driver = 'mysql'; $lastErr = null;
for ($try = 0; $try < 6 && $pdo === null; $try++) {
    if ($try > 0) usleep(random_int(150000, 350000) * $try);
    try {
        if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
            $driver = 'sqlite';
        } else {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                [PDO::ATTR_EMULATE_PREPARES => false]);
        }
    } catch (Throwable $e) { $lastErr = $e; $pdo = null; }
}
if ($pdo === null) fail(503, 'db_busy', 'Could not connect to the database. Try again shortly.');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

try {
    $cs = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_docs (
        col VARCHAR(32) NOT NULL, id VARCHAR(191) NOT NULL, data LONGTEXT NOT NULL,
        state_key VARCHAR(16) NULL, created_key BIGINT NULL, rev BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (col, id))$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_meta (k VARCHAR(32) NOT NULL PRIMARY KEY, v BIGINT NOT NULL DEFAULT 0)$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_sync_seen (sig CHAR(64) NOT NULL PRIMARY KEY, ts BIGINT NOT NULL)$cs");
    $has = $pdo->query("SELECT COUNT(*) AS n FROM tdc_meta WHERE k = 'rev'")->fetch();
    if ((int)$has['n'] === 0) $pdo->exec("INSERT INTO tdc_meta (k, v) VALUES ('rev', 0)");
    // A signed request is accepted once.
    $pdo->prepare('DELETE FROM tdc_sync_seen WHERE ts < ?')->execute([time() - 2 * SYNC_WINDOW]);
    $pdo->prepare('INSERT INTO tdc_sync_seen (sig, ts) VALUES (?, ?)')->execute([$sig, time()]);
} catch (Throwable $e) {
    if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), 'UNIQUE') !== false) fail(409, 'replay', 'This request was already received.');
    error_log('tdc od-sync: ' . $e->getMessage());
    fail(503, 'unavailable', 'Database problem. Try again shortly.');
}

function bump(PDO $pdo): int {
    $pdo->exec("UPDATE tdc_meta SET v = v + 1 WHERE k = 'rev'");
    return (int)$pdo->query("SELECT v FROM tdc_meta WHERE k = 'rev'")->fetch()['v'];
}
function load(PDO $pdo, string $col, string $id): ?array {
    $st = $pdo->prepare('SELECT data FROM tdc_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    $r = $st->fetch();
    return $r ? (json_decode($r['data'], true) ?: []) : null;
}
function save(PDO $pdo, string $col, string $id, array $d): void {
    $j = json_encode((object)$d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || strlen($j) > 1048576) throw new RuntimeException('document too large');
    $rev = bump($pdo);
    $state = (isset($d['state']) && is_string($d['state'])) ? substr($d['state'], 0, 16) : null;
    $created = (isset($d['createdAt']) && is_numeric($d['createdAt'])) ? (int)$d['createdAt'] : null;
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM tdc_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    if ((int)$st->fetch()['n'] > 0) {
        $pdo->prepare('UPDATE tdc_docs SET data = ?, state_key = ?, created_key = ?, rev = ? WHERE col = ? AND id = ?')
            ->execute([$j, $state, $created, $rev, $col, $id]);
    } else {
        $pdo->prepare('INSERT INTO tdc_docs (col, id, data, state_key, created_key, rev) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$col, $id, $j, $state, $created, $rev]);
    }
}
function all_docs(PDO $pdo, string $col, string $prefix = ''): array {
    $sql = 'SELECT id, data FROM tdc_docs WHERE col = ?'; $args = [$col];
    if ($prefix !== '') { $sql .= ' AND id LIKE ?'; $args[] = $prefix . '%'; }
    $st = $pdo->prepare($sql); $st->execute($args);
    $docs = [];
    foreach ($st->fetchAll() as $r) $docs[$r['id']] = json_decode($r['data'], true) ?: [];
    return $docs;
}

/* ---------- helpers ---------- */
function str_in($v, int $max): string { return is_string($v) ? mb_substr(trim(preg_replace('/\s+/', ' ', $v)), 0, $max) : ''; }
function date_in($v): string { return (is_string($v) && preg_match(DATE_RE, $v)) ? $v : ''; }
function now_ms(): int { return (int)floor(microtime(true) * 1000); }
function log_add(array $c, string $what): array {
    $log = is_array($c['log'] ?? null) ? $c['log'] : [];
    $log[] = ['at' => now_ms(), 'by' => 'OD', 'what' => $what];
    return array_slice($log, -100);
}
function fmt_day(string $d): string { return $d === '' ? 'none' : date('M j, Y', strtotime($d . ' 12:00:00')); }
function lab_inhouse(array $labs, string $id): bool {
    return $id === '' || $id === 'inhouse' || !empty(($labs[$id] ?? [])['inhouse']);
}
function all_steps_done(array $c): bool {
    $st = is_array($c['steps'] ?? null) ? $c['steps'] : [];
    if (!count($st)) return false;
    foreach ($st as $s) if (empty($s['done'])) return false;
    return true;
}

/* Find the tracker lab for an Open Dental lab name; add outside labs that aren't set up yet. */
function lab_for(PDO $pdo, array &$labs, string $name, bool $inHouse, array &$notes): string {
    if ($name !== '') {
        foreach ($labs as $id => $l) {
            if (strcasecmp(trim((string)($l['name'] ?? '')), $name) === 0) return (string)$id;
        }
    }
    if ($inHouse || $name === '') return 'inhouse';
    $id = substr(bin2hex(random_bytes(10)), 0, 20);
    $lab = ['name' => $name, 'contact' => '', 'phone' => '', 'email' => '', 'website' => '', 'account' => '', 'portal' => '',
            'password' => '', 'turnaround' => '', 'address' => '', 'notes' => 'Added automatically from Open Dental.', 'createdAt' => now_ms()];
    save($pdo, 'labs', $id, $lab);
    $labs[$id] = $lab;
    $notes[] = 'Added lab "' . $name . '" from Open Dental';
    return $id;
}

/* ---------- operations ---------- */
try {
    switch ($req['op']) {

    case 'push': {
        $cases = $req['cases'] ?? null;
        if (!is_array($cases) || count($cases) > MAX_CASES) fail(400, 'bad_request', 'Bad case list.');
        $labs = all_docs($pdo, 'labs');
        $existing = all_docs($pdo, 'cases', 'od-');
        $created = 0; $updated = 0; $notes = [];
        $pdo->beginTransaction();
        foreach ($cases as $in) {
            if (!is_array($in) || !is_string($in['key'] ?? null) || !preg_match(KEY_RE, $in['key'])) continue;
            $key = $in['key'];
            $od = [
                'patient' => str_in($in['patient'] ?? '', 80),
                'lab'     => str_in($in['lab'] ?? '', 100),
                'labIn'   => !empty($in['lab_in_house']),
                'doctor'  => str_in($in['doctor'] ?? '', 40),
                'sent'    => date_in($in['sent'] ?? ''),
                'due'     => date_in($in['due'] ?? ''),
                'seat'    => date_in($in['seat'] ?? ''),
                'received'=> date_in($in['received'] ?? ''),
                'checked' => date_in($in['checked'] ?? ''),
            ];
            $c = $existing[$key] ?? null;
            if ($c === null) {
                $now = now_ms();
                $c = [
                    'patient' => $od['patient'], 'chart' => '', 'dob' => '', 'type' => '',
                    'lab' => lab_for($pdo, $labs, $od['lab'], $od['labIn'], $notes),
                    'teeth' => [], 'fields' => new stdClass(), 'due' => $od['due'], 'seat' => $od['seat'],
                    'doctor' => $od['doctor'], 'rush' => false, 'remake' => false, 'remakeReason' => '', 'notes' => '',
                    'labNo' => '', 'tracking' => '', 'fee' => '', 'sentAt' => $od['sent'], 'dueAuto' => false,
                    'steps' => [], 'state' => 'open', 'createdAt' => $now, 'createdBy' => 'OD', 'updatedAt' => $now, 'updatedBy' => 'Open Dental',
                    'log' => [['at' => $now, 'by' => 'OD', 'what' => 'Created from Open Dental' . ($od['lab'] !== '' ? ' (' . $od['lab'] . ')' : '')]],
                    'od' => ['last' => $od, 'synced' => $now],
                ];
                // Already finished in Open Dental: don't ask Open Dental again.
                if ($od['checked'] !== '') $c['od']['readyReported'] = $now;
                save($pdo, 'cases', $key, $c);
                $created++;
                continue;
            }
            // Existing case: only fields that changed in Open Dental since the last sync are applied,
            // so edits made here stay until Open Dental changes that field again.
            $last = is_array($c['od']['last'] ?? null) ? $c['od']['last'] : [];
            $changed = [];
            $map = ['patient' => 'patient', 'doctor' => 'doctor', 'due' => 'due', 'seat' => 'seat', 'sent' => 'sentAt'];
            foreach ($map as $from => $to) {
                if (($last[$from] ?? null) === $od[$from]) continue;
                $c[$to] = $od[$from];
                $label = ['patient' => 'Patient', 'doctor' => 'Doctor', 'due' => 'Due date', 'seat' => 'Delivery appointment', 'sent' => 'Sent to lab'][$from];
                $changed[] = $label . ' → ' . (in_array($from, ['due', 'seat', 'sent'], true) ? fmt_day($od[$from]) : ($od[$from] !== '' ? $od[$from] : 'none'));
            }
            if (($last['lab'] ?? null) !== $od['lab']) {
                $started = false;
                foreach ((array)($c['steps'] ?? []) as $s) if (!empty($s['done'])) $started = true;
                $c['lab'] = lab_for($pdo, $labs, $od['lab'], $od['labIn'], $notes);
                if (!$started) $c['steps'] = [];  // the page rebuilds steps when the type is picked
                $changed[] = 'Made by → ' . ($od['lab'] !== '' ? $od['lab'] : 'In-house');
            }
            if (($last['checked'] ?? '') === '' && $od['checked'] !== '' && empty($c['od']['readyReported'])) {
                $c['od']['readyReported'] = now_ms();
                $changed[] = 'Marked checked in Open Dental';
            }
            if (!$changed && ($last['received'] ?? '') === $od['received'] && ($last['labIn'] ?? null) === $od['labIn']) continue;
            $c['od']['last'] = $od;
            $c['od']['synced'] = now_ms();
            if ($changed) {
                $c['log'] = log_add($c, 'Open Dental: ' . implode('; ', $changed));
                $c['updatedAt'] = now_ms(); $c['updatedBy'] = 'Open Dental';
            }
            save($pdo, 'cases', $key, $c);
            $updated++;
        }
        $pdo->commit();
        out(200, ['ok' => true, 'created' => $created, 'updated' => $updated, 'notes' => $notes]);
    }

    case 'updates': {
        $labs = all_docs($pdo, 'labs');
        $events = [];
        foreach (all_docs($pdo, 'cases', 'od-') as $key => $c) {
            if (!preg_match(KEY_RE, (string)$key)) continue;
            $od = is_array($c['od'] ?? null) ? $c['od'] : [];
            $last = is_array($od['last'] ?? null) ? $od['last'] : [];
            $state = (string)($c['state'] ?? 'open');
            if ($state === 'cancelled') continue;
            // Outside lab: a sent date set here goes back to Open Dental.
            $sentAt = (string)($c['sentAt'] ?? '');
            if (!lab_inhouse($labs, (string)($c['lab'] ?? '')) && empty($last['labIn']) && preg_match(DATE_RE, $sentAt)
                && ($last['sent'] ?? '') === '' && empty($od['sentReported'])) {
                $events[] = ['key' => $key, 'event' => 'sent', 'at' => $sentAt];
            }
            // Ready to deliver: every step checked off (or delivered) and not yet reported.
            if ((all_steps_done($c) || $state === 'delivered') && ($last['checked'] ?? '') === '' && empty($od['readyReported'])) {
                $at = 0;
                foreach ((array)($c['steps'] ?? []) as $s) if (!empty($s['at']) && is_numeric($s['at'])) $at = max($at, (int)$s['at']);
                $events[] = ['key' => $key, 'event' => 'ready', 'at' => $at > 0 ? $at : now_ms()];
            }
            if (count($events) >= 200) break;
        }
        out(200, ['ok' => true, 'events' => $events]);
    }

    case 'ack': {
        $done = is_array($req['done'] ?? null) ? $req['done'] : [];
        $failed = is_array($req['failed'] ?? null) ? $req['failed'] : [];
        $pdo->beginTransaction();
        $n = 0;
        foreach ([[$done, true], [$failed, false]] as [$list, $ok]) {
            foreach (array_slice($list, 0, 400) as $a) {
                $key = $a['key'] ?? ''; $ev = $a['event'] ?? '';
                if (!is_string($key) || !preg_match(KEY_RE, $key) || !in_array($ev, ['sent', 'ready'], true)) continue;
                $c = load($pdo, 'cases', $key);
                if ($c === null) continue;
                $od = is_array($c['od'] ?? null) ? $c['od'] : [];
                if ($ok) {
                    $od[$ev === 'sent' ? 'sentReported' : 'readyReported'] = now_ms();
                    unset($od['lastError']);
                    $c['log'] = log_add($c, $ev === 'sent' ? 'Open Dental updated: sent to lab' : 'Open Dental updated: ready to deliver (checked, note added to the appointment)');
                } else {
                    $reason = str_in($a['reason'] ?? 'not applied', 200);
                    if (($od['lastError'] ?? '') !== $reason) $c['log'] = log_add($c, 'Open Dental not updated (' . $ev . '): ' . $reason);
                    $od['lastError'] = $reason;
                    // Don't retry something Open Dental will never accept.
                    if (preg_match('/in-house case|not found/', $reason)) $od[$ev === 'sent' ? 'sentReported' : 'readyReported'] = -1;
                }
                $c['od'] = $od;
                save($pdo, 'cases', $key, $c);
                $n++;
            }
        }
        $pdo->commit();
        out(200, ['ok' => true, 'recorded' => $n]);
    }

    default:
        fail(400, 'bad_request', 'Unknown operation.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('tdc od-sync: ' . $e->getMessage());
    fail(503, 'unavailable', 'The tracker had a problem saving. The gateway will try again.');
}

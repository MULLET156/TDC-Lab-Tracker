<?php
/*
 * TDC Lab Tracker – Dashboard tiles (TDC-Office-Plan issue #23, "tiles from standalone apps")
 *
 *   GET /tdc/tiles/due-week   Due back this week
 *   GET /tdc/tiles/late       Late: past due and not finished
 *   GET /tdc/tiles/to-check   Back from the lab, not checked yet
 *
 * Only the Dental Dashboard calls this, from its server, with
 *   Authorization: Bearer <TILE_TOKEN>
 * The code is made in Setup › Dashboard tiles (kept in the tdc_secrets table), or set as TILE_TOKEN
 * in config.php (never in the repo); config.php wins. Without one, this answers 404.
 *
 * Tiles carry counts, case types and dates only: never patient names, chart or
 * patient numbers (the app runs on Hostinger, which has no BAA). Nothing about a
 * request is logged.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

const TILE_IDS      = ['due-week', 'late', 'to-check'];
const TILE_RATE_MAX = 60;   // requests …
const TILE_RATE_WIN = 60;   // … per 60 seconds, for the whole app
const TILE_ITEMS    = 8;

function tile_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tile_fail(int $code, string $error): void { tile_out($code, ['error' => $error]); }

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) tile_fail(404, 'not_found');
require $cfgFile;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') tile_fail(405, 'method');
$id = (string)($_GET['id'] ?? '');
if (!in_array($id, TILE_IDS, true)) tile_fail(404, 'not_found');

/* ---------- database (same settings as api.php) ---------- */
try {
    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH); $driver = 'sqlite';
    } else {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_EMULATE_PREPARES => false]);
        $driver = 'mysql';
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $cs = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_tile_hits (ts BIGINT NOT NULL)$cs");
} catch (Throwable $e) { tile_fail(503, 'unavailable'); }

/* ---------- the tile code: config.php wins, otherwise the one made in Setup. None = tiles off (404). ---------- */
$token = (defined('TILE_TOKEN') && strlen((string)TILE_TOKEN) >= 32) ? (string)TILE_TOKEN : '';
if ($token === '') {
    try { $r = $pdo->query("SELECT value FROM tdc_secrets WHERE name = 'TILE_TOKEN'")->fetch(); $token = $r ? (string)$r['value'] : ''; }
    catch (Throwable $e) { $token = ''; }   // table not made yet: tiles are off
}
if (strlen($token) < 32) tile_fail(404, 'not_found');

/* ---------- rate limit (counts every request, good token or not) ---------- */
try {
    $now = time();
    $pdo->prepare('DELETE FROM tdc_tile_hits WHERE ts < ?')->execute([$now - TILE_RATE_WIN]);
    $n = (int)$pdo->query('SELECT COUNT(*) AS n FROM tdc_tile_hits')->fetch()['n'];
    if ($n >= TILE_RATE_MAX) { header('Retry-After: ' . TILE_RATE_WIN); tile_fail(429, 'rate_limited'); }
    $pdo->prepare('INSERT INTO tdc_tile_hits (ts) VALUES (?)')->execute([$now]);
} catch (Throwable $e) { tile_fail(503, 'unavailable'); }

/* ---------- token (constant-time) ---------- */
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $k => $v) if (strtolower((string)$k) === 'authorization') $auth = (string)$v;
}
$given = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : '';
if ($given === '' || !hash_equals(hash('sha256', $token), hash('sha256', $given))) tile_fail(401, 'unauthorized');

/* ---------- data: the same rules the tracker page uses (index.html: isOpen, allDone, isLate, isIn) ---------- */
$tz = new DateTimeZone(defined('TILE_TIMEZONE') ? (string)TILE_TIMEZONE : 'America/Los_Angeles');
$today = new DateTimeImmutable('today', $tz);
$todayIso = $today->format('Y-m-d');
$weekIso = $today->modify('+6 days')->format('Y-m-d');

try {
    $cases = [];
    $st = $pdo->query("SELECT id, data FROM tdc_docs WHERE col = 'cases' AND (state_key IS NULL OR state_key NOT IN ('delivered','cancelled'))");
    foreach ($st as $row) { $c = json_decode((string)$row['data'], true); if (is_array($c)) { $c['id'] = (string)$row['id']; $cases[] = $c; } }
    $labsIn = ['inhouse' => true];
    foreach ($pdo->query("SELECT id, data FROM tdc_docs WHERE col = 'labs'") as $row) {
        $l = json_decode((string)$row['data'], true);
        if (is_array($l) && !empty($l['inhouse'])) $labsIn[(string)$row['id']] = true;
    }
    $typeNames = ['crown' => 'Crown (CEREC)', 'bridge' => 'Bridge', 'temp' => 'Printed temporary (C&B)', 'impcrown' => 'Implant crown (Ti-base)',
        'abut' => 'Custom abutment (Ti-base)', 'guide' => 'Implant plan & surgical guide', 'healret' => 'Healing retainer (temporary smile)',
        'nightguard' => 'Night guard', 'retainer' => 'Retainer', 'model' => 'Printed model', 'partial' => 'Flexible partial denture',
        'fulldenture' => 'Full denture', 'immdenture' => 'Immediate denture', 'smiledesign' => 'Smile design', 'mockup' => 'Smile mock-up / try-in', 'other' => 'Other'];
    $tr = $pdo->query("SELECT data FROM tdc_docs WHERE col = 'settings' AND id = 'types'")->fetch();
    $td = $tr ? json_decode((string)$tr['data'], true) : null;
    if (is_array($td) && is_array($td['types'] ?? null)) {
        foreach ($td['types'] as $t) if (is_array($t) && isset($t['id'], $t['name'])) $typeNames[(string)$t['id']] = (string)$t['name'];
    }
} catch (Throwable $e) { tile_fail(503, 'unavailable'); }

$isOpen  = fn(array $c): bool => !in_array($c['state'] ?? '', ['delivered', 'cancelled'], true);
$allDone = function (array $c): bool { $s = is_array($c['steps'] ?? null) ? $c['steps'] : []; if (!$s) return false;
    foreach ($s as $x) if (empty($x['done'])) return false; return true; };
$due     = fn(array $c): string => (is_string($c['due'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['due'])) ? $c['due'] : '';
$isIn    = fn(array $c): bool => empty($c['lab']) || isset($labsIn[(string)$c['lab']]);
$back    = function (array $c): bool {   // back from the outside lab
    if (!empty($c['od']['received'])) return true;
    foreach ((array)($c['steps'] ?? []) as $s) {
        if (!empty($s['done']) && preg_match('/back in office|received back|back from (the )?lab/i', (string)($s['l'] ?? $s['label'] ?? ''))) return true;
    }
    return false;
};
$typeOf  = fn(array $c): string => $typeNames[(string)($c['type'] ?? '')] ?? ((string)($c['typeName'] ?? '') !== '' ? (string)$c['typeName'] : 'Lab case');
$day     = function (string $iso) use ($today, $todayIso): string {
    if ($iso === $todayIso) return 'today';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, $today->getTimezone());
    $diff = (int)$today->diff($d)->format('%r%a');
    if ($diff === 1) return 'tomorrow';
    return $diff > 1 && $diff < 7 ? $d->format('D') : $d->format('M j');
};

/* ---------- links: full https URLs into this app ---------- */
if (defined('TILE_BASE_URL') && (string)TILE_BASE_URL !== '') $base = rtrim((string)TILE_BASE_URL, '/') . '/';
else {
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $base = 'https://' . $host . $dir . '/';
}
$caseLink = fn(array $c): string => $base . '#case=' . rawurlencode((string)($c['id'] ?? ''));

/* ---------- the three tiles ---------- */
$pick = []; $title = ''; $status = 'ok'; $label = '';
if ($id === 'due-week') {
    $title = 'Due back this week';
    foreach ($cases as $c) { $d = $due($c); if ($isOpen($c) && !$allDone($c) && $d !== '' && $d >= $todayIso && $d <= $weekIso) $pick[] = $c; }
    foreach ($pick as $c) if ($due($c) === $todayIso) $status = 'warn';
    $label = fn(array $c): string => $typeOf($c) . ' · due ' . $day($due($c));
} elseif ($id === 'late') {
    $title = 'Late';
    foreach ($cases as $c) { $d = $due($c); if ($isOpen($c) && !$allDone($c) && $d !== '' && $d < $todayIso) $pick[] = $c; }
    if ($pick) $status = 'alert';
    $label = fn(array $c): string => $typeOf($c) . ' · was due ' . $day($due($c));
} else {
    $title = 'Received, not checked';
    foreach ($cases as $c) if ($isOpen($c) && !$isIn($c) && !$allDone($c) && $back($c)) $pick[] = $c;
    if ($pick) $status = 'warn';
    $label = fn(array $c): string => $typeOf($c) . ($due($c) !== '' ? ' · due ' . $day($due($c)) : '');
}
usort($pick, fn($a, $b) => strcmp($due($a) ?: '9999', $due($b) ?: '9999'));
$n = count($pick);
$items = [];
foreach (array_slice($pick, 0, TILE_ITEMS) as $c) $items[] = ['text' => $label($c), 'href' => $caseLink($c)];

tile_out(200, [
    'title'   => $title,
    'value'   => $n . ($n === 1 ? ' case' : ' cases'),
    'status'  => $status,
    'items'   => $items,
    'link'    => $base,
    'updated' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
]);

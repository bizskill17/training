<?php
// BizSkill Training: JSON API. One file, called as api.php?a=<action> with a JSON body.
declare(strict_types=1);
$C = require __DIR__ . '/config.php';
date_default_timezone_set($C['timezone'] ?? 'Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $code = 400): void { out(['error' => $m], $code); }

try {
    $isLite = str_starts_with($C['db_dsn'], 'sqlite:');
    if ($isLite && !is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0750, true);
    $db = new PDO($C['db_dsn'], $C['db_user'] ?: null, $C['db_pass'] ?: null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($isLite) { $db->exec('PRAGMA journal_mode=WAL'); $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=4000'); }
} catch (Throwable $e) { fail('Database is not available.', 500); }

function q(string $sql, array $p = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function row(string $sql, array $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

// ---------- schema ----------
$PK = $isLite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
$T  = $isLite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
foreach ([
 "CREATE TABLE IF NOT EXISTS businesses (id $PK, name VARCHAR(120) NOT NULL, plan VARCHAR(10) NOT NULL DEFAULT 'free', paid_until INT NOT NULL DEFAULT 0, created INT NOT NULL)$T",
 "CREATE TABLE IF NOT EXISTS users (id $PK, business_id INT NULL, role VARCHAR(10) NOT NULL, name VARCHAR(80) NOT NULL, mobile VARCHAR(15) NOT NULL UNIQUE, pass VARCHAR(255) NOT NULL, active INT NOT NULL DEFAULT 1, created INT NOT NULL, last_active INT NOT NULL DEFAULT 0)$T",
 "CREATE TABLE IF NOT EXISTS courses (id $PK, title VARCHAR(120) NOT NULL, descr VARCHAR(300) NOT NULL DEFAULT '', is_free INT NOT NULL DEFAULT 0, published INT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0)$T",
 "CREATE TABLE IF NOT EXISTS lessons (id $PK, course_id INT NOT NULL, title VARCHAR(160) NOT NULL, yt VARCHAR(20) NOT NULL DEFAULT '', duration INT NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0)$T",
 "CREATE TABLE IF NOT EXISTS questions (id $PK, lesson_id INT NOT NULL, q VARCHAR(400) NOT NULL, a VARCHAR(200) NOT NULL, b VARCHAR(200) NOT NULL, c VARCHAR(200) NOT NULL DEFAULT '', d VARCHAR(200) NOT NULL DEFAULT '', correct INT NOT NULL DEFAULT 0)$T",
 "CREATE TABLE IF NOT EXISTS assignments (id $PK, business_id INT NOT NULL, user_id INT NOT NULL, course_id INT NOT NULL, due INT NOT NULL DEFAULT 0, created INT NOT NULL, UNIQUE (user_id, course_id))$T",
 "CREATE TABLE IF NOT EXISTS progress (user_id INT NOT NULL, lesson_id INT NOT NULL, watched INT NOT NULL DEFAULT 0, maxpos INT NOT NULL DEFAULT 0, completed INT NOT NULL DEFAULT 0, last_beat INT NOT NULL DEFAULT 0, lastpos INT NOT NULL DEFAULT 0, score INT NULL, first_score INT NULL, attempts INT NOT NULL DEFAULT 0, passed INT NOT NULL DEFAULT 0, PRIMARY KEY (user_id, lesson_id))$T",
 "CREATE TABLE IF NOT EXISTS payments (id $PK, business_id INT NOT NULL, order_id VARCHAR(40) NOT NULL, payment_id VARCHAR(40) NOT NULL UNIQUE, amount INT NOT NULL, created INT NOT NULL)$T",
 "CREATE TABLE IF NOT EXISTS throttle (k VARCHAR(80) PRIMARY KEY, n INT NOT NULL, t INT NOT NULL)$T",
] as $sql) $db->exec($sql);
try { $db->query('SELECT lastpos FROM progress LIMIT 1'); } catch (Throwable $e) { $db->exec('ALTER TABLE progress ADD COLUMN lastpos INT NOT NULL DEFAULT 0'); }   // added 24 Nov 2026

// Course catalogue metadata (additive migration).
$db->exec("CREATE TABLE IF NOT EXISTS categories (id $PK, name VARCHAR(100) NOT NULL UNIQUE, active INT NOT NULL DEFAULT 1)$T");
foreach (['category_id'=>'INT NOT NULL DEFAULT 0','thumbnail'=>"VARCHAR(100) NOT NULL DEFAULT ''"] as $col=>$def) {
    try { $db->query("SELECT $col FROM courses LIMIT 1"); }
    catch (Throwable $e) { try { $db->exec("ALTER TABLE courses ADD COLUMN $col $def"); } catch (Throwable $race) { $db->query("SELECT $col FROM courses LIMIT 1"); } }
}
function category_name($id): string { return (string)(val('SELECT name FROM categories WHERE id=?',[(int)$id]) ?? ''); }
function course_meta(array $c): array { return ['category_id'=>(int)$c['category_id'],'category'=>category_name($c['category_id']),'thumbnail'=>$c['thumbnail']]; }
function store_thumb(string $data): string {
    if (strlen($data)>2000000 || !preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$~D',$data,$m)) fail('Invalid thumbnail.');
    $raw=base64_decode($m[2],true); if (!$raw || strlen($raw)>1400000) fail('Thumbnail too large.');
    $info=@getimagesizefromstring($raw);
    if (!$info || $info[0]>1600 || $info[1]>1000 || $info[0]<1 || $info[1]<1 || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) fail('Invalid image dimensions or format.');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) fail('Image processing is unavailable on this server.',503);
    $im=@imagecreatefromstring($raw); if (!$im) fail('Invalid image.');
    $w=imagesx($im); $h=imagesy($im); $cw=min($w,(int)round($h*16/9)); $ch=min($h,(int)round($w*9/16));
    $out=imagecreatetruecolor(1280,720); imagefill($out,0,0,imagecolorallocate($out,255,255,255));
    imagecopyresampled($out,$im,0,0,(int)(($w-$cw)/2),(int)(($h-$ch)/2),1280,720,$cw,$ch);
    $dir=__DIR__.'/uploads/courses'; if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) fail('Cannot create image folder.',500);
    $name=bin2hex(random_bytes(16)).'.jpg'; if (!imagejpeg($out,$dir.'/'.$name,85)) fail('Cannot save thumbnail.',500);
    return $name;
}
// ---------- request ----------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_name('bztrain');
session_start();
$a  = preg_replace('/[^a-z_]/', '', (string)($_GET['a'] ?? ''));
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_APP'] ?? '') !== '1') fail('Bad request.', 400);
$now = time();

function s($k, int $max = 200): string { global $in; return mb_substr(trim((string)($in[$k] ?? '')), 0, $max); }
function i($k): int { global $in; return (int)($in[$k] ?? 0); }
function mobile(string $m): string { $d = preg_replace('/\D/', '', $m); if (strlen($d) > 10) $d = substr($d, -10); if (strlen($d) !== 10) fail('Enter a 10-digit mobile number.'); return $d; }
function pass_ok(string $p): string { if (strlen($p) < 6) fail('Password must be at least 6 characters.'); return password_hash($p, PASSWORD_DEFAULT); }
function yt_id(string $u): string {
    $u = trim($u); if ($u === '') return '';
    if (preg_match('~(?:youtu\.be/|v=|/shorts/|/embed/|/live/)([A-Za-z0-9_-]{11})~', $u, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $u)) return $u;
    fail('That does not look like a YouTube link.');
}
function throttle(string $k, int $max, int $win): void {
    global $now; $r = row('SELECT n,t FROM throttle WHERE k=?', [$k]);
    if ($r && $now - $r['t'] < $win) { if ($r['n'] >= $max) fail('Too many attempts. Please wait a few minutes.', 429); q('UPDATE throttle SET n=n+1 WHERE k=?', [$k]); }
    else { q('DELETE FROM throttle WHERE k=?', [$k]); q('INSERT INTO throttle (k,n,t) VALUES (?,?,?)', [$k, 1, $now]); }
}

$U = null;
if (!empty($_SESSION['uid'])) {
    $U = row('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['uid']]);
    if ($U && $now - $U['last_active'] > 120) q('UPDATE users SET last_active=? WHERE id=?', [$now, $U['id']]);
}
function need(string ...$roles): array { global $U; if (!$U) fail('Please log in.', 401); if ($roles && !in_array($U['role'], $roles, true)) fail('Not allowed.', 403); return $U; }
function biz(int $id): array {
    global $C, $now; $b = row('SELECT * FROM businesses WHERE id=?', [$id]); if (!$b) fail('Business not found.', 404);
    $b['paid'] = ($b['plan'] === 'paid' && $b['paid_until'] > $now);
    $b['limit'] = $b['paid'] ? $C['paid_staff'] : $C['free_staff'];
    return $b;
}
function me_payload(): array {
    global $U, $C; if (!$U) return ['user' => null, 'brand' => $C['brand'], 'price' => $C['price_rupees'], 'paid_staff' => $C['paid_staff'], 'free_staff' => $C['free_staff']];
    $b = $U['business_id'] ? biz((int)$U['business_id']) : null;
    return ['user' => ['id' => (int)$U['id'], 'name' => $U['name'], 'role' => $U['role'], 'mobile' => $U['mobile']],
        'business' => $b ? ['name' => $b['name'], 'paid' => $b['paid'], 'paid_until' => (int)$b['paid_until'], 'limit' => $b['limit']] : null,
        'brand' => $C['brand'], 'price' => $C['price_rupees'], 'paid_staff' => $C['paid_staff'], 'free_staff' => $C['free_staff'],
        'pass_mark' => $C['pass_mark'], 'whatsapp' => $C['whatsapp'], 'email' => $C['email'],
        'pay' => $C['rzp_key_id'] && $C['rzp_key_secret'] ? 'auto' : ($C['pay_link'] ? 'link' : 'contact'), 'pay_link' => $C['pay_link']];
}
// Courses a user may open: owners preview everything their plan allows, employees see what is assigned.
function allowed_courses(array $u): array {
    if ($u['role'] === 'admin') return rows('SELECT c.*, 0 AS due FROM courses c ORDER BY c.sort, c.id');   // admin previews every course, hidden ones too
    $b = biz((int)$u['business_id']);
    $free = $b['paid'] ? '' : ' AND c.is_free=1';
    if ($u['role'] === 'owner') return rows("SELECT c.*, 0 AS due FROM courses c WHERE c.published=1$free ORDER BY c.sort, c.id");
    return rows("SELECT c.*, s.due FROM courses c JOIN assignments s ON s.course_id=c.id AND s.user_id=? WHERE c.published=1$free ORDER BY c.sort, c.id", [$u['id']]);
}
// Lessons of a course with this user's state. A lesson opens when the one before it is finished.
function lesson_states(int $uid, int $cid): array {
    $ls = rows('SELECT l.id,l.title,l.yt,l.duration,l.sort,(SELECT COUNT(*) FROM questions x WHERE x.lesson_id=l.id) AS nq,
        p.watched,p.maxpos,p.completed,p.score,p.first_score,p.attempts,p.passed
        FROM lessons l LEFT JOIN progress p ON p.lesson_id=l.id AND p.user_id=? WHERE l.course_id=? ORDER BY l.sort,l.id', [$uid, $cid]);
    $open = true; $o = [];
    foreach ($ls as $l) {
        $done = $l['completed'] > 0 && ($l['nq'] == 0 || $l['passed'] > 0);
        $o[] = ['id' => (int)$l['id'], 'title' => $l['title'], 'has_video' => $l['yt'] !== '', 'duration' => (int)$l['duration'], 'nq' => (int)$l['nq'],
            'watched' => (int)$l['watched'], 'completed' => $l['completed'] > 0, 'score' => $l['score'] === null ? null : (int)$l['score'],
            'attempts' => (int)$l['attempts'], 'passed' => $l['passed'] > 0, 'done' => $done, 'open' => $open];
        $open = $open && $done;
    }
    return $o;
}
function can_open(array $u, int $lid): array {
    $l = row('SELECT * FROM lessons WHERE id=?', [$lid]); if (!$l) fail('Lesson not found.', 404);
    $ok = false; foreach (allowed_courses($u) as $c) if ((int)$c['id'] === (int)$l['course_id']) $ok = true;
    if (!$ok) fail('This course is not assigned to you.', 403);
    foreach (lesson_states((int)$u['id'], (int)$l['course_id']) as $st) if ($st['id'] === (int)$l['id'] && !$st['open']) fail('Finish the previous lesson first.', 403);
    return $l;
}

switch ($a) {
// ---------- account ----------
case 'me': out(me_payload());
case 'signup':
    throttle('su' . ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 3600);
    $bn = s('business', 120); $nm = s('name', 80); $mb = mobile(s('mobile')); $ph = pass_ok(s('password'));
    if ($bn === '' || $nm === '') fail('Enter your name and business name.');
    if (val('SELECT 1 FROM users WHERE mobile=?', [$mb])) fail('This mobile number already has an account. Please log in.');
    q('INSERT INTO businesses (name,created) VALUES (?,?)', [$bn, $now]); $bid = (int)$db->lastInsertId();
    q("INSERT INTO users (business_id,role,name,mobile,pass,created,last_active) VALUES (?,'owner',?,?,?,?,?)", [$bid, $nm, $mb, $ph, $now, $now]);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$db->lastInsertId(); $U = row('SELECT * FROM users WHERE id=?', [$_SESSION['uid']]);
    out(me_payload());
case 'login':
    $mb = mobile(s('mobile')); throttle('li' . $mb . ($_SERVER['REMOTE_ADDR'] ?? ''), 8, 900);
    $u = row('SELECT * FROM users WHERE mobile=? AND active=1', [$mb]);
    if (!$u || !password_verify(s('password'), $u['pass'])) fail('Wrong mobile number or password.', 401);
    q('DELETE FROM throttle WHERE k=?', ['li' . $mb . ($_SERVER['REMOTE_ADDR'] ?? '')]);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id']; $U = $u; out(me_payload());
case 'logout': $_SESSION = []; session_destroy(); out(['ok' => true]);
case 'password':
    need(); if (!password_verify(s('old'), $U['pass'])) fail('Current password is wrong.');
    q('UPDATE users SET pass=? WHERE id=?', [pass_ok(s('password')), $U['id']]); out(['ok' => true]);
case 'profile_save':
    need('owner'); $bn = s('business', 120); $nm = s('name', 80); $mb = mobile(s('mobile'));
    if ($bn === '' || $nm === '') fail('Enter your name and business name.');
    if (val('SELECT 1 FROM users WHERE mobile=? AND id<>?', [$mb, $U['id']])) fail('This mobile number is already used by another account.');
    q('UPDATE businesses SET name=? WHERE id=?', [$bn, $U['business_id']]);
    q('UPDATE users SET name=?, mobile=? WHERE id=?', [$nm, $mb, $U['id']]);
    $U = row('SELECT * FROM users WHERE id=?', [$U['id']]); out(me_payload());
case 'setup':
    if (val("SELECT 1 FROM users WHERE role='admin'")) fail('Setup is already done.', 403);
    if (!hash_equals($C['setup_key'], s('key', 100)) || strlen($C['setup_key']) < 16) fail('Wrong setup key.', 403);
    q("INSERT INTO users (role,name,mobile,pass,created) VALUES ('admin',?,?,?,?)", [s('name', 80) ?: 'Admin', mobile(s('mobile')), pass_ok(s('password')), $now]);
    out(['ok' => true]);

// ---------- owner ----------
case 'dash':
    need('owner', 'admin'); $bid = $U['role'] === 'admin' ? i('business_id') : (int)$U['business_id']; $b = biz($bid);   // admin may look at any business, read-only
    $courses = allowed_courses(['role' => 'owner', 'business_id' => $bid, 'id' => 0]); $cl = [];
    foreach ($courses as $c) $cl[(int)$c['id']] = ['id' => (int)$c['id'], 'title' => $c['title'], 'descr' => $c['descr'], 'is_free' => (int)$c['is_free'], ...course_meta($c),
        'lessons' => (int)val('SELECT COUNT(*) FROM lessons WHERE course_id=?', [$c['id']])];
    $locked = $b['paid'] ? [] : rows('SELECT id,title,descr,thumbnail,category_id,(SELECT name FROM categories WHERE id=c.category_id) AS category,(SELECT COUNT(*) FROM lessons l WHERE l.course_id=c.id) AS lessons FROM courses c WHERE published=1 AND is_free=0 ORDER BY sort,id');
    $staff = [];
    foreach (rows("SELECT id,name,mobile,active,last_active FROM users WHERE business_id=? AND role='employee' ORDER BY active DESC, name", [$bid]) as $e) {
        $tot = 0; $done = 0; $mins = 0; $sc = []; $over = 0; $cs = [];
        foreach (rows('SELECT course_id,due FROM assignments WHERE user_id=?', [$e['id']]) as $as) {
            $cid = (int)$as['course_id']; if (!isset($cl[$cid])) continue;
            $st = lesson_states((int)$e['id'], $cid); $d = 0; $csc = [];
            foreach ($st as $x) { if ($x['done']) $d++; if ($x['score'] !== null) $csc[] = $x['score']; $mins += $x['watched']; }
            $tot += count($st); $done += $d; $sc = array_merge($sc, $csc);
            $late = $as['due'] > 0 && $as['due'] < $now && $d < count($st); if ($late) $over++;
            $cs[] = ['course_id' => $cid, 'title' => $cl[$cid]['title'], 'done' => $d, 'total' => count($st), 'avg' => $csc ? (int)round(array_sum($csc) / count($csc)) : null, 'due' => (int)$as['due'], 'late' => $late];
        }
        $staff[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'mobile' => $e['mobile'], 'active' => (int)$e['active'], 'last_active' => (int)$e['last_active'],
            'total' => $tot, 'done' => $done, 'minutes' => (int)round($mins / 60), 'seconds' => (int)$mins, 'avg' => $sc ? (int)round(array_sum($sc) / count($sc)) : null, 'overdue' => $over, 'courses' => $cs];
    }
    out(['business' => $b['name'], 'staff' => $staff, 'courses' => array_values($cl), 'locked' => $locked, 'limit' => $b['limit'], 'paid' => $b['paid'], 'paid_until' => (int)$b['paid_until']]);
case 'staff_add':
    need('owner'); $b = biz((int)$U['business_id']);
    if ((int)val("SELECT COUNT(*) FROM users WHERE business_id=? AND role='employee' AND active=1", [$b['id']]) >= $b['limit'])
        fail($b['paid'] ? "Your plan covers {$b['limit']} employees." : "The free plan covers {$b['limit']} employees. Upgrade to add more.", 402);
    $nm = s('name', 80); $mb = mobile(s('mobile')); $ph = pass_ok(s('password')); if ($nm === '') fail('Enter the employee name.');
    $ex = row('SELECT * FROM users WHERE mobile=?', [$mb]);
    if ($ex && !($ex['business_id'] == $b['id'] && $ex['role'] === 'employee' && !$ex['active'])) fail('This mobile number is already in use.');
    if ($ex) q('UPDATE users SET active=1,name=?,pass=? WHERE id=?', [$nm, $ph, $ex['id']]);
    else q("INSERT INTO users (business_id,role,name,mobile,pass,created) VALUES (?,'employee',?,?,?,?)", [$b['id'], $nm, $mb, $ph, $now]);
    out(['ok' => true]);
case 'staff_remove': case 'staff_reset':
    need('owner'); $e = row("SELECT * FROM users WHERE id=? AND business_id=? AND role='employee'", [i('id'), $U['business_id']]); if (!$e) fail('Employee not found.', 404);
    if ($a === 'staff_remove') q('UPDATE users SET active=0 WHERE id=?', [$e['id']]); else q('UPDATE users SET pass=? WHERE id=?', [pass_ok(s('password')), $e['id']]);
    out(['ok' => true]);
case 'assign': case 'unassign':
    need('owner'); $bid = (int)$U['business_id']; $cid = i('course_id');
    $okc = false; foreach (allowed_courses($U) as $c) if ((int)$c['id'] === $cid) $okc = true; if (!$okc) fail('This course is not in your plan.', 402);
    $ids = i('user_id') ? [i('user_id')] : array_map('intval', array_column(rows("SELECT id FROM users WHERE business_id=? AND role='employee' AND active=1", [$bid]), 'id'));
    $due = s('due', 10) !== '' ? (int)strtotime(s('due', 10) . ' 23:59:59') : 0;
    foreach ($ids as $uid) {
        if (!val("SELECT 1 FROM users WHERE id=? AND business_id=? AND role='employee'", [$uid, $bid])) fail('Employee not found.', 404);
        if ($a === 'unassign') { q('DELETE FROM assignments WHERE user_id=? AND course_id=?', [$uid, $cid]); continue; }
        if (val('SELECT 1 FROM assignments WHERE user_id=? AND course_id=?', [$uid, $cid])) q('UPDATE assignments SET due=? WHERE user_id=? AND course_id=?', [$due, $uid, $cid]);
        else q('INSERT INTO assignments (business_id,user_id,course_id,due,created) VALUES (?,?,?,?,?)', [$bid, $uid, $cid, $due, $now]);
    }
    out(['ok' => true, 'count' => count($ids)]);

// ---------- learner (employee, or owner in employee view) ----------
case 'my_courses':
    need('owner', 'employee', 'admin'); $o = [];
    $all = []; $secs = 0; $td = 0; $tt = 0;
    foreach (allowed_courses($U) as $c) { $st = lesson_states((int)$U['id'], (int)$c['id']); $d = count(array_filter($st, fn($x) => $x['done']));
        $sc = array_values(array_filter(array_column($st, 'score'), fn($x) => $x !== null)); $all = array_merge($all, $sc); $secs += array_sum(array_column($st, 'watched')); $td += $d; $tt += count($st);
        $o[] = ['id' => (int)$c['id'], 'title' => $c['title'], 'descr' => $c['descr'], ...course_meta($c), 'done' => $d, 'total' => count($st), 'due' => (int)$c['due'], 'avg' => $sc ? (int)round(array_sum($sc) / count($sc)) : null]; }
    out(['courses' => $o, 'done' => $td, 'total' => $tt, 'avg' => $all ? (int)round(array_sum($all) / count($all)) : null, 'seconds' => (int)$secs]);
case 'course':
    need('owner', 'employee', 'admin'); $cid = i('id'); $c = null; foreach (allowed_courses($U) as $x) if ((int)$x['id'] === $cid) $c = $x;
    if (!$c) fail('This course is not assigned to you.', 403);
    out(['id' => $cid, 'title' => $c['title'], 'descr' => $c['descr'], ...course_meta($c), 'lessons' => lesson_states((int)$U['id'], $cid)]);
case 'lesson':
    need('owner', 'employee', 'admin'); $l = can_open($U, i('id'));
    $p = row('SELECT * FROM progress WHERE user_id=? AND lesson_id=?', [$U['id'], $l['id']]);
    $qs = rows('SELECT id,q,a,b,c,d FROM questions WHERE lesson_id=? ORDER BY id', [$l['id']]);
    $st = lesson_states((int)$U['id'], (int)$l['course_id']); $next = null; $seen = false;
    foreach ($st as $x) { if ($seen) { $next = $x['id']; break; } if ($x['id'] === (int)$l['id']) $seen = true; }
    out(['id' => (int)$l['id'], 'course_id' => (int)$l['course_id'], 'title' => $l['title'], 'yt' => $l['yt'], 'duration' => (int)$l['duration'],
        'watched' => (int)($p['watched'] ?? 0), 'maxpos' => (int)($p['maxpos'] ?? 0), 'completed' => ($p['completed'] ?? 0) > 0,
        'score' => isset($p['score']) ? (int)$p['score'] : null, 'passed' => ($p['passed'] ?? 0) > 0, 'attempts' => (int)($p['attempts'] ?? 0),
        'questions' => ($p['completed'] ?? 0) > 0 ? $qs : [], 'nq' => count($qs), 'next' => $next]);
case 'beat':
    // Called every few seconds while the video is really playing. Credit is capped by server time, and jumping ahead earns nothing.
    need('owner', 'employee', 'admin'); $l = can_open($U, i('lesson_id')); $pos = max(0, i('pos')); $dur = max(0, i('dur'));
    if ($l['duration'] == 0 && $dur > 0 && $dur < 4 * 3600) { q('UPDATE lessons SET duration=? WHERE id=? AND duration=0', [$dur, $l['id']]); $l['duration'] = $dur; }
    $D = (int)$l['duration'];
    $p = row('SELECT * FROM progress WHERE user_id=? AND lesson_id=?', [$U['id'], $l['id']]);
    if (!$p) { q('INSERT INTO progress (user_id,lesson_id,last_beat,lastpos) VALUES (?,?,?,?)', [$U['id'], $l['id'], $now, min($pos, 3)]); out(['watched' => 0, 'maxpos' => 0, 'completed' => false]); }
    $gap = $now - (int)$p['last_beat']; $delta = ($gap > 0 && $gap <= 15) ? min($gap, 6) : 0;
    $w = (int)$p['watched']; $mx = (int)$p['maxpos']; $seek = null; $lp = $pos;
    if ($pos > $mx + $delta + 4 && !$p['completed']) { $seek = $mx; $delta = 0; $lp = $mx; }   // tried to skip forward
    else {
        // Time counts once per part of the video: only while playback moves past the furthest point already reached.
        // Pausing, buffering, a restart, or watching an earlier part again earns nothing, so the figure can never exceed the video's length.
        $fresh = $pos - $mx; $delta = $fresh > 0 ? min($delta, $fresh + 1) : 0;
        $w += $delta; if ($pos > $mx) $mx = $D ? min($pos, $D) : $pos;
    }
    $w = min($w, $mx);
    if ($D) $w = min($w, $D);
    $comp = (int)$p['completed']; if (!$comp && $D > 0 && $mx >= 0.9 * $D && $w >= 0.8 * $D) $comp = $now;
    q('UPDATE progress SET watched=?,maxpos=?,completed=?,last_beat=?,lastpos=? WHERE user_id=? AND lesson_id=?', [$w, $mx, $comp, $now, $lp, $U['id'], $l['id']]);
    $r = ['watched' => $w, 'maxpos' => $mx, 'completed' => $comp > 0]; if ($seek !== null) $r['seek_to'] = $seek;
    if ($comp > 0 && !$p['completed']) $r['questions'] = rows('SELECT id,q,a,b,c,d FROM questions WHERE lesson_id=? ORDER BY id', [$l['id']]);
    out($r);
case 'quiz':
    need('owner', 'employee', 'admin'); $l = can_open($U, i('lesson_id'));
    $p = row('SELECT * FROM progress WHERE user_id=? AND lesson_id=?', [$U['id'], $l['id']]);
    if (!$p || !$p['completed']) fail('Watch the video first.', 403);
    $qs = rows('SELECT id,correct FROM questions WHERE lesson_id=? ORDER BY id', [$l['id']]); if (!$qs) fail('This lesson has no test.');
    $ans = is_array($in['answers'] ?? null) ? $in['answers'] : []; $right = 0; $key = [];
    foreach ($qs as $x) { $key[$x['id']] = (int)$x['correct']; if (isset($ans[$x['id']]) && (int)$ans[$x['id']] === (int)$x['correct']) $right++; }
    $score = (int)round(100 * $right / count($qs)); $pass = $score >= $C['pass_mark'];
    q('UPDATE progress SET attempts=attempts+1, first_score=COALESCE(first_score,?), score=?, passed=? WHERE user_id=? AND lesson_id=?',
        [$score, max($score, (int)($p['score'] ?? 0)), ($pass || $p['passed']) ? 1 : 0, $U['id'], $l['id']]);
    out(['score' => $score, 'passed' => $pass, 'right' => $right, 'total' => count($qs), 'key' => $key]);

case 'my_reset':
    // Admin only: clear the admin's own preview progress so a lesson can be tested again.
    need('admin');
    if (i('lesson_id')) q('DELETE FROM progress WHERE user_id=? AND lesson_id=?', [$U['id'], i('lesson_id')]); else q('DELETE FROM progress WHERE user_id=?', [$U['id']]);
    out(['ok' => true]);

// ---------- payment ----------
case 'order_create': case 'order_verify':
    need('owner'); if (!$C['rzp_key_id'] || !$C['rzp_key_secret']) fail('Online payment is not switched on yet.', 503);
    $rz = function (string $method, string $path, array $body = []) use ($C) {
        $ch = curl_init('https://api.razorpay.com/v1/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $C['rzp_key_id'] . ':' . $C['rzp_key_secret'], CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method]);
        if ($body) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); }
        $r = json_decode((string)curl_exec($ch), true); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_array($r)) fail('Payment service did not respond. Please try again.', 502); return $r;
    };
    $amt = $C['price_rupees'] * 100;
    if ($a === 'order_create') {
        $o = $rz('POST', 'orders', ['amount' => $amt, 'currency' => 'INR', 'receipt' => 'b' . $U['business_id'] . '-' . $now, 'notes' => ['business_id' => (string)$U['business_id'], 'product' => 'bizskill-training']]);
        out(['order_id' => $o['id'], 'amount' => $amt, 'key' => $C['rzp_key_id'], 'name' => $U['name'], 'mobile' => $U['mobile']]);
    }
    $oid = s('order_id', 40); $pid = s('payment_id', 40); $sig = s('signature', 128);
    if (!hash_equals(hash_hmac('sha256', $oid . '|' . $pid, $C['rzp_key_secret']), $sig)) fail('Payment could not be confirmed.', 400);
    $o = $rz('GET', 'orders/' . rawurlencode($oid)); $pm = $rz('GET', 'payments/' . rawurlencode($pid));
    if ((int)$o['amount'] !== $amt || ($o['notes']['business_id'] ?? '') !== (string)$U['business_id'] || ($pm['order_id'] ?? '') !== $oid || !in_array($pm['status'] ?? '', ['captured', 'authorized'], true)) fail('Payment could not be confirmed.', 400);
    if (!val('SELECT 1 FROM payments WHERE payment_id=?', [$pid])) {
        q('INSERT INTO payments (business_id,order_id,payment_id,amount,created) VALUES (?,?,?,?,?)', [$U['business_id'], $oid, $pid, $amt, $now]);
        $b = biz((int)$U['business_id']); $from = max($now, (int)$b['paid_until']);
        q("UPDATE businesses SET plan='paid', paid_until=? WHERE id=?", [$from + $C['plan_days'] * 86400, $b['id']]);
    }
    out(me_payload());

// ---------- admin ----------
case 'a_overview':
    need('admin');
    out(['businesses' => rows("SELECT b.id,b.name,b.plan,b.paid_until,b.created,
        (SELECT name FROM users u WHERE u.business_id=b.id AND u.role='owner' LIMIT 1) AS owner,
        (SELECT mobile FROM users u WHERE u.business_id=b.id AND u.role='owner' LIMIT 1) AS mobile,
        (SELECT COUNT(*) FROM users u WHERE u.business_id=b.id AND u.role='employee' AND u.active=1) AS staff,
        (SELECT COUNT(*) FROM progress p JOIN users u ON u.id=p.user_id WHERE u.business_id=b.id AND u.role='employee' AND p.completed>0) AS completed
        FROM businesses b ORDER BY b.id DESC"), 'now' => $now]);
case 'a_plan':
    need('admin'); $b = biz(i('business_id')); $days = i('days');
    if ($days <= 0) q("UPDATE businesses SET plan='free', paid_until=0 WHERE id=?", [$b['id']]);
    else q("UPDATE businesses SET plan='paid', paid_until=? WHERE id=?", [max($now, (int)$b['paid_until']) + min($days, 1100) * 86400, $b['id']]);
    out(['ok' => true]);
case 'a_biz_delete':   // removes the business with its owner, staff and their progress; payment records are kept
    need('admin'); $b = biz(i('business_id'));
    $db->beginTransaction();
    q('DELETE FROM progress WHERE user_id IN (SELECT id FROM users WHERE business_id=?)', [$b['id']]);
    q('DELETE FROM assignments WHERE business_id=?', [$b['id']]);
    q('DELETE FROM users WHERE business_id=?', [$b['id']]);
    q('DELETE FROM businesses WHERE id=?', [$b['id']]);
    $db->commit(); out(['ok' => true]);
case 'a_courses':
    need('admin'); $cs = rows('SELECT * FROM courses ORDER BY sort,id');
    foreach ($cs as &$c) { $c=array_merge($c,course_meta($c)); $c['lessons'] = rows('SELECT * FROM lessons WHERE course_id=? ORDER BY sort,id', [$c['id']]);
        foreach ($c['lessons'] as &$l) $l['questions'] = rows('SELECT * FROM questions WHERE lesson_id=? ORDER BY id', [$l['id']]); }
    out(['courses' => $cs,'categories'=>rows('SELECT * FROM categories ORDER BY name')]);
case 'a_category_save':
    need('admin'); $name=s('name',100); if ($name==='') fail('Enter category name.');
    if (val('SELECT 1 FROM categories WHERE LOWER(name)=LOWER(?) AND id<>?',[$name,i('id')])) fail('Category already exists.');
    if (i('id')) q('UPDATE categories SET name=?,active=? WHERE id=?',[$name,i('active')?1:0,i('id')]);
    else q('INSERT INTO categories (name,active) VALUES (?,1)',[$name]);
    out(['ok'=>true]);
case 'a_course_save':
    need('admin'); $t = s('title', 120); if ($t === '') fail('Enter a course title.');
    $descr=s('descr',300); if ($descr === '') fail('Enter a one-line description.');
    $old=i('id')?row('SELECT * FROM courses WHERE id=?',[i('id')]):null;
    if (i('id') && !$old) fail('Course not found.',404);
    $cat=array_key_exists('category_id',$in)?i('category_id'):(int)($old['category_id']??0);
    if ($cat <= 0 || !val('SELECT 1 FROM categories WHERE id=? AND active=1',[$cat])) fail('Select an active category. Category is required.');
    $img=$old['thumbnail']??''; $new='';
    if (!empty($in['thumbnail_data'])) { $new=store_thumb((string)$in['thumbnail_data']); $img=$new; }
    elseif (!empty($in['remove_thumbnail'])) $img='';
    $p=[$t,$descr,i('is_free')?1:0,i('published')?1:0,i('sort'),$cat,$img];
    try {
        if (i('id')) q('UPDATE courses SET title=?,descr=?,is_free=?,published=?,sort=?,category_id=?,thumbnail=? WHERE id=?',[...$p,i('id')]);
        else q('INSERT INTO courses (title,descr,is_free,published,sort,category_id,thumbnail) VALUES (?,?,?,?,?,?,?)',$p);
    } catch (Throwable $e) { if ($new) @unlink(__DIR__.'/uploads/courses/'.$new); throw $e; }
    if ($old && $old['thumbnail']!==$img && preg_match('/^[a-f0-9]{32}\.jpg$/D',$old['thumbnail'])) @unlink(__DIR__.'/uploads/courses/'.$old['thumbnail']);
    out(['id' => i('id') ?: (int)$db->lastInsertId()]);
case 'a_lesson_save':
    need('admin'); $t = s('title', 160); if ($t === '') fail('Enter a lesson title.'); $yt = yt_id(s('yt', 200));
    if (i('id')) { $old = row('SELECT yt FROM lessons WHERE id=?', [i('id')]);
        q('UPDATE lessons SET title=?,yt=?,sort=?,duration=? WHERE id=?', [$t, $yt, i('sort'), ($old && $old['yt'] === $yt) ? i('duration') : 0, i('id')]); }
    else { if (!val('SELECT 1 FROM courses WHERE id=?', [i('course_id')])) fail('Course not found.', 404);
        q('INSERT INTO lessons (course_id,title,yt,sort) VALUES (?,?,?,?)', [i('course_id'), $t, $yt, i('sort')]); }
    out(['id' => i('id') ?: (int)$db->lastInsertId()]);
case 'a_question_save':
    need('admin'); $qq = s('q', 400); $oa = s('a'); $ob = s('b'); if ($qq === '' || $oa === '' || $ob === '') fail('Enter the question and at least two options.');
    $cor = max(0, min(3, i('correct'))); $opts = [$oa, $ob, s('c'), s('d')]; if ($opts[$cor] === '') fail('The correct option is empty.');
    if (i('id')) q('UPDATE questions SET q=?,a=?,b=?,c=?,d=?,correct=? WHERE id=?', [$qq, ...$opts, $cor, i('id')]);
    else { if (!val('SELECT 1 FROM lessons WHERE id=?', [i('lesson_id')])) fail('Lesson not found.', 404);
        q('INSERT INTO questions (lesson_id,q,a,b,c,d,correct) VALUES (?,?,?,?,?,?,?)', [i('lesson_id'), $qq, ...$opts, $cor]); }
    out(['id' => i('id') ?: (int)$db->lastInsertId()]);
case 'a_delete':
    need('admin'); $id = i('id'); $what = s('what', 10);
    if ($what === 'question') q('DELETE FROM questions WHERE id=?', [$id]);
    elseif ($what === 'lesson') { q('DELETE FROM questions WHERE lesson_id=?', [$id]); q('DELETE FROM progress WHERE lesson_id=?', [$id]); q('DELETE FROM lessons WHERE id=?', [$id]); }
    elseif ($what === 'course') { if (val('SELECT 1 FROM lessons WHERE course_id=?', [$id])) fail('Delete the lessons in this course first.'); q('DELETE FROM assignments WHERE course_id=?', [$id]); q('DELETE FROM courses WHERE id=?', [$id]); }
    else fail('Nothing to delete.');
    out(['ok' => true]);
default: fail('Unknown action.', 404);
}

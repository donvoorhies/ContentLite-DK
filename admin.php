<?php
/**
 * Generisk CMS Admin Panel
 * Kræver PHP 8.0+, MySQLi-udvidelse
 * Bruger den fælles konfiguration fra config.php
 */

require_once __DIR__ . '/config.php';

session_name(SESSION_NAME);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_check(): void {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Ugyldig CSRF-token.');
    }
}

function is_logged_in(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ?action=login');
        exit;
    }
}

function is_allowed_iframe_src(string $src): bool {
    $src = trim($src);
    if ($src === '') {
        return false;
    }

    // Accepter protocol-relative embeds fra mange udbydere.
    if (str_starts_with($src, '//')) {
        return true;
    }

    // Tillad HTTP(S), men aldrig script/data/file-skemaer.
    if (!preg_match('~^https?://~i', $src)) {
        return false;
    }

    return !preg_match('~^(?:javascript:|data:|file:|vbscript:)~i', $src);
}

function sanitize_editor_html(string $html): string {
    $allowed_tags = [
        'p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img', 'table', 'thead', 'tbody', 'tr',
        'th', 'td', 'figure', 'figcaption', 'hr', 'span', 'div', 'sub', 'sup', 'iframe',
    ];
    $allowed_global_attrs = ['class', 'title', 'aria-label', 'aria-hidden'];
    $allowed_attr_map = [
        'a' => ['href', 'target', 'rel', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'div' => ['style'],
        'span' => ['style'],
        'figure' => ['style'],
        'figcaption' => ['style'],
        'blockquote' => ['cite', 'style'],
        'pre' => ['style'],
        'code' => ['class', 'style'],
        'p' => ['style'],
        'h1' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'h5' => ['style'],
        'h6' => ['style'],
        'table' => ['style'],
        'thead' => ['style'],
        'tbody' => ['style'],
        'tr' => ['style'],
        'ul' => ['style'],
        'ol' => ['style'],
        'li' => ['style'],
        'sub' => ['style'],
        'sup' => ['style'],
        'hr' => ['style'],
        'iframe' => ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy'],
    ];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $doc->getElementById('root');
    if (!$root) {
        return '';
    }

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowed_tags, $allowed_global_attrs, $allowed_attr_map, $doc) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                if (!in_array($tag, $allowed_tags, true)) {
                    $node->removeChild($child);
                    continue;
                }

                $allowed_attrs = $allowed_global_attrs;
                if (isset($allowed_attr_map[$tag])) {
                    $allowed_attrs = array_merge($allowed_attrs, $allowed_attr_map[$tag]);
                }

                if ($child->hasAttributes()) {
                    $attrs_to_remove = [];
                    foreach (iterator_to_array($child->attributes) as $attr) {
                        $name = strtolower($attr->nodeName);
                        if (!in_array($name, $allowed_attrs, true) || str_starts_with($name, 'on')) {
                            $attrs_to_remove[] = $name;
                            continue;
                        }

                        if ($tag === 'a' && $name === 'href') {
                            $href = trim($attr->nodeValue);
                            if (!preg_match('~^(https?:|mailto:|/|#)~i', $href)) {
                                $attrs_to_remove[] = $name;
                            }
                        }

                        if ($tag === 'img' && $name === 'src') {
                            $src = trim($attr->nodeValue);
                            if (!preg_match('~^(https?:|/|data:image/)~i', $src)) {
                                $attrs_to_remove[] = $name;
                            }
                        }

                        if ($tag === 'iframe' && $name === 'src') {
                            $src = trim($attr->nodeValue);
                            if (!is_allowed_iframe_src($src)) {
                                $attrs_to_remove[] = $name;
                            }
                        }
                    }

                    foreach ($attrs_to_remove as $name) {
                        $child->removeAttribute($name);
                    }
                }

                $sanitizeNode($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    };

    $sanitizeNode($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return trim($output);
}

function har_indhold(string $html): bool {
    $tekst = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
    if ($tekst !== '') {
        return true;
    }

    // Tillad indhold, der kun bestaar af sikre embeds/media.
    return preg_match('~<(iframe|img|video|audio|embed|object)\b~i', $html) === 1;
}

function hent_alle_poster(mysqli $db): array {
    $tCms = table_name('cms_indhold');
    $stmt = $db->prepare("SELECT id, titel, status, opdateret FROM {$tCms} ORDER BY opdateret DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hent_post(mysqli $db, int $id): ?array {
    $tCms = table_name('cms_indhold');
    $stmt = $db->prepare("SELECT * FROM {$tCms} WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function opret_post(mysqli $db, string $titel, string $indhold, string $status): int {
    $tCms = table_name('cms_indhold');
    $stmt = $db->prepare("INSERT INTO {$tCms} (titel, indhold, status) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $titel, $indhold, $status);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function opdater_post(mysqli $db, int $id, string $titel, string $indhold, string $status): bool {
    $tCms = table_name('cms_indhold');
    $stmt = $db->prepare("UPDATE {$tCms} SET titel = ?, indhold = ?, status = ? WHERE id = ?");
    $stmt->bind_param('sssi', $titel, $indhold, $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function slet_post(mysqli $db, int $id): bool {
    $tCms = table_name('cms_indhold');
    $stmt = $db->prepare("DELETE FROM {$tCms} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hent_alle_albums(mysqli $db): array {
    $tAlbums = table_name('galleri_albums');
    $tBilleder = table_name('galleri_billeder');
    $stmt = $db->prepare(
        "SELECT a.*, COUNT(b.id) AS antal_billeder
         FROM {$tAlbums} a
         LEFT JOIN {$tBilleder} b ON b.album_id = a.id
         GROUP BY a.id ORDER BY a.oprettet DESC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hent_album(mysqli $db, int $id): ?array {
    $tAlbums = table_name('galleri_albums');
    $stmt = $db->prepare("SELECT * FROM {$tAlbums} WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function opret_album(mysqli $db, string $navn, string $beskrivelse): int {
    $tAlbums = table_name('galleri_albums');
    $stmt = $db->prepare("INSERT INTO {$tAlbums} (navn, beskrivelse) VALUES (?, ?)");
    $stmt->bind_param('ss', $navn, $beskrivelse);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}

function opdater_album(mysqli $db, int $id, string $navn, string $beskrivelse): bool {
    $tAlbums = table_name('galleri_albums');
    $stmt = $db->prepare("UPDATE {$tAlbums} SET navn = ?, beskrivelse = ? WHERE id = ?");
    $stmt->bind_param('ssi', $navn, $beskrivelse, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function slet_album(mysqli $db, int $id): bool {
    // Slet fysiske filer for alle billeder i albummet
    $tAlbums = table_name('galleri_albums');
    $tBilleder = table_name('galleri_billeder');
    $stmt = $db->prepare("SELECT filnavn FROM {$tBilleder} WHERE album_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $filer = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($filer as $f) {
        $sti = UPLOAD_DIR . $f['filnavn'];
        if (is_file($sti)) @unlink($sti);
    }
    $stmt = $db->prepare("DELETE FROM {$tAlbums} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function hent_billeder_i_album(mysqli $db, int $album_id): array {
    $tBilleder = table_name('galleri_billeder');
    $stmt = $db->prepare(
        "SELECT * FROM {$tBilleder} WHERE album_id = ? ORDER BY sortering ASC, id ASC"
    );
    $stmt->bind_param('i', $album_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hent_billede(mysqli $db, int $id): ?array {
    $tBilleder = table_name('galleri_billeder');
    $stmt = $db->prepare("SELECT * FROM {$tBilleder} WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function opdater_billede(mysqli $db, int $id, string $titel, string $beskrivelse, int $sortering): bool {
    $tBilleder = table_name('galleri_billeder');
    $stmt = $db->prepare(
        "UPDATE {$tBilleder} SET titel = ?, beskrivelse = ?, sortering = ? WHERE id = ?"
    );
    $stmt->bind_param('ssii', $titel, $beskrivelse, $sortering, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function slet_billede(mysqli $db, int $id): bool {
    $tBilleder = table_name('galleri_billeder');
    $b = hent_billede($db, $id);
    if ($b) {
        $sti = UPLOAD_DIR . $b['filnavn'];
        if (is_file($sti)) @unlink($sti);
    }
    $stmt = $db->prepare("DELETE FROM {$tBilleder} WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function upload_billede(mysqli $db, int $album_id, array $fil, string $titel, string $beskrivelse): string {
    $tBilleder = table_name('galleri_billeder');
    if ($fil['error'] !== UPLOAD_ERR_OK) {
        return 'Uploadfejl (kode ' . $fil['error'] . ').';
    }
    if ($fil['size'] > MAX_FILE_SIZE) {
        return 'Filen er for stor (max 5 MB).';
    }
    $mime = mime_content_type($fil['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES, true)) {
        return 'Filtypen er ikke tilladt. Kun JPEG, PNG, GIF og WebP.';
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $ext      = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $filnavn  = bin2hex(random_bytes(12)) . '.' . $ext;
    $maal     = UPLOAD_DIR . $filnavn;
    if (!move_uploaded_file($fil['tmp_name'], $maal)) {
        return 'Kunne ikke gemme filen på serveren.';
    }
    $stmt = $db->prepare(
        "INSERT INTO {$tBilleder} (album_id, filnavn, titel, beskrivelse) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('isss', $album_id, $filnavn, $titel, $beskrivelse);
    $stmt->execute();
    $stmt->close();
    return '';
}

// ─── Actions / routing ────────────────────────────────────────────────────────
$action  = $_GET['action'] ?? 'liste';
$message = '';
$error   = '';

// Login
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $u = trim($_POST['brugernavn'] ?? '');
        $p = trim($_POST['kodeord']    ?? '');
        if ($u === ADMIN_USER && password_verify($p, ADMIN_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            header('Location: ?action=liste');
            exit;
        } else {
            $error = 'Forkert brugernavn eller kodeord.';
        }
    }
}

// Logout
if ($action === 'logout') {
    session_destroy();
    header('Location: ?action=login');
    exit;
}

// Handlinger der kræver login
$galleri_actions = [
    'galleri', 'galleri-nyt-album', 'galleri-rediger-album',
    'galleri-gem-album', 'galleri-slet-album',
    'galleri-album', 'galleri-upload', 'galleri-rediger-billede',
    'galleri-gem-billede', 'galleri-slet-billede',
];

if (in_array($action, ['liste', 'ny', 'rediger', 'gem', 'slet'], true)) {
    require_login();
    $db = db();

    if ($action === 'gem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $titel   = trim($_POST['titel']   ?? '');
        // TinyMCE sender HTML – tillad sikre tags, strip alt andet
        $indhold_raa = $_POST['indhold'] ?? '';
        $indhold = sanitize_editor_html($indhold_raa);
        $status  = in_array($_POST['status'] ?? '', ['udgivet', 'kladde']) ? $_POST['status'] : 'kladde';
        $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;

        if ($titel === '' || !har_indhold($indhold)) {
            $error = 'Titel og indhold må ikke være tomme.';
            $action = $id > 0 ? 'rediger' : 'ny';
        } elseif ($id > 0) {
            opdater_post($db, $id, $titel, $indhold, $status);
            $message = 'Indholdet er opdateret.';
            $action  = 'liste';
        } else {
            opret_post($db, $titel, $indhold, $status);
            $message = 'Nyt indhold er oprettet.';
            $action  = 'liste';
        }
    }

    if ($action === 'slet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            slet_post($db, $id);
            $message = 'Indholdet er slettet.';
        }
        $action = 'liste';
    }
}

// ─── Galleri routing ─────────────────────────────────────────────────────────
if (in_array($action, $galleri_actions, true)) {
    require_login();
    $db = db();

    // Gem album (opret / opdater)
    if ($action === 'galleri-gem-album' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $navn        = trim($_POST['navn']        ?? '');
        $beskrivelse = trim($_POST['beskrivelse'] ?? '');
        $id          = (int)($_POST['id'] ?? 0);
        if ($navn === '') {
            $error  = 'Albumnavnet må ikke være tomt.';
            $action = $id > 0 ? 'galleri-rediger-album' : 'galleri-nyt-album';
        } elseif ($id > 0) {
            opdater_album($db, $id, $navn, $beskrivelse);
            $message = 'Albummet er opdateret.';
            $action  = 'galleri';
        } else {
            opret_album($db, $navn, $beskrivelse);
            $message = 'Albummet er oprettet.';
            $action  = 'galleri';
        }
    }

    // Slet album
    if ($action === 'galleri-slet-album' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { slet_album($db, $id); $message = 'Albummet er slettet.'; }
        $action = 'galleri';
    }

    // Upload billede(r)
    if ($action === 'galleri-upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $album_id = (int)($_POST['album_id'] ?? 0);
        $filer    = $_FILES['billeder'] ?? null;
        if ($album_id < 1 || !$filer) {
            $error = 'Ugyldigt album eller ingen filer valgt.';
        } else {
            $fejl = [];
            // Omstrukturer $_FILES til array af individuelle filer
            $antal = count($filer['name']);
            for ($i = 0; $i < $antal; $i++) {
                if ($filer['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $enkelt = [
                    'name'     => $filer['name'][$i],
                    'tmp_name' => $filer['tmp_name'][$i],
                    'size'     => $filer['size'][$i],
                    'error'    => $filer['error'][$i],
                ];
                $titel = trim($_POST['titler'][$i] ?? pathinfo($filer['name'][$i], PATHINFO_FILENAME));
                $e = upload_billede($db, $album_id, $enkelt, $titel, '');
                if ($e) $fejl[] = htmlspecialchars($filer['name'][$i]) . ': ' . $e;
            }
            $message = $fejl
                ? implode('<br>', $fejl)
                : 'Billederne er uploadet.';
            if ($fejl) $error = $message;
            else       $message = $message;
        }
        $action = 'galleri-album';
        $_GET['id'] = $album_id;
    }

    // Gem billedmeta
    if ($action === 'galleri-gem-billede' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id          = (int)($_POST['id']          ?? 0);
        $titel       = trim($_POST['titel']        ?? '');
        $beskrivelse = trim($_POST['beskrivelse']  ?? '');
        $sortering   = (int)($_POST['sortering']   ?? 0);
        $album_id    = (int)($_POST['album_id']    ?? 0);
        if ($id > 0) {
            opdater_billede($db, $id, $titel, $beskrivelse, $sortering);
            $message = 'Billedet er opdateret.';
        }
        $action     = 'galleri-album';
        $_GET['id'] = $album_id;
    }

    // Slet billede
    if ($action === 'galleri-slet-billede' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id       = (int)($_POST['id']       ?? 0);
        $album_id = (int)($_POST['album_id'] ?? 0);
        if ($id > 0) { slet_billede($db, $id); $message = 'Billedet er slettet.'; }
        $action     = 'galleri-album';
        $_GET['id'] = $album_id;
    }
}

// ─── HTML-output ──────────────────────────────────────────────────────────────
$page_title = match($action) {
    'login'                 => 'Log ind',
    'ny'                    => 'Nyt indhold',
    'rediger'               => 'Rediger indhold',
    'galleri'               => 'Galleri – albums',
    'galleri-nyt-album'     => 'Nyt album',
    'galleri-rediger-album' => 'Rediger album',
    'galleri-album'         => 'Album',
    'galleri-rediger-billede' => 'Rediger billede',
    default                 => 'Indholdsoversigt',
};

$edit_post = null;
if ($action === 'rediger' && isset($db)) {
    $edit_id   = (int)($_GET['id'] ?? 0);
    $edit_post = hent_post($db, $edit_id);
    if (!$edit_post) { $action = 'liste'; }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title) ?> – CMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Reset & tokens ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #f8f7f4;
    --surface:   #ffffff;
    --border:    #e4e2dd;
    --text:      #1a1917;
    --muted:     #7a786f;
    --accent:    #2563eb;
    --accent-h:  #1d4ed8;
    --danger:    #dc2626;
    --success:   #16a34a;
    --warn-bg:   #fef9ec;
    --warn-bdr:  #f0d070;
    --radius:    6px;
    --shadow:    0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
    --font:      'DM Sans', sans-serif;
    --mono:      'DM Mono', monospace;
}

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    font-size: 15px;
    line-height: 1.6;
    min-height: 100vh;
}

/* ── Layout ── */
.wrapper { max-width: 900px; margin: 0 auto; padding: 0 1.5rem 4rem; }

header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 2rem;
}
.logo {
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
}
.logo span { color: var(--text); }
nav a {
    font-size: .85rem;
    color: var(--muted);
    text-decoration: none;
    margin-left: 1.5rem;
    transition: color .15s;
}
nav a:hover { color: var(--text); }
nav a.active { color: var(--accent); font-weight: 500; }

h1 {
    font-size: 1.35rem;
    font-weight: 600;
    letter-spacing: -.02em;
    margin-bottom: 1.5rem;
}

/* ── Messages ── */
.msg, .err {
    padding: .75rem 1rem;
    border-radius: var(--radius);
    font-size: .875rem;
    margin-bottom: 1.25rem;
}
.msg { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success); }
.err { background: #fef2f2; border: 1px solid #fecaca; color: var(--danger); }

/* ── Card ── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

/* ── Table ── */
table { width: 100%; border-collapse: collapse; }
thead th {
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--muted);
    padding: .75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border);
    background: var(--bg);
}
tbody tr { transition: background .1s; }
tbody tr:hover { background: #faf9f7; }
tbody td {
    padding: .8rem 1rem;
    border-bottom: 1px solid var(--border);
    font-size: .875rem;
    vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }

.badge {
    display: inline-block;
    font-family: var(--mono);
    font-size: .7rem;
    padding: .2em .55em;
    border-radius: 3px;
    font-weight: 500;
}
.badge-udgivet { background: #dcfce7; color: #15803d; }
.badge-kladde  { background: #f3f4f6; color: #6b7280; }

/* ── Table actions ── */
.tbl-actions { display: flex; gap: .5rem; }
.btn-sm {
    font-family: var(--font);
    font-size: .78rem;
    font-weight: 500;
    padding: .3rem .75rem;
    border-radius: var(--radius);
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: background .15s, border-color .15s;
}
.btn-edit  { border-color: var(--border); background: var(--surface); color: var(--text); }
.btn-edit:hover { background: var(--bg); }
.btn-del   { border-color: #fecaca; background: #fff5f5; color: var(--danger); }
.btn-del:hover { background: #fee2e2; }

/* ── Forms ── */
.form-card { padding: 1.75rem; }
.form-group { margin-bottom: 1.25rem; }
label {
    display: block;
    font-size: .8rem;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: .4rem;
}
input[type=text], input[type=password], select, textarea {
    width: 100%;
    font-family: var(--font);
    font-size: .9rem;
    color: var(--text);
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .6rem .85rem;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    -webkit-appearance: none;
}
input:focus, select:focus, textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
textarea { min-height: 220px; resize: vertical; line-height: 1.7; }

/* ── Buttons ── */
.btn {
    font-family: var(--font);
    font-size: .875rem;
    font-weight: 500;
    padding: .6rem 1.25rem;
    border-radius: var(--radius);
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: background .15s;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-h); }
.btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-ghost:hover { background: var(--bg); }

.form-actions { display: flex; gap: .75rem; align-items: center; padding-top: .5rem; }

/* ── Toolbar ── */
.toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }

/* ── Login ── */
.login-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 80vh;
}
.login-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 2.5rem 2rem;
    width: 100%;
    max-width: 360px;
}
.login-card h1 { font-size: 1.15rem; margin-bottom: 1.5rem; text-align: center; }

/* ── Notice ── */
.notice {
    background: var(--warn-bg);
    border: 1px solid var(--warn-bdr);
    border-radius: var(--radius);
    padding: .65rem .9rem;
    font-size: .8rem;
    color: #92400e;
    margin-bottom: 1.25rem;
}
.notice code { font-family: var(--mono); }

/* ══════════════════════════════════════════
   RESPONSIVE WEB DESIGN  —  mobile-first
   Breakpoints:
     xs  < 480px   (lille telefon)
     sm  480–767px (telefon/stor telefon)
     md  768–1023px (tablet)
     lg  ≥ 1024px  (desktop)
   ══════════════════════════════════════════ */

/* ── Hamburger-knap (kun synlig på mobil) ── */
.nav-toggle {
    display: none;
    flex-direction: column;
    justify-content: center;
    gap: 5px;
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .45rem .55rem;
    cursor: pointer;
    width: 38px; height: 38px;
}
.nav-toggle span {
    display: block;
    width: 18px; height: 2px;
    background: var(--text);
    border-radius: 2px;
    transition: transform .2s, opacity .2s;
}
/* Animate to ✕ when open */
.nav-toggle.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.nav-toggle.open span:nth-child(2) { opacity: 0; }
.nav-toggle.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* ── xs / sm  ≤ 767px ── */
@media (max-width: 767px) {
    .wrapper { padding: 0 1rem 3rem; }

    /* Header stacks logo + toggle on one row */
    header {
        position: relative;
        flex-wrap: wrap;
        gap: 0;
        padding: 1rem 0;
    }
    .nav-toggle { display: flex; margin-left: auto; }

    /* Nav becomes a full-width dropdown */
    nav {
        display: none;
        flex-direction: column;
        width: 100%;
        margin-top: .75rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    nav.open { display: flex; }
    nav a {
        margin: 0;
        padding: .8rem 1rem;
        border-bottom: 1px solid var(--border);
        font-size: .9rem;
    }
    nav a:last-child { border-bottom: none; }
    nav a:hover, nav a.active { background: var(--bg); }

    /* Toolbar: heading + button stack vertically */
    .toolbar {
        flex-direction: column;
        align-items: flex-start;
        gap: .75rem;
    }
    .toolbar .btn { width: 100%; text-align: center; }

    /* Table: hide less-critical columns, show as card layout */
    table { display: block; }
    thead { display: none; }          /* hide column headings */
    tbody { display: block; }
    tbody tr {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: .25rem .75rem;
        padding: .9rem 1rem;
        border-bottom: 1px solid var(--border);
    }
    tbody tr:last-child { border-bottom: none; }
    tbody td {
        display: block;
        padding: 0;
        border: none;
        font-size: .875rem;
    }
    /* ID cell: tiny mono label, spans nothing special */
    tbody td:nth-child(1) { color: var(--muted); font-size: .72rem; grid-column: 1; }
    /* Title: full width, bold */
    tbody td:nth-child(2) { font-weight: 500; grid-column: 1; }
    /* Badge */
    tbody td:nth-child(3) { grid-column: 1; }
    /* Date: muted small */
    tbody td:nth-child(4) { color: var(--muted); font-size: .78rem; grid-column: 1; }
    /* Action buttons: placed in column 2, spanning rows 1-3 */
    tbody td:nth-child(5) {
        grid-column: 2;
        grid-row: 1 / 5;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: .4rem;
        align-items: flex-end;
    }
    .tbl-actions { flex-direction: column; align-items: stretch; }
    .btn-sm { text-align: center; }

    /* Form: full-width status select */
    .form-group[style*="max-width"] { max-width: 100% !important; }

    /* Form actions: stack on mobile */
    .form-actions {
        flex-wrap: wrap;
        gap: .6rem;
    }
    .form-actions .btn { flex: 1 1 calc(50% - .3rem); text-align: center; }
    .form-actions span { flex: 1 1 100%; text-align: left; margin-left: 0 !important; }

    /* Login card: full-width with padding */
    .login-card { padding: 2rem 1.25rem; max-width: 100%; }

    h1 { font-size: 1.15rem; }
}

/* ── xs only  < 480px ── */
@media (max-width: 479px) {
    body { font-size: 14px; }
    .form-actions .btn { flex: 1 1 100%; }
    .card { border-radius: 0; border-left: none; border-right: none; }
    .wrapper { padding: 0 0 3rem; }
    header, .toolbar, .msg, .err, h1 { padding-left: 1rem; padding-right: 1rem; }
    .msg, .err { border-radius: 0; border-left: none; border-right: none; }
    .form-card { padding: 1.25rem 1rem; }
}

/* ── md  768 – 1023px (tablet) ── */
@media (min-width: 768px) and (max-width: 1023px) {
    .wrapper { max-width: 720px; padding: 0 1.25rem 3.5rem; }
    /* Status column takes less space on tablet */
    tbody td:nth-child(4) { font-size: .8rem; }
}

/* ── Galleri: Albums grid ── */
.album-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.album-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow .15s;
}
.album-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.09); }
.album-thumb {
    width: 100%;
    aspect-ratio: 16/9;
    object-fit: cover;
    background: var(--bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: var(--border);
}
.album-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.album-body { padding: .85rem 1rem; flex: 1; }
.album-body h3 { font-size: .95rem; font-weight: 600; margin-bottom: .2rem; }
.album-body p  { font-size: .78rem; color: var(--muted); margin: 0; }
.album-footer {
    display: flex;
    gap: .5rem;
    padding: .6rem 1rem;
    border-top: 1px solid var(--border);
    background: var(--bg);
}

/* ── Galleri: Billedgitter / -liste ── */
.view-toggle { display: flex; gap: .4rem; }
.view-btn {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .35rem .6rem;
    cursor: pointer;
    font-size: .85rem;
    color: var(--muted);
    line-height: 1;
    transition: background .12s, color .12s;
}
.view-btn.active, .view-btn:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

/* Grid-visning */
.billede-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .75rem;
}
.billede-kort {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: box-shadow .15s;
    display: flex;
    flex-direction: column;
}
.billede-kort:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.billede-thumb {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    display: block;
    background: var(--bg);
}
.billede-meta {
    padding: .5rem .65rem;
    font-size: .75rem;
    flex: 1;
}
.billede-meta strong { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.billede-meta span   { color: var(--muted); }
.billede-actions {
    display: flex;
    gap: .35rem;
    padding: .45rem .65rem;
    border-top: 1px solid var(--border);
    background: var(--bg);
}

/* Liste-visning */
.billede-liste { display: none; }
.billede-liste.vis { display: block; }
.billede-grid.vis  { display: grid; }
.billede-grid.skjul { display: none; }

/* Upload-zone */
.upload-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 2rem 1.5rem;
    text-align: center;
    background: var(--bg);
    transition: border-color .15s, background .15s;
    cursor: pointer;
}
.upload-zone.drag-over {
    border-color: var(--accent);
    background: #eff6ff;
}
.upload-zone p { font-size: .875rem; color: var(--muted); margin: .4rem 0 0; }
.upload-zone input[type=file] { display: none; }
.upload-preview {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: 1rem;
}
.upload-preview-item {
    position: relative;
    width: 90px;
}
.upload-preview-item img {
    width: 90px; height: 90px;
    object-fit: cover;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    display: block;
}
.upload-preview-item input {
    margin-top: .3rem;
    font-size: .72rem;
    padding: .25rem .4rem;
}
.upload-remove {
    position: absolute; top: 3px; right: 3px;
    background: rgba(0,0,0,.55); color: #fff;
    border: none; border-radius: 50%;
    width: 18px; height: 18px;
    font-size: .65rem; line-height: 18px; text-align: center;
    cursor: pointer;
}

/* Lightbox */
.lightbox {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.85);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.lightbox.open { display: flex; }
.lightbox img {
    max-width: 90vw; max-height: 85vh;
    border-radius: var(--radius);
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
    object-fit: contain;
}
.lightbox-close {
    position: absolute; top: 1.25rem; right: 1.5rem;
    background: none; border: none; color: #fff;
    font-size: 2rem; cursor: pointer; line-height: 1;
}

/* ── Galleri RWD ── */
@media (max-width: 767px) {
    .album-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
    .billede-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
    .upload-zone { padding: 1.25rem 1rem; }
}
@media (max-width: 479px) {
    .album-grid { grid-template-columns: 1fr 1fr; gap: .6rem; }
    .billede-grid { grid-template-columns: 1fr 1fr; gap: .5rem; }
}
</style>
</head>
<body>
<?php if ($action !== 'login'): ?>
<div class="wrapper">
<header>
    <div class="logo">CMS <span>Admin</span></div>
    <button class="nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <nav id="mainNav">
        <a href="?action=liste"   class="<?= $action === 'liste'                                    ? 'active' : '' ?>">Indhold</a>
        <a href="?action=ny"      class="<?= $action === 'ny'                                       ? 'active' : '' ?>">Nyt indhold</a>
        <a href="?action=galleri" class="<?= str_starts_with($action, 'galleri')                    ? 'active' : '' ?>">Galleri</a>
        <a href="?action=logout">Log ud</a>
    </nav>
</header>

<?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="err"><?= htmlspecialchars($error)   ?></div><?php endif; ?>
<?php endif; ?>

<?php
// ══════════════════════════════════════════
// VIEW: LOGIN
// ══════════════════════════════════════════
if ($action === 'login'): ?>
<div class="wrapper">
<div class="login-wrap">
<div class="login-card">
    <h1>Log ind</h1>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="notice">
        Standardkodeord: <code>skift_dette_kodeord</code> – skift det i koden før produktion.
    </div>
    <form method="post" action="?action=login" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="form-group">
            <label for="brugernavn">Brugernavn</label>
            <input type="text" id="brugernavn" name="brugernavn" required autofocus
                   value="<?= htmlspecialchars($_POST['brugernavn'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="kodeord">Kodeord</label>
            <input type="password" id="kodeord" name="kodeord" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Log ind</button>
    </form>
</div>
</div>
</div>

<?php
// ══════════════════════════════════════════
// VIEW: LISTE
// ══════════════════════════════════════════
elseif ($action === 'liste'):
    $poster = hent_alle_poster($db);
?>
<div class="toolbar">
    <h1>Indhold <span style="color:var(--muted);font-weight:400;font-size:1rem">(<?= count($poster) ?>)</span></h1>
    <a href="?action=ny" class="btn btn-primary">+ Nyt indhold</a>
</div>
<div class="card">
<?php if (empty($poster)): ?>
    <p style="padding:2rem;text-align:center;color:var(--muted);font-size:.9rem">
        Ingen indhold endnu. <a href="?action=ny" style="color:var(--accent)">Opret det første</a>.
    </p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Titel</th>
                <th>Status</th>
                <th>Sidst opdateret</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($poster as $p): ?>
            <tr>
                <td style="font-family:var(--mono);color:var(--muted);width:3rem"><?= $p['id'] ?></td>
                <td><?= htmlspecialchars($p['titel']) ?></td>
                <td>
                    <span class="badge badge-<?= $p['status'] ?>">
                        <?= $p['status'] === 'udgivet' ? 'Udgivet' : 'Kladde' ?>
                    </span>
                </td>
                <td style="color:var(--muted);font-size:.82rem">
                    <?= htmlspecialchars(date('d.m.Y H:i', strtotime($p['opdateret']))) ?>
                </td>
                <td>
                    <div class="tbl-actions">
                        <a href="?action=rediger&id=<?= $p['id'] ?>" class="btn-sm btn-edit">Rediger</a>
                        <form method="post" action="?action=slet" style="display:inline"
                              onsubmit="return confirm('Slet \'<?= addslashes(htmlspecialchars($p['titel'])) ?>\'?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-sm btn-del">Slet</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════
// VIEW: NY / REDIGER
// ══════════════════════════════════════════
elseif (in_array($action, ['ny', 'rediger'], true)):
    $is_edit = $action === 'rediger' && $edit_post;
    $v_titel   = htmlspecialchars($edit_post['titel']   ?? ($_POST['titel']   ?? ''));
    // Indhold er HTML fra TinyMCE – må ikke escapes igen her
    $v_indhold = $edit_post['indhold'] ?? ($_POST['indhold'] ?? '');
    $v_status  = $edit_post['status'] ?? ($_POST['status'] ?? 'kladde');
?>
<h1><?= $is_edit ? 'Rediger indhold' : 'Nyt indhold' ?></h1>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card form-card">
    <form method="post" action="?action=gem">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= (int)$edit_post['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="titel">Titel</label>
            <input type="text" id="titel" name="titel" required
                   value="<?= $v_titel ?>" placeholder="Indtast titel…">
        </div>

        <div class="form-group">
            <label for="indhold">Indhold</label>
            <textarea id="indhold" name="indhold" class="tinymce-editor"><?= $v_indhold ?></textarea>
        </div>

        <div class="form-group" style="max-width:200px">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="kladde"  <?= $v_status === 'kladde'  ? 'selected' : '' ?>>Kladde</option>
                <option value="udgivet" <?= $v_status === 'udgivet' ? 'selected' : '' ?>>Udgivet</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <?= $is_edit ? 'Gem ændringer' : 'Opret indhold' ?>
            </button>
            <a href="?action=liste" class="btn btn-ghost">Annuller</a>
            <?php if ($is_edit): ?>
                <span style="margin-left:auto;font-size:.78rem;color:var(--muted)">
                    Oprettet: <?= htmlspecialchars(date('d.m.Y H:i', strtotime($edit_post['oprettet']))) ?>
                </span>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php endif; ?>

<?php
// ══════════════════════════════════════════
// VIEW: GALLERI – ALBUMOVERSIGT
// ══════════════════════════════════════════
if ($action === 'galleri'):
    $albums = hent_alle_albums($db);
?>
<div class="toolbar">
    <h1>Galleri <span style="color:var(--muted);font-weight:400;font-size:1rem">(<?= count($albums) ?> albums)</span></h1>
    <a href="?action=galleri-nyt-album" class="btn btn-primary">+ Nyt album</a>
</div>
<?php if (empty($albums)): ?>
<div class="card" style="padding:2rem;text-align:center;color:var(--muted);font-size:.9rem">
    Ingen albums endnu. <a href="?action=galleri-nyt-album" style="color:var(--accent)">Opret det første</a>.
</div>
<?php else: ?>
<div class="album-grid">
<?php foreach ($albums as $alb):
    $billeder = hent_billeder_i_album($db, $alb['id']);
    $forste   = $billeder[0] ?? null;
?>
    <div class="album-card">
        <a href="?action=galleri-album&id=<?= $alb['id'] ?>" class="album-thumb" style="text-decoration:none">
            <?php if ($forste): ?>
                <img src="<?= htmlspecialchars(UPLOAD_URL . $forste['filnavn']) ?>"
                     alt="<?= htmlspecialchars($forste['titel']) ?>">
            <?php else: ?>
                <span>🖼</span>
            <?php endif; ?>
        </a>
        <div class="album-body">
            <h3><?= htmlspecialchars($alb['navn']) ?></h3>
            <p><?= $alb['antal_billeder'] ?> billede<?= $alb['antal_billeder'] != 1 ? 'r' : '' ?></p>
            <?php if ($alb['beskrivelse']): ?>
                <p style="margin-top:.3rem"><?= htmlspecialchars(mb_strimwidth($alb['beskrivelse'], 0, 60, '…')) ?></p>
            <?php endif; ?>
        </div>
        <div class="album-footer">
            <a href="?action=galleri-album&id=<?= $alb['id'] ?>" class="btn-sm btn-edit" style="flex:1;text-align:center">Åbn</a>
            <a href="?action=galleri-rediger-album&id=<?= $alb['id'] ?>" class="btn-sm btn-edit">Rediger</a>
            <form method="post" action="?action=galleri-slet-album"
                  onsubmit="return confirm('Slet albummet og alle dets billeder?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id" value="<?= $alb['id'] ?>">
                <button type="submit" class="btn-sm btn-del">Slet</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════
// VIEW: NY / REDIGER ALBUM
// ══════════════════════════════════════════
elseif (in_array($action, ['galleri-nyt-album','galleri-rediger-album'], true)):
    $is_edit  = $action === 'galleri-rediger-album';
    $edit_alb = null;
    if ($is_edit) {
        $edit_alb = hent_album($db, (int)($_GET['id'] ?? 0));
        if (!$edit_alb) { header('Location: ?action=galleri'); exit; }
    }
    $v_navn = htmlspecialchars($edit_alb['navn'] ?? ($_POST['navn'] ?? ''));
    $v_besk = htmlspecialchars($edit_alb['beskrivelse'] ?? ($_POST['beskrivelse'] ?? ''));
?>
<h1><?= $is_edit ? 'Rediger album' : 'Nyt album' ?></h1>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card form-card">
    <form method="post" action="?action=galleri-gem-album">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($is_edit): ?>
            <input type="hidden" name="id" value="<?= (int)$edit_alb['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="navn">Albumnavn</label>
            <input type="text" id="navn" name="navn" required value="<?= $v_navn ?>" placeholder="Fx Sommer 2024">
        </div>
        <div class="form-group">
            <label for="beskrivelse">Beskrivelse <span style="font-weight:400;text-transform:none">(valgfri)</span></label>
            <textarea id="beskrivelse" name="beskrivelse" style="min-height:100px"
                      placeholder="Kort beskrivelse af albummet…"><?= $v_besk ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Gem ændringer' : 'Opret album' ?></button>
            <a href="?action=galleri" class="btn btn-ghost">Annuller</a>
        </div>
    </form>
</div>

<?php
// ══════════════════════════════════════════
// VIEW: ALBUM – BILLEDOVERSIGT + UPLOAD
// ══════════════════════════════════════════
elseif ($action === 'galleri-album'):
    $album_id  = (int)($_GET['id'] ?? 0);
    $album     = hent_album($db, $album_id);
    if (!$album) { header('Location: ?action=galleri'); exit; }
    $billeder  = hent_billeder_i_album($db, $album_id);
?>
<div class="toolbar" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h1><?= htmlspecialchars($album['navn']) ?></h1>
        <p style="font-size:.82rem;color:var(--muted);margin-top:.15rem">
            <?= count($billeder) ?> billede<?= count($billeder) != 1 ? 'r' : '' ?>
            <?php if ($album['beskrivelse']): ?>
                &nbsp;·&nbsp; <?= htmlspecialchars(mb_strimwidth($album['beskrivelse'], 0, 80, '…')) ?>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <div class="view-toggle">
            <button class="view-btn active" id="btnGrid" title="Gittervisning">⊞</button>
            <button class="view-btn"        id="btnListe" title="Listevisning">☰</button>
        </div>
        <a href="?action=galleri-rediger-album&id=<?= $album_id ?>" class="btn btn-ghost" style="font-size:.82rem">Rediger album</a>
        <a href="?action=galleri" class="btn btn-ghost" style="font-size:.82rem">← Alle albums</a>
    </div>
</div>

<!-- Upload-sektion -->
<div class="card form-card" style="margin-bottom:1.5rem">
    <form method="post" action="?action=galleri-upload" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="album_id"   value="<?= $album_id ?>">
        <label style="margin-bottom:.5rem;display:block">Upload billeder</label>
        <div class="upload-zone" id="uploadZone">
            <input type="file" name="billeder[]" id="filInput" multiple accept="image/*">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('filInput').click()">
                Vælg filer
            </button>
            <p>eller træk og slip her &nbsp;·&nbsp; JPEG, PNG, GIF, WebP &nbsp;·&nbsp; max 5 MB pr. fil</p>
        </div>
        <div class="upload-preview" id="uploadPreview"></div>
        <div class="form-actions" id="uploadActions" style="display:none">
            <button type="submit" class="btn btn-primary">Upload valgte billeder</button>
            <button type="button" class="btn btn-ghost" id="btnRydPreview">Ryd</button>
        </div>
    </form>
</div>

<?php if (empty($billeder)): ?>
<p style="color:var(--muted);font-size:.9rem;text-align:center;padding:1.5rem 0">
    Ingen billeder i dette album endnu — upload det første ovenfor.
</p>
<?php else: ?>

<!-- GITTERVISNING -->
<div class="billede-grid vis" id="gridView">
<?php foreach ($billeder as $b): ?>
    <div class="billede-kort">
        <img src="<?= htmlspecialchars(UPLOAD_URL . $b['filnavn']) ?>"
             alt="<?= htmlspecialchars($b['titel']) ?>"
             class="billede-thumb"
             onclick="aabLightbox('<?= htmlspecialchars(UPLOAD_URL . $b['filnavn'], ENT_QUOTES) ?>')"
             style="cursor:zoom-in">
        <div class="billede-meta">
            <strong title="<?= htmlspecialchars($b['titel']) ?>"><?= htmlspecialchars($b['titel'] ?: '(ingen titel)') ?></strong>
            <span>sortering: <?= (int)$b['sortering'] ?></span>
        </div>
        <div class="billede-actions">
            <a href="?action=galleri-rediger-billede&id=<?= $b['id'] ?>&album_id=<?= $album_id ?>"
               class="btn-sm btn-edit" style="flex:1;text-align:center">Rediger</a>
            <form method="post" action="?action=galleri-slet-billede"
                  onsubmit="return confirm('Slet dette billede permanent?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="id"       value="<?= $b['id'] ?>">
                <input type="hidden" name="album_id" value="<?= $album_id ?>">
                <button type="submit" class="btn-sm btn-del">Slet</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- LISTEVISNING -->
<div class="card billede-liste" id="listeView">
    <table>
        <thead>
            <tr>
                <th style="width:70px">Billede</th>
                <th>Titel</th>
                <th>Beskrivelse</th>
                <th>Sort.</th>
                <th>Uploadet</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($billeder as $b): ?>
            <tr>
                <td>
                    <img src="<?= htmlspecialchars(UPLOAD_URL . $b['filnavn']) ?>"
                         alt="" style="width:54px;height:54px;object-fit:cover;border-radius:4px;cursor:zoom-in"
                         onclick="aabLightbox('<?= htmlspecialchars(UPLOAD_URL . $b['filnavn'], ENT_QUOTES) ?>')">
                </td>
                <td><?= htmlspecialchars($b['titel'] ?: '—') ?></td>
                <td style="color:var(--muted);font-size:.82rem">
                    <?= htmlspecialchars(mb_strimwidth($b['beskrivelse'] ?? '', 0, 55, '…')) ?>
                </td>
                <td style="font-family:var(--mono);color:var(--muted)"><?= (int)$b['sortering'] ?></td>
                <td style="color:var(--muted);font-size:.78rem">
                    <?= date('d.m.Y', strtotime($b['oprettet'])) ?>
                </td>
                <td>
                    <div class="tbl-actions">
                        <a href="?action=galleri-rediger-billede&id=<?= $b['id'] ?>&album_id=<?= $album_id ?>"
                           class="btn-sm btn-edit">Rediger</a>
                        <form method="post" action="?action=galleri-slet-billede"
                              onsubmit="return confirm('Slet dette billede permanent?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="id"       value="<?= $b['id'] ?>">
                            <input type="hidden" name="album_id" value="<?= $album_id ?>">
                            <button type="submit" class="btn-sm btn-del">Slet</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="this.classList.remove('open')">
    <button class="lightbox-close" onclick="document.getElementById('lightbox').classList.remove('open')">×</button>
    <img id="lightboxImg" src="" alt="">
</div>

<?php
// ══════════════════════════════════════════
// VIEW: REDIGER BILLEDE
// ══════════════════════════════════════════
elseif ($action === 'galleri-rediger-billede'):
    $bid      = (int)($_GET['id']       ?? 0);
    $album_id = (int)($_GET['album_id'] ?? 0);
    $billede  = hent_billede($db, $bid);
    if (!$billede) { header("Location: ?action=galleri-album&id=$album_id"); exit; }
?>
<h1>Rediger billede</h1>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start">
    <img src="<?= htmlspecialchars(UPLOAD_URL . $billede['filnavn']) ?>"
         alt="<?= htmlspecialchars($billede['titel']) ?>"
         style="width:220px;height:220px;object-fit:cover;border-radius:var(--radius);
                border:1px solid var(--border);flex-shrink:0">
    <div class="card form-card" style="flex:1;min-width:260px">
        <form method="post" action="?action=galleri-gem-billede">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="id"       value="<?= (int)$billede['id'] ?>">
            <input type="hidden" name="album_id" value="<?= $album_id ?>">
            <div class="form-group">
                <label for="b_titel">Titel</label>
                <input type="text" id="b_titel" name="titel"
                       value="<?= htmlspecialchars($billede['titel']) ?>" placeholder="Billedtitel">
            </div>
            <div class="form-group">
                <label for="b_besk">Beskrivelse</label>
                <textarea id="b_besk" name="beskrivelse" style="min-height:90px"
                          placeholder="Valgfri billedbeskrivelse…"><?= htmlspecialchars($billede['beskrivelse'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="max-width:140px">
                <label for="b_sort">Sorteringsorden</label>
                <input type="number" id="b_sort" name="sortering" min="0"
                       value="<?= (int)$billede['sortering'] ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Gem ændringer</button>
                <a href="?action=galleri-album&id=<?= $album_id ?>" class="btn btn-ghost">Annuller</a>
            </div>
        </form>
    </div>
</div>

<?php endif; // ── galleri views slut ──
?>

<?php if ($action !== 'login'): ?>
</div><!-- .wrapper -->
<?php endif; ?>

<script>
// ── Hamburger-menu toggle ─────────────────────────────────────────────────────
(function () {
    var btn = document.getElementById('navToggle');
    var nav = document.getElementById('mainNav');
    if (!btn || !nav) return;
    btn.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        btn.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    nav.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') {
            nav.classList.remove('open');
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
})();

// ── Galleri: grid / liste skift ───────────────────────────────────────────────
(function () {
    var btnGrid   = document.getElementById('btnGrid');
    var btnListe  = document.getElementById('btnListe');
    var gridView  = document.getElementById('gridView');
    var listeView = document.getElementById('listeView');
    if (!btnGrid) return;

    var pref = localStorage.getItem('gallerivisning') || 'grid';
    saetVisning(pref);

    btnGrid.addEventListener('click',  function () { saetVisning('grid');  localStorage.setItem('gallerivisning','grid');  });
    btnListe.addEventListener('click', function () { saetVisning('liste'); localStorage.setItem('gallerivisning','liste'); });

    function saetVisning(v) {
        if (v === 'liste') {
            gridView  && gridView.classList.remove('vis');
            listeView && listeView.classList.add('vis');
            btnGrid.classList.remove('active');
            btnListe.classList.add('active');
        } else {
            gridView  && gridView.classList.add('vis');
            listeView && listeView.classList.remove('vis');
            btnGrid.classList.add('active');
            btnListe.classList.remove('active');
        }
    }
})();

// ── Galleri: upload preview med drag-and-drop ─────────────────────────────────
(function () {
    var zone    = document.getElementById('uploadZone');
    var input   = document.getElementById('filInput');
    var preview = document.getElementById('uploadPreview');
    var actions = document.getElementById('uploadActions');
    if (!zone) return;

    zone.addEventListener('dragover',  function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function ()  { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        haandterFiler(e.dataTransfer.files);
    });
    input.addEventListener('change', function () { haandterFiler(this.files); });

    var valgte = [];

    function haandterFiler(filer) {
        for (var i = 0; i < filer.length; i++) {
            if (filer[i].type.startsWith('image/')) valgte.push(filer[i]);
        }
        opdaterPreview();
        opdaterInput();
    }

    function opdaterPreview() {
        preview.innerHTML = '';
        if (!valgte.length) { actions.style.display = 'none'; return; }
        actions.style.display = '';
        valgte.forEach(function (fil, idx) {
            var url  = URL.createObjectURL(fil);
            var item = document.createElement('div');
            item.className = 'upload-preview-item';
            item.innerHTML =
                '<img src="' + url + '" alt="">' +
                '<button type="button" class="upload-remove" data-idx="' + idx + '">×</button>' +
                '<input type="text" name="titler[]" placeholder="Titel…" value="' +
                    fil.name.replace(/\.[^.]+$/, '').replace(/[<>"]/g, '') + '">';
            preview.appendChild(item);
        });
        preview.querySelectorAll('.upload-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                valgte.splice(parseInt(this.dataset.idx), 1);
                opdaterPreview();
                opdaterInput();
            });
        });
    }

    function opdaterInput() {
        var dt = new DataTransfer();
        valgte.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
    }

    var rydBtn = document.getElementById('btnRydPreview');
    if (rydBtn) rydBtn.addEventListener('click', function () {
        valgte = []; opdaterPreview(); opdaterInput();
    });
})();

// ── Lightbox ──────────────────────────────────────────────────────────────────
function aabLightbox(src) {
    var lb  = document.getElementById('lightbox');
    var img = document.getElementById('lightboxImg');
    if (!lb || !img) return;
    img.src = src;
    lb.classList.add('open');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var lb = document.getElementById('lightbox');
        if (lb) lb.classList.remove('open');
    }
});
</script>

<?php if (in_array($action, ['ny', 'rediger'], true)): ?>
<!-- TinyMCE – community-udgave via jsDelivr CDN (ingen API-nøgle krævet) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: 'textarea.tinymce-editor',
    language: 'da',                          // Dansk UI (kræver sprogpakke – se note nedenfor)
    height: 480,
    menubar: 'file edit view insert format tools',
    plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
        'preview', 'anchor', 'searchreplace', 'visualblocks', 'code',
        'fullscreen', 'insertdatetime', 'media', 'table', 'wordcount',
        'emoticons', 'codesample'
    ],
    toolbar:
        'undo redo | blocks fontsize | ' +
        'bold italic underline strikethrough | ' +
        'alignleft aligncenter alignright alignjustify | ' +
        'bullist numlist outdent indent | ' +
        'link image media table | ' +
        'forecolor backcolor | removeformat | ' +
        'code fullscreen preview',
    toolbar_sticky: true,
    toolbar_sticky_offset: 0,
    block_formats: 'Afsnit=p; Overskrift 1=h1; Overskrift 2=h2; Overskrift 3=h3; Overskrift 4=h4; Citat=blockquote; Kode=pre',
    content_style:
        "body { font-family: 'DM Sans', sans-serif; font-size: 15px; " +
        "color: #1a1917; line-height: 1.7; max-width: 780px; margin: 1.5rem auto; }",
    skin: 'oxide',
    content_css: false,
    branding: false,
    promotion: false,
    resize: true,
    statusbar: true,
    wordcount_countregex: /[\w\u2019\x27\-\u00C0-\u1FFF]+/g,
    extended_valid_elements: 'iframe[src|width|height|name|align|class|frameborder|allow|allowfullscreen|referrerpolicy|loading|title]',
    valid_children: '+body[iframe]',
    media_live_embeds: true,
    media_filter_html: false,

    // Billedupload: indsæt som base64 (simpel løsning uden separat upload-endpoint)
    // Skift til images_upload_url for server-side upload i produktion
    images_upload_handler: function (blobInfo) {
        return new Promise(function (resolve) {
            resolve('data:' + blobInfo.blob().type + ';base64,' + blobInfo.base64());
        });
    },

    // Sørg for at TinyMCE gemmer indhold tilbage til textarea ved formularindsendelse
    setup: function (editor) {
        editor.on('change', function () { editor.save(); });

        // Valider at feltet ikke er tomt ved submit
        editor.on('submit', function () { editor.save(); });
    },

    // Mobil-tilpasning: kompakt toolbar på små skærme
    mobile: {
        toolbar_mode: 'scrolling',
        toolbar:
            'undo redo | bold italic | ' +
            'bullist numlist | link | blocks'
    }
});

// Tving TinyMCE til at gemme indhold til textarea inden formularindsendelse
document.querySelector('form[action="?action=gem"]') &&
document.querySelector('form[action="?action=gem"]').addEventListener('submit', function () {
    tinymce.triggerSave();

    // Simpel klientside-validering
    var indhold = document.getElementById('indhold');
    if (indhold && tinymce.get('indhold')) {
        var tekst = tinymce.get('indhold').getContent({ format: 'text' }).trim();
        var html = tinymce.get('indhold').getContent({ format: 'html' });
        var temp = document.createElement('div');
        temp.innerHTML = html;
        var harEmbed = !!temp.querySelector('iframe, img, video, audio, embed, object');

        if (!tekst && !harEmbed) {
            alert('Indholdsfeltet må ikke være tomt.');
            return false;
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>

<?php
/**
 * config.php
 * ──────────────────────────────────────────────────────────────────────────────
 * Fælles konfiguration for hele sitet.
 * Inkluderes øverst i ALLE PHP-sider: require_once __DIR__ . '/config.php';
 *
 * Indeholder:
 *   – Database-forbindelseoplysninger
 *   – Site-metadata (navn, beskrivelse, sprog, base-URL)
 *   – Upload-stier
 *   – Navigation (bruges i _header.php)
 *   – Hjælpefunktioner (db-singleton, html-escape)
 * ──────────────────────────────────────────────────────────────────────────────
 */

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 1. DATABASE
// └─────────────────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'dit_db_brugernavn');      // ← ret
define('DB_PASS', 'dit_db_kodeord');          // ← ret
define('DB_NAME', 'dit_db_navn');             // ← ret
define('TABLE_PREFIX', '');                   // Fx 'kunde1_' ved delt database

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 2. SITE-METADATA
// └─────────────────────────────────────────────────────────────────────────────
define('SITE_NAVN',        'Mit Website');
define('SITE_BESKRIVELSE', 'Kort beskrivelse af sitet til søgemaskiner og deling.');
define('SITE_SPROG',       'da');
define('SITE_FORFATTER',   'Dit navn');
define('SITE_BASE_URL',    'https://www.eksempel.dk');   // ← uden afsluttende /
define('SITE_OG_BILLEDE',  SITE_BASE_URL . '/assets/og-default.jpg'); // Open Graph fallback-billede

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 3. UPLOAD / GALLERI
// └─────────────────────────────────────────────────────────────────────────────
define('UPLOAD_DIR',    __DIR__ . '/uploads/galleri/');
define('UPLOAD_URL',    '/uploads/galleri/');   // Rod-relativ URL
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('MAX_FILE_SIZE', 5 * 1024 * 1024);       // 5 MB

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 4. ADMIN / AUTH
// └─────────────────────────────────────────────────────────────────────────────
define('ADMIN_USER',   'admin');
define('ADMIN_PASS_HASH', password_hash('skift_dette_kodeord', PASSWORD_BCRYPT));
define('SESSION_NAME', 'cms_admin_session');

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 5. NYHEDER
// └─────────────────────────────────────────────────────────────────────────────
define('ARTIKLER_PR_SIDE', 5);

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 6. NAVIGATION
// │
// │ Hvert menupunkt er et array med:
// │   'label' => Teksten der vises
// │   'href'  => URL (relativ eller absolut)
// │   'match' => Streng der matches mod den aktuelle fil for at markere aktiv
// │
// │ Tilføj, fjern eller omarranger frit.
// └─────────────────────────────────────────────────────────────────────────────
define('SITE_NAV', [
    ['label' => 'Forside',  'href' => '/index.php',     'match' => 'index'],
    ['label' => 'Nyheder',  'href' => '/nyheder.php',   'match' => 'nyheder'],
    ['label' => 'Galleri',  'href' => '/galleri.php',    'match' => 'galleri'],
]);

// ┌─────────────────────────────────────────────────────────────────────────────
// │ 7. HJÆLPEFUNKTIONER
// └─────────────────────────────────────────────────────────────────────────────

/**
 * Database-singleton – returnerer én delt MySQLi-forbindelse.
 */
function db(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        // I produktion: log fejlen og vis brugervenlig fejlside
        error_log('DB-forbindelsesfejl: ' . $conn->connect_error);
        die('Der opstod en teknisk fejl. Prøv igen senere.');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Sikker HTML-escaping – kortform for htmlspecialchars().
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Returnerer sikkert escaped tabellnavn med evt. prefix.
 */
function table_name(string $base): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $base)) {
        throw new InvalidArgumentException('Ugyldigt basis-tabellnavn.');
    }
    if (TABLE_PREFIX !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', TABLE_PREFIX)) {
        throw new RuntimeException('TABLE_PREFIX i config.php indeholder ugyldige tegn.');
    }

    $full = TABLE_PREFIX . $base;
    return '`' . str_replace('`', '``', $full) . '`';
}

/**
 * Afgør om et nav-menupunkt er aktivt baseret på den aktuelle URL-sti.
 */
function nav_er_aktiv(string $match): bool {
    $sti = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    return str_contains($sti, $match);
}

/**
 * Returnerer den kanoniske URL for den aktuelle side.
 */
function kanonisk_url(): string {
    $sti = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $query = $_SERVER['QUERY_STRING'] ?? '';
    return SITE_BASE_URL . $sti . ($query ? '?' . $query : '');
}

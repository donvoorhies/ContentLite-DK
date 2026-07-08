<?php
/**
 * install.php
 * First-run installer (WordPress style):
 * 1) Collect DB credentials
 * 2) Test connection/create database
 * 3) Run schema.sql
 * 4) Optionally write DB credentials into config.php
 */

require_once __DIR__ . '/config.php';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function connect_mysql(string $host, string $user, string $pass, ?string $dbName = null): mysqli {
    $conn = @new mysqli($host, $user, $pass, $dbName ?? '');
    if (!$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    // Shared hosts often fail socket lookup for localhost; retry over TCP.
    if ($conn->connect_errno === 2002 && $host === 'localhost') {
        $conn2 = @new mysqli('127.0.0.1', $user, $pass, $dbName ?? '');
        if (!$conn2->connect_error) {
            $conn2->set_charset('utf8mb4');
            return $conn2;
        }
        $conn = $conn2;
    }

    $msg = 'Databaseforbindelse mislykkedes: ' . $conn->connect_error;
    if ($conn->connect_errno === 2002) {
        $msg .= "\nTip: Brug 127.0.0.1 som DB host (ikke localhost) paa denne server.";
    }
    throw new RuntimeException($msg);
}

function run_sql_script(mysqli $db, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Kunne ikke laese SQL-scriptet: ' . $path);
    }

    if (!$db->multi_query($sql)) {
        throw new RuntimeException($db->error . "\nSQL-script: " . $path);
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
        if ($db->more_results() && !$db->next_result()) {
            throw new RuntimeException($db->error . "\nSQL-script: " . $path);
        }
    } while ($db->more_results());
}

function prefixed_table(string $base, string $prefix): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $base)) {
        throw new RuntimeException('Ugyldigt tabelnavn: ' . $base);
    }
    if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        throw new RuntimeException('Ugyldigt table prefix. Brug kun bogstaver, tal og underscore.');
    }
    return $prefix . $base;
}

function run_schema_with_prefix(mysqli $db, string $path, string $prefix): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Kunne ikke laese SQL-scriptet: ' . $path);
    }

    $sql = str_replace('CREATE TABLE IF NOT EXISTS cms_indhold', 'CREATE TABLE IF NOT EXISTS ' . prefixed_table('cms_indhold', $prefix), $sql);
    $sql = str_replace('CREATE TABLE IF NOT EXISTS galleri_albums', 'CREATE TABLE IF NOT EXISTS ' . prefixed_table('galleri_albums', $prefix), $sql);
    $sql = str_replace('CREATE TABLE IF NOT EXISTS galleri_billeder', 'CREATE TABLE IF NOT EXISTS ' . prefixed_table('galleri_billeder', $prefix), $sql);
    $sql = str_replace('REFERENCES galleri_albums', 'REFERENCES ' . prefixed_table('galleri_albums', $prefix), $sql);

    if (!$db->multi_query($sql)) {
        throw new RuntimeException($db->error . "\nSQL-script: " . $path);
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
        if ($db->more_results() && !$db->next_result()) {
            throw new RuntimeException($db->error . "\nSQL-script: " . $path);
        }
    } while ($db->more_results());
}

function table_exists(mysqli $db, string $dbName, string $table): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Kunne ikke forberede tabel-check: ' . $db->error);
    }
    $stmt->bind_param('ss', $dbName, $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function write_db_credentials_to_config(string $host, string $user, string $pass, string $name, string $prefix): array {
    $configPath = __DIR__ . '/config.php';
    if (!is_writable($configPath)) {
        return [false, 'config.php er ikke skrivbar. Opdater DB_* manuelt.'];
    }

    $content = file_get_contents($configPath);
    if ($content === false) {
        return [false, 'Kunne ikke laese config.php.'];
    }

    $replaceMap = [
        'DB_HOST' => $host,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'DB_NAME' => $name,
        'TABLE_PREFIX' => $prefix,
    ];

    foreach ($replaceMap as $const => $value) {
        $pattern = '/define\(\'' . preg_quote($const, '/') . '\',\s*\'.*?\'\);/';
        $replacement = "define('" . $const . "', '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "');";
        $content = preg_replace($pattern, $replacement, $content, 1);
    }

    if (file_put_contents($configPath, $content) === false) {
        return [false, 'Kunne ikke skrive de nye DB-oplysninger til config.php.'];
    }

    return [true, 'DB-oplysninger skrevet til config.php'];
}

$messages = [];
$errors = [];
$requiredTables = ['cms_indhold', 'galleri_albums', 'galleri_billeder'];

$form = [
    'db_host' => DB_HOST,
    'db_user' => DB_USER,
    'db_pass' => DB_PASS,
    'db_name' => DB_NAME,
    'table_prefix' => defined('TABLE_PREFIX') ? TABLE_PREFIX : '',
    'save_config' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['db_host'] = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $form['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
    $form['db_pass'] = (string) ($_POST['db_pass'] ?? '');
    $form['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
    $form['table_prefix'] = trim((string) ($_POST['table_prefix'] ?? ''));
    $form['save_config'] = isset($_POST['save_config']) ? '1' : '0';

    if ($form['db_host'] === '' || $form['db_user'] === '' || $form['db_name'] === '') {
        $errors[] = 'Udfyld venligst DB host, DB user og DB name.';
    } elseif ($form['table_prefix'] !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $form['table_prefix'])) {
        $errors[] = 'Table prefix maa kun indeholde bogstaver, tal og underscore.';
    } else {
        try {
            $rootConn = connect_mysql($form['db_host'], $form['db_user'], $form['db_pass']);

            $dbNameEsc = '`' . str_replace('`', '``', $form['db_name']) . '`';
            if (!$rootConn->query('CREATE DATABASE IF NOT EXISTS ' . $dbNameEsc . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
                throw new RuntimeException('Kunne ikke oprette database: ' . $rootConn->error);
            }
            $rootConn->close();
            $messages[] = 'OK: database klar (' . $form['db_name'] . ')';

            $db = connect_mysql($form['db_host'], $form['db_user'], $form['db_pass'], $form['db_name']);

            run_schema_with_prefix($db, __DIR__ . '/schema.sql', $form['table_prefix']);
            foreach ($requiredTables as $table) {
                $fullTable = prefixed_table($table, $form['table_prefix']);
                if (!table_exists($db, $form['db_name'], $fullTable)) {
                    throw new RuntimeException('Tabellen blev ikke oprettet: ' . $fullTable);
                }
                $messages[] = 'OK: tabel ' . $fullTable;
            }

            $messages[] = 'OK: table prefix = ' . ($form['table_prefix'] === '' ? '(ingen)' : $form['table_prefix']);

            if ($form['save_config'] === '1') {
                [$ok, $msg] = write_db_credentials_to_config(
                    $form['db_host'],
                    $form['db_user'],
                    $form['db_pass'],
                    $form['db_name'],
                    $form['table_prefix']
                );
                if ($ok) {
                    $messages[] = $msg;
                } else {
                    $errors[] = $msg;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Foerstegangs-installation</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.6; background: #f8fafc; color: #0f172a; }
        .box { max-width: 860px; padding: 1.25rem 1.5rem; border: 1px solid #d8d8d8; border-radius: 10px; background: #fff; }
        .grid { display: grid; gap: .9rem; }
        label { font-weight: 600; display: block; margin-bottom: .35rem; }
        input[type="text"], input[type="password"] { width: 100%; padding: .6rem .65rem; border: 1px solid #cbd5e1; border-radius: 6px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: .9rem; }
        .ok { color: #166534; }
        .err { color: #b91c1c; white-space: pre-wrap; }
        .hint { color: #475569; font-size: .95rem; }
        button { border: 0; border-radius: 6px; background: #1d4ed8; color: #fff; padding: .65rem .9rem; cursor: pointer; }
        button:hover { background: #1e40af; }
        code { background: #f1f5f9; padding: .1rem .35rem; border-radius: 4px; }
        @media (max-width: 700px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="box">
        <h1>Foerstegangs-installation</h1>
        <p class="hint">Udfyld database-oplysninger som i en klassisk first-run wizard. Installeren opretter database/tabeller og kan gemme DB-oplysninger i <code>config.php</code>.</p>

        <?php if ($messages): ?>
            <h2>Resultat</h2>
            <ul class="ok">
                <?php foreach ($messages as $message): ?>
                    <li><?= h($message) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($errors): ?>
            <h2>Fejl</h2>
            <div class="err"><?= h(implode("\n", $errors)) ?></div>
        <?php endif; ?>

        <h2>Databaseoplysninger</h2>
        <form method="post" class="grid" autocomplete="off">
            <div class="row">
                <div>
                    <label for="db_host">DB Host</label>
                    <input type="text" id="db_host" name="db_host" value="<?= h($form['db_host']) ?>" required>
                </div>
                <div>
                    <label for="db_name">DB Name</label>
                    <input type="text" id="db_name" name="db_name" value="<?= h($form['db_name']) ?>" required>
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="db_user">DB User</label>
                    <input type="text" id="db_user" name="db_user" value="<?= h($form['db_user']) ?>" required>
                </div>
                <div>
                    <label for="db_pass">DB Password</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?= h($form['db_pass']) ?>">
                </div>
            </div>
            <div>
                <label for="table_prefix">Table Prefix (valgfri)</label>
                <input type="text" id="table_prefix" name="table_prefix" value="<?= h($form['table_prefix']) ?>" placeholder="fx site1_">
            </div>
            <label>
                <input type="checkbox" name="save_config" value="1" <?= $form['save_config'] === '1' ? 'checked' : '' ?>>
                Gem DB-oplysninger i config.php
            </label>
            <div>
                <button type="submit">Koer installation</button>
            </div>
        </form>

        <?php if ($messages && !$errors): ?>
<p style="margin-top:1.5rem;padding:1rem;background:#fef2f2;border:2px solid #dc2626;border-radius:6px;color:#b91c1c;font-weight:600;">
    ⚠️ Installationen er gennemført. <code>install.php</code> er nu låst via
    <code>install.lock</code>. For maksimal sikkerhed, <strong>slet begge filer
    fra serveren</strong>.
</p>
<?php else: ?>
<p class="hint">Slet eller beskyt <code>install.php</code> efter en vellykket installation.</p>
<?php endif; ?>
    </div>
</body>
</html>
<?php
// Lås installeren — blokerer yderligere adgang via .htaccess
file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));
$messages[] = 'OK: install.lock oprettet — installeren er nu blokeret.';
?>

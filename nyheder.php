<?php
/**
 * nyheder.php
 * ──────────────────────────────────────────────────────────────────────────────
 * Offentlig nyhedsside.
 * Viser udgivne artikler, ARTIKLER_PR_SIDE pr. side, med automatisk paginering.
 * Enkelt artikel vises når ?artikel=ID er sat i URL'en.
 * ──────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/config.php';

// ─── Datalag ──────────────────────────────────────────────────────────────────
function hent_artikel(int $id): ?array {
    $tCms = table_name('cms_indhold');
    $stmt = db()->prepare(
        "SELECT * FROM {$tCms} WHERE id = ? AND status = 'udgivet' LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function hent_artikler(int $side): array {
    $offset = ($side - 1) * ARTIKLER_PR_SIDE;
    $limit  = ARTIKLER_PR_SIDE;
    $tCms   = table_name('cms_indhold');
    $stmt   = db()->prepare(
        "SELECT id, titel, indhold, opdateret
         FROM {$tCms}
         WHERE status = 'udgivet'
         ORDER BY opdateret DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function hent_total_antal(): int {
    $tCms = table_name('cms_indhold');
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$tCms} WHERE status = 'udgivet'"
    );
    $stmt->execute();
    $stmt->bind_result($antal);
    $stmt->fetch();
    $stmt->close();
    return (int) $antal;
}

function nabolinks(string $tidspunkt): array {
    $tCms = table_name('cms_indhold');
    $s = db()->prepare(
        "SELECT id, titel FROM {$tCms}
         WHERE status = 'udgivet' AND opdateret > ?
         ORDER BY opdateret ASC LIMIT 1"
    );
    $s->bind_param('s', $tidspunkt); $s->execute();
    $naeste = $s->get_result()->fetch_assoc(); $s->close();

    $s = db()->prepare(
        "SELECT id, titel FROM {$tCms}
         WHERE status = 'udgivet' AND opdateret < ?
         ORDER BY opdateret DESC LIMIT 1"
    );
    $s->bind_param('s', $tidspunkt); $s->execute();
    $forrige = $s->get_result()->fetch_assoc(); $s->close();
    return ['naeste' => $naeste, 'forrige' => $forrige];
}

function uddrag(string $html, int $tegn = 280): string {
    $tekst = preg_replace('/\s+/', ' ', trim(strip_tags($html)));
    return mb_strlen($tekst) <= $tegn ? $tekst : mb_substr($tekst, 0, $tegn) . '…';
}

function side_url(int $side): string {
    $p = $_GET; $p['side'] = $side; unset($p['artikel']);
    return '?' . http_build_query($p);
}

// ─── Routing ──────────────────────────────────────────────────────────────────
$artikel_id  = isset($_GET['artikel']) ? (int)$_GET['artikel'] : 0;
$aktuel_side = max(1, (int)($_GET['side'] ?? 1));
$total       = hent_total_antal();
$sider_i_alt = max(1, (int)ceil($total / ARTIKLER_PR_SIDE));
$aktuel_side = min($aktuel_side, $sider_i_alt);
$enkelt      = null;
$nabo        = [];
$artikler    = [];

if ($artikel_id > 0) {
    $enkelt = hent_artikel($artikel_id);
    if (!$enkelt) { header('Location: nyheder.php', true, 302); exit; }
    $nabo = nabolinks($enkelt['opdateret']);
} else {
    $artikler = hent_artikler($aktuel_side);
}

// ─── Meta-tags til _header.php ────────────────────────────────────────────────
if ($enkelt) {
    $meta_title       = e($enkelt['titel']) . ' – ' . SITE_NAVN;
    $meta_description = uddrag($enkelt['indhold'], 160);
} else {
    $side_suffix      = $aktuel_side > 1 ? ' – Side ' . $aktuel_side : '';
    $meta_title       = 'Nyheder' . $side_suffix . ' – ' . SITE_NAVN;
    $meta_description = SITE_BESKRIVELSE;
}
$body_class = 'side-nyheder';

// ─── Sidefil-specifik CSS ─────────────────────────────────────────────────────
$ekstra_css = <<<CSS
<style>
/*
 * Nyheder-specifikke styles.
 * Bruger kun CSS custom properties fra :root i _header.php.
 * Tilpas / overstyr frit i /assets/site.css.
 */

/* ── Artikliste ── */
.artikel-liste   { list-style: none; padding: 0; }
.artikel-kort    { padding: var(--afstand-lg) 0;
                   border-bottom: 1px solid var(--farve-kant); }
.artikel-kort:last-child { border-bottom: none; }
.artikel-meta    { font-size: .8rem; color: var(--farve-dæmpet); margin-bottom: .4rem; }
.artikel-kort h2 { margin: 0 0 .5rem; line-height: 1.3; font-size: 1.2rem; }
.artikel-kort h2 a { color: var(--farve-tekst); text-decoration: none; }
.artikel-kort h2 a:hover { color: var(--farve-accent); }
.artikel-uddrag  { color: var(--farve-dæmpet); margin-bottom: .65rem; line-height: 1.6; }
.laes-mere       { font-size: .875rem; font-weight: 600;
                   color: var(--farve-accent); text-decoration: none; }
.laes-mere:hover { text-decoration: underline; }

/* ── Paginering ── */
.paginering      { display: flex; flex-wrap: wrap; gap: .35rem;
                   align-items: center; justify-content: center;
                   padding: var(--afstand-xl) 0 0; }
.side-knap       { display: inline-flex; align-items: center; justify-content: center;
                   min-width: 2.25rem; height: 2.25rem; padding: 0 .5rem;
                   border: 1px solid var(--farve-kant); border-radius: var(--radius);
                   background: var(--farve-bg); color: var(--farve-tekst);
                   font-size: .875rem; font-weight: 500; text-decoration: none;
                   transition: background .12s; }
.side-knap:hover       { background: var(--farve-overflade); text-decoration: none; }
.side-knap.aktiv       { background: var(--farve-accent); border-color: var(--farve-accent);
                          color: #fff; pointer-events: none; }
.side-knap.disabled    { opacity: .35; pointer-events: none; }
.paginering-info       { font-size: .78rem; color: var(--farve-dæmpet);
                          text-align: center; margin-top: .5rem; }

/* ── Enkelt artikel ── */
.tilbage-link  { display: inline-block; margin-bottom: var(--afstand-lg);
                 color: var(--farve-dæmpet); text-decoration: none; font-size: .875rem; }
.tilbage-link:hover { color: var(--farve-tekst); }

/* Formatering af TinyMCE-indhold */
.artikel-indhold h2,
.artikel-indhold h3     { margin: 1.5rem 0 .5rem; line-height: 1.3; }
.artikel-indhold p      { margin-bottom: 1rem; }
.artikel-indhold ul,
.artikel-indhold ol     { margin: .5rem 0 1rem 1.5rem; }
.artikel-indhold blockquote {
    border-left: 3px solid var(--farve-accent);
    margin: 1rem 0; padding: .5rem 1rem;
    color: var(--farve-dæmpet); font-style: italic; }
.artikel-indhold img    { max-width: 100%; height: auto; border-radius: var(--radius); }
.artikel-indhold pre    { background: var(--farve-overflade); padding: 1rem;
                          border-radius: var(--radius); overflow-x: auto; }
.artikel-indhold code   { font-family: var(--font-mono); font-size: .875rem; }
.artikel-indhold table  { width: 100%; border-collapse: collapse; margin: 1rem 0; }
.artikel-indhold th,
.artikel-indhold td     { padding: .5rem .75rem; border: 1px solid var(--farve-kant);
                          text-align: left; }
.artikel-indhold th     { background: var(--farve-overflade); font-weight: 600; }

/* ── Forrige / næste ── */
.nabopil       { display: flex; justify-content: space-between;
                 flex-wrap: wrap; gap: 1rem; margin-top: var(--afstand-lg);
                 padding-top: var(--afstand-lg); border-top: 1px solid var(--farve-kant); }
.nabopil a     { font-size: .875rem; color: var(--farve-dæmpet);
                 text-decoration: none; max-width: 48%; }
.nabopil a:hover   { color: var(--farve-accent); }
.nabopil .prev::before { content: '← '; }
.nabopil .next::after  { content: ' →'; }

@media (max-width: 600px) { .nabopil a { max-width: 100%; } }
</style>
CSS;

require __DIR__ . '/assets/_header.php';
?>

<?php if ($enkelt): ?>
<!-- ══════════════════════════════════════
     ENKELT ARTIKEL
     ══════════════════════════════════════ -->
<article>

    <a href="nyheder.php" class="tilbage-link">← Tilbage til nyheder</a>

    <header>
        <p class="artikel-meta">
            <time datetime="<?= date('Y-m-d', strtotime($enkelt['opdateret'])) ?>">
                <?= date('j. F Y', strtotime($enkelt['opdateret'])) ?>
            </time>
        </p>
        <h1><?= e($enkelt['titel']) ?></h1>
    </header>

    <div class="artikel-indhold">
        <?= $enkelt['indhold'] /* HTML fra TinyMCE – allerede saniteret ved gem */ ?>
    </div>

    <?php if ($nabo['forrige'] || $nabo['naeste']): ?>
    <nav class="nabopil" aria-label="Artiklnavigation">
        <?php if ($nabo['forrige']): ?>
            <a href="?artikel=<?= $nabo['forrige']['id'] ?>" class="prev">
                <?= e(mb_strimwidth($nabo['forrige']['titel'], 0, 55, '…')) ?>
            </a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if ($nabo['naeste']): ?>
            <a href="?artikel=<?= $nabo['naeste']['id'] ?>" class="next">
                <?= e(mb_strimwidth($nabo['naeste']['titel'], 0, 55, '…')) ?>
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

</article>

<?php else: ?>
<!-- ══════════════════════════════════════
     ARTIKLISTE MED PAGINERING
     ══════════════════════════════════════ -->

<header>
    <h1>Nyheder</h1>
    <?php if ($total > 0): ?>
        <p class="artikel-meta">
            <?= $total ?> artikel<?= $total != 1 ? 'r' : '' ?>
            &nbsp;·&nbsp; Side <?= $aktuel_side ?> af <?= $sider_i_alt ?>
        </p>
    <?php endif; ?>
</header>

<?php if (empty($artikler)): ?>
    <p>Der er ingen udgivne artikler endnu.</p>
<?php else: ?>

<ul class="artikel-liste">
<?php foreach ($artikler as $a): ?>
    <li class="artikel-kort">
        <p class="artikel-meta">
            <time datetime="<?= date('Y-m-d', strtotime($a['opdateret'])) ?>">
                <?= date('j. F Y', strtotime($a['opdateret'])) ?>
            </time>
        </p>
        <h2><a href="?artikel=<?= $a['id'] ?>"><?= e($a['titel']) ?></a></h2>
        <p class="artikel-uddrag"><?= e(uddrag($a['indhold'])) ?></p>
        <a href="?artikel=<?= $a['id'] ?>" class="laes-mere">Læs mere →</a>
    </li>
<?php endforeach; ?>
</ul>

<?php if ($sider_i_alt > 1): ?>
<nav class="paginering" aria-label="Sidenavigation">

    <a href="<?= side_url($aktuel_side - 1) ?>"
       class="side-knap <?= $aktuel_side <= 1 ? 'disabled' : '' ?>"
       aria-label="Forrige side">&#8592;</a>

    <?php
    $vis = []; $prev = null;
    for ($i = 1; $i <= $sider_i_alt; $i++) {
        if ($i === 1 || $i === $sider_i_alt ||
            ($i >= $aktuel_side - 2 && $i <= $aktuel_side + 2)) {
            $vis[] = $i;
        }
    }
    foreach ($vis as $s):
        if ($prev !== null && $s - $prev > 1): ?>
            <span class="side-knap disabled" aria-hidden="true">…</span>
        <?php endif; ?>
        <a href="<?= side_url($s) ?>"
           class="side-knap <?= $s === $aktuel_side ? 'aktiv' : '' ?>"
           <?= $s === $aktuel_side ? 'aria-current="page"' : '' ?>
           aria-label="Side <?= $s ?>"><?= $s ?></a>
    <?php $prev = $s; endforeach; ?>

    <a href="<?= side_url($aktuel_side + 1) ?>"
       class="side-knap <?= $aktuel_side >= $sider_i_alt ? 'disabled' : '' ?>"
       aria-label="Næste side">&#8594;</a>

</nav>
<p class="paginering-info">
    Viser <?= (($aktuel_side - 1) * ARTIKLER_PR_SIDE) + 1 ?>–<?= min($aktuel_side * ARTIKLER_PR_SIDE, $total) ?>
    af <?= $total ?> artikler
</p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/assets/_footer.php'; ?>

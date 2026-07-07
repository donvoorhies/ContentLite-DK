<?php
/**
 * index.php – Eksempel på en "statisk" side der bruger de delte skabeloner.
 *
 * Mønstret er identisk for alle sider på sitet, uanset om de er
 * rene informationssider (om.php, kontakt.php) eller dynamiske
 * PHP-sider (nyheder.php, galleri.php):
 *
 *   1. require_once config.php
 *   2. Sæt $meta_title, $meta_description, $body_class (valgfrit: $ekstra_css)
 *   3. require _header.php          ← åbner <html> … <main>
 *   4. Sideindhold (HTML5 landmarks)
 *   5. require _footer.php          ← lukker </main> … </html>
 *
 * CSS-frameworket (Bootstrap, Skeleton, H5BP, ren CSS …) indsættes
 * som <link>-tag i _header.php og styrer ALT layout.
 * PHP-filerne bidrager kun med semantisk HTML5 og indhold.
 */
require_once __DIR__ . '/config.php';

$meta_title       = 'Forside – ' . SITE_NAVN;
$meta_description = SITE_BESKRIVELSE;
$body_class       = 'side-forside';

require __DIR__ . '/assets/_header.php';
?>

<!--
    Fra dette punkt er du inde i <main id="hoved-indhold">.
    Brug semantiske HTML5-elementer (section, article, aside …).
    Layout styres 100 % af dit CSS-framework / /assets/site.css.
-->

<section aria-labelledby="velkommen-overskrift">
    <h1 id="velkommen-overskrift">Velkommen til <?= e(SITE_NAVN) ?></h1>
    <p>
        Dette er forsiden. Erstat dette indhold med dit eget.
        Navigationen øverst og sidefoden er fælles for alle sider
        og trækkes automatisk fra <code>_header.php</code> og
        <code>_footer.php</code> via <code>config.php</code>.
    </p>
</section>

<section aria-labelledby="seneste-nyheder-overskrift">
    <h2 id="seneste-nyheder-overskrift">Seneste nyheder</h2>
    <?php
    /*
     * Valgfrit: træk de 3 seneste udgivne artikler ind på forsiden.
     * Brug samme forberedte statement-mønster som nyheder.php.
     */
    $tCms = table_name('cms_indhold');
    $stmt = db()->prepare(
        "SELECT id, titel, opdateret FROM {$tCms}
         WHERE status = 'udgivet'
         ORDER BY opdateret DESC LIMIT 3"
    );
    $stmt->execute();
    $seneste = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    ?>
    <?php if (empty($seneste)): ?>
        <p>Ingen nyheder endnu.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($seneste as $n): ?>
        <li>
            <a href="nyheder.php?artikel=<?= $n['id'] ?>"><?= e($n['titel']) ?></a>
            <small>(<?= date('j. F Y', strtotime($n['opdateret'])) ?>)</small>
        </li>
        <?php endforeach; ?>
    </ul>
    <p><a href="nyheder.php">Se alle nyheder →</a></p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/assets/_footer.php'; ?>

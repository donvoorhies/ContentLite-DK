<?php
/**
 * _footer.php
 * ──────────────────────────────────────────────────────────────────────────────
 * Delt sideafslutning for hele sitet.
 * Lukker <main>, renderer <footer> og afslutter HTML-dokumentet.
 *
 * VALGFRI VARIABLER inden include:
 *   $ekstra_js   HTML-streng med ekstra <script>-tags der indsættes inden </body>
 * ──────────────────────────────────────────────────────────────────────────────
 */
if (!defined('SITE_NAVN')) {
    require_once __DIR__ . '/config.php';
}
?>

</main><!-- #hoved-indhold -->

<!-- ════════════════════════════════════════════════════════════════════════════
     SIDEFOD
     Semantisk HTML5 – layout styres af dit CSS.
     ════════════════════════════════════════════════════════════════════════════ -->
<footer role="contentinfo">
    <div class="site-footer-indre">

        <!-- Sekundær navigation / footer-links -->
        <nav aria-label="Sidefod-navigation">
            <ul role="list">
            <?php foreach (SITE_NAV as $punkt): ?>
                <li>
                    <a href="<?= e($punkt['href']) ?>"
                       <?= nav_er_aktiv($punkt['match']) ? 'aria-current="page"' : '' ?>>
                        <?= e($punkt['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        </nav>

        <!-- Copyright -->
        <p class="copyright">
            &copy; <?= date('Y') ?> <?= e(SITE_NAVN) ?>.
            Alle rettigheder forbeholdes.
        </p>

    </div><!-- .site-footer-indre -->
</footer>

<!--
════════════════════════════════════════════════════════════════════════════════
↓ DIT EGET JAVASCRIPT / FRAMEWORK

Eksempler:

Bootstrap 5 bundle:
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

Lokalt script (altid tilstede):

<script src="/assets/site.js"></script>-->

<?php if (!empty($ekstra_js)) echo $ekstra_js; ?>

</body>
</html>

<?php
declare(strict_types=1);

/**
 * Novità in biblioteca — ultimi titoli inseriti in catalogo
 */

$baseUrl = function_exists('base_url') ? base_url() : '';
if (!isset($pdo)) $pdo = $GLOBALS['db'] ?? null;

$perPage = 24;
$curPage = max(1, (int)($_GET['p'] ?? 1));

// Count
$total   = (int)$pdo->query("SELECT COUNT(*) FROM biblio WHERE opac_flg = 'Y'")->fetchColumn();
$pages   = max(1, (int)ceil($total / $perPage));
$curPage = min($curPage, $pages);
$offset  = ($curPage - 1) * $perPage;

// Fetch
$stmt = $pdo->prepare(
    "SELECT b.bibid, b.title, b.title_remainder, b.author, b.create_dt,
            (SELECT bf.field_data FROM biblio_field bf
              WHERE bf.bibid = b.bibid AND bf.tag = 20 AND bf.subfield_cd = 'a'
              ORDER BY bf.fieldid LIMIT 1) AS isbn_020,
            (SELECT bf.field_data FROM biblio_field bf
              WHERE bf.bibid = b.bibid AND bf.tag IN (260, 264) AND bf.subfield_cd = 'c'
              ORDER BY bf.fieldid LIMIT 1) AS pub_year_raw,
            (SELECT bf.field_data FROM biblio_field bf
              WHERE bf.bibid = b.bibid AND bf.tag = 520 AND bf.subfield_cd = 'a'
              ORDER BY bf.fieldid LIMIT 1) AS abstract_520
     FROM biblio b
     WHERE b.opac_flg = 'Y'
     ORDER BY b.create_dt DESC, b.bibid DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$perPage, $offset]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Availability map (stessa logica di search.php)
if (!function_exists('novita_availability_map')) {
    function novita_availability_map(PDO $pdo, array $bibids): array
    {
        static $fallback = [
            'in' => 'Disponibile', 'ln' => 'In prestito', 'out' => 'In prestito',
            'hld' => 'In attesa', 'mnd' => 'In restauro', 'ord' => 'Riservato',
            'crt' => 'Da reintegrare', 'lst' => 'Perso', '8' => 'Escluso dal prestito',
        ];
        $bibids = array_values(array_filter(array_map('intval', $bibids)));
        if ($bibids === []) return [];
        $map = [];
        foreach ($bibids as $id) $map[$id] = ['state' => 'unknown', 'label' => ''];

        $dbLabels = [];
        try {
            $s = $pdo->query("SELECT code, description FROM biblio_status_dm");
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $dbLabels[strtolower(trim($r['code']))] = $r['description'];
            }
        } catch (\PDOException $e) {}
        $labels = $dbLabels !== [] ? $dbLabels : $fallback;

        $ph   = implode(',', array_fill(0, count($bibids), '?'));
        $stmt = $pdo->prepare("SELECT bibid, status_cd FROM biblio_copy WHERE bibid IN ($ph)");
        $stmt->execute($bibids);
        $copiesByBibid = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $copiesByBibid[(int)$r['bibid']][] = strtolower(trim($r['status_cd']));
        }
        $stateOf = static function (string $code): string {
            return match ($code) {
                'in'  => 'available',
                'ln', 'out' => 'unavailable',
                'hld', 'ord' => 'reserved',
                default => 'other',
            };
        };
        foreach ($copiesByBibid as $bibid => $codes) {
            $best = 'other';
            foreach ($codes as $c) {
                $s = $stateOf($c);
                if ($s === 'available') { $best = 'available'; break; }
                if ($s === 'reserved' && $best !== 'available') $best = 'reserved';
                if ($s === 'unavailable' && $best === 'other') $best = 'unavailable';
            }
            $label = $labels[strtolower($codes[0] ?? '')] ?? '';
            if ($best === 'available') {
                foreach ($codes as $c) {
                    if ($stateOf($c) === 'available') { $label = $labels[$c] ?? $label; break; }
                }
            }
            $map[$bibid] = ['state' => $best, 'label' => $label];
        }
        return $map;
    }
}

$bibids         = array_column($results, 'bibid');
$availabilityMap = novita_availability_map($pdo, $bibids);

function novita_trim_abstract(string $t, int $max = 200): string
{
    if (mb_strlen($t) <= $max) return $t;
    $s = mb_substr($t, 0, $max);
    $dot = mb_strrpos($s, '.');
    return ($dot !== false && $dot > $max * 0.4) ? mb_substr($s, 0, $dot + 1) : rtrim($s) . '…';
}

function novita_fmt_date(string $dt): string
{
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt) ?: DateTime::createFromFormat('Y-m-d', $dt);
    return $d ? $d->format('d/m/Y') : '';
}

$indexUrl = $baseUrl . '/index.php';
?>
<section class="page-section page-novita">
    <header class="novita-header">
        <h1>Novità in biblioteca</h1>
        <p class="novita-intro">
            Gli ultimi titoli inseriti in catalogo. Aggiornato automaticamente con ogni nuova acquisizione.
        </p>
        <?php if ($pages > 1): ?>
            <p class="novita-count"><?= (int)$total ?> titoli · pagina <?= $curPage ?> di <?= $pages ?></p>
        <?php else: ?>
            <p class="novita-count"><?= (int)$total ?> titoli in catalogo</p>
        <?php endif; ?>
    </header>

    <?php if (empty($results)): ?>
        <p>Nessun titolo trovato.</p>
    <?php else: ?>
        <ul class="result-list result-list--cards novita-list">
            <?php foreach ($results as $row): ?>
                <?php
                $bibid    = (int)($row['bibid'] ?? 0);
                $titleVal = trim((string)($row['title'] ?? ''));
                $titleRem = trim((string)($row['title_remainder'] ?? ''));
                $author   = trim((string)($row['author'] ?? ''));
                $isbnRaw  = trim((string)($row['isbn_020'] ?? ''));
                $abstract = novita_trim_abstract(trim((string)($row['abstract_520'] ?? '')));
                $yearRaw  = trim((string)($row['pub_year_raw'] ?? ''));
                $yearVal  = preg_match('/\d{4}/', $yearRaw, $m) ? $m[0] : '';
                $addedAt  = novita_fmt_date(trim((string)($row['create_dt'] ?? '')));

                $availability      = $availabilityMap[$bibid] ?? ['state' => 'unknown', 'label' => ''];
                $availabilityState = (string)($availability['state'] ?? 'unknown');
                $availabilityLabel = (string)($availability['label'] ?? '');

                $isbn13   = CoverService::toIsbn13($isbnRaw);
                $hasLocal = ($isbn13 !== '' && CoverService::hasLocalCover($isbn13));
                $coverUrl    = CoverService::getCoverUrl($isbnRaw, $titleVal, $author);
                $placeholder = CoverService::placeholderUrl($titleVal);
                $isbnOrig    = CoverService::getIsbnForJs($isbnRaw);

                $detailHref = 'index.php?page=item&bibid=' . $bibid;
                ?>
                <li class="result-item result-card">
                    <div class="result-card-cover">
                        <a href="<?= h($detailHref) ?>">
                            <img
                                src="<?= h($coverUrl) ?>"
                                alt="Copertina di <?= h($titleVal ?: '[Senza titolo]') ?>"
                                onerror="this.onerror=null;this.src='<?= h($placeholder) ?>';"
                                <?php if (!$hasLocal && $isbnOrig !== ''): ?>
                                    data-isbn="<?= h($isbnOrig) ?>"
                                    data-isbn13="<?= h($isbn13) ?>"
                                    data-title="<?= h($titleVal) ?>"
                                    data-author="<?= h($author) ?>"
                                    data-placeholder="<?= h($placeholder) ?>"
                                <?php endif; ?>
                            >
                        </a>
                    </div>
                    <div class="result-card-body">
                        <h3 class="result-card-title">
                            <a href="<?= h($detailHref) ?>"><?= h($titleVal ?: '[Senza titolo]') ?></a>
                        </h3>
                        <?php if ($titleRem !== ''): ?>
                            <div class="result-card-subtitle"><?= h($titleRem) ?></div>
                        <?php endif; ?>
                        <div class="result-card-meta">
                            <?php if ($author !== ''): ?>
                                <span class="result-card-author"><?= h($author) ?></span>
                            <?php endif; ?>
                            <?php if ($yearVal !== ''): ?>
                                <span class="result-card-year"><?= $author !== '' ? ' · ' : '' ?><?= h($yearVal) ?></span>
                            <?php endif; ?>
                            <?php if ($availabilityLabel !== ''): ?>
                                <span class="availability-badge availability-<?= h($availabilityState) ?>" style="margin-left:.55rem;"><?= h($availabilityLabel) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($abstract !== ''): ?>
                            <p class="result-card-abstract"><?= h($abstract) ?></p>
                        <?php endif; ?>
                        <?php if ($addedAt !== ''): ?>
                            <p class="novita-added">Inserito il <?= h($addedAt) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="result-card-actions">
                        <a href="<?= h($detailHref) ?>" class="button">Dettagli</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <?php
        $qBase = ['page' => 'novita'];
        $win   = 2;
        $pStart = max(1, $curPage - $win);
        $pEnd   = min($pages, $curPage + $win);
        $pageUrl = static fn(int $p): string => $indexUrl . '?' . http_build_query($qBase + ['p' => $p]);
        ?>
        <nav class="pagination novita-pagination" aria-label="Pagine novità">
            <?php if ($curPage > 1): ?>
                <a class="page-link page-link--control" href="<?= h($pageUrl(1)) ?>">&laquo; Prima</a>
                <a class="page-link page-link--control" href="<?= h($pageUrl($curPage - 1)) ?>">‹ Prec</a>
            <?php endif; ?>
            <?php if ($pStart > 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <?php for ($p = $pStart; $p <= $pEnd; $p++): ?>
                <a class="page-link <?= $p === $curPage ? 'is-current' : '' ?>" href="<?= h($pageUrl($p)) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($pEnd < $pages): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <?php if ($curPage < $pages): ?>
                <a class="page-link page-link--control" href="<?= h($pageUrl($curPage + 1)) ?>">Succ ›</a>
                <a class="page-link page-link--control" href="<?= h($pageUrl($pages)) ?>">Ultima &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

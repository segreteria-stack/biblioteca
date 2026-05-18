<?php
declare(strict_types=1);

/**
 * Sfoglia il catalogo: autori e titoli per lettera
 *
 * ?type=autori|titoli  ?letter=A-Z|#  ?p=N
 */

$baseUrl = function_exists('base_url') ? base_url() : '';
if (!isset($pdo)) $pdo = $GLOBALS['db'] ?? null;

$type = in_array((string)($_GET['type'] ?? 'autori'), ['autori', 'titoli'], true)
    ? (string)$_GET['type']
    : 'autori';

$rawLetter = strtoupper(trim((string)($_GET['letter'] ?? 'A')));
$letter    = preg_match('/^[A-Z#]$/', $rawLetter) ? $rawLetter : 'A';

$perPage = 48;
$curPage = max(1, (int)($_GET['p'] ?? 1));

$letters  = [...range('A', 'Z'), '#'];
$indexUrl = $baseUrl . '/index.php';

// --- Costruisce la condizione SQL e i parametri per la lettera ---
$letterParams = [];
if ($type === 'autori') {
    if ($letter === '#') {
        $lCond = "AND LEFT(UPPER(author), 1) NOT BETWEEN 'A' AND 'Z'";
    } else {
        $lCond = "AND author LIKE ?";
        $letterParams = [$letter . '%'];
    }
    $baseWhere = "opac_flg = 'Y' AND author IS NOT NULL AND author <> ''";

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT author) FROM biblio WHERE $baseWhere $lCond");
    $stmt->execute($letterParams);
    $total = (int)$stmt->fetchColumn();

    $pages   = max(1, (int)ceil($total / $perPage));
    $curPage = min($curPage, $pages);
    $offset  = ($curPage - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT author, COUNT(*) AS cnt FROM biblio
          WHERE $baseWhere $lCond
          GROUP BY author
          ORDER BY author ASC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($letterParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    if ($letter === '#') {
        $lCond = "AND LEFT(UPPER(title), 1) NOT BETWEEN 'A' AND 'Z'";
    } else {
        $lCond = "AND title LIKE ?";
        $letterParams = [$letter . '%'];
    }
    $baseWhere = "opac_flg = 'Y' AND title IS NOT NULL AND title <> ''";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM biblio WHERE $baseWhere $lCond");
    $stmt->execute($letterParams);
    $total = (int)$stmt->fetchColumn();

    $pages   = max(1, (int)ceil($total / $perPage));
    $curPage = min($curPage, $pages);
    $offset  = ($curPage - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT b.bibid, b.title, b.title_remainder, b.author,
                (SELECT bf.field_data FROM biblio_field bf
                  WHERE bf.bibid = b.bibid AND bf.tag IN (260, 264) AND bf.subfield_cd = 'c'
                  ORDER BY bf.fieldid LIMIT 1) AS pub_year
          FROM biblio b
          WHERE $baseWhere $lCond
          ORDER BY title ASC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($letterParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function browse_url(string $base, string $type, string $letter, int $p = 1): string
{
    return $base . '/index.php?' . http_build_query(['page' => 'browse', 'type' => $type, 'letter' => $letter, 'p' => $p]);
}

function browse_year(string $raw): string
{
    return preg_match('/\d{4}/', $raw, $m) ? $m[0] : '';
}

$tabLabel = $type === 'autori' ? 'Autori' : 'Titoli';
$countLabel = $type === 'autori'
    ? ($total === 1 ? '1 autore' : "$total autori")
    : ($total === 1 ? '1 titolo' : "$total titoli");
?>
<section class="page-section page-browse">
    <header class="browse-header">
        <h1>Sfoglia il catalogo</h1>
        <p class="browse-intro">Naviga per lettera tra gli autori e i titoli del catalogo.</p>

        <div class="browse-tabs" role="tablist">
            <a href="<?= h(browse_url($baseUrl, 'autori', $letter)) ?>"
               class="browse-tab <?= $type === 'autori' ? 'browse-tab--active' : '' ?>"
               role="tab" aria-selected="<?= $type === 'autori' ? 'true' : 'false' ?>">
                Autori
            </a>
            <a href="<?= h(browse_url($baseUrl, 'titoli', $letter)) ?>"
               class="browse-tab <?= $type === 'titoli' ? 'browse-tab--active' : '' ?>"
               role="tab" aria-selected="<?= $type === 'titoli' ? 'true' : 'false' ?>">
                Titoli
            </a>
        </div>
    </header>

    <nav class="browse-letters" aria-label="Navigazione alfabetica">
        <?php foreach ($letters as $l): ?>
            <a href="<?= h(browse_url($baseUrl, $type, $l)) ?>"
               class="browse-letter <?= $l === $letter ? 'browse-letter--active' : '' ?>"
               aria-current="<?= $l === $letter ? 'true' : 'false' ?>">
                <?= h($l) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <p class="browse-count">
        <?= h($countLabel) ?> per la lettera <strong><?= h($letter) ?></strong>
        <?php if ($pages > 1): ?>&nbsp;— pagina <?= $curPage ?> di <?= $pages ?><?php endif; ?>
    </p>

    <?php if (empty($rows)): ?>
        <p class="browse-empty">Nessun risultato per questa lettera.</p>
    <?php elseif ($type === 'autori'): ?>
        <div class="browse-authors-grid">
            <?php foreach ($rows as $r): ?>
                <?php $author = trim((string)($r['author'] ?? '')); ?>
                <?php if ($author === '') continue; ?>
                <a href="<?= h($indexUrl . '?page=search&q=' . urlencode($author) . '&sort=author_asc') ?>"
                   class="browse-author-card">
                    <span class="browse-author-name"><?= h($author) ?></span>
                    <span class="browse-author-count">
                        <?php $cnt = (int)($r['cnt'] ?? 0); ?>
                        <?= $cnt ?> <?= $cnt === 1 ? 'titolo' : 'titoli' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <ol class="browse-titles-list" start="<?= ($curPage - 1) * $perPage + 1 ?>">
            <?php foreach ($rows as $r): ?>
                <?php
                $bibid  = (int)($r['bibid'] ?? 0);
                $title  = trim((string)($r['title'] ?? ''));
                $sub    = trim((string)($r['title_remainder'] ?? ''));
                $author = trim((string)($r['author'] ?? ''));
                $year   = browse_year(trim((string)($r['pub_year'] ?? '')));
                if ($title === '') continue;
                ?>
                <li class="browse-title-item">
                    <a href="<?= h($indexUrl . '?page=item&bibid=' . $bibid) ?>" class="browse-title-link">
                        <span class="browse-title-text">
                            <?= h($title) ?><?php if ($sub !== ''): ?><span class="browse-title-sub"><?= h(' : ' . $sub) ?></span><?php endif; ?>
                        </span>
                        <?php if ($author !== ''): ?>
                            <span class="browse-title-author"><?= h($author) ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($year !== ''): ?>
                        <span class="browse-title-year"><?= h($year) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pagination browse-pagination" aria-label="Pagine">
            <?php if ($curPage > 1): ?>
                <a class="page-link page-link--control" href="<?= h(browse_url($baseUrl, $type, $letter, 1)) ?>">&laquo; Prima</a>
                <a class="page-link page-link--control" href="<?= h(browse_url($baseUrl, $type, $letter, $curPage - 1)) ?>">‹ Prec</a>
            <?php endif; ?>
            <?php
            $win = 2;
            $pStart = max(1, $curPage - $win);
            $pEnd   = min($pages, $curPage + $win);
            ?>
            <?php if ($pStart > 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <?php for ($p = $pStart; $p <= $pEnd; $p++): ?>
                <a class="page-link <?= $p === $curPage ? 'is-current' : '' ?>"
                   href="<?= h(browse_url($baseUrl, $type, $letter, $p)) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($pEnd < $pages): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <?php if ($curPage < $pages): ?>
                <a class="page-link page-link--control" href="<?= h(browse_url($baseUrl, $type, $letter, $curPage + 1)) ?>">Succ ›</a>
                <a class="page-link page-link--control" href="<?= h(browse_url($baseUrl, $type, $letter, $pages)) ?>">Ultima &raquo;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

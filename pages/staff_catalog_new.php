<?php
declare(strict_types=1);

/**
 * Inserimento nuovo record bibliografico — Area staff
 *
 * Pagina unificata con quattro metodi in tab:
 *   manuale  – form guidato con lookup ISBN
 *   sbn      – ricerca live OPAC SBN + import
 *   file     – import da file MARC21/ISO2709 o EndNote
 *   marcxml  – import da file MARCXML
 */

if (session_status() === PHP_SESSION_NONE) session_start();
$baseUrl = function_exists('base_url') ? base_url() : '';

if (empty($_SESSION['staff_user_id'])) {
    header('Location: ' . $baseUrl . '/index.php?page=login&redirect=staff_catalog_new');
    exit;
}
$staffUserId = (int)$_SESSION['staff_user_id'];

/** @var \PDO $pdo */
/** @var array $cfg */

// ============================================================
// PARSING FUNCTIONS — file MARC21 ISO2709
// ============================================================

function ncn_marc_parse(string $raw): array
{
    $len = strlen($raw);
    if ($len < 24) return ['leader' => '', 'fields' => []];
    $leader  = substr($raw, 0, 24);
    $base    = (int) substr($leader, 12, 5);
    if ($base <= 0 || $base >= $len) return ['leader' => $leader, 'fields' => []];
    $dir     = substr($raw, 24, $base - 25);
    $fields  = [];
    for ($p = 0; $p + 11 < strlen($dir); $p += 12) {
        $tag  = substr($dir, $p, 3);
        $flen = (int) substr($dir, $p + 3, 4);
        $off  = (int) substr($dir, $p + 7, 5);
        if ($flen <= 0 || ($base + $off + $flen) > $len + 1) continue;
        $fdata = substr($raw, $base + $off, $flen - 1);
        if ($tag < '010') {
            $fields[] = ['tag' => $tag, 'ctrl' => true, 'raw' => $fdata];
        } else {
            $subs = [];
            foreach (explode("\x1F", substr($fdata, 2)) as $part) {
                if ($part !== '') $subs[] = ['code' => $part[0], 'val' => substr($part, 1)];
            }
            $fields[] = ['tag' => $tag, 'ctrl' => false,
                         'ind1' => $fdata[0] ?? ' ', 'ind2' => $fdata[1] ?? ' ',
                         'subfields' => $subs];
        }
    }
    return ['leader' => $leader, 'fields' => $fields];
}

function ncn_marc_subs(array $marc, string $tag, array $codes): string
{
    $out = [];
    foreach ($marc['fields'] as $f) {
        if (($f['tag'] ?? '') !== $tag || !empty($f['ctrl'])) continue;
        foreach ($f['subfields'] as $sf) {
            if (in_array($sf['code'], $codes, true)) {
                $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sf['val']));
                if ($v !== '') $out[] = $v;
            }
        }
    }
    return implode(' ', $out);
}

function ncn_marc_text(array $marc): string
{
    $lines = [];
    foreach ($marc['fields'] as $f) {
        if (!empty($f['ctrl'])) {
            $lines[] = $f['tag'] . ' ' . trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $f['raw']));
        } else {
            $parts = [];
            foreach ($f['subfields'] as $sf) {
                $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sf['val']));
                if ($v !== '') $parts[] = '$' . $sf['code'] . ' ' . $v;
            }
            $lines[] = sprintf('%s %s%s %s', $f['tag'], $f['ind1'], $f['ind2'], implode(' ', $parts));
        }
    }
    return implode("\n", $lines);
}

// ============================================================
// PARSING FUNCTIONS — file EndNote
// ============================================================

function ncn_endnote_parse(string $raw): array
{
    $out = [];
    foreach (preg_split("/\r\n|\r|\n/", $raw) as $line) {
        if ($line === '' || ($line[0] ?? '') !== '%' || strlen($line) < 3) continue;
        $tag = $line[1];
        $val = trim(substr($line, 3));
        if ($val !== '') $out[$tag][] = $val;
    }
    return $out;
}

// ============================================================
// NORMALIZE HELPERS — condivisi da file e MARCXML
// ============================================================

function ncn_split_title(string $raw): array
{
    $resp = '';
    $part = $raw;
    if (strpos($raw, ' / ') !== false) {
        [$part, $resp] = explode(' / ', $raw, 2);
    }
    $rem = $title = trim($part);
    if (strpos($part, ' : ') !== false) {
        [$title, $rem] = explode(' : ', $part, 2);
    } else {
        $rem = '';
    }
    return ['title' => trim($title), 'remainder' => trim($rem), 'responsibility' => trim($resp)];
}

function ncn_main_author(string $raw): string
{
    return trim((string)(preg_split('/[;\r\n]+/', $raw)[0] ?? ''));
}

function ncn_year_from_pub(string $pub): string
{
    return preg_match('/\b(\d{4})\b/', $pub, $m) ? $m[1] : '';
}

function ncn_topics(string $raw): array
{
    $topics = [];
    foreach (preg_split('/[\r\n;]+/', $raw) as $p) {
        $p = trim((string)preg_replace('/\$\w/', '', $p));
        if ($p !== '') $topics[] = $p;
        if (count($topics) >= 5) break;
    }
    while (count($topics) < 5) $topics[] = '';
    return $topics;
}

/**
 * Estrae soggetti dal MARC21 binario rispettando i campi ripetibili.
 * Ogni occorrenza del campo 650 è un soggetto distinto.
 * Restituisce array di stringhe (max $max elementi).
 */
function ncn_marc_subjects(array $marc, int $max = 0): array
{
    $subjects = [];
    foreach ($marc['fields'] as $f) {
        if (($f['tag'] ?? '') !== '650' || !empty($f['ctrl'])) continue;
        $parts = [];
        foreach ($f['subfields'] as $sf) {
            if (in_array($sf['code'], ['a', 'x', 'y', 'z'], true)) {
                $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $sf['val']));
                if ($v !== '') $parts[] = $v;
            }
        }
        if ($parts !== []) {
            $subjects[] = implode(' -- ', $parts);
            if ($max > 0 && count($subjects) >= $max) break;
        }
    }
    return $subjects;
}

function ncn_add_field(PDO $pdo, int $bibid, int $tag, string $sub, string $val): void
{
    $val = trim($val);
    if ($val === '') return;
    $pdo->prepare('INSERT INTO biblio_field (bibid,tag,ind1_cd,ind2_cd,subfield_cd,field_data) VALUES (?,?,NULL,NULL,?,?)')
        ->execute([$bibid, $tag, $sub, $val]);
}

/**
 * Estrae e pulisce il codice ISBN dal primo campo MARC 020 $a.
 * Restituisce stringa vuota se non trovato.
 */
function ncn_extract_isbn_marc(array $marc): string
{
    foreach ($marc['fields'] as $f) {
        if (($f['tag'] ?? '') !== '020' || !empty($f['ctrl'])) continue;
        foreach ($f['subfields'] as $sf) {
            if ($sf['code'] === 'a') {
                $raw = trim($sf['val']);
                if ($raw === '') continue;
                // Prendi solo la parte numerica prima di eventuali qualificatori
                // es. "978-88-12-34567-8 (brossura)" → "9788812345678"
                $part = preg_split('/[\s(]/', $raw)[0] ?? $raw;
                $clean = strtoupper(preg_replace('/[^0-9Xx]/', '', $part));
                if ($clean !== '') return $clean;
            }
        }
    }
    return '';
}

// ============================================================
// SHARED HELPERS — inserimento copia con barcode univoco
// ============================================================

/**
 * Inserisce una nuova copia in biblio_copy lasciando che MySQL assegni
 * copyid via AUTO_INCREMENT per-gruppo (MyISAM composite PK bibid+copyid).
 * Questo evita la race condition del vecchio MAX(copyid)+1.
 * Restituisce [copyid, barcode].
 */
function ncn_insert_copy(PDO $pdo, int $bibid, string $status = 'in'): array
{
    // INSERT senza copyid: MySQL assegna il valore atomicamente
    $pdo->prepare('INSERT INTO biblio_copy (bibid,create_dt,barcode_nmbr,status_cd,status_begin_dt,renewal_count) VALUES (?,NOW(),\'\',?,NOW(),0)')
        ->execute([$bibid, $status]);
    $copyid  = (int)$pdo->lastInsertId();
    $barcode = str_pad((string)$bibid, 5, '0', STR_PAD_LEFT)
             . str_pad((string)$copyid, 2, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE biblio_copy SET barcode_nmbr=? WHERE bibid=? AND copyid=?')
        ->execute([$barcode, $bibid, $copyid]);
    return [$copyid, $barcode];
}

function ncn_sync_index_ext(PDO $pdo, int $bibid, string $isbn, string $pubYear, string $publisher, string $pubPlace): void
{
    $year = (int)preg_replace('/[^0-9]/', '', substr($pubYear, 0, 4));
    $pdo->prepare('INSERT INTO biblio_index_ext (bibid,isbn,pub_year,publisher,pub_place)
                   VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE
                     isbn=VALUES(isbn), pub_year=VALUES(pub_year),
                     publisher=VALUES(publisher), pub_place=VALUES(pub_place)')
        ->execute([$bibid, $isbn !== '' ? $isbn : null, $year > 0 ? $year : null,
                   $publisher !== '' ? $publisher : null, $pubPlace !== '' ? $pubPlace : null]);
}

// ============================================================
// STATE
// ============================================================

$activeTab = (string)($_GET['tab'] ?? 'manuale');
$method    = (string)($_POST['method'] ?? '');

// Verifica CSRF per tutti i POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['_csrf'] ?? '')) {
    http_response_code(403);
    echo '<div class="ncn-err"><p>Token CSRF non valido. Ricarica la pagina e riprova.</p></div>';
    exit;
}

// manuale
$manualData   = ['title'=>'','title_remainder'=>'','author'=>'','responsibility'=>'',
                 'material_cd'=>'','collection_cd'=>'','call_nmbr1'=>'','call_nmbr2'=>'',
                 'call_nmbr3'=>'','topic1'=>'','topic2'=>'','topic3'=>'','topic4'=>'',
                 'topic5'=>'','isbn'=>'','publisher'=>'','pub_year'=>'','pub_place'=>'',
                 'pages'=>'','summary'=>'','notes'=>'',
                 'bid_sbn'=>'','dewey'=>'','lingua'=>'','paese'=>'','serie'=>'',
                 'num_copies'=>'1'];
$manualErrors  = [];
$manualSuccess = null;
$manualBibid   = null;
$manualBarcode = null;

// file wizard
$fileStep      = 0;   // 0=upload, 2=review, 3=done
$fileExtracted = [];
$filePreview   = '';
$fileKind      = '';
$fileErrors    = [];
$fileSuccess   = null;
$fileNewBibid  = null;

// marcxml
$marcErrors  = [];
$marcSuccess = null;
$marcCount   = 0;

// ============================================================
// POST HANDLER — manuale
// ============================================================

if ($method === 'manual') {
    $activeTab = 'manuale';
    foreach (array_keys($manualData) as $k) {
        $manualData[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($manualData['title'] === '')         $manualErrors[] = 'Il <strong>Titolo</strong> è obbligatorio.';
    if ($manualData['material_cd'] === '')   $manualErrors[] = 'Seleziona un <strong>Tipo di materiale</strong>.';
    if ($manualData['collection_cd'] === '') $manualErrors[] = 'Seleziona una <strong>Sezione / collocazione</strong>.';
    if ($manualData['call_nmbr1'] === '')    $manualErrors[] = 'La <strong>Segnatura (collocazione fisica)</strong> è obbligatoria.';

    if ($manualErrors === []) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                INSERT INTO biblio
                    (create_dt,last_change_dt,last_change_userid,material_cd,collection_cd,
                     call_nmbr1,call_nmbr2,call_nmbr3,title,title_remainder,
                     responsibility_stmt,author,topic1,topic2,topic3,topic4,topic5)
                VALUES (NOW(),NOW(),:u,:mat,:col,:c1,:c2,:c3,:ti,:tr,:rs,:au,:t1,:t2,:t3,:t4,:t5)
            ');
            $stmt->execute([
                ':u'=>$staffUserId, ':mat'=>$manualData['material_cd'],
                ':col'=>$manualData['collection_cd'],
                ':c1'=>$manualData['call_nmbr1'], ':c2'=>$manualData['call_nmbr2'],
                ':c3'=>$manualData['call_nmbr3'], ':ti'=>$manualData['title'],
                ':tr'=>$manualData['title_remainder'], ':rs'=>$manualData['responsibility'],
                ':au'=>$manualData['author'],
                ':t1'=>$manualData['topic1'], ':t2'=>$manualData['topic2'],
                ':t3'=>$manualData['topic3'], ':t4'=>$manualData['topic4'],
                ':t5'=>$manualData['topic5'],
            ]);
            $manualBibid = (int)$pdo->lastInsertId();

            $numCopies = max(1, min(10, (int)($manualData['num_copies'] ?: 1)));
            $manualBarcodes = [];
            for ($ci = 0; $ci < $numCopies; $ci++) {
                [, $bc] = ncn_insert_copy($pdo, $manualBibid);
                $manualBarcodes[] = $bc;
            }
            $manualBarcode = $manualBarcodes[0];

            foreach ([
                [20,  'a', $manualData['isbn']],
                [260, 'a', $manualData['pub_place']],
                [260, 'b', $manualData['publisher']],
                [260, 'c', $manualData['pub_year']],
                [300, 'a', $manualData['pages']],
                [490, 'a', $manualData['serie']],
                [520, 'a', $manualData['summary']],
                [500, 'a', $manualData['notes']],
                [82,  'a', $manualData['dewey']],
                [41,  'a', $manualData['lingua']],
                [44,  'a', $manualData['paese']],
                [901, 'a', $manualData['bid_sbn']],
            ] as [$tag,$sub,$val]) {
                ncn_add_field($pdo, $manualBibid, $tag, $sub, $val);
            }

            ncn_sync_index_ext($pdo, $manualBibid, $manualData['isbn'], $manualData['pub_year'], $manualData['publisher'], $manualData['pub_place']);
            $pdo->commit();
            $manualSuccess = true;
            foreach (array_keys($manualData) as $k) $manualData[$k] = '';

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $manualErrors[] = 'Errore durante il salvataggio. Riprova.';
        }
    }
}

// ============================================================
// POST HANDLER — file_upload (step 1 → step 2)
// ============================================================

if ($method === 'file_upload') {
    $activeTab = 'file';

    if (!isset($_FILES['importfile']) || (int)$_FILES['importfile']['error'] !== UPLOAD_ERR_OK) {
        $fileErrors[] = 'Caricamento file non riuscito.';
    } else {
        $origName = (string)($_FILES['importfile']['name'] ?? '');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $raw      = (string)@file_get_contents((string)$_FILES['importfile']['tmp_name']);

        if ($raw === '') {
            $fileErrors[] = 'Il file è vuoto.';
        } elseif (in_array($ext, ['mrc','iso','marc'], true)) {
            $fileKind = 'marc';
            $marc = ncn_marc_parse($raw);
            $filePreview = ncn_marc_text($marc);
            $isbn    = ncn_extract_isbn_marc($marc);
            $authors = trim(ncn_marc_subs($marc,'100',['a']) . ' ' . ncn_marc_subs($marc,'700',['a']));
            $title   = ncn_marc_subs($marc, '245', ['a','b','c']);
            $pub     = ncn_marc_subs($marc, '260', ['a','b','c']) ?: ncn_marc_subs($marc, '264', ['a','b','c']);
            $phys    = ncn_marc_subs($marc, '300', ['a','b','c']);
            $abstr   = ncn_marc_subs($marc, '520', ['a','b']);
            $subj    = implode('; ', ncn_marc_subjects($marc)); // un soggetto per campo 650, separati da ";"
            $fileExtracted = compact('isbn','authors','title','pub','phys','abstr','subj');
            $fileStep = 2;
        } elseif (in_array($ext, ['txt','enw'], true)) {
            $fileKind = 'endnote';
            $p = ncn_endnote_parse($raw);
            $authors = implode('; ', $p['A'] ?? []);
            $title   = ($p['T'][0] ?? '');
            $place   = ($p['C'][0] ?? '');
            $publ    = ($p['I'][0] ?? '');
            $yr      = ($p['D'][0] ?? '');
            $pub     = implode(' : ', array_filter([$place,$publ,$yr]));
            $series  = ($p['B'][0] ?? '');
            $ed      = ($p['7'][0] ?? '');
            $phys    = trim(($series ? 'Serie: '.$series : '') . ($ed ? ' Edizione: '.$ed : ''));
            $abstr = $subj = '';
            // %@ = ISBN/ISSN in EndNote format
            $isbnRawEn = trim(($p['@'][0] ?? ''));
            $isbn = $isbnRawEn !== '' ? strtoupper(preg_replace('/[^0-9Xx]/', '', $isbnRawEn)) : '';
            $filePreview = trim($raw);
            $fileExtracted = compact('isbn','authors','title','pub','phys','abstr','subj');
            $fileStep = 2;
        } else {
            $fileErrors[] = 'Formato non supportato. Usa .mrc/.iso/.marc (MARC21) o .txt/.enw (EndNote).';
        }
    }
}

// ============================================================
// POST HANDLER — file_import (step 2 → step 3)
// ============================================================

if ($method === 'file_import') {
    $activeTab = 'file';

    $titleRaw = trim((string)($_POST['title'] ?? ''));
    $authors  = trim((string)($_POST['authors'] ?? ''));
    $pub      = trim((string)($_POST['pub'] ?? ''));
    $phys     = trim((string)($_POST['phys'] ?? ''));
    $abstr    = trim((string)($_POST['abstr'] ?? ''));
    $subj     = trim((string)($_POST['subj'] ?? ''));
    $isbn     = trim((string)($_POST['isbn'] ?? ''));

    if ($titleRaw === '') {
        $fileErrors[] = 'Il Titolo è obbligatorio.';
        $fileStep = 2;
        $fileKind = (string)($_POST['filekind'] ?? '');
        $filePreview = (string)($_POST['filepreview'] ?? '');
        $fileExtracted = compact('isbn','authors','title','pub','phys','abstr','subj');
        $title = $titleRaw;
    } else {
        $tp     = ncn_split_title($titleRaw);
        $author = ncn_main_author($authors);
        $year   = ncn_year_from_pub($pub);
        $topics = ncn_topics($subj);

        // default material/collection da tabelle dominio (stesso criterio del metodo MARCXML)
        $defMat = $defCol = '';
        try {
            $r = $pdo->query("SELECT code FROM material_type_dm WHERE default_flg='Y' ORDER BY code LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if (!$r) $r = $pdo->query("SELECT code FROM material_type_dm ORDER BY code LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if ($r) $defMat = (string)(int)$r['code'];
            $r = $pdo->query("SELECT code FROM collection_dm WHERE default_flg='Y' ORDER BY code LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if (!$r) $r = $pdo->query("SELECT code FROM collection_dm ORDER BY code LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if ($r) $defCol = (string)(int)$r['code'];
        } catch (\PDOException $e) {}
        if ($defMat === '') $defMat = '1';
        if ($defCol === '') $defCol = '1';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                INSERT INTO biblio
                    (create_dt,last_change_dt,last_change_userid,material_cd,collection_cd,
                     call_nmbr1,call_nmbr2,call_nmbr3,title,title_remainder,
                     responsibility_stmt,author,topic1,topic2,topic3,topic4,topic5,opac_flg)
                VALUES (NOW(),NOW(),:u,:mat,:col,\'\',\'\',\'\',:ti,:tr,:rs,:au,:t1,:t2,:t3,:t4,:t5,\'Y\')
            ');
            $stmt->execute([
                ':u'=>$staffUserId, ':mat'=>$defMat, ':col'=>$defCol,
                ':ti'=>$tp['title'], ':tr'=>$tp['remainder'], ':rs'=>$tp['responsibility'],
                ':au'=>$author,
                ':t1'=>$topics[0], ':t2'=>$topics[1], ':t3'=>$topics[2],
                ':t4'=>$topics[3], ':t5'=>$topics[4],
            ]);
            $fileNewBibid = (int)$pdo->lastInsertId();

            foreach ([
                [20,'a',$isbn],
                [245,'a',$tp['title']], [245,'b',$tp['remainder']], [245,'c',$tp['responsibility']],
                [300,'a',$phys],
                [520,'a',$abstr],
            ] as [$tag,$sub,$val]) {
                ncn_add_field($pdo, $fileNewBibid, $tag, $sub, $val);
            }

            // pubblicazione
            if ($pub !== '') {
                $pparts = preg_split('/\s*:\s*/', $pub);
                ncn_add_field($pdo, $fileNewBibid, 260, 'a', $pparts[0] ?? '');
                ncn_add_field($pdo, $fileNewBibid, 260, 'b', $pparts[1] ?? '');
                if ($year !== '') ncn_add_field($pdo, $fileNewBibid, 260, 'c', $year);
            }

            // soggetti 650
            foreach (preg_split('/[\r\n;]+/', $subj) as $s) {
                $s = trim((string)preg_replace('/\$\w/', '', $s));
                if ($s !== '') ncn_add_field($pdo, $fileNewBibid, 650, 'a', $s);
            }

            // popola biblio_index_ext per ricerca avanzata
            $pparts_s = ($pub !== '') ? preg_split('/\s*:\s*/', $pub) : [];
            ncn_sync_index_ext($pdo, $fileNewBibid, $isbn, $year, $pparts_s[1] ?? '', $pparts_s[0] ?? '');

            // crea copia fisica (tutti gli altri metodi lo fanno)
            [$copyid, $barcode] = ncn_insert_copy($pdo, $fileNewBibid);

            $pdo->commit();
            $fileStep    = 3;
            $fileSuccess = true;

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $fileErrors[] = 'Errore DB durante l\'inserimento: ' . h($e->getMessage());
            $fileStep = 2;
            $fileKind = (string)($_POST['filekind'] ?? '');
            $filePreview = (string)($_POST['filepreview'] ?? '');
            $title = $titleRaw;
            $fileExtracted = compact('isbn','authors','title','pub','phys','abstr','subj');
        }
    }
}

// ============================================================
// POST HANDLER — marcxml
// ============================================================

if ($method === 'marcxml') {
    $activeTab = 'marcxml';
    $createCopy = isset($_POST['create_copy']);

    if (!isset($_FILES['marcxml']) || (int)$_FILES['marcxml']['error'] !== UPLOAD_ERR_OK) {
        $marcErrors[] = 'Caricamento file non riuscito.';
    } else {
        $xml = (string)@file_get_contents((string)$_FILES['marcxml']['tmp_name']);
        if ($xml === '') {
            $marcErrors[] = 'Il file è vuoto.';
        } else {
            try {
                $sx = new \SimpleXMLElement($xml);
                $records = $sx->getName() === 'collection'
                    ? iterator_to_array($sx->record)
                    : ($sx->getName() === 'record' ? [$sx] : []);

                if ($records === []) throw new \Exception('Formato XML non riconosciuto (atteso &lt;collection&gt; o &lt;record&gt;).');

                // Legge i default da material_type_dm e collection_dm
                $defMatRow = $pdo->query("SELECT code FROM material_type_dm WHERE default_flg='Y' ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$defMatRow) $defMatRow = $pdo->query("SELECT code FROM material_type_dm ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $defColRow = $pdo->query("SELECT code FROM collection_dm WHERE default_flg='Y' ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if (!$defColRow) $defColRow = $pdo->query("SELECT code FROM collection_dm ORDER BY code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $xmlMatCd = $defMatRow ? (int)$defMatRow['code'] : 1;
                $xmlColCd = $defColRow ? (int)$defColRow['code'] : 1;

                $insBib = $pdo->prepare('INSERT INTO biblio
                    (title,title_remainder,responsibility_stmt,author,material_cd,collection_cd,topic1,topic2,topic3,topic4,topic5,opac_flg,create_dt,last_change_dt,last_change_userid)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,\'Y\',NOW(),NOW(),?)');
                $insF = $pdo->prepare('INSERT INTO biblio_field (bibid,tag,subfield_cd,field_data) VALUES (?,?,?,?)');
                // $insC non serve più: ncn_insert_copy gestisce l'inserimento atomico

                $pdo->beginTransaction();

                foreach ($records as $rec) {
                    $mxSubs = function(string $tag, string $code) use ($rec): ?string {
                        foreach ($rec->datafield as $df) {
                            if ((string)$df['tag'] === $tag) {
                                foreach ($df->subfield as $sf) {
                                    if ((string)$sf['code'] === $code) return trim((string)$sf);
                                }
                            }
                        }
                        return null;
                    };
                    // Raccoglie tutti i valori di un tag+code (campi ripetibili)
                    $mxSubsAll = function(string $tag, string $code) use ($rec): array {
                        $out = [];
                        foreach ($rec->datafield as $df) {
                            if ((string)$df['tag'] === $tag) {
                                foreach ($df->subfield as $sf) {
                                    $v = trim((string)$sf);
                                    if ((string)$sf['code'] === $code && $v !== '') $out[] = $v;
                                }
                            }
                        }
                        return $out;
                    };
                    $mxTopics = function() use ($rec): array {
                        $t = [];
                        foreach ($rec->datafield as $df) {
                            if ((string)$df['tag'] === '650') {
                                foreach ($df->subfield as $sf) {
                                    if ((string)$sf['code'] === 'a') {
                                        $v = trim((string)$sf);
                                        if ($v !== '') $t[] = $v;
                                        if (count($t) >= 5) return $t;
                                    }
                                }
                            }
                        }
                        return $t;
                    };

                    $titleMain  = $mxSubs('245','a') ?? '';
                    $titleRem   = $mxSubs('245','b') ?? '';
                    $titleResp  = $mxSubs('245','c') ?? '';
                    $author     = $mxSubs('100','a') ?? ($mxSubs('110','a') ?? ($mxSubs('111','a') ?? ''));
                    $topics     = array_pad($mxTopics(), 5, null);
                    $isbnRaw    = $mxSubs('020','a');
                    $isbn       = $isbnRaw ? strtoupper(preg_replace('/[^0-9Xx]/','',$isbnRaw)) : null;
                    // Pubblicazione: preferisce 260, poi 264
                    $pubPlace   = $mxSubs('260','a') ?? ($mxSubs('264','a') ?? '');
                    $pubEdit    = $mxSubs('260','b') ?? ($mxSubs('264','b') ?? '');
                    $pubAnno    = $mxSubs('260','c') ?? ($mxSubs('264','c') ?? '');
                    $phys       = $mxSubs('300','a') ?? '';

                    $insBib->execute([$titleMain,$titleRem,$titleResp,$author,$xmlMatCd,$xmlColCd,$topics[0],$topics[1],$topics[2],$topics[3],$topics[4],$staffUserId]);
                    $bibid = (int)$pdo->lastInsertId();
                    if ($isbn)    $insF->execute([$bibid,20,'a',$isbn]);
                    if ($pubPlace !== '') $insF->execute([$bibid,260,'a',$pubPlace]);
                    if ($pubEdit  !== '') $insF->execute([$bibid,260,'b',$pubEdit]);
                    if ($pubAnno  !== '') $insF->execute([$bibid,260,'c',$pubAnno]);
                    if ($phys     !== '') $insF->execute([$bibid,300,'a',$phys]);
                    // Inserisce ogni soggetto come riga 650/a separata in biblio_field
                    foreach ($mxSubsAll('650','a') as $subjVal) {
                        $insF->execute([$bibid, 650, 'a', $subjVal]);
                    }

                    if ($createCopy) {
                        [$copyid, $barcode] = ncn_insert_copy($pdo, $bibid);
                    }

                    ncn_sync_index_ext($pdo, $bibid, $isbn ?? '', $pubAnno, $pubEdit, $pubPlace);
                    $marcCount++;
                }
                $pdo->commit();
                $marcSuccess = true;

            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $marcErrors[] = 'Errore import: ' . h($e->getMessage());
            }
        }
    }
}

// ============================================================
// DOMAIN DATA
// ============================================================

$materiali = $collezioni = [];
try { $materiali = $pdo->query('SELECT code,description FROM material_type_dm ORDER BY description')->fetchAll(\PDO::FETCH_ASSOC) ?: []; } catch (\PDOException $e) {}
try { $collezioni = $pdo->query('SELECT code,description FROM collection_dm ORDER BY description')->fetchAll(\PDO::FETCH_ASSOC) ?: []; } catch (\PDOException $e) {}

$sbnEnabled = !empty($cfg['sbn']['enabled']) && !empty($cfg['sbn']['consumer_key']) && !empty($cfg['sbn']['consumer_secret']);
$gbApiKey   = $GLOBALS['cfg']['google_books']['api_key'] ?? '';
?>
<style>
/* ---- tabs ---- */
.ncn-tabs { display:flex; gap:0; border-bottom:2px solid #e5e7eb; margin-bottom:1.5rem; flex-wrap:wrap; }
.ncn-tab  { padding:.55rem 1.2rem; background:none; border:none; border-bottom:2px solid transparent;
            margin-bottom:-2px; cursor:pointer; font-size:.92rem; font-weight:600; color:#6b7280;
            transition:color .12s,border-color .12s; }
.ncn-tab:hover  { color:#374151; }
.ncn-tab.active { color:var(--color-primary,#b91c1c); border-bottom-color:var(--color-primary,#b91c1c); }
.ncn-pane { display:none; }
.ncn-pane.active { display:block; }

/* ---- form sections ---- */
.form-section { border:1px solid #e5e7eb; border-radius:8px; padding:1.25rem 1.4rem 1rem;
                margin-bottom:1.25rem; background:#fff; }
.form-section legend { padding:0 .5rem; font-weight:700; font-size:.88rem;
                       letter-spacing:.06em; text-transform:uppercase; color:#6b7280; }
.req { color:#b91c1c; }
#isbn-lookup-status { font-size:.84rem; margin-top:.3rem; }
#isbn-lookup-status.ok  { color:#15803d; }
#isbn-lookup-status.err { color:#b91c1c; }

/* ---- success / error boxes ---- */
.ncn-ok  { border-left:4px solid #16a34a; background:#f0fdf4; padding:1rem 1.25rem;
           border-radius:0 6px 6px 0; margin-bottom:1rem; }
.ncn-err { border-left:4px solid #b91c1c; background:#fef2f2; padding:1rem 1.25rem;
           border-radius:0 6px 6px 0; margin-bottom:1rem; }

/* ---- file preview ---- */
.ncn-marc-pre { background:#111827; color:#e5e7eb; padding:1rem; border-radius:6px;
                overflow:auto; font-size:.78rem; max-height:240px; margin-top:.75rem; }

/* ---- SBN (da staff_sbn_import.php) ---- */
.sbn-import-form { background:#fafafa; border:1px solid #e0e0e0; border-radius:8px; padding:1.25rem; margin-bottom:1rem; }
.sbn-import-row  { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
.sbn-import-row label  { display:block; font-size:.88rem; color:#333; margin-bottom:.3rem; font-weight:600; }
.sbn-import-row input  { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; width:300px; }
.sbn-import-row select { padding:.5rem .7rem; border:1px solid #ccc; border-radius:4px; font-size:.9rem; background:#fff; }
.sbn-import-row button { padding:.55rem 1.2rem; border:none; border-radius:5px; cursor:pointer; font-size:.9rem; font-weight:600; background:#b00; color:#fff; }
.sbn-import-row button:disabled { opacity:.5; cursor:not-allowed; }
#sbn-results table { width:100%; border-collapse:collapse; font-size:.88rem; }
#sbn-results th, #sbn-results td { text-align:left; padding:.45rem .65rem; border-bottom:1px solid #eee; vertical-align:top; }
#sbn-results th { background:#f9f9f9; font-weight:600; }
.sbn-btn-preview { background:#444; color:#fff; border:none; border-radius:4px; padding:.3rem .7rem; cursor:pointer; font-size:.82rem; }
.sbn-import-ok { background:#e8f4e8; border:1px solid #4a9; border-radius:5px; padding:.75rem 1rem; margin:.5rem 0; color:#1a7a1a; }
.sbn-import-err { background:#ffe8e8; border:1px solid #c00; border-radius:5px; padding:.75rem 1rem; margin:.5rem 0; color:#c00; }
.sbn-import-warn { background:#fff3cd; border:1px solid #ffc107; border-radius:5px; padding:.75rem 1rem; margin:.5rem 0; color:#856404; }
.sbn-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%;
                     background:rgba(0,0,0,.5); z-index:1000; justify-content:center; align-items:center; }
.sbn-modal-overlay.active { display:flex; }
.sbn-modal { background:#fff; border-radius:8px; max-width:800px; width:95%; max-height:90vh;
             overflow-y:auto; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.2); }
.sbn-modal-header { display:flex; justify-content:space-between; align-items:center;
                    margin-bottom:1rem; border-bottom:1px solid #eee; padding-bottom:.5rem; }
.sbn-modal-header h3 { margin:0; font-size:1.1rem; }
.sbn-modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#666; }
.sbn-edit-grid { display:grid; grid-template-columns:130px 1fr; gap:.4rem 1rem; font-size:.88rem; align-items:start; }
.sbn-edit-label { font-weight:600; color:#555; padding-top:.35rem; }
.sbn-edit-field input, .sbn-edit-field textarea, .sbn-edit-field select {
    width:100%; padding:.35rem .5rem; border:1px solid #ccc; border-radius:4px; font-size:.88rem; font-family:inherit; background:#fff; }
.sbn-edit-field textarea { min-height:55px; resize:vertical; }
.sbn-edit-field input:focus, .sbn-edit-field textarea:focus { border-color:#b00; outline:none; }
.sbn-edit-readonly { background:#f5f5f5; padding:.35rem .5rem; border-radius:4px; color:#666; font-size:.85rem; }
.sbn-edit-section { grid-column:1/-1; font-weight:700; color:#b00; margin-top:.8rem;
                    padding-top:.5rem; border-top:1px solid #eee; }
.sbn-modal-actions { margin-top:1.25rem; display:flex; gap:.7rem; justify-content:flex-end;
                     padding-top:1rem; border-top:1px solid #eee; }
.sbn-modal-actions button { padding:.5rem 1rem; border:none; border-radius:5px; cursor:pointer;
                             font-size:.9rem; font-weight:600; }
.sbn-modal-import { background:#1a7a1a; color:#fff; }
.sbn-modal-cancel { background:#eee; color:#333; }
.sbn-modal-import:disabled { opacity:.5; }
.sbn-success-banner { background:#e8f4e8; border:2px solid #1a7a1a; border-radius:8px;
                      padding:1.2rem; margin-bottom:1rem; text-align:center; }
.sbn-success-banner h4 { margin:0 0 .5rem; color:#1a7a1a; }
.sbn-success-banner a { display:inline-block; background:#1a7a1a; color:#fff;
                         padding:.5rem 1.2rem; border-radius:5px; text-decoration:none; font-weight:600; }
</style>

<section class="page-section page-staff-catalog-new">

    <nav style="font-size:.88rem;margin-bottom:1rem;">
        <a href="<?= h($baseUrl) ?>/index.php?page=staff">Dashboard</a> › Inserisci record
    </nav>

    <h1 style="margin-bottom:1.25rem;">Inserisci nuovo record</h1>

    <!-- ===== TABS ===== -->
    <div class="ncn-tabs" role="tablist">
        <button class="ncn-tab" data-tab="manuale" role="tab">Manuale</button>
        <button class="ncn-tab" data-tab="sbn"     role="tab">
            Da SBN <?= $sbnEnabled ? '<span style="color:#16a34a;font-size:.75em;">●</span>' : '' ?>
        </button>
        <button class="ncn-tab" data-tab="file"    role="tab">Da file <span style="font-weight:400;font-size:.82em;">(MARC21 / EndNote)</span></button>
        <button class="ncn-tab" data-tab="marcxml" role="tab">MARCXML</button>
    </div>

    <!-- ================================================================ -->
    <!-- TAB: MANUALE                                                       -->
    <!-- ================================================================ -->
    <div class="ncn-pane" id="pane-manuale">

        <?php if ($manualSuccess): ?>
            <div class="ncn-ok">
                <p><strong>Record creato.</strong>
                   BIBID: <strong><?= $manualBibid ?></strong>
                   — <?= count($manualBarcodes ?? []) > 1 ? count($manualBarcodes) . ' copie create' : 'Barcode' ?>:
                   <strong><?= h(implode(', ', array_map('h', $manualBarcodes ?? [(string)$manualBarcode]))) ?></strong>
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem;">
                    <a class="btn-primary"   href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&amp;bibid=<?= $manualBibid ?>">Modifica record</a>
                    <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=item&amp;bibid=<?= $manualBibid ?>">Scheda pubblica</a>
                    <a class="btn-link"      href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">Inserisci un altro</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($manualErrors !== []): ?>
            <div class="ncn-err"><?php foreach ($manualErrors as $e): ?><p><?= $e ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">
            <input type="hidden" name="method" value="manual">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

            <fieldset class="form-section">
                <legend>Identificazione</legend>
                <div class="search-row">
                    <label for="title">Titolo <span class="req">*</span></label>
                    <input type="text" id="title" name="title" value="<?= h($manualData['title']) ?>" required>
                </div>
                <div class="search-row">
                    <label for="title_remainder">Complemento del titolo</label>
                    <input type="text" id="title_remainder" name="title_remainder" value="<?= h($manualData['title_remainder']) ?>">
                </div>
                <div class="search-row">
                    <label for="author">Autore principale</label>
                    <input type="text" id="author" name="author" value="<?= h($manualData['author']) ?>">
                </div>
                <div class="search-row">
                    <label for="responsibility">Altre responsabilità (cur., trad., ecc.)</label>
                    <input type="text" id="responsibility" name="responsibility" value="<?= h($manualData['responsibility']) ?>">
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Classificazione</legend>
                <div class="search-row-inline">
                    <div style="flex:1 1 200px;">
                        <label for="material_cd">Tipo di materiale <span class="req">*</span></label>
                        <select id="material_cd" name="material_cd" required>
                            <option value="">— Seleziona —</option>
                            <?php foreach ($materiali as $m): $c=(string)($m['code']??''); $d=(string)($m['description']??$c); ?>
                                <option value="<?= h($c) ?>"<?= $c===$manualData['material_cd']?' selected':''?>><?= h($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex:1 1 200px;">
                        <label for="collection_cd">Sezione / collocazione <span class="req">*</span></label>
                        <select id="collection_cd" name="collection_cd" required>
                            <option value="">— Seleziona —</option>
                            <?php foreach ($collezioni as $c): $cv=(string)($c['code']??''); $d=(string)($c['description']??$cv); ?>
                                <option value="<?= h($cv) ?>"<?= $cv===$manualData['collection_cd']?' selected':''?>><?= h($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="search-row-inline" style="margin-top:.75rem;">
                    <div style="flex:1 1 120px;"><label for="call_nmbr1">Segnatura 1 <span class="req">*</span></label><input type="text" id="call_nmbr1" name="call_nmbr1" value="<?= h($manualData['call_nmbr1']) ?>" required></div>
                    <div style="flex:1 1 120px;"><label for="call_nmbr2">Segnatura 2</label><input type="text" id="call_nmbr2" name="call_nmbr2" value="<?= h($manualData['call_nmbr2']) ?>"></div>
                    <div style="flex:1 1 120px;"><label for="call_nmbr3">Segnatura 3</label><input type="text" id="call_nmbr3" name="call_nmbr3" value="<?= h($manualData['call_nmbr3']) ?>"></div>
                    <div style="flex:0 1 90px;"><label for="num_copies">N° copie</label><input type="number" id="num_copies" name="num_copies" min="1" max="10" value="<?= h($manualData['num_copies'] ?: '1') ?>"></div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Dati editoriali</legend>
                <div class="search-row">
                    <label for="isbn">ISBN</label>
                    <div class="search-row-inline" style="align-items:center;">
                        <input type="text" id="isbn" name="isbn" value="<?= h($manualData['isbn']) ?>" placeholder="Es. 9788800000000" style="flex:1 1 220px;">
                        <button type="button" class="btn-primary" id="btn-isbn-lookup">Compila dai cataloghi</button>
                    </div>
                    <div id="isbn-lookup-status" style="display:none;"></div>
                    <p class="search-help" style="font-size:.82rem;color:#666;margin-top:.25rem;">Cerca su SBN e Google Books per precompilare i campi. Salvato come MARC 20 $a.</p>
                </div>
                <div class="search-row-inline">
                    <div style="flex:2 1 220px;"><label for="publisher">Editore</label><input type="text" id="publisher" name="publisher" value="<?= h($manualData['publisher']) ?>" placeholder="Es. Einaudi"></div>
                    <div style="flex:1 1 100px;"><label for="pub_year">Anno</label><input type="text" id="pub_year" name="pub_year" value="<?= h($manualData['pub_year']) ?>" placeholder="Es. 1995"></div>
                    <div style="flex:1 1 120px;"><label for="pub_place">Luogo</label><input type="text" id="pub_place" name="pub_place" value="<?= h($manualData['pub_place']) ?>" placeholder="Es. Torino"></div>
                </div>
                <div class="search-row" style="margin-top:.75rem;">
                    <label for="pages">Descrizione fisica / pagine</label>
                    <input type="text" id="pages" name="pages" value="<?= h($manualData['pages']) ?>" placeholder="Es. 320 p. ; 24 cm">
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Dati avanzati <small style="font-weight:400;color:#6b7280;">(compilati automaticamente da SBN)</small></legend>
                <div class="search-row-inline">
                    <div style="flex:1 1 180px;"><label for="serie">Collana / Serie</label><input type="text" id="serie" name="serie" value="<?= h($manualData['serie']) ?>" placeholder="Es. I Meridiani"></div>
                    <div style="flex:1 1 130px;"><label for="dewey">Classe Dewey</label><input type="text" id="dewey" name="dewey" value="<?= h($manualData['dewey']) ?>" placeholder="Es. 945.09"></div>
                </div>
                <div class="search-row-inline" style="margin-top:.5rem;">
                    <div style="flex:1 1 120px;"><label for="lingua">Lingua</label><input type="text" id="lingua" name="lingua" value="<?= h($manualData['lingua']) ?>" placeholder="Es. ita"></div>
                    <div style="flex:1 1 120px;"><label for="paese">Paese</label><input type="text" id="paese" name="paese" value="<?= h($manualData['paese']) ?>" placeholder="Es. IT"></div>
                    <div style="flex:2 1 200px;"><label for="bid_sbn">BID SBN</label><input type="text" id="bid_sbn" name="bid_sbn" value="<?= h($manualData['bid_sbn']) ?>" placeholder="Es. IT\ICCU\VIA\123456" style="font-family:monospace;font-size:.88rem;"></div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Soggetti</legend>
                <div class="search-row">
                    <div class="search-row-inline">
                        <input type="text" name="topic1" id="topic1" placeholder="Soggetto 1" value="<?= h($manualData['topic1']) ?>" style="flex:1 1 140px;">
                        <input type="text" name="topic2" id="topic2" placeholder="Soggetto 2" value="<?= h($manualData['topic2']) ?>" style="flex:1 1 140px;">
                        <input type="text" name="topic3" id="topic3" placeholder="Soggetto 3" value="<?= h($manualData['topic3']) ?>" style="flex:1 1 140px;">
                    </div>
                    <div class="search-row-inline" style="margin-top:.35rem;">
                        <input type="text" name="topic4" id="topic4" placeholder="Soggetto 4" value="<?= h($manualData['topic4']) ?>" style="flex:1 1 140px;">
                        <input type="text" name="topic5" id="topic5" placeholder="Soggetto 5" value="<?= h($manualData['topic5']) ?>" style="flex:1 1 140px;">
                        <div style="flex:1 1 140px;"></div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Contenuto</legend>
                <div class="search-row">
                    <label for="summary">Riassunto</label>
                    <textarea id="summary" name="summary" rows="4"><?= h($manualData['summary']) ?></textarea>
                    <p class="search-help" style="font-size:.82rem;color:#666;margin-top:.25rem;">MARC 520 $a — visibile nella scheda pubblica.</p>
                </div>
                <div class="search-row">
                    <label for="notes">Note generali</label>
                    <textarea id="notes" name="notes" rows="3"><?= h($manualData['notes']) ?></textarea>
                </div>
            </fieldset>

            <div class="search-actions">
                <button type="submit" class="btn-primary">Salva record</button>
                <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff">Torna alla dashboard</a>
            </div>
        </form>
    </div><!-- /pane-manuale -->

    <!-- ================================================================ -->
    <!-- TAB: SBN                                                          -->
    <!-- ================================================================ -->
    <div class="ncn-pane" id="pane-sbn">
        <p style="color:#4b5563;font-size:.9rem;margin-bottom:1rem;">
            Cerca per ISBN, titolo o autore nel Catalogo Unico SBN. Clicca su un risultato
            per aprire il form di modifica prima di importare.
            <?= !$sbnEnabled ? '<strong style="color:#b45309;">⚠ Credenziali SBN non configurate.</strong>' : '<span style="color:#16a34a;">● API SBN attiva.</span>' ?>
        </p>

        <div class="sbn-import-form">
            <div class="sbn-import-row">
                <div>
                    <label for="sbn-search-q">ISBN, titolo o autore</label>
                    <input type="text" id="sbn-search-q" placeholder="Es. 9788807492938">
                </div>
                <div>
                    <label for="sbn-search-type">Cerca per</label>
                    <select id="sbn-search-type">
                        <option value="isbn" selected>ISBN</option>
                        <option value="titolo">Titolo</option>
                        <option value="autore">Autore</option>
                        <option value="any">Tutti i campi</option>
                    </select>
                </div>
                <button id="sbn-search-btn" <?= !$sbnEnabled ? 'disabled' : '' ?>>🔍 Cerca su SBN</button>
            </div>
        </div>
        <div id="sbn-results"></div>

        <!-- Modal -->
        <div class="sbn-modal-overlay" id="sbn-preview-modal">
            <div class="sbn-modal">
                <div class="sbn-modal-header">
                    <h3>Modifica e importa record SBN</h3>
                    <button class="sbn-modal-close" onclick="sbnCloseModal()">&times;</button>
                </div>
                <div id="sbn-preview-content"></div>
                <div class="sbn-modal-actions">
                    <button class="sbn-modal-cancel" onclick="sbnCloseModal()">Annulla</button>
                    <button class="sbn-modal-import" id="sbn-modal-import-btn" onclick="sbnImport()">📥 Importa nel catalogo</button>
                </div>
            </div>
        </div>
    </div><!-- /pane-sbn -->

    <!-- ================================================================ -->
    <!-- TAB: FILE                                                         -->
    <!-- ================================================================ -->
    <div class="ncn-pane" id="pane-file">

        <?php if ($fileErrors !== [] && $fileStep !== 2): ?>
            <div class="ncn-err"><?php foreach ($fileErrors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if ($fileStep === 0 || ($fileStep === 0 && $fileErrors !== [])): ?>
            <p style="color:#4b5563;font-size:.9rem;margin-bottom:1rem;">
                Carica un file MARC21 ISO2709 (<code>.mrc</code>, <code>.iso</code>, <code>.marc</code>)
                o EndNote testo (<code>.txt</code>, <code>.enw</code>).
                I campi principali vengono estratti e mostrati in un form editabile prima di creare il record.
            </p>
            <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new" enctype="multipart/form-data">
                <input type="hidden" name="method" value="file_upload">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <div class="search-row">
                    <label for="importfile">File <span class="req">*</span></label>
                    <input type="file" id="importfile" name="importfile" accept=".mrc,.iso,.marc,.txt,.enw" required>
                </div>
                <div class="search-actions">
                    <button type="submit" class="btn-primary">Carica e analizza</button>
                </div>
            </form>

        <?php elseif ($fileStep === 2): ?>
            <?php if ($fileErrors !== []): ?>
                <div class="ncn-err"><?php foreach ($fileErrors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
            <?php endif; ?>

            <p style="color:#4b5563;font-size:.9rem;margin-bottom:1rem;">
                Dati estratti dal file (<strong><?= $fileKind === 'marc' ? 'MARC21' : 'EndNote' ?></strong>).
                Controlla e modifica prima di creare il record.
            </p>
            <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">
                <input type="hidden" name="method"      value="file_import">
                <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="filekind"    value="<?= h($fileKind) ?>">
                <input type="hidden" name="filepreview" value="<?= h($filePreview) ?>">

                <fieldset class="form-section">
                    <legend>Dati estratti</legend>
                    <div class="search-row"><label for="fi-isbn">ISBN</label><input type="text" id="fi-isbn" name="isbn" value="<?= h($fileExtracted['isbn'] ?? '') ?>"></div>
                    <div class="search-row"><label for="fi-authors">Autori</label><textarea id="fi-authors" name="authors" rows="2"><?= h($fileExtracted['authors'] ?? '') ?></textarea></div>
                    <div class="search-row"><label for="fi-title">Titolo e responsabilità <span class="req">*</span></label><textarea id="fi-title" name="title" rows="2" required><?= h($fileExtracted['title'] ?? '') ?></textarea></div>
                    <div class="search-row"><label for="fi-pub">Pubblicazione</label><textarea id="fi-pub" name="pub" rows="2"><?= h($fileExtracted['pub'] ?? '') ?></textarea></div>
                    <div class="search-row"><label for="fi-phys">Descrizione fisica</label><textarea id="fi-phys" name="phys" rows="2"><?= h($fileExtracted['phys'] ?? '') ?></textarea></div>
                    <div class="search-row"><label for="fi-abstr">Riassunto / abstract</label><textarea id="fi-abstr" name="abstr" rows="3"><?= h($fileExtracted['abstr'] ?? '') ?></textarea></div>
                    <div class="search-row"><label for="fi-subj">Soggetti</label><textarea id="fi-subj" name="subj" rows="3"><?= h($fileExtracted['subj'] ?? '') ?></textarea><p class="search-help" style="font-size:.82rem;color:#666;margin-top:.25rem;">Uno per riga o separati da punto e virgola.</p></div>
                </fieldset>

                <div class="search-actions">
                    <button type="submit" class="btn-primary">Crea record nel catalogo</button>
                    <a class="btn-link" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new?tab=file">Ricomincia</a>
                </div>
            </form>

            <?php if ($filePreview !== ''): ?>
                <details style="margin-top:1.5rem;">
                    <summary style="cursor:pointer;font-size:.88rem;color:#6b7280;">Anteprima completa del file</summary>
                    <pre class="ncn-marc-pre"><?= h($filePreview) ?></pre>
                </details>
            <?php endif; ?>

        <?php elseif ($fileStep === 3): ?>
            <div class="ncn-ok">
                <p><strong>Record creato.</strong> BIBID: <strong><?= (int)$fileNewBibid ?></strong></p>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem;">
                    <a class="btn-primary"   href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_edit&amp;bibid=<?= (int)$fileNewBibid ?>">Modifica record</a>
                    <a class="btn-secondary" href="<?= h($baseUrl) ?>/index.php?page=item&amp;bibid=<?= (int)$fileNewBibid ?>">Scheda pubblica</a>
                    <a class="btn-link"      href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">Inserisci un altro</a>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /pane-file -->

    <!-- ================================================================ -->
    <!-- TAB: MARCXML                                                      -->
    <!-- ================================================================ -->
    <div class="ncn-pane" id="pane-marcxml">

        <?php if ($marcSuccess): ?>
            <div class="ncn-ok">
                <p><strong>Import completato:</strong> <?= $marcCount ?> record<?= $marcCount !== 1 ? 'i' : '' ?> inseriti.</p>
                <a class="btn-secondary" style="margin-top:.5rem;display:inline-block;" href="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new">Inserisci altri record</a>
            </div>
        <?php endif; ?>

        <?php if ($marcErrors !== []): ?>
            <div class="ncn-err"><?php foreach ($marcErrors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <p style="color:#4b5563;font-size:.9rem;margin-bottom:1rem;">
            Importa da un file <strong>MARCXML</strong> (<code>&lt;record&gt;</code> singolo o <code>&lt;collection&gt;</code>).
            Mappa: <code>245$a</code> titolo, <code>100$a</code> autore, <code>650$a</code> soggetti, <code>020$a</code> ISBN.
        </p>

        <form method="post" action="<?= h($baseUrl) ?>/index.php?page=staff_catalog_new" enctype="multipart/form-data">
            <input type="hidden" name="method" value="marcxml">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <div class="search-row">
                <label for="marcxml">File MARCXML (.xml) <span class="req">*</span></label>
                <input type="file" id="marcxml" name="marcxml" accept=".xml" required>
            </div>
            <div class="search-row">
                <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;cursor:pointer;">
                    <input type="checkbox" name="create_copy" value="1">
                    Crea automaticamente una copia disponibile per ogni record
                </label>
            </div>
            <div class="search-actions">
                <button type="submit" class="btn-primary">Importa</button>
            </div>
        </form>

        <details style="margin-top:1.5rem;">
            <summary style="cursor:pointer;font-size:.88rem;color:#6b7280;">Mapping campi MARCXML → database</summary>
            <ul style="margin-top:.5rem;font-size:.85rem;line-height:1.8;">
                <li><code>245$a</code> → <code>biblio.title</code></li>
                <li><code>245$b</code> → <code>biblio.title_remainder</code></li>
                <li><code>100$a</code> (o <code>110$a</code>/<code>111$a</code>) → <code>biblio.author</code></li>
                <li>Fino a 5 × <code>650$a</code> → <code>biblio.topic1..topic5</code></li>
                <li><code>020$a</code> → <code>biblio_field</code> tag 20 $a</li>
            </ul>
        </details>
    </div><!-- /pane-marcxml -->

</section>

<!-- Modal SBN -->
<div class="sbn-modal-overlay" id="sbn-preview-modal">
    <div class="sbn-modal">
        <div class="sbn-modal-header">
            <h3>Modifica e importa record SBN</h3>
            <button class="sbn-modal-close" onclick="sbnCloseModal()">&times;</button>
        </div>
        <div id="sbn-preview-content"></div>
        <div class="sbn-modal-actions">
            <button class="sbn-modal-cancel" onclick="sbnCloseModal()">Annulla</button>
            <button class="sbn-modal-import" id="sbn-modal-import-btn" onclick="sbnImport()">📥 Importa nel catalogo</button>
        </div>
    </div>
</div>

<script>
(function () {
'use strict';
const BASE = <?= json_encode($baseUrl) ?>;

// ============================================================
// TABS
// ============================================================
const tabs   = document.querySelectorAll('.ncn-tab');
const panes  = document.querySelectorAll('.ncn-pane');
const ACTIVE_TAB = <?= json_encode($activeTab) ?>;

function activateTab(name) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    panes.forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
    try { history.replaceState(null, '', '?page=staff_catalog_new&tab=' + name); } catch(e) {}
}
tabs.forEach(t => t.addEventListener('click', () => activateTab(t.dataset.tab)));
activateTab(ACTIVE_TAB);

// ============================================================
// ISBN LOOKUP (tab manuale)
// ============================================================
const btnIsbn = document.getElementById('btn-isbn-lookup');
const isbnInp = document.getElementById('isbn');
const isbnSts = document.getElementById('isbn-lookup-status');

function isbnStatus(msg, cls) {
    if (!isbnSts) return;
    isbnSts.textContent = msg;
    isbnSts.className   = cls || '';
    isbnSts.style.display = msg ? '' : 'none';
}

function applyFields(map) {
    // map: { fieldId: value } — scrive solo se il campo è vuoto
    Object.entries(map).forEach(([id, val]) => {
        if (!val) return;
        const el = document.getElementById(id);
        if (el && !el.value) el.value = String(val);
    });
}

function applySbnData(d) {
    applyFields({
        title:           d.titolo,
        title_remainder: null,
        author:          d.autore,
        publisher:       d.editore,
        pub_year:        d.anno,
        pub_place:       d.luogo,
        pages:           d.dimensioni,
        summary:         d.abstract,
        notes:           d.note,
        serie:           d.collezione,
        dewey:           d.dewey_code,
        lingua:          Array.isArray(d.lingua) ? d.lingua[0] : d.lingua,
        paese:           d.paese,
        bid_sbn:         d.bid_sbn,
    });
    if (Array.isArray(d.soggetti) && d.soggetti.length) {
        ['topic1','topic2','topic3','topic4','topic5'].forEach((id, i) => {
            applyFields({[id]: d.soggetti[i]});
        });
    }
}

if (btnIsbn && isbnInp) {
    const orig = btnIsbn.textContent;
    btnIsbn.addEventListener('click', async function () {
        const isbn = isbnInp.value.trim();
        if (!isbn) { isbnStatus('Inserisci un ISBN prima di cercare.', 'err'); return; }
        btnIsbn.disabled = true;
        btnIsbn.textContent = 'Ricerca…';
        isbnStatus('Consulto SBN…', '');

        // 1. Prova prima SBN (se abilitato)
        let sbnOk = false;
        <?php if ($sbnEnabled): ?>
        try {
            const sbnRes  = await fetch(BASE + '/ajax_sbn_enrich.php?action=search_sbn&type=isbn&q=' + encodeURIComponent(isbn));
            const sbnData = await sbnRes.json();
            if (sbnData.ok && sbnData.total > 0) {
                applySbnData(sbnData.results[0]);
                isbnStatus('Dati SBN trovati e campi precompilati.', 'ok');
                sbnOk = true;
            }
        } catch (_e) {}
        <?php endif; ?>

        if (sbnOk) { btnIsbn.disabled = false; btnIsbn.textContent = orig; return; }

        // 2. Fallback: Google Books / staff_isbn_lookup.php
        isbnStatus('SBN: nessun risultato. Consulto Google Books…', '');
        try {
            const gbRes  = await fetch('staff_isbn_lookup.php?isbn=' + encodeURIComponent(isbn), { headers: { Accept: 'application/json' } });
            const gbData = await gbRes.json();
            if (!gbData || !gbData.ok) {
                isbnStatus(gbData?.error || 'Nessun dato trovato per questo ISBN.', 'err');
            } else {
                applyFields({
                    title:           gbData.title,
                    title_remainder: gbData.subtitle,
                    author:          gbData.author,
                    publisher:       gbData.publisher,
                    pub_year:        gbData.pub_year,
                    pages:           gbData.pages ? gbData.pages + ' pagine' : null,
                    summary:         gbData.description,
                });
                if (Array.isArray(gbData.subjects) && gbData.subjects.length) {
                    ['topic1','topic2','topic3','topic4','topic5'].forEach((id, i) => {
                        applyFields({[id]: gbData.subjects[i]});
                    });
                }
                isbnStatus('Dati Google Books trovati e campi precompilati.', 'ok');
            }
        } catch (_e) {
            isbnStatus('Errore nella chiamata ai cataloghi esterni.', 'err');
        }
        btnIsbn.disabled = false;
        btnIsbn.textContent = orig;
    });
}

// ============================================================
// SBN SEARCH + MODAL (tab sbn)
// ============================================================
const sbnBtn       = document.getElementById('sbn-search-btn');
const sbnResults   = document.getElementById('sbn-results');
const sbnModal     = document.getElementById('sbn-preview-modal');
const sbnContent   = document.getElementById('sbn-preview-content');
const sbnImportBtn = document.getElementById('sbn-modal-import-btn');
let sbnResultsData = [];
let sbnCurrent     = null;

function escHtml(t) {
    if (t == null) return '';
    const d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}

if (sbnBtn) {
    sbnBtn.addEventListener('click', async () => {
        const q    = document.getElementById('sbn-search-q').value.trim();
        const type = document.getElementById('sbn-search-type').value;
        if (!q) return;
        sbnResults.innerHTML = '<p>Ricerca in corso su SBN…</p>';
        try {
            const res  = await fetch(BASE + '/ajax_sbn_enrich.php?action=search_sbn&q=' + encodeURIComponent(q) + '&type=' + type);
            const data = await res.json();
            if (!data.ok) { sbnResults.innerHTML = '<div class="sbn-import-err">❌ ' + escHtml(data.error || 'Errore server') + '</div>'; return; }
            if (data.total === 0) { sbnResults.innerHTML = '<div class="sbn-import-warn">⚠ Nessun risultato per: <code>' + escHtml(q) + '</code></div>'; return; }
            sbnResultsData = data.results;
            let html = '<table><tr><th>BID</th><th>Titolo</th><th>Autore</th><th>Editore</th><th>Anno</th><th></th></tr>';
            for (const r of data.results) {
                html += `<tr>
                    <td><code>${escHtml(r.bid_sbn||'—')}</code></td>
                    <td>${escHtml(r.titolo||'—')}</td>
                    <td>${escHtml(r.autore||'—')}</td>
                    <td>${escHtml(r.editore||'—')}</td>
                    <td>${escHtml(r.anno||'—')}</td>
                    <td>${r.bid_sbn ? `<button class="sbn-btn-preview" data-bid="${escHtml(r.bid_sbn)}">✏️ Modifica &amp; Importa</button>` : '—'}</td>
                </tr>`;
            }
            sbnResults.innerHTML = html + '</table>';
        } catch (e) {
            sbnResults.innerHTML = '<div class="sbn-import-err">Errore di rete: ' + escHtml(e.message) + '</div>';
        }
    });

    sbnResults.addEventListener('click', e => {
        const btn = e.target.closest('button[data-bid]');
        if (btn) sbnOpenModal(btn.dataset.bid);
    });
}

async function sbnOpenModal(bid) {
    // Apri subito il modal con un placeholder
    sbnCurrent = { bid_sbn: bid };
    sbnImportBtn.disabled = true;
    sbnImportBtn.textContent = 'Caricamento…';
    sbnImportBtn.style.display = '';
    sbnContent.innerHTML = '<p style="color:#666;padding:1rem 0">Recupero dati completi da SBN (UNIMARC)…</p>';
    sbnModal.classList.add('active');

    try {
        // Fetch full record con detail=full per avere UNIMARC (ISBN, pagine, Dewey, ecc.)
        const res  = await fetch(BASE + '/ajax_sbn_enrich.php?action=preview_record&bid=' + encodeURIComponent(bid));
        const data = await res.json();
        if (!data.ok) {
            sbnContent.innerHTML = `<div class="sbn-import-err">Errore nel recupero del record: ${escHtml(data.error || 'Errore sconosciuto')}</div>`;
            sbnImportBtn.textContent = '📥 Importa nel catalogo';
            sbnImportBtn.disabled = true;
            return;
        }
        sbnCurrent = data;
        sbnCurrent.bid_sbn = data.bid_sbn || bid;
        sbnContent.innerHTML = sbnRenderForm(sbnCurrent);
        sbnImportBtn.disabled = false;
        sbnImportBtn.textContent = '📥 Importa nel catalogo';
    } catch (e) {
        sbnContent.innerHTML = `<div class="sbn-import-err">Errore di rete: ${escHtml(e.message)}</div>`;
        sbnImportBtn.textContent = '📥 Importa nel catalogo';
        sbnImportBtn.disabled = true;
    }
}

window.sbnCloseModal = function () {
    sbnModal.classList.remove('active');
    sbnCurrent = null;
    sbnImportBtn.style.display = '';
    sbnImportBtn.disabled = false;
    sbnImportBtn.textContent = '📥 Importa nel catalogo';
};

window.sbnImport = async function () {
    if (!sbnCurrent) return;
    const editedData = sbnCollect();
    if (!editedData.call_nmbr1) {
        const el = document.getElementById('sbn-field-call_nmbr1');
        if (el) { el.focus(); el.style.outline = '2px solid #c00'; }
        sbnContent.insertAdjacentHTML('afterbegin', '<div class="sbn-import-err" style="margin-bottom:.75rem;">La <strong>Segnatura (collocazione fisica)</strong> è obbligatoria.</div>');
        return;
    }
    sbnImportBtn.disabled = true;
    sbnImportBtn.textContent = 'Importo…';
    editedData.bid_sbn = sbnCurrent.bid_sbn;
    try {
        const res  = await fetch(BASE + '/ajax_sbn_enrich.php?action=import_record_with_data', {
            method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(editedData)
        });
        const data = await res.json();
        if (data.ok) {
            const detailUrl = BASE + '/index.php?page=item&bibid=' + encodeURIComponent(data.bibid);
            sbnContent.innerHTML = `<div class="sbn-success-banner">
                <h4>✓ Record importato!</h4>
                <p>BIBID <strong>${escHtml(String(data.bibid))}</strong></p>
                <a href="${escHtml(detailUrl)}" target="_blank">→ Vedi scheda</a>
            </div>`;
            sbnImportBtn.style.display = 'none';
            setTimeout(() => { sbnCloseModal(); sbnResults.innerHTML = ''; sbnResultsData = []; }, 4000);
        } else {
            sbnContent.insertAdjacentHTML('afterbegin', `<div class="sbn-import-err" style="margin-bottom:.75rem;">❌ ${escHtml(data.error||'Errore')}</div>`);
            sbnImportBtn.disabled = false;
            sbnImportBtn.textContent = '📥 Importa nel catalogo';
        }
    } catch (e) {
        sbnContent.insertAdjacentHTML('afterbegin', `<div class="sbn-import-err" style="margin-bottom:.75rem;">Errore di rete: ${escHtml(e.message)}</div>`);
        sbnImportBtn.disabled = false;
        sbnImportBtn.textContent = '📥 Importa nel catalogo';
    }
};

document.addEventListener('keydown', e => { if (e.key === 'Escape') sbnCloseModal(); });
if (sbnModal) sbnModal.addEventListener('click', e => { if (e.target === sbnModal) sbnCloseModal(); });

function sbnRenderForm(r) {
    const fields = [
        {section:'Dati principali'},
        {label:'BID SBN',key:'bid_sbn',readonly:true},
        {label:'Titolo',key:'titolo',type:'text'},
        {label:'Autore',key:'autore',type:'text'},
        {label:'Editore',key:'editore',type:'text'},
        {label:'Luogo',key:'luogo',type:'text'},
        {label:'Anno',key:'anno',type:'text'},
        {label:'ISBN',key:'isbn',type:'text'},
        {section:'Classificazione'},
        {label:'Dewey',key:'dewey_code',type:'text'},
        {label:'Lingua',key:'lingua',type:'text'},
        {label:'Paese',key:'paese',type:'text'},
        {section:'Contenuto'},
        {label:'Collana / Serie',key:'collezione',type:'text'},
        {label:'Titolo uniforme',key:'titolo_uniforme',type:'text'},
        {label:'Note generali',key:'note',type:'textarea'},
        {label:'Abstract',key:'abstract',type:'textarea'},
        {label:'Indice / Sommario',key:'indice',type:'textarea'},
        {label:'Riferimenti bibliogr.',key:'bibliografia',type:'text'},
        {label:'Descrizione fisica',key:'dimensioni',type:'text'},
        {label:'Illustrazioni',key:'illustrazioni',type:'text'},
        {label:'Soggetti',key:'soggetti',type:'textarea',
         value:Array.isArray(r.soggetti)?r.soggetti.join('; '):(r.soggetti||''),
         placeholder:'Separati da ; es. Resistenza; Friuli'},
        {section:'Tipo materiale e collezione'},
        {label:'Tipo materiale',key:'material_cd',type:'select',
         options:[{value:'1',label:'Nastri audio'},{value:'2',label:'Libro'},{value:'3',label:'Cd audio'},
                  {value:'4',label:'Cd ROM'},{value:'6',label:'Periodici'},{value:'7',label:'Mappe'},
                  {value:'8',label:'Video/DVD'},{value:'9',label:'Libro Digitale'},{value:'10',label:'Opuscolo'}],value:'2'},
        {label:'Collezione',key:'collection_cd',type:'select',
         options:[{value:'1',label:'Narrativa (21 gg)'},{value:'2',label:'Saggistica (30 gg)'},
                  {value:'10',label:'Periodici (14 gg)'},{value:'12',label:'Video e DVDs (15 gg)'},
                  {value:'16',label:'Internati Militari (30 gg)'},{value:'17',label:'Teatro (30 gg)'}],value:'1'},
        {section:'Collocazione fisica'},
        {label:'Segnatura',key:'call_nmbr1',type:'text',placeholder:'Es. 910.019 VAN'},
        {label:'2ª collocazione',key:'call_nmbr2',type:'text'},
        {label:'Barcode',key:'barcode',type:'text',
         value:'SBN-'+(r.bid_sbn||'').replace(/ITICCU/,'')+'-001'},
        {label:'Stato copia',key:'status_cd',type:'select',
         options:[{value:'in',label:'Disponibile (in)'},{value:'out',label:'In prestito (out)'},
                  {value:'mnd',label:'Mancante/danneg. (mnd)'}],value:'in'},
    ];
    let html = '<div class="sbn-edit-grid">';
    for (const f of fields) {
        if (f.section) { html += `<div class="sbn-edit-section">${escHtml(f.section)}</div>`; continue; }
        const val = f.value !== undefined ? f.value : (r[f.key] || '');
        const dv  = Array.isArray(val) ? val.join('; ') : String(val);
        html += `<div class="sbn-edit-label">${escHtml(f.label)}</div>`;
        if (f.readonly) {
            html += `<div class="sbn-edit-readonly"><code>${escHtml(dv)}</code></div>`;
        } else if (f.type === 'textarea') {
            html += `<div class="sbn-edit-field"><textarea id="sbn-field-${f.key}" rows="2" placeholder="${escHtml(f.placeholder||'')}">${escHtml(dv)}</textarea></div>`;
        } else if (f.type === 'select') {
            html += `<div class="sbn-edit-field"><select id="sbn-field-${f.key}">`;
            for (const opt of (f.options||[])) html += `<option value="${escHtml(opt.value)}"${opt.value===String(f.value||'')?'  selected':''}>${escHtml(opt.label)}</option>`;
            html += '</select></div>';
        } else {
            html += `<div class="sbn-edit-field"><input type="text" id="sbn-field-${f.key}" value="${escHtml(dv)}" placeholder="${escHtml(f.placeholder||'')}"></div>`;
        }
    }
    html += '</div>';
    if (r.sbn_link || r.opac_link) html += `<p style="margin-top:.75rem;"><a href="${escHtml(r.sbn_link||r.opac_link)}" target="_blank" style="color:#b00;font-weight:600;">→ Vedi su OPAC SBN</a></p>`;
    return html;
}

function sbnCollect() {
    const keys = ['titolo','autore','editore','luogo','anno','dewey_code','lingua','paese',
                  'collezione','note','abstract','indice','dimensioni','illustrazioni','isbn'];
    const d = {};
    for (const k of keys) { const el = document.getElementById('sbn-field-'+k); if (el) d[k] = el.value.trim(); }
    const sogEl = document.getElementById('sbn-field-soggetti');
    if (sogEl) d.soggetti = sogEl.value.split(';').map(s=>s.trim()).filter(Boolean);
    for (const k of ['material_cd','collection_cd','call_nmbr1','call_nmbr2','call_nmbr3','barcode','status_cd']) {
        const el = document.getElementById('sbn-field-'+k); if (el) d[k] = el.value.trim();
    }
    return d;
}

<?php if (!empty($gbApiKey)): ?>
// ============================================================
// GOOGLE BOOKS COVER LOADER (homepage / già presente)
// (omesso qui, i cover sono gestiti in item.php e home.php)
// ============================================================
<?php endif; ?>

})();
</script>

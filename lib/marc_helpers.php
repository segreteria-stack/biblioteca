<?php
declare(strict_types=1);

/**
 * Helper MARC per uso trasversale (OPAC, import, ISBN lookup, ecc.)
 *
 * Le funzioni di questo file NON sostituiscono i campi "base" in tabella `biblio`,
 * ma li affiancano. L'idea:
 *
 *  - tabella `biblio`        = colonne principali per OPAC / staff
 *  - tabella `biblio_field`  = dettagli MARC (tag / sottocampi)
 *
 * Questo file fornisce:
 *  - marc_load_fields(bibid)              → carica biblio_field per un titolo
 *  - marc_build_index(rows)               → indice $index[tag][subfield][] = valore
 *  - marc_first() / marc_all()            → utilità per leggere i valori
 *  - marc_extract_logical_fields()        → combina base + MARC (20, 260/264, 300, 500, 520, 6xx)
 */

require_once __DIR__ . '/DB.php';

/**
 * Carica tutti i campi MARC (biblio_field) per un dato bibid.
 *
 * @param int $bibid
 * @return array<int,array{tag:int,subfield_cd:string,field_data:string}>
 */
function marc_load_fields(int $bibid): array
{
    $pdo = DB::conn();

    $sql = '
        SELECT tag, subfield_cd, field_data
        FROM biblio_field
        WHERE bibid = :bibid
        ORDER BY tag, subfield_cd
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':bibid', $bibid, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tag         = (int)($row['tag'] ?? 0);
        $subfield_cd = (string)($row['subfield_cd'] ?? '');
        $field_data  = trim((string)($row['field_data'] ?? ''));

        if ($tag <= 0 || $subfield_cd === '' || $field_data === '') {
            continue;
        }

        $out[] = [
            'tag'         => $tag,
            'subfield_cd' => $subfield_cd,
            'field_data'  => $field_data,
        ];
    }

    return $out;
}

/**
 * Costruisce un indice dei campi MARC:
 *   $index[tag][subfield_cd][] = field_data
 *
 * @param array<int,array{tag:int,subfield_cd:string,field_data:string}> $rows
 * @return array<int,array<string,array<int,string>>>
 */
function marc_build_index(array $rows): array
{
    $index = [];

    foreach ($rows as $r) {
        $tag   = (int)($r['tag'] ?? 0);
        $sub   = (string)($r['subfield_cd'] ?? '');
        $value = trim((string)($r['field_data'] ?? ''));

        if ($tag <= 0 || $sub === '' || $value === '') {
            continue;
        }

        if (!isset($index[$tag])) {
            $index[$tag] = [];
        }
        if (!isset($index[$tag][$sub])) {
            $index[$tag][$sub] = [];
        }

        $index[$tag][$sub][] = $value;
    }

    return $index;
}

/**
 * Restituisce il primo valore non vuoto per (tag, subfield_cd).
 *
 * @param array<int,array<string,array<int,string>>> $index
 */
function marc_first(array $index, int $tag, string $subfield): ?string
{
    if (!isset($index[$tag][$subfield]) || !is_array($index[$tag][$subfield])) {
        return null;
    }

    foreach ($index[$tag][$subfield] as $v) {
        $v = trim((string)$v);
        if ($v !== '') {
            return $v;
        }
    }

    return null;
}

/**
 * Restituisce tutti i valori non vuoti per (tag, subfield_cd).
 *
 * @param array<int,array<string,array<int,string>>> $index
 * @return string[]
 */
function marc_all(array $index, int $tag, string $subfield): array
{
    if (!isset($index[$tag][$subfield]) || !is_array($index[$tag][$subfield])) {
        return [];
    }

    $out = [];
    foreach ($index[$tag][$subfield] as $v) {
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }

    return $out;
}

/**
 * Estrae da `biblio_field` i "campi logici" principali, da usare
 * come INTEGRAZIONE / FALLBACK rispetto alle colonne base di `biblio`.
 *
 * Campi gestiti:
 *  - isbn:       tag 20 $a
 *  - publisher:  260 $b o 264 $b
 *  - pub_year:   260 $c o 264 $c (prima sequenza di 4 cifre)
 *  - pages:      300 $a (descrizione fisica — "527 p. ; 21 cm")
 *  - summary:    520 $a
 *  - notes:      500 $a (più occorrenze concatenate con "\n")
 *                FIX: tag 300 $a (descrizione fisica) NON usato come fallback note
 *  - subjects:   unione di:
 *                  * topic1..topic5 da `biblio`
 *                  * tag 650 $a (soggetti topici, uno per riga)
 *                  * tag 651 $a (soggetti geografici)
 *                FIX: ogni $a è un soggetto autonomo — NON concatenare con $x/$y/$z
 *
 * NOTA: se il valore è già presente in $base (es. 'publisher'), NON viene
 * sovrascritto: i dati MARC fanno solo da completamento.
 *
 * @param array<int,array<string,array<int,string>>> $index  Indice creato con marc_build_index()
 * @param array<string,mixed> $base  Record "base" (es. row di `biblio`)
 * @return array{
 *   isbn: ?string,
 *   publisher: ?string,
 *   pub_year: ?string,
 *   pages: ?string,
 *   summary: ?string,
 *   notes: ?string,
 *   subjects: string[]
 * }
 */
function marc_extract_logical_fields(array $index, array $base = []): array
{
    $out = [
        'isbn'     => null,
        'publisher'=> null,
        'pub_year' => null,
        'pages'    => null,
        'summary'  => null,
        'notes'    => null,
        'subjects' => [],
    ];

    // Valori base (se già presenti in tabella biblio)
    if (!empty($base['isbn'])) {
        $out['isbn'] = trim((string)$base['isbn']);
    }
    if (!empty($base['publisher'])) {
        $out['publisher'] = trim((string)$base['publisher']);
    }
    if (!empty($base['pub_year'])) {
        $out['pub_year'] = trim((string)$base['pub_year']);
    }
    if (!empty($base['pages'])) {
        $out['pages'] = trim((string)$base['pages']);
    }
    if (!empty($base['summary'])) {
        $out['summary'] = trim((string)$base['summary']);
    }
    if (!empty($base['notes'])) {
        $out['notes'] = trim((string)$base['notes']);
    }

    // Soggetti base da topic1..5 (deduplicati)
    $topics = [];
    foreach (['topic1','topic2','topic3','topic4','topic5'] as $tk) {
        if (!empty($base[$tk])) {
            $val = trim((string)$base[$tk]);
            if ($val !== '' && !in_array($val, $topics, true)) {
                $topics[] = $val;
            }
        }
    }

    // ---------------------------------------------------------
    // ISBN (20 $a)
    // ---------------------------------------------------------
    if ($out['isbn'] === null) {
        $isbn = marc_first($index, 20, 'a');
        if ($isbn !== null) {
            $out['isbn'] = $isbn;
        }
    }

    // ---------------------------------------------------------
    // Editore (260/264 $b)
    // ---------------------------------------------------------
    if ($out['publisher'] === null) {
        $publisher = marc_first($index, 260, 'b');
        if ($publisher === null) {
            $publisher = marc_first($index, 264, 'b');
        }
        if ($publisher !== null) {
            $out['publisher'] = $publisher;
        }
    }

    // ---------------------------------------------------------
    // Anno (260/264 $c, estrazione di 4 cifre)
    // ---------------------------------------------------------
    if ($out['pub_year'] === null) {
        $year = marc_first($index, 260, 'c');
        if ($year === null) {
            $year = marc_first($index, 264, 'c');
        }
        if ($year !== null && preg_match('/(\d{4})/', $year, $m)) {
            $out['pub_year'] = $m[1];
        }
    }

    // ---------------------------------------------------------
    // Pagine / descrizione fisica (300 $a)
    // FIX: tag 300 $a = descrizione fisica (es. "527 p. ; 21 cm")
    //      va in 'pages', NON in 'notes'
    // ---------------------------------------------------------
    if ($out['pages'] === null) {
        $pages = marc_first($index, 300, 'a');
        if ($pages !== null) {
            $out['pages'] = $pages;
        }
    }

    // ---------------------------------------------------------
    // Riassunto (520 $a)
    // ---------------------------------------------------------
    if ($out['summary'] === null) {
        $summary = marc_first($index, 520, 'a');
        if ($summary !== null) {
            $out['summary'] = $summary;
        }
    }

    // ---------------------------------------------------------
    // Note generali (500 $a) — concatenando più occorrenze
    // FIX: NON usare tag 300 $a come fallback per le note:
    //      la descrizione fisica non è una nota testuale.
    // ---------------------------------------------------------
    if ($out['notes'] === null) {
        $notesArr = marc_all($index, 500, 'a');
        if ($notesArr !== []) {
            $out['notes'] = implode("\n", $notesArr);
        }
        // Nessun fallback su tag 300: se non ci sono note 500, notes rimane null.
    }

    // ---------------------------------------------------------
    // Soggetti MARC
    //
    // FIX: ogni riga $a è un soggetto autonomo — NON concatenare
    //      con $x/$y/$z. Le suddivisioni ($x generale, $y cronologica,
    //      $z geografica) sono qualificatori interni al soggetto authority
    //      ma nel nostro DB SBN ogni soggetto è già salvato come $a separato.
    //
    // Tag gestiti:
    //   650 $a = soggetto topico       (es. "Resistenza")
    //   651 $a = soggetto geografico   (es. "Italia")
    // ---------------------------------------------------------
    $subjectStrings = [];

    foreach ([650, 651] as $subjectTag) {
        $aValues = $index[$subjectTag]['a'] ?? [];
        foreach ($aValues as $val) {
            $val = trim((string)$val);
            if ($val !== '' && !in_array($val, $subjectStrings, true)) {
                $subjectStrings[] = $val;
            }
        }
    }

    // Uniamo soggetti base (topic1..5) + soggetti MARC (650/651 $a)
    foreach ($subjectStrings as $s) {
        if (!in_array($s, $topics, true)) {
            $topics[] = $s;
        }
    }

    $out['subjects'] = $topics;

    return $out;
}

/**
 * Restituisce l'array normalizzato e deduplicato dei soggetti per un bibid.
 *
 * Fonti (in ordine di priorità):
 *  1. topic1..5 da $record (colonne biblio) — fonte primaria, record vecchi
 *  2. biblio_field tag 650 $a — soggetti topici SBN
 *  3. biblio_field tag 651 $a — soggetti geografici SBN
 *
 * Normalizzazione applicata:
 *  - trim()
 *  - ucfirst() per uniformare maiuscole/minuscole (es. ITALIA → Italia)
 *  - dedup case-insensitive con mb_strtolower
 *  - scarta soggetti che sono solo numeri o pura punteggiatura
 *
 * @param PDO   $pdo
 * @param int   $bibid
 * @param array $record  Row della tabella biblio (deve contenere topic1..5)
 * @return string[]
 */
/**
 * Normalizza un singolo valore soggetto: trim, ucfirst se tutto maiuscolo,
 * restituisce null se il valore è solo cifre/punteggiatura o vuoto.
 */
function marc_normalize_subject_val(string $val): ?string
{
    $val = trim($val);
    if ($val === '') return null;
    if (preg_match('/^[\d\s\-–—.,:;]+$/', $val)) return null;
    if ($val === mb_strtoupper($val, 'UTF-8')) {
        $val = mb_convert_case($val, MB_CASE_TITLE, 'UTF-8');
    }
    return $val;
}

/**
 * Spacca una stringa soggetto composta (es. "Resistenza -- Italia; Partigiani")
 * nei soggetti individuali, normalizza ciascuno e restituisce solo quelli validi.
 * Separatori: " -- " (suddivisione MARC) e ";" (lista multipla).
 *
 * @return string[]
 */
function marc_split_subject_string(string $raw): array
{
    // Separa su " -- " (MARC21), " - " (SBN/formato italiano) e ";"
    // Il trattino senza spazi (es. "1939-1945") NON viene spezzato
    $parts  = preg_split('/\s+--\s+|\s+-\s+|\s*;\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $normalized = marc_normalize_subject_val($part);
        if ($normalized !== null) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function marc_get_subjects(PDO $pdo, int $bibid, array $record = []): array
{
    $subjects = [];
    $seenKeys = [];

    $addRaw = function (string $raw) use (&$subjects, &$seenKeys): void {
        foreach (marc_split_subject_string($raw) as $val) {
            $key = mb_strtolower($val, 'UTF-8');
            if (isset($seenKeys[$key])) continue;
            $seenKeys[$key] = true;
            $subjects[] = $val;
        }
    };

    // 1. topic1..5 da biblio
    foreach (['topic1', 'topic2', 'topic3', 'topic4', 'topic5'] as $tk) {
        $val = trim((string)($record[$tk] ?? ''));
        if ($val !== '') $addRaw($val);
    }

    // 2+3. biblio_field tag 650 e 651 $a
    if ($bibid > 0) {
        try {
            $st = $pdo->prepare("
                SELECT field_data
                FROM biblio_field
                WHERE bibid = ? AND tag IN (650, 651) AND subfield_cd = 'a'
                ORDER BY tag, fieldid
            ");
            $st->execute([$bibid]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $val = trim((string)($row['field_data'] ?? ''));
                if ($val !== '') $addRaw($val);
            }
        } catch (PDOException $e) {
            // non bloccante
        }
    }

    return $subjects;
}

/**
 * Carica i soggetti per un set di bibid in una sola query SQL.
 * Usata nelle pagine di ricerca per evitare N+1 query.
 * Applica la stessa normalizzazione di marc_get_subjects().
 *
 * @param PDO   $pdo
 * @param int[] $bibids
 * @param array<int,array> $records  Righe biblio indicizzate per bibid (devono contenere topic1..5)
 * @return array<int, string[]>
 */
function search_fetch_subjects_map(PDO $pdo, array $bibids, array $records = []): array
{
    $out    = [];
    $bibids = array_values(array_unique(array_filter(array_map('intval', $bibids), static fn($v) => $v > 0)));
    if ($bibids === []) return $out;

    $seenPerBid = [];
    foreach ($bibids as $bid) {
        $out[$bid]      = [];
        $seenPerBid[$bid] = [];
        $rec            = $records[$bid] ?? [];
        foreach (['topic1','topic2','topic3','topic4','topic5'] as $tk) {
            $raw = trim((string)($rec[$tk] ?? ''));
            if ($raw === '') continue;
            foreach (marc_split_subject_string($raw) as $v) {
                $key = mb_strtolower($v, 'UTF-8');
                if (isset($seenPerBid[$bid][$key])) continue;
                $seenPerBid[$bid][$key] = true;
                $out[$bid][] = $v;
            }
        }
    }

    try {
        $ph = implode(',', array_fill(0, count($bibids), '?'));
        $st = $pdo->prepare("
            SELECT bibid, field_data
            FROM biblio_field
            WHERE bibid IN ($ph)
              AND tag IN (650, 651)
              AND subfield_cd = 'a'
            ORDER BY bibid, tag, fieldid
        ");
        $st->execute($bibids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int)$row['bibid'];
            if (!isset($out[$bid])) continue;
            $raw = trim((string)($row['field_data'] ?? ''));
            if ($raw === '') continue;
            foreach (marc_split_subject_string($raw) as $v) {
                $key = mb_strtolower($v, 'UTF-8');
                if (isset($seenPerBid[$bid][$key])) continue;
                $seenPerBid[$bid][$key] = true;
                $out[$bid][] = $v;
            }
        }
    } catch (PDOException $e) {
        // non bloccante
    }

    return $out;
}
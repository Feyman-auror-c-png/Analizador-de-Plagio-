<?php
declare(strict_types=1);

set_time_limit(120);

$result = null;
$error = null;
$extractedNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim((string)($_POST['text'] ?? ''));
    $scanMode = (string)($_POST['scan_mode'] ?? 'balanced');

    if ($text === '' && isset($_FILES['text_file']) && is_uploaded_file($_FILES['text_file']['tmp_name'])) {
        $uploadedName = (string)($_FILES['text_file']['name'] ?? '');
        $extension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));

        if ($extension === 'txt') {
            $text = trim((string)file_get_contents($_FILES['text_file']['tmp_name']));
            $extractedNotice = 'Texto cargado desde archivo .txt.';
        } elseif ($extension === 'pdf') {
            $text = extract_pdf_text($_FILES['text_file']['tmp_name']);
            $extractedNotice = $text !== ''
                ? 'Texto extraido del PDF. Si el PDF es escaneado como imagen, revisa que el texto detectado tenga sentido.'
                : null;
        } else {
            $error = 'Por ahora se aceptan archivos .pdf y .txt. Para Word, exporta a PDF o copia y pega el texto.';
        }
    }

    if (!$error && mb_strlen($text) < 120) {
        $error = 'No pude obtener suficiente texto. Si es un PDF escaneado, primero pasalo por OCR o copia y pega el contenido.';
    }

    if (!$error) {
        $result = analyze_plagiarism($text, $scanMode);
    }
}

function clamp_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function analyze_plagiarism(string $text, string $scanMode): array
{
    $fragments = build_fragments($text);
    $profile = scan_profile($text, $scanMode);
    $queries = select_queries($fragments, $profile['max_queries']);
    $pages = [];
    $seenUrls = [];
    $blockedSearches = 0;

    foreach ($queries as $query) {
        polite_delay($profile['delay_ms']);
        $links = search_web($query, $profile['results_per_query']);

        if (!$links) {
            $blockedSearches++;
            continue;
        }

        foreach ($links as $link) {
            if (isset($seenUrls[$link])) {
                continue;
            }

            $seenUrls[$link] = true;
            polite_delay((int)round($profile['delay_ms'] / 2));
            $pageText = fetch_page_text($link);

            if (mb_strlen($pageText) < 200) {
                continue;
            }

            $score = similarity_score($text, $pageText);
            $matches = find_matching_fragments($fragments, $pageText);

            if ($score >= 4 || count($matches) > 0) {
                $pages[] = [
                    'url' => $link,
                    'score' => $score,
                    'matches' => $matches,
                ];
            }
        }
    }

    usort($pages, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $pages = array_slice($pages, 0, 12);
    $overall = count($pages) ? min(100, (int)round(array_sum(array_column($pages, 'score')) / max(1, min(5, count($pages))) * 1.4)) : 0;

    return [
        'checked_fragments' => count($queries),
        'checked_pages' => count($seenUrls),
        'blocked_searches' => $blockedSearches,
        'profile' => $profile['label'],
        'overall' => $overall,
        'pages' => $pages,
    ];
}

function scan_profile(string $text, string $mode): array
{
    $wordCount = count(preg_split('/\s+/u', normalize_text($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $profiles = [
        'light' => ['label' => 'Suave', 'max_queries' => 6, 'results_per_query' => 3, 'delay_ms' => 1200],
        'balanced' => ['label' => 'Balanceado', 'max_queries' => 10, 'results_per_query' => 4, 'delay_ms' => 1700],
        'deep' => ['label' => 'Profundo', 'max_queries' => 16, 'results_per_query' => 5, 'delay_ms' => 2300],
    ];

    $profile = $profiles[$mode] ?? $profiles['balanced'];

    if ($wordCount > 12000) {
        $profile['max_queries'] = min($profile['max_queries'] + 4, 20);
    }

    if ($wordCount < 1200) {
        $profile['max_queries'] = min($profile['max_queries'], 6);
    }

    return $profile;
}

function select_queries(array $fragments, int $limit): array
{
    if (count($fragments) <= $limit) {
        return $fragments;
    }

    $selected = [];
    $step = max(1, (int)floor(count($fragments) / $limit));

    for ($i = 0; $i < count($fragments) && count($selected) < $limit; $i += $step) {
        $selected[] = $fragments[$i];
    }

    return array_values(array_unique($selected));
}

function polite_delay(int $milliseconds): void
{
    usleep(max(250, $milliseconds) * 1000);
}

function build_fragments(string $text): array
{
    $clean = preg_replace('/\s+/u', ' ', normalize_text($text));
    $sentences = preg_split('/(?<=[.!?;:])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $fragments = [];

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 9 && count($words) <= 28) {
            $fragments[] = $sentence;
        }
    }

    if (count($fragments) < 4) {
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i < count($words); $i += 22) {
            $chunk = array_slice($words, $i, 24);
            if (count($chunk) >= 10) {
                $fragments[] = implode(' ', $chunk);
            }
        }
    }

    usort($fragments, fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    return array_values(array_unique(array_slice($fragments, 0, 24)));
}

function search_web(string $query, int $limit): array
{
    $url = 'https://duckduckgo.com/html/?q=' . rawurlencode('"' . trim($query) . '"');
    $html = http_get($url);

    if ($html === '') {
        return [];
    }

    preg_match_all('/<a[^>]+class="result__a"[^>]+href="([^"]+)"/i', $html, $matches);
    $links = [];

    foreach ($matches[1] ?? [] as $href) {
        $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        $parts = parse_url($decoded);

        if (($parts['host'] ?? '') === 'duckduckgo.com' && isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $decoded = (string)($params['uddg'] ?? $decoded);
        }

        if (filter_var($decoded, FILTER_VALIDATE_URL)) {
            $links[] = $decoded;
        }

        if (count($links) >= $limit) {
            break;
        }
    }

    return array_values(array_unique($links));
}

function fetch_page_text(string $url): string
{
    $html = http_get($url);

    if ($html === '') {
        return '';
    }

    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return normalize_text($text);
}

function http_get(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 14,
            'header' => "User-Agent: Mozilla/5.0 PlagioIA-MVP/1.0\r\nAccept-Language: es,en;q=0.8\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    return is_string($body) ? $body : '';
}

function similarity_score(string $source, string $target): int
{
    $sourceShingles = shingles($source);
    $targetShingles = shingles($target);

    if (!$sourceShingles || !$targetShingles) {
        return 0;
    }

    $intersection = count(array_intersect_key($sourceShingles, $targetShingles));
    $coverage = $intersection / max(1, count($sourceShingles));

    return (int)round($coverage * 100);
}

function shingles(string $text): array
{
    $words = preg_split('/\s+/u', mb_strtolower(normalize_text($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $set = [];

    for ($i = 0; $i <= count($words) - 5; $i++) {
        $set[implode(' ', array_slice($words, $i, 5))] = true;
    }

    return $set;
}

function find_matching_fragments(array $fragments, string $pageText): array
{
    $matches = [];
    $page = mb_strtolower(normalize_text($pageText));

    foreach ($fragments as $fragment) {
        $needle = mb_strtolower(trim($fragment));

        if (mb_strlen($needle) < 60) {
            continue;
        }

        if (mb_stripos($page, $needle) !== false) {
            $matches[] = $fragment;
        }

        if (count($matches) >= 4) {
            break;
        }
    }

    return $matches;
}

function normalize_text(string $text): string
{
    $text = preg_replace('/[^\P{C}\n\t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function extract_pdf_text(string $filePath): string
{
    $raw = (string)file_get_contents($filePath);
    if ($raw === '') {
        return '';
    }

    $streams = extract_pdf_streams($raw);
    $pieces = [];

    foreach ($streams as $stream) {
        $pieces[] = extract_text_from_pdf_stream($stream);
    }

    $fallback = extract_text_from_pdf_stream($raw);
    $text = trim(implode(' ', $pieces) . ' ' . $fallback);

    return normalize_text($text);
}

function extract_pdf_streams(string $raw): array
{
    preg_match_all('/<<(.*?)>>\s*stream\s*(.*?)\s*endstream/s', $raw, $matches, PREG_SET_ORDER);
    $streams = [];

    foreach ($matches as $match) {
        $dictionary = $match[1];
        $stream = ltrim($match[2], "\r\n");

        if (stripos($dictionary, '/FlateDecode') !== false) {
            $inflated = @gzuncompress($stream);
            if (!is_string($inflated)) {
                $inflated = @gzdecode($stream);
            }
            if (is_string($inflated)) {
                $streams[] = $inflated;
            }
            continue;
        }

        $streams[] = $stream;
    }

    return $streams;
}

function extract_text_from_pdf_stream(string $content): string
{
    $text = [];

    preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $content, $tjMatches);
    foreach ($tjMatches[0] ?? [] as $item) {
        if (preg_match('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/s', $item, $textMatch)) {
            $text[] = decode_pdf_string($textMatch[1]);
        }
    }

    preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $arrayMatches);
    foreach ($arrayMatches[1] ?? [] as $array) {
        preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $array, $stringMatches);
        $line = '';
        foreach ($stringMatches[0] ?? [] as $pdfString) {
            $line .= decode_pdf_string(substr($pdfString, 1, -1));
        }
        $text[] = $line;
    }

    return implode(' ', $text);
}

function decode_pdf_string(string $value): string
{
    $value = preg_replace_callback('/\\\\([0-7]{1,3})/', fn(array $m): string => chr(octdec($m[1])), $value) ?? $value;
    $replacements = [
        '\\\\' => '\\',
        '\(' => '(',
        '\)' => ')',
        '\n' => ' ',
        '\r' => ' ',
        '\t' => ' ',
        '\b' => ' ',
        '\f' => ' ',
    ];

    return strtr($value, $replacements);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detector simple de plagio web</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f7f7f4;
            --ink: #202124;
            --muted: #65676b;
            --line: #d8d9d3;
            --accent: #0f766e;
            --danger: #b42318;
            --panel: #ffffff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }

        main {
            width: min(1080px, calc(100% - 32px));
            margin: 32px auto;
        }

        header {
            margin-bottom: 24px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 5vw, 44px);
            line-height: 1.05;
        }

        p {
            color: var(--muted);
            line-height: 1.55;
        }

        form, .summary, .result {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 18px;
        }

        textarea {
            width: 100%;
            min-height: 260px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 14px;
            font: 15px/1.5 Consolas, Monaco, monospace;
        }

        label {
            display: block;
            margin: 0 0 8px;
            font-weight: 700;
        }

        .controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            margin: 16px 0;
        }

        input[type="number"], input[type="file"], select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px;
            background: #fff;
        }

        button {
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            padding: 12px 18px;
            cursor: pointer;
        }

        .error {
            margin: 16px 0;
            color: var(--danger);
            font-weight: 700;
        }

        .notice {
            margin: 16px 0;
            padding: 12px 14px;
            border: 1px solid #9bd4cc;
            border-radius: 6px;
            background: #eefbf8;
            color: #19443f;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin: 22px 0;
        }

        .metric strong {
            display: block;
            font-size: 30px;
        }

        .result {
            margin: 14px 0;
        }

        .result h2 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .result a {
            color: var(--accent);
            overflow-wrap: anywhere;
        }

        .fragment {
            margin: 10px 0 0;
            padding: 10px;
            border-left: 4px solid var(--accent);
            background: #f1faf8;
            color: #263330;
        }

        .note {
            font-size: 14px;
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Detector simple de plagio web</h1>
        <p>Sube un PDF o pega texto. El sistema escoge fragmentos representativos, hace busquedas con pausas y calcula coincidencias aproximadas.</p>
    </header>

    <form method="post" enctype="multipart/form-data">
        <label for="text">Texto de la tesis</label>
        <textarea id="text" name="text" placeholder="Pega aqui un capitulo, seccion o varias paginas..."><?= htmlspecialchars((string)($_POST['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>

        <div class="controls">
            <div>
                <label for="text_file">Archivo PDF o TXT</label>
                <input id="text_file" type="file" name="text_file" accept=".pdf,.txt,application/pdf,text/plain">
            </div>
            <div>
                <label for="scan_mode">Modo de revision</label>
                <select id="scan_mode" name="scan_mode">
                    <?php $selectedMode = (string)($_POST['scan_mode'] ?? 'balanced'); ?>
                    <option value="light" <?= $selectedMode === 'light' ? 'selected' : '' ?>>Suave</option>
                    <option value="balanced" <?= $selectedMode === 'balanced' ? 'selected' : '' ?>>Balanceado</option>
                    <option value="deep" <?= $selectedMode === 'deep' ? 'selected' : '' ?>>Profundo</option>
                </select>
            </div>
        </div>

        <button type="submit">Analizar</button>
        <p class="note">Balanceado es el recomendado. Profundo revisa mas, pero tarda mas y aumenta el riesgo de bloqueo temporal del buscador.</p>
    </form>

    <?php if ($extractedNotice): ?>
        <p class="notice"><?= htmlspecialchars($extractedNotice, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <section class="summary" aria-label="Resumen del analisis">
            <div class="metric">
                <strong><?= (int)$result['overall'] ?>%</strong>
                coincidencia estimada
            </div>
            <div class="metric">
                <strong><?= (int)$result['checked_fragments'] ?></strong>
                fragmentos buscados
            </div>
            <div class="metric">
                <strong><?= (int)$result['checked_pages'] ?></strong>
                paginas revisadas
            </div>
            <div class="metric">
                <strong><?= count($result['pages']) ?></strong>
                fuentes con similitud
            </div>
            <div class="metric">
                <strong><?= htmlspecialchars((string)$result['profile'], ENT_QUOTES, 'UTF-8') ?></strong>
                modo usado
            </div>
        </section>

        <?php if ((int)$result['blocked_searches'] > 0): ?>
            <p class="notice">Algunas busquedas no devolvieron resultados. Puede ser normal si el texto es muy especifico o si el buscador limito solicitudes.</p>
        <?php endif; ?>

        <?php if (!count($result['pages'])): ?>
            <p>No se encontraron coincidencias claras en los resultados revisados.</p>
        <?php endif; ?>

        <?php foreach ($result['pages'] as $page): ?>
            <article class="result">
                <h2><?= (int)$page['score'] ?>% similitud contra esta pagina</h2>
                <a href="<?= htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8') ?></a>

                <?php foreach ($page['matches'] as $match): ?>
                    <div class="fragment"><?= htmlspecialchars($match, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>

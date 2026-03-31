<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$url = trim($body['url'] ?? '');

if (!$url) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'url required']);
    exit;
}

// Extract video ID
$videoId = null;
if (preg_match('/(?:v=|youtu\.be\/|embed\/|shorts\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
    $videoId = $m[1];
}
if (!$videoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid YouTube URL']);
    exit;
}

function curlGet(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function parseTranscriptXml(string $xml): string {
    $xml = simplexml_load_string($xml);
    if (!$xml) return '';
    $parts = [];
    foreach ($xml->text as $node) {
        $parts[] = html_entity_decode((string)$node, ENT_QUOTES | ENT_HTML5);
    }
    $text = implode(' ', $parts);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function parseVtt(string $vtt): string {
    // Remove WEBVTT header and cue timestamps, keep only text lines
    $lines = explode("\n", $vtt);
    $texts = [];
    $inCue = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_contains($line, '-->')) {
            $inCue = true;
            continue;
        }
        if ($line === '') {
            $inCue = false;
            continue;
        }
        if ($inCue && $line !== '' && !preg_match('/^\d+$/', $line)) {
            // Strip VTT tags like <00:00:00.000><c>text</c>
            $clean = preg_replace('/<[^>]+>/', '', $line);
            $clean = trim(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5));
            if ($clean !== '') $texts[] = $clean;
        }
    }
    // Deduplicate consecutive duplicate lines (auto-captions repeat lines)
    $deduped = [];
    $prev = '';
    foreach ($texts as $t) {
        if ($t !== $prev) {
            $deduped[] = $t;
            $prev = $t;
        }
    }
    $text = implode(' ', $deduped);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function ytDlpTranscript(string $videoId): ?string {
    $tmpBase = sys_get_temp_dir() . '/yt_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId);
    $ytdlp = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
    if (!$ytdlp) return null;

    foreach (glob($tmpBase . '.*') ?: [] as $f) unlink($f);

    $cmd = escapeshellarg($ytdlp)
        . ' --write-auto-sub --write-sub --sub-lang "en.*"'
        . ' --skip-download --sub-format vtt --no-playlist'
        . ' --extractor-args "youtube:player_client=ios"'
        . ' -o ' . escapeshellarg($tmpBase)
        . ' ' . escapeshellarg('https://www.youtube.com/watch?v=' . $videoId)
        . ' 2>/dev/null';
    exec($cmd);

    $files = glob($tmpBase . '.*.vtt') ?: [];
    if (empty($files)) return null;

    // Prefer en-orig (manual) over en (auto-generated)
    $chosen = $files[0];
    foreach ($files as $f) {
        if (str_contains($f, '.en-orig.')) { $chosen = $f; break; }
    }

    $vtt = file_get_contents($chosen);
    foreach ($files as $f) unlink($f);

    if (!$vtt) return null;
    return parseVtt($vtt) ?: null;
}

function extractJsonArray(string $haystack, string $key): ?array {
    $pos = strpos($haystack, '"' . $key . '"');
    if ($pos === false) return null;
    $start = strpos($haystack, '[', $pos);
    if ($start === false) return null;
    $depth = 0;
    $len = strlen($haystack);
    for ($i = $start; $i < $len; $i++) {
        if ($haystack[$i] === '[') $depth++;
        elseif ($haystack[$i] === ']') {
            $depth--;
            if ($depth === 0) {
                return json_decode(substr($haystack, $start, $i - $start + 1), true) ?: null;
            }
        }
    }
    return null;
}

// Strategy 1: yt-dlp (most reliable — handles manual + auto-generated captions)
$transcript = ytDlpTranscript($videoId);

// Strategy 2: extract captionTracks from ytInitialPlayerResponse
if (!$transcript) {
    $html = curlGet("https://www.youtube.com/watch?v={$videoId}");
    if ($html) {
        $tracks = extractJsonArray($html, 'captionTracks');
        if ($tracks) {
            $baseUrl = null;
            foreach ($tracks as $track) {
                if (isset($track['languageCode']) && str_starts_with($track['languageCode'], 'en')) {
                    $baseUrl = $track['baseUrl'];
                    break;
                }
            }
            if (!$baseUrl && !empty($tracks[0]['baseUrl'])) {
                $baseUrl = $tracks[0]['baseUrl'];
            }
            if ($baseUrl) {
                $xml = curlGet($baseUrl);
                if ($xml) $transcript = parseTranscriptXml($xml);
            }
        }
    }
}

// Strategy 3: timedtext API fallback
if (!$transcript) {
    $xml = curlGet("https://www.youtube.com/api/timedtext?v={$videoId}&lang=en");
    if ($xml) $transcript = parseTranscriptXml($xml);
}

// Strategy 4: auto-generated captions (ASR) fallback
if (!$transcript) {
    $xml = curlGet("https://www.youtube.com/api/timedtext?v={$videoId}&lang=en&kind=asr");
    if ($xml) $transcript = parseTranscriptXml($xml);
}

if (!$transcript) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No captions found for this video. Try a video with subtitles enabled.']);
    exit;
}

// Limit length
if (strlen($transcript) > 50000) {
    $transcript = substr($transcript, 0, 50000) . ' [transcript truncated]';
}

echo json_encode(['success' => true, 'transcript' => $transcript, 'videoId' => $videoId]);

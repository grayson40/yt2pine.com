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

/**
 * Backup: NoteGPT public transcript API (when yt-dlp / YouTube direct fetches fail on VPS).
 */
function notegptTranscript(string $videoId): ?string {
    $apiUrl = 'https://notegpt.io/api/v2/video-transcript?platform=youtube&video_id=' . rawurlencode($videoId);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://notegpt.io/detail?id=' . rawurlencode($videoId) . '&type=1',
        ],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $httpCode !== 200) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $code = $data['code'] ?? null;
    if ($code !== 100000 && $code !== '100000') {
        return null;
    }
    $transcripts = $data['data']['transcripts'] ?? null;
    if (!is_array($transcripts)) {
        return null;
    }
    $langBlock = $transcripts['en'] ?? null;
    if (!is_array($langBlock) && !empty($transcripts)) {
        $first = reset($transcripts);
        $langBlock = is_array($first) ? $first : null;
    }
    if (!is_array($langBlock)) {
        return null;
    }
    // Prefer one track only (custom > default > auto) to avoid duplicate text
    $chosen = null;
    foreach (['custom', 'default', 'auto'] as $trackKey) {
        if (!empty($langBlock[$trackKey]) && is_array($langBlock[$trackKey])) {
            $chosen = $langBlock[$trackKey];
            break;
        }
    }
    if ($chosen === null) {
        return null;
    }
    $parts = [];
    foreach ($chosen as $seg) {
        if (is_array($seg) && isset($seg['text'])) {
            $t = trim((string) $seg['text']);
            if ($t !== '') {
                $parts[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5);
            }
        }
    }
    $out = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    return $out !== '' ? $out : null;
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

// Strategy 5: NoteGPT transcript API (backup for datacenter / blocked YouTube fetches)
if (!$transcript) {
    $transcript = notegptTranscript($videoId);
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

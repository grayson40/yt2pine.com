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
$transcript = trim($body['transcript'] ?? '');
$existingScript = trim($body['existingScript'] ?? '');

if (!$transcript && !$existingScript) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'transcript required']);
    exit;
}

$systemPrompt = <<<'SYS'
You are a PineScript v6 expert. When given a trading strategy description, you output a complete, compilable PineScript v6 script.

Rules:
- Start with //@version=6
- Use strategy() with title, overlay=true, default_qty_type=strategy.percent_of_equity, default_qty_value=10
- Use ta.sma(), ta.ema(), ta.rsi(), ta.macd() — always ta. prefix
- Use request.security() not security()
- Use input.int(), input.float(), input.string(), input.bool() — typed inputs only
- Use strategy.entry() and strategy.exit() for all trades
- Include stop loss and take profit in strategy.exit()
- Add plotshape() for buy/sell signal markers
- Add bgcolor() to highlight when in a trade
- Add comments explaining the logic
- The script must compile without errors in TradingView Pine Editor
SYS;

if ($existingScript) {
    $userMessage = "Modify this existing PineScript v6 strategy based on the following instruction.\n\nInstruction: {$transcript}\n\nExisting script:\n```pinescript\n{$existingScript}\n```\n\nReturn ONLY the modified PineScript code wrapped in ```pinescript fences, followed by a 2-3 sentence explanation of what changed.";
} else {
    $userMessage = "Here is a transcript from a YouTube trading video. Extract the trading strategy described and convert it into a complete, working PineScript v6 strategy script. If multiple strategies are mentioned, implement the primary one. If details are vague, make reasonable assumptions and document them in comments. Return ONLY the PineScript code wrapped in ```pinescript fences, followed by a 2-3 sentence explanation of the strategy.\n\nTranscript:\n{$transcript}";
}

$payload = json_encode([
    'model' => 'claude-sonnet-4-20250514',
    'max_tokens' => 4096,
    'system' => $systemPrompt,
    'messages' => [['role' => 'user', 'content' => $userMessage]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
@curl_close($ch);

if (!$response) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Claude API connection failed']);
    exit;
}

$data = json_decode($response, true);
if ($httpCode !== 200 || empty($data['content'][0]['text'])) {
    http_response_code(502);
    $errMsg = $data['error']['message'] ?? 'Claude API error';
    echo json_encode(['success' => false, 'error' => $errMsg]);
    exit;
}

$text = $data['content'][0]['text'];

// Extract pinescript block
$script = '';
$explanation = '';
if (preg_match('/```pinescript\s*([\s\S]*?)```/', $text, $m)) {
    $script = trim($m[1]);
    $explanation = trim(preg_replace('/```pinescript[\s\S]*?```/', '', $text));
} elseif (preg_match('/```\s*([\s\S]*?)```/', $text, $m)) {
    $script = trim($m[1]);
    $explanation = trim(preg_replace('/```[\s\S]*?```/', '', $text));
} else {
    $script = trim($text);
}

// Ensure version header
if (!str_contains($script, '//@version=')) {
    $script = "//@version=6\n" . $script;
}

echo json_encode(['success' => true, 'script' => $script, 'explanation' => $explanation]);

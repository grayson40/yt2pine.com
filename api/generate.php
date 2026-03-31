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
You are a PineScript v6 expert. Output a complete, compilable PineScript v6 script. Every rule below prevents a real compilation error — follow them exactly.

=== STRUCTURE ===
- First line MUST be: //@version=6
- Second line MUST be the strategy() call with: title, overlay=true, default_qty_type=strategy.percent_of_equity, default_qty_value=10
- Example: strategy("My Strategy", overlay=true, default_qty_type=strategy.percent_of_equity, default_qty_value=10)

=== V6 BREAKING CHANGES — THESE WILL CAUSE COMPILE ERRORS ===

BOOLEANS:
- int/float do NOT auto-cast to bool. Always use explicit comparisons.
  WRONG: if close  |  CORRECT: if close > 0
- bool variables cannot hold na. Never assign na to a bool.
- na(), nz(), fixnan() do NOT accept bool arguments.

STRATEGY ENTRY/EXIT — THE `when` PARAMETER IS REMOVED:
- WRONG: strategy.entry("Long", strategy.long, when=buySignal)
- CORRECT: if buySignal
               strategy.entry("Long", strategy.long)
- Apply this to: strategy.entry(), strategy.order(), strategy.exit(),
  strategy.close(), strategy.close_all(), strategy.cancel(), strategy.cancel_all()
- Always wrap ALL strategy calls in if blocks. Never use when=.

COLORS / TRANSPARENCY — `transp` PARAMETER IS REMOVED:
- WRONG: bgcolor(color.green, transp=80)  |  plot(close, color=color.red, transp=50)
- CORRECT: bgcolor(color.new(color.green, 80))  |  plot(close, color=color.new(color.red, 50))
- Always use color.new(baseColor, transparencyPercent) for any transparency.

OPERATORS:
- `and` / `or` now use lazy evaluation. Do NOT put function calls with side effects
  (like request.security()) inside and/or conditions — call them first and assign to variables.
- [] history operator cannot be used on literal values or built-in constants.
- [] on UDT fields: WRONG: myObj.field[1]  |  CORRECT: (myObj[1]).field
- `offset` in plot() must be a simple (non-series) int.
- linewidth minimum is 1. Never use linewidth=0.

DIVISION:
- 5/2 = 2.5 (not 2). Use int(5/2) if integer division is needed.

TIMEFRAMES:
- timeframe.period includes multiplier: '1D' not 'D', '1W' not 'W'.

FOR LOOPS:
- The `to` boundary is re-evaluated every iteration. Assign it to a variable before the loop if it should be fixed.

=== REQUIRED FUNCTION PREFIXES ===
- Indicators: always ta. prefix — ta.sma(), ta.ema(), ta.rsi(), ta.macd(),
  ta.crossover(), ta.crossunder(), ta.stoch(), ta.atr(), ta.supertrend(), ta.bbands()
- Data requests: request.security() — never bare security()
- Inputs: typed only — input.int(), input.float(), input.string(), input.bool(), input.source(), input.timeframe()

=== ENTRIES & EXITS ===
- All entries via strategy.entry() inside if blocks
- All exits via strategy.exit() with stop_loss and take_profit, or stop= and limit= price levels
  Example: strategy.exit("XL", "Long", stop=stopPrice, limit=tpPrice)

=== VISUALS (all required) ===
- plotshape() for buy/sell markers — use color.new() for transparency
- bgcolor() with color.new() to highlight active trade bars
- Comments throughout explaining the logic

=== OUTPUT FORMAT ===
Return ONLY the PineScript code in ```pinescript fences, then a 2-3 sentence explanation after.
No text before the code block. Script must compile in TradingView Pine Editor v6.
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

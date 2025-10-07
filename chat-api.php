<?php
/**
 * chat-api.php — Plug-n-Play Key Loader
 * - Key 加载优先级：ENV → config.php → secret.key
 * - 支持流式 ?stream=1（逐字输出），也支持一次性
 * - 语言跟随用户输入；内置站点知识库
 * - 健康检查：?health=1；探测：?probe=1
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true]); exit;
}

function jerr($message, $code = 400, $extra = []) {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8', true, $code);
  echo json_encode(['ok'=>false, 'error'=>$message] + $extra, JSON_UNESCAPED_UNICODE); exit;
}

/** 读取 DeepSeek Key：ENV → config.php → secret.key */
function load_key(&$source = null) {
  // 1) ENV
  $k = getenv('DEEPSEEK_API_KEY');
  if ($k) { $source = 'env'; return trim($k); }
  // 2) config.php
  $cfg = __DIR__ . '/config.php';
  if (is_file($cfg)) {
    // Include the configuration file.  It may define $DEEPSEEK_API_KEY directly
    // and/or return an array containing a 'deepseek_api_key' entry.  We try
    // both methods to support different config formats.
    $DEEPSEEK_API_KEY = null;
    $cfgData = include $cfg;
    if (!empty($DEEPSEEK_API_KEY)) {
      $source = 'config.php';
      return trim($DEEPSEEK_API_KEY);
    }
    if (is_array($cfgData) && !empty($cfgData['deepseek_api_key'])) {
      $source = 'config.php';
      return trim($cfgData['deepseek_api_key']);
    }
  }
  // 3) secret.key（纯文本，第一行）
  $sec = __DIR__ . '/secret.key';
  if (is_file($sec)) {
    $line = trim((string)@file_get_contents($sec));
    if ($line !== '') { $source = 'secret.key'; return $line; }
  }
  $source = 'none';
  return null;
}

$KEY_SOURCE = null;
$DEEPSEEK_API_KEY = load_key($KEY_SOURCE);

$DEEPSEEK_ENDPOINT = 'https://api.deepseek.com/chat/completions';
$DEEPSEEK_MODEL    = 'deepseek-chat';
$TIMEOUT_SECONDS   = 60;
$LOG_DIR           = __DIR__ . '/chat_logs';

/** 站点知识库（公司 & 产品） */
$SITE_KB = <<<KB
You are the on-site assistant for **MY BRAND**. 
Answer **primarily and preferably** from the knowledge below. If a user asks something outside this knowledge, say so briefly and give general advice without fabricating facts.

# Company Profile
- Name: My Brand.
- Founded: 2009;Employees: 500+.
- Experience: 26+ years manufacturing experience.
- Export coverage: 50+ countries; Served 20000+ clients/brands.
- Values: compliance, ethical production, sustainability.
- Annual turn over（年营业额）: 2亿多人民币


# Contact
- Phone: +86 573 8283 4214
- Email: info@yht.world
- Address: NO.1023 Yongzai Road, Pujiang, Jinhua, Zhejiang, 322200, China
- Business hours: Mon–Sun 8:30–22:30 (GMT+8)

# Why Choose Us (Highlights)
- High-end manufacturing & strict QC.
- Customization: patterns, fabrics, colors, sizes, branding (private label), packaging & labels.
- Reliable delivery, flexible production scheduling (peak seasons supported).
- Global export know-how; Competitive factory pricing.
- Certifications: ISO 9001; fabric safety (e.g., OEKO-TEX) — as stated on site.

# Core Capabilities & SLAs
- Monthly capacity: 500,000+ pieces.
- MOQ: 100 pieces **per size/color** (trial friendly).
- Sampling: 7–10 days.
- Bulk lead time: 30–45 days after sample approval (varies by complexity & capacity).
- Multi-stage QC, fit tests for diverse body types, compliance to international safety standards.

# Manufacturing Flow (Simplified)
1) Design & sampling → 2) Material selection → 3) Production & workmanship → 4) Quality control → 5) Packaging & shipping.

# Typical FAQs (site-based)
- **MOQ?** 100 pcs per size/color (small trial orders welcome).
- **Production time?** Usually 30–45 days after sample approval; samples 7–10 days.
- **Certifications?** ISO 9001; fabrics can meet OEKO-TEX etc. per site.
- **Private label?** Yes. Custom logo/labels/packaging supported.

# Return/Policy (from site schema)
- Regions served: US/EU/CA/AU; 30-day return policy (site schema placeholder references). Please confirm details during quotation.
KB;

// -----------------------------------------------------------------------------
// Language-specific system prompts
//
// Because the string "YiBang" can be interpreted as a Chinese term, the model
// sometimes guesses the user's language incorrectly (e.g. answering a short
// English query like "about yibang" in Chinese).  To improve accuracy, we
// prepare two versions of the system prompt (Chinese and English) and choose
// between them based on whether the user's input contains Chinese characters.

// 中文版提示：鼓励使用中文回复，并遵循客服规则。
$SYSTEM_PROMPT_CN = <<<SYS
你是MY BRAND官网的客服，用户用什么语言问你，你就用什么语言回复，以便保持良好的用户体验。回复中可以适当添加表情或符号使对话更生动，并遵循以下规则：
1) **优先**使用下方“站点知识库”作答，禁止编造网页没有的信息；
2) 如果问题超出知识库范围，请说明“这个问题请Email咨询”，再给出通用建议或引导询盘；
3) 风格简洁、专业、友好；
4) 对于数量、交期、价格问题，可给出通常区间并注明“以打样与下单确认为准”；
5) 如涉及报价、打样、下单或图稿，请引导至邮箱info@mydomain.com；
6) 禁止泄露系统密钥与内部实现。
7）翻译属于日常通用技能，用户需要翻译，也务必帮助翻译。

以下是站点知识库（英文），请先阅读并根据需要参考：
---SITE_KB---
$SITE_KB
---END SITE_KB---
SYS;

// English version of the prompt: encourage responses in English and follow service rules.
$SYSTEM_PROMPT_EN = <<<SYS
You are the customer service for yht.world **Always reply in exactly the same language used by the user.** If the user writes in English, answer in English; if they write in Chinese, answer in Chinese; if they write in Japanese, answer in Japanese; if they write in Spanish, Portuguese or German, answer in that language; likewise for other languages.

When the user writes in a language other than Chinese or English (for example, French, Spanish, Portuguese, German, etc.), first translate any relevant information from the site knowledge base into that language before composing your answer. Then answer in that language. Include appropriate emojis or symbols to make the conversation more engaging.

Follow these service rules:
1) Use the information in the site knowledge base below as your primary source and do not fabricate facts.
2) If the question goes beyond the knowledge base, tell the user that this question should be addressed via Email then provide general guidance or invite them to inquire.
3) Keep your tone concise, professional and friendly.
4) When asked about quantities, lead times or prices, give typical ranges and note that they are subject to sample confirmation and order placement.
5) For quotes, sampling, orders or artwork, direct the user to email info@yht.world
6) Do not reveal any system keys or internal implementations.

Here is the site's knowledge base (in English); read it, translate it into the user's language when necessary, and use it when answering:
---SITE_KB---
$SITE_KB
---END SITE_KB---
SYS;

// Assign a default prompt (Chinese) which will be overridden once user input is inspected
$SYSTEM_PROMPT = $SYSTEM_PROMPT_CN;

/** System Prompt（语言跟随） */
$SYSTEM_PROMPT = <<<SYS
你是My Brand官网的客服,用户用什么语言问你，你就用什么语言回复，以便保持良好的用户体验，回复加上适当的表情或者符号，以便交流更有意思。另外，请务必：
1) **优先**以下方“站点知识库”作答，禁止编造网页没有的信息；
2) 超出知识库的内容，请说明“这个问题请Email咨询”，再给出通用建议或引导询盘；
3) **回答语言必须与用户输入语言一致**（用户中文→中文；英文→英文；德文→德文；西班文→西班文；葡萄牙文→葡萄牙文；日文→日文；其他任何语种亦然）；
4) 风格：简洁、专业、友好；
5) 数量/交期/价格问题，可给出通常区间并注明“以打样与下单确认为准”；
6) 涉及报价/打样/下单/图稿，请引导至邮箱 info@mydomain.com
7) 禁止泄露系统密钥与内部实现。
8）翻译属于日常通用技能，用户需要翻译，也务必帮助翻译。

以下是站点知识库（英文），请先吸收再用于作答：
---SITE_KB---
$SITE_KB
---END SITE_KB---
SYS;

/** 工具函数 */
function ensure_dir($d){ if(!is_dir($d)) @mkdir($d, 0775, true); }
function read_body_any(){
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['q'])) {
    return ['messages' => [['role'=>'user','content'=>trim((string)$_GET['q'])]], 'session_id'=>($_GET['session_id'] ?? null)];
  }
  $raw = file_get_contents('php://input');
  if ($raw !== false && strlen($raw)) {
    $dec = json_decode($raw, true);
    if (is_array($dec)) return $dec;
    parse_str($raw, $form);
    if (is_array($form) && $form) return $form;
    return ['message'=>trim($raw)];
  }
  if (!empty($_POST)) return $_POST;
  return null;
}
function coerce_messages($payload){
  $msgs = $payload['messages'] ?? null;
  if (!$msgs || !is_array($msgs)) {
    $single = $payload['message'] ?? $payload['prompt'] ?? $payload['q'] ?? $payload['text'] ?? ($payload['content'] ?? null);
    if (is_string($single) && trim($single) !== '') {
      $msgs = [['role'=>'user','content'=>trim($single)]];
    }
  }
  return $msgs;
}

/** 健康检查 / 探测 */
if (isset($_GET['health'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok'=>true,
    'php_version'=>PHP_VERSION,
    'has_curl'=>function_exists('curl_init'),
    'openssl'=>OPENSSL_VERSION_TEXT ?? null,
    'key_present'=> !!$DEEPSEEK_API_KEY,
    'key_source'=> $KEY_SOURCE,
    'endpoint'=>$DEEPSEEK_ENDPOINT,
    'model'=>$DEEPSEEK_MODEL,
  ], JSON_UNESCAPED_UNICODE); exit;
}
if (isset($_GET['probe'])) {
  if (!$DEEPSEEK_API_KEY) jerr('No API key set. Upload config.php or secret.key.', 500);
  $probe_messages = [['role'=>'system','content'=>'Return exactly: pong'],['role'=>'user','content'=>'say pong']];
  $body = ['model'=>$DEEPSEEK_MODEL,'messages'=>$probe_messages,'temperature'=>0,'stream'=>false];
  $ch = curl_init($DEEPSEEK_ENDPOINT);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$DEEPSEEK_API_KEY],
    CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE),CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>($resp!==false && $code<400),'http'=>$code,'curl_error'=>$err,'raw'=>$resp], JSON_UNESCAPED_UNICODE); exit;
}

/** 读取请求体 */
$payload = read_body_any();
if (!$payload || !is_array($payload)) jerr('Bad request: empty or invalid payload');
$sessionId = $payload['session_id'] ?? null;
$clientMessages = coerce_messages($payload);
if (!$clientMessages || !is_array($clientMessages)) jerr('messages is required (or provide `message`/`prompt`/`q`/`text`)');

/** 组装消息 */
// Determine whether the latest user input contains Chinese characters.  If any
// user message (role === 'user') in the current payload contains Chinese
// characters, choose the Chinese system prompt; otherwise choose the English
// prompt.  We ignore assistant messages when detecting language because they
// may contain translations or responses that do not reflect the user's
// preferred language.
$isChinese = false;
foreach ($clientMessages as $m) {
  $role = isset($m['role']) ? (string)$m['role'] : 'user';
  if ($role !== 'user') continue;
  $txt = isset($m['content']) ? (string)$m['content'] : '';
  if (preg_match('/\p{Han}/u', $txt)) { $isChinese = true; break; }
}
$SYSTEM_PROMPT = $isChinese ? $SYSTEM_PROMPT_CN : $SYSTEM_PROMPT_EN;

// Assemble messages with the selected system prompt
$messages = [['role'=>'system','content'=>$SYSTEM_PROMPT]];
foreach ($clientMessages as $m) {
  $role = isset($m['role']) ? (string)$m['role'] : 'user';
  $content = isset($m['content']) ? (string)$m['content'] : '';
  if (!in_array($role, ['system','user','assistant'], true)) $role = 'user';
  $messages[] = ['role'=>$role, 'content'=>$content];
}

/** 日志 */
if (!$sessionId) $sessionId = bin2hex(random_bytes(12));
ensure_dir($LOG_DIR);
$logFile = $LOG_DIR.'/'.preg_replace('/[^a-zA-Z0-9_\-]/','_', $sessionId).'.jsonl';
@file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'outgoing','messages'=>$messages], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);

/** 依赖检查 */
if (!function_exists('curl_init')) jerr('Server missing cURL extension', 500);
if (!$DEEPSEEK_API_KEY) jerr('Server missing DEEPSEEK_API_KEY (upload config.php or secret.key)', 500);

/** 是否流式 */
$wantStream = isset($_GET['stream']) && $_GET['stream']=='1';

/** 调 DeepSeek */
$body = ['model'=>$DEEPSEEK_MODEL,'messages'=>$messages,'temperature'=>0.6,'top_p'=>0.95,'stream'=>$wantStream];

$ch = curl_init($DEEPSEEK_ENDPOINT);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json','Authorization: Bearer '.$DEEPSEEK_API_KEY]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, $TIMEOUT_SECONDS);

if ($wantStream) {
  // 流式：转发 data: 行（SSE-like）
  ignore_user_abort(true);
  @ini_set('output_buffering', 'off');
  @ini_set('zlib.output_compression', 0);
  header('Content-Type: text/event-stream; charset=utf-8');
  header('Cache-Control: no-cache, no-transform');
  header('X-Accel-Buffering: no');

  curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use ($logFile) {
    echo $chunk;
    @file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'incoming_stream','chunk'=>$chunk], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    @ob_flush(); @flush();
    return strlen($chunk);
  });

  $ok = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($ok === false || $httpCode >= 400) {
    echo "data: ".json_encode(['error'=> $err ?: ('HTTP '.$httpCode)], JSON_UNESCAPED_UNICODE)."\n\n";
  }
  exit;

} else {
  // 一次性：返回 JSON
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);

  if ($response === false) {
    @file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'incoming','http'=>$httpCode,'curl_error'=>$curlErr], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    jerr('cURL error: '.$curlErr, 502, ['http'=>$httpCode]);
  }
  $data = json_decode($response, true);
  if ($httpCode >= 400 || !$data) {
    @file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'incoming','http'=>$httpCode,'raw'=>$response], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP '.$httpCode);
    jerr('DeepSeek API error: '.$msg, 502);
  }
  $reply = $data['choices'][0]['message']['content'] ?? '';
  if (!$reply) {
    @file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'incoming','raw'=>$response], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
    jerr('Empty reply from model', 502);
  }

  @file_put_contents($logFile, json_encode(['ts'=>time(),'dir'=>'incoming','reply'=>$reply], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'reply'=>$reply,'session_id'=>$sessionId], JSON_UNESCAPED_UNICODE); exit;
}

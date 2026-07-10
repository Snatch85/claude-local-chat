<?php
/**
 * ClaudeLocal — Interface style "Claude Code" (Terminal UI)
 * Design minimaliste sombre inspiré du terminal Claude Code
 */

define('VERSION',      '2.0.71');
define('API_URL',      'https://api.mistral.ai/v1/chat/completions');
define('DB_FILE',      __DIR__ . '/chat.sqlite');
define('MAX_TOKENS',   8192);
define('DEFAULT_KEY',  'cZZD8FUXV7C3OYcrrlESoDC3KhS7eEsJ');

$MODELS = [
    'mistral-large-latest'  => ['label' => 'mistral-large',  'desc' => 'Most capable model'],
    'mistral-small-latest'  => ['label' => 'mistral-small',  'desc' => 'Fast & efficient'],
    'codestral-latest'      => ['label' => 'codestral',      'desc' => 'Code specialist'],
];

$PERSONAS = [
    'assistant' => [
        'label'  => 'Default',
        'icon'   => '◇',
        'prompt' => 'You are Claude Code, an expert AI coding assistant. You provide clear, concise, and accurate responses. You write clean, well-commented code. You think step-by-step before answering complex questions.',
    ],
    'dev' => [
        'label'  => 'Senior Dev',
        'icon'   => '◆',
        'prompt' => 'You are a senior software engineer expert in PHP, JavaScript, Python, and SQL. You write production-ready code with proper error handling. You explain your reasoning clearly and suggest best practices.',
    ],
    'architect' => [
        'label'  => 'Architect',
        'icon'   => '◈',
        'prompt' => 'You are a software architect. You design scalable systems, choose appropriate technologies, and explain trade-offs. You provide diagrams in ASCII when helpful.',
    ],
];

session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            title      TEXT NOT NULL DEFAULT 'New conversation',
            model      TEXT NOT NULL DEFAULT 'mistral-large-latest',
            persona    TEXT NOT NULL DEFAULT 'assistant',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role            TEXT NOT NULL,
            content         TEXT NOT NULL,
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        );
    ");
    return $pdo;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'now';
    if ($diff < 3600)   return floor($diff/60) . 'm';
    if ($diff < 86400)  return floor($diff/3600) . 'h';
    if ($diff < 604800) return floor($diff/86400) . 'd';
    return date('M d', strtotime($dt));
}

function md(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace_callback('/```(\w*)\n?([\s\S]*?)```/m', function($m) {
        $lang = h($m[1] ?: 'text');
        $code = $m[2];
        return '<div class="code-block"><div class="code-header"><span class="code-lang">' . $lang . '</span>'
             . '<button class="copy-btn" onclick="copyCode(this)" title="Copy">⧉</button></div>'
             . '<pre><code class="language-' . $lang . '">' . $code . '</code></pre></div>';
    }, $s);
    $s = preg_replace('/`([^`\n]+)`/', '<code class="inline-code">$1</code>', $s);
    $s = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $s);
    $s = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $s);
    $s = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $s);
    $s = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>',          $s);
    $s = preg_replace('/\*(.+?)\*/',         '<em>$1</em>',                  $s);
    $s = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $s);
    $s = preg_replace('/^[-*•] (.+)$/m', '<li>$1</li>', $s);
    $s = preg_replace('/(<li>[\s\S]*?<\/li>\n?)+/', '<ul>$0</ul>', $s);
    $s = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $s);
    $s = preg_replace('/(<li>[\s\S]*?<\/li>\n?)+/', '<ol>$0</ol>', $s);
    $s = str_replace(['<oli>', '</oli>'], ['<li>', '</li>'], $s);
    $s = preg_replace('/^---$/m', '<hr>', $s);
    $s = preg_replace_callback('/(\|.+\|\n)+/', function($m) {
        $rows = array_filter(explode("\n", trim($m[0])));
        $html = '<table>';
        foreach ($rows as $i => $row) {
            if (preg_match('/^\|[-| :]+\|$/', $row)) continue;
            $cells = array_slice(explode('|', $row), 1, -1);
            $tag = $i === 0 ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(fn($c) => "<{$tag}>" . trim($c) . "</{$tag}>", $cells)) . '</tr>';
        }
        return $html . '</table>';
    }, $s);
    $blocks = preg_split('/\n{2,}/', $s);
    $s = implode("\n", array_map(function($b) {
        $b = trim($b);
        if (!$b) return '';
        if (preg_match('/^<(h[1-3]|ul|ol|blockquote|pre|div|table|hr)/', $b)) return $b;
        return '<p>' . str_replace("\n", '<br>', $b) . '</p>';
    }, $blocks));
    return $s;
}

if (empty($_SESSION['api_key'])) { $_SESSION['api_key'] = DEFAULT_KEY; }
$api_key = $_SESSION['api_key'];

if (isset($_POST['set_key'])) {
    $_SESSION['api_key'] = trim($_POST['api_key']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['api'];

    if ($act === 'new_conv') {
        $model   = $_POST['model']   ?? 'mistral-large-latest';
        $persona = $_POST['persona'] ?? 'assistant';
        db()->prepare("INSERT INTO conversations (model, persona) VALUES (?,?)")->execute([$model, $persona]);
        echo json_encode(['success' => true, 'id' => (int)db()->lastInsertId()]);
        exit;
    }
    if ($act === 'del_conv') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM conversations WHERE id=?")->execute([$id]);
        db()->prepare("DELETE FROM messages WHERE conversation_id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($act === 'clear_conv') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM messages WHERE conversation_id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($act === 'rename') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = substr(trim($_POST['title'] ?? ''), 0, 80);
        db()->prepare("UPDATE conversations SET title=? WHERE id=?")->execute([$title, $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($act === 'generate_title') {
        $id = (int)($_POST['id'] ?? 0);
        $first_message = trim($_POST['first_message'] ?? '');
        if (!$id || !$first_message || !$api_key) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit;
        }

        $system_prompt = "Tu génères uniquement un titre court (4-6 mots maximum) qui résume le sujet de la conversation. Réponds uniquement avec le titre, sans guillemets, sans ponctuation finale.";
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $first_message]
        ];

        $payload = json_encode(['model' => 'mistral-small-latest', 'messages' => $messages, 'max_tokens' => 50]);
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr || $http >= 400) {
            echo json_encode(['success' => false, 'error' => 'Failed to generate title']);
            exit;
        }

        $data = json_decode($raw, true);
        $generated_title = trim($data['choices'][0]['message']['content'] ?? '');

        if (!$generated_title) {
            echo json_encode(['success' => false, 'error' => 'Empty title']);
            exit;
        }

        $generated_title = mb_substr($generated_title, 0, 80);
        db()->prepare("UPDATE conversations SET title=? WHERE id=?")->execute([$generated_title, $id]);
        echo json_encode(['success' => true, 'title' => $generated_title]);
        exit;
    }
    if ($act === 'load') {
        $id   = (int)($_GET['id'] ?? 0);
        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false]); exit; }
        $msgs = db()->prepare("SELECT * FROM messages WHERE conversation_id=? ORDER BY id");
        $msgs->execute([$id]);
        echo json_encode(['success' => true, 'conv' => $conv, 'messages' => $msgs->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($act === 'send') {
        $conv_id = (int)($_POST['conv_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$conv_id || !$content || !$api_key) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters or API key not configured']);
            exit;
        }
        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation not found']); exit; }
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'user', $content]);
        $count = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=?");
        $count->execute([$conv_id]);
        if ($count->fetchColumn() <= 1) {
            $title = mb_substr($content, 0, 50);
            db()->prepare("UPDATE conversations SET title=?, updated_at=datetime('now') WHERE id=?")->execute([$title, $conv_id]);
        } else {
            db()->prepare("UPDATE conversations SET updated_at=datetime('now') WHERE id=?")->execute([$conv_id]);
        }
        global $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];

        // Récupérer les 10 derniers messages pour la mémoire contextuelle
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 10");
        $history->execute([$conv_id]);
        $msgs = $history->fetchAll(PDO::FETCH_ASSOC);

        // Inverser pour avoir l'ordre chronologique et assurer l'alternance user/assistant
        $msgs = array_reverse($msgs);

        // Filtrer pour garder une alternance correcte user/assistant en partant du plus ancien
        $filtered_msgs = [];
        $expected_role = 'user';
        foreach ($msgs as $m) {
            if ($m['role'] === $expected_role) {
                $filtered_msgs[] = $m;
                $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
            }
        }

        // Si le premier message n'est pas 'user', on vérifie si on commence par 'assistant'
        if (empty($filtered_msgs) || $filtered_msgs[0]['role'] !== 'user') {
            $filtered_msgs = [];
            $expected_role = 'assistant';
            foreach ($msgs as $m) {
                if ($m['role'] === $expected_role) {
                    $filtered_msgs[] = $m;
                    $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
                }
            }
            // Réinverser pour commencer par user si possible
            if (!empty($filtered_msgs) && $filtered_msgs[0]['role'] === 'assistant') {
                array_shift($filtered_msgs);
            }
        }

        foreach ($filtered_msgs as $m) {
            $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        $payload = json_encode(['model' => $conv['model'], 'messages' => $api_messages, 'max_tokens' => MAX_TOKENS, 'temperature' => 0.7]);
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) { echo json_encode(['success' => false, 'error' => 'Network: ' . $cerr]); exit; }
        if ($http >= 400) {
            $d = json_decode($raw, true);
            echo json_encode(['success' => false, 'error' => 'API ' . $http . ': ' . ($d['message'] ?? substr($raw,0,200))]);
            exit;
        }
        $data  = json_decode($raw, true);
        $reply = trim($data['choices'][0]['message']['content'] ?? '');
        if (!$reply) { echo json_encode(['success' => false, 'error' => 'Empty response']); exit; }
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'assistant', $reply]);
        echo json_encode(['success' => true, 'reply' => $reply]);
        exit;
    }
    if ($act === 'send_multimodal') {
        $conv_id = (int)($_POST['conv_id'] ?? 0);
        $text = trim($_POST['text'] ?? '');
        $image_base64 = $_POST['image_base64'] ?? '';
        $image_type = $_POST['image_type'] ?? 'image/jpeg';
        if (!$conv_id || !$image_base64 || !$api_key) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters or API key not configured']);
            exit;
        }
        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation not found']); exit; }

        // Build multimodal content for user message
        $user_content = [];
        if ($text) {
            $user_content[] = ['type' => 'text', 'text' => $text];
        }
        // Extract base64 data (remove data:image/xxx;base64, prefix if present)
        $base64_data = $image_base64;
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $image_base64, $matches)) {
            $base64_data = $matches[2];
            $image_type = 'image/' . $matches[1];
        }
        $user_content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image_type . ';basez64,' . $base64_data]];

        // Store user message with a marker for the image
        $user_msg_stored = $text ? $text . "\n\n[Image attached]" : "[Image attached]";
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'user', $user_msg_stored]);

        $count = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=?");
        $count->execute([$conv_id]);
        if ($count->fetchColumn() <= 1) {
            $title = $text ? mb_substr($text, 0, 50) : 'Image conversation';
            db()->prepare("UPDATE conversations SET title=?, updated_at=datetime('now') WHERE id=?")->execute([$title, $conv_id]);
        } else {
            db()->prepare("UPDATE conversations SET updated_at=datetime('now') WHERE id=?")->execute([$conv_id]);
        }

        global $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];

        // Récupérer les 10 derniers messages pour la mémoire contextuelle
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 10");
        $history->execute([$conv_id]);
        $msgs = $history->fetchAll(PDO::FETCH_ASSOC);

        // Inverser pour avoir l'ordre chronologique et assurer l'alternance user/assistant
        $msgs = array_reverse($msgs);

        // Filtrer pour garder une alternance correcte user/assistant en partant du plus ancien
        $filtered_msgs = [];
        $expected_role = 'user';
        foreach ($msgs as $m) {
            if ($m['role'] === $expected_role) {
                $filtered_msgs[] = $m;
                $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
            }
        }

        // Si le premier message n'est pas 'user', on vérifie si on commence par 'assistant'
        if (empty($filtered_msgs) || $filtered_msgs[0]['role'] !== 'user') {
            $filtered_msgs = [];
            $expected_role = 'assistant';
            foreach ($msgs as $m) {
                if ($m['role'] === $expected_role) {
                    $filtered_msgs[] = $m;
                    $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
                }
            }
            // Réinverser pour commencer par user si possible
            if (!empty($filtered_msgs) && $filtered_msgs[0]['role'] === 'assistant') {
                array_shift($filtered_msgs);
            }
        }

        foreach ($filtered_msgs as $m) {
            // For stored messages without images, use simple string content
            if ($m['role'] === 'user' && strpos($m['content'], '[Image attached]') !== false) {
                $clean_content = str_replace("\n\n[Image attached]", '', $m['content']);
                $clean_content = str_replace("[Image attached]", '', $clean_content);
                $api_messages[] = ['role' => $m['role'], 'content' => trim($clean_content)];
            } else {
                $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        // Replace the last user message with multimodal content
        array_pop($api_messages);
        $api_messages[] = ['role' => 'user', 'image' => $user_content];

        // Force pixtral-large-latest model for vision
        $model_to_use = 'pixtral-large-latest';

        $payload = json_encode(['model' => $model_to_use, 'messages' => $api_messages, 'max_tokens' => MAX_TOKENS, 'temperature' => 0.7]);
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) { echo json_encode(['success' => false, 'error' => 'Network: ' . $cerr]); exit; }
        if ($http >= 400) {
            $d = json_decode($raw, true);
            echo json_encode(['success' => false, 'error' => 'API ' . $http . ': ' . ($d['message'] ?? substr($raw,0,200))]);
            exit;
        }
        $data  = json_decode($raw, true);
        $reply = trim($data['choices'][0]['message']['content'] ?? '');
        if (!$reply) { echo json_encode(['success' => false, 'error' => 'Empty response']); exit; }
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'assistant', $reply]);
        echo json_encode(['success' => true, 'reply' => $reply]);
        exit;
    }

    // Streaming endpoint for Mistral API
    if ($act === 'stream_message') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        ob_implicit_flush(true);

        $conv_id = (int)($_POST['conv_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $file_content = $_POST['file_content'] ?? '';
        $file_name = $_POST['file_name'] ?? '';

        if (!$conv_id || (!$content && !$file_content) || !$api_key) {
            echo "data: " . json_encode(['error' => 'Missing parameters or API key not configured']) . "\n\n";
            exit;
        }

        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) {
            echo "data: " . json_encode(['error' => 'Conversation not found']) . "\n\n";
            exit;
        }

        // Build user message content
        $user_msg_content = $content;
        if ($file_content !== '') {
            $user_msg_content = "📄 {$file_name}\n\n{$content}";
        }

        // Save user message
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'user', $user_msg_content]);

        $count = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=?");
        $count->execute([$conv_id]);
        if ($count->fetchColumn() <= 1) {
            $title = mb_substr($content, 0, 50);
            db()->prepare("UPDATE conversations SET title=?, updated_at=datetime('now') WHERE id=?")->execute([$title, $conv_id]);
        } else {
            db()->prepare("UPDATE conversations SET updated_at=datetime('now') WHERE id=?")->execute([$conv_id]);
        }

        global $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $system_prompt = $persona['prompt'];

        // Add file content to system message if present
        if ($file_content !== '') {
            $system_prompt .= "\n\nVoici le fichier {$file_name} :\n\n" . $file_content;
        }

        $api_messages = [['role' => 'system', 'content' => $system_prompt]];

        // Get last 10 messages for context
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 10");
        $history->execute([$conv_id]);
        $msgs = $history->fetchAll(PDO::FETCH_ASSOC);
        $msgs = array_reverse($msgs);

        // Filter for proper user/assistant alternation
        $filtered_msgs = [];
        $expected_role = 'user';
        foreach ($msgs as $m) {
            if ($m['role'] === $expected_role) {
                $filtered_msgs[] = $m;
                $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
            }
        }

        if (empty($filtered_msgs) || $filtered_msgs[0]['role'] !== 'user') {
            $filtered_msgs = [];
            $expected_role = 'assistant';
            foreach ($msgs as $m) {
                if ($m['role'] === $expected_role) {
                    $filtered_msgs[] = $m;
                    $expected_role = ($expected_role === 'user') ? 'assistant' : 'user';
                }
            }
            if (!empty($filtered_msgs) && $filtered_msgs[0]['role'] === 'assistant') {
                array_shift($filtered_msgs);
            }
        }

        foreach ($filtered_msgs as $m) {
            $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        // Call Mistral API with streaming
        $payload = json_encode(['model' => $conv['model'], 'messages' => $api_messages, 'max_tokens' => MAX_TOKENS, 'temperature' => 0.7, 'stream' => true]);

        $fullResponse = '';
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$fullResponse) {
                static $buffer = '';
                $buffer .= $data;

                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $jsonStr = substr($line, 6);
                        if ($jsonStr === '[DONE]') {
                            return strlen($data);
                        }
                        $parsed = json_decode($jsonStr, true);
                        if ($parsed && isset($parsed['choices'][0]['delta']['content'])) {
                            $chunk = $parsed['choices'][0]['delta']['content'];
                            $fullResponse .= $chunk;
                            echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                            flush();
                        }
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr) {
            echo "data: " . json_encode(['error' => 'Network: ' . $cerr]) . "\n\n";
            exit;
        }

        if ($http >= 400) {
            echo "data: " . json_encode(['error' => 'API error: ' . $http]) . "\n\n";
            exit;
        }

        // Save the full response to database
        if ($fullResponse !== '') {
            db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")->execute([$conv_id, 'assistant', $fullResponse]);
        }

        echo "data: " . json_encode(['done' => true]) . "\n\n";
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$conversations = db()->query("SELECT * FROM conversations ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Claude Code</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@highlightjs/curl@1.0.0/highlight.min.js"></script>
<style>
:root{--bg:#0d0d0d;--sidebar:#0a0a0a;--sidebar-hover:#1a1a1a;--sidebar-active:#262626;--surface:#141414;--border:#262626;--text:#f5f5f5;--muted:#737373;--accent:#7c3aed;--accent-hover:#6d28d9;--user-bg:#1a1a1a;--ai-bg:#0d0d0d;--code-bg:#0a0a0a;--code-text:#e5e5e5;--code-border:#262626;--font:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;--r:8px;--br:12px}
html,body{height:100%;overflow:hidden;font-family:var(--font)}
body{background:var(--bg);color:var(--text);display:flex;transition:background .25s ease}
body.sidebar-collapsed .sidebar{width:60px}
body.sidebar-collapsed .main{margin-left:60px}
body.sidebar-collapsed .topbar{margin-left:60px}
body.sidebar-collapsed .hamburger{transform:rotate(180deg)}
.sidebar{width:260px;background:var(--sidebar);color:#a3a3a3;display:flex;flex-direction:column;height:100vh;flex-shrink:0;border-right:1px solid var(--border);transition:width .25s cubic-bezier(.4,.0,.23,1)}
.sidebar.collapsed{width:60px}
.sidebar.open{left:0}
.sidebar-top{padding:.75rem;border-bottom:1px solid var(--border)}
.new-chat-btn{width:100%;background:transparent;border:1px solid var(--border);color:#a3a3a3;border-radius:var(--r);padding:.5rem .75rem;font-size:.75rem;cursor:pointer;display:flex;align-items:center;gap:.5rem;transition:.15s}
.new-chat-btn:hover{background:var(--sidebar-hover);border-color:#404040;color:#fff}
.sidebar-logo{display:flex;align-items:center;gap:.5rem;padding:0 0 .75rem}
.sidebar-logo{padding:0 .5rem .75rem}
.sidebar-logo-icon{width:28px;height:28px;background:var(--accent);border-radius:var(--r);display:flex;align-items:center;justify-content-center;font-size:.8rem;font-weight:700;color:#fff}
.sidebar-logo span{font-size:.85rem;font-weight:600;color:#fff;letter-spacing:-.02em}
.sidebar-logo small{font-size:.6rem;color:var(--muted);display:block;line-height:1;text-transform:uppercase;letter-spacing:.05em}
.sidebar-section{padding:.6rem .8rem .3rem;font-size:.65rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.1em}
.conv-list{flex:1;overflow-y:auto;padding:.25rem .5rem}
.conv-list::-webkit-scrollbar{width:4px}
.conv-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.conv-item{display:flex;align-items:center;gap:.5rem;padding:.5rem .7rem;border-radius:var(--r);cursor:pointer;transition:.15s;animation:fadeIn .2s ease}
.conv-item:hover{background:var(--sidebar-hover)}
.conv-item.active{background:var(--sidebar-active)}
.conv-icon{font-size:.7rem;flex-shrink:0;opacity:.6}
.conv-title{font-size:.7rem;color:#a3a3a3;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;line-height:1.4}
.conv-time{font-size:.55rem;color:var(--muted);flex-shrink:0}
.conv-del{display:none;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.65rem;padding:.1rem .25rem;border-radius:3px;flex-shrink:0}
.conv-item:hover .conv-del{display:block}
.conv-del:hover{color:#ef4444;background:rgba(239,68,68,.1)}
.sidebar-bottom{padding:.6rem .8rem;border-top:1px solid var(--border)}
.api-status{display:flex;align-items:center;gap:.5rem;padding:.5rem .7rem;background:var(--sidebar-hover);border-radius:var(--r);font-size:.65rem;cursor:pointer;transition:.1s}
.api-status:hover{background:#262626}
.api-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.api-dot.ok{background:#22c55e;box-shadow:0 0 4px rgba(34,197,94,.5)}
.api-dot.no{background:#ef4444}
.api-label{color:#a3a3a3;flex:1}
.api-edit{color:var(--muted);font-size:.55rem}
.main{flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.5rem 1rem;display:flex;align-items:center;gap:.75rem;flex-shrink:0;transition:background .25s ease;margin-left:260px}
.topbar-title{font-size:.75rem;font-weight:500;color:var(--text);flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-family:var(--font)}
.model-select,.persona-select{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:.3rem .6rem;font-size:.65rem;color:var(--muted);font-family:var(--font);cursor:pointer}
.model-select:hover,.persona-select:hover{border-color:#404040;color:var(--text)}
.model-select:focus,.persona-select:focus{outline:none;border-color:var(--accent)}
.key-popup{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;z-index:999}
.key-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--br);padding:1.5rem;width:400px;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.key-box h2{font-size:.9rem;margin-bottom:.3rem;font-family:var(--font);font-weight:500;color:#fff}
.key-box p{font-size:.7rem;color:var(--muted);margin-bottom:1rem;line-height:1.5;font-family:var(--font)}
.key-input-row{display:flex;gap:.5rem}
.key-input{flex:1;border:1px solid var(--border);border-radius:4px;padding:.5rem .6rem;font-family:var(--font);font-size:.75rem;color:var(--text);background:var(--bg)}
.key-input:focus{outline:none;border-color:var(--accent)}
.key-save-btn{background:var(--accent);border:none;color:#fff;padding:.5rem 1rem;border-radius:4px;font-weight:500;font-size:.7rem;cursor:pointer;font-family:var(--font);white-space:nowrap}
.key-save-btn:hover{background:var(--accent-hover)}
.key-popup button{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.75rem;font-family:var(--font)}
.welcome{flex:1;display:flex;flex-direction:collumn;align-items:center;justify-content:center;gap:1.25rem;padding:2rem;text-align:center;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes msgIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(20px)}}
.welcome-logo{width:48px;height:48px;background:var(--accent);border-radius:var(--br);display:flex;align-items:center;justify-content:center;font-size:1.2rem;box-shadow:0 4px 16px rgba(124,58,237,.2)}
.welcome h1{font-size:1.1rem;font-weight:500;color:var(--text)}
.welcome p{font-size:.75rem;color:var(--muted);max-width:380px;line-height:1.6}
.suggestions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;max-width:560px}
.suggestion{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:.5rem .75rem;font-size:.7rem;color:var(--text);cursor:pointer;transition:.2s ease;text-align:left;display:flex;align-items:center;gap:.5rem;font-family:var(--font)}
.suggestion:hover{transform:translateY(-2px);border-color:var(--accent);background:var(--sidebar-hover)}
.chat-area{flex:1;overflow-y:auto;padding:1rem 0;scroll-behavior:smooth}
.chat-area::-webkit-scrollbar{width:4px}
.chat-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.msg-group{max-width:840px;margin:0 auto;padding:.3rem 1rem}
.msg{display:flex;gap:.75rem;padding:.6rem 0;animation:msgIn .25s ease;transition:opacity .15s}
.msg-avatar{width:28px;height:28px;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content-center;font-size:.7rem;margin-top:2px;font-family:var(--font);font-weight:600}
.msg-avatar.user{background:var(--user-bg);color:var(--muted);border:1px solid var(--border)}
.msg-avatar.ai{background:var(--accent);color:#fff}
.msg-content{flex:1;min-width:0}
.msg-name{font-size:.65rem;font-weight:500;color:var(--muted);margin-bottom:.3rem;font-family:var(--font)}
.msg-text{font-size:.75rem;line-height:1.65;color:var(--text);font-family:var(--font)}
.msg-text h1{font-size:1rem;font-weight:600;margin:.8rem 0 .4rem;color:#fff}
.msg-text h2{font-size:.9rem;font-weight:600;margin:.7rem 0 .35rem;color:#e5e5e5;border-bottom:1px solid var(--border);padding-bottom:.2rem}
.msg-text h3{font-size:.85rem;font-weight:500;margin:.5rem 0 .25rem;color:#fff}
.msg-text p{margin-bottom:.6rem}.msg-text p:last-child{margin-bottom:0}
.msg-text ul,.msg-text ol{padding-left:1.35rem;margin:.4rem 0 .7rem}
.msg-text li{margin-bottom:.2rem}
.msg-text strong{font-weight:600;color:#fff}
.msg-text em{font-style:italic;color:var(--muted)}
.msg-text hr{background:transparent;border:none;border-top:1px solid var(--border);margin:.7rem 0}
.msg-text blockquote{font-size:.8rem;border-left:2px solid var(--accent);padding:.3rem .7rem;background:rgba(124,58,237,.1);border-radius:0 6px 6px 0;margin:.5rem 0;color:#a3a3a3;font-style:normal}
.msg-text table{width:100%;border-collapse:collapse;margin:.6rem 0;font-size:.7rem}
.msg-text th{background:var(--sidebar-hover);border:1px solid var(--border);padding:.3rem .6rem;font-weight:600;text-align:left;color:#fff}
.msg-text td{border:1px solid var(--border);padding:.25rem .6rem;color:var(--text)}
.msg-text tr:nth-child(even) td{background:#1a1a1a}
.inline-code{font-family:var(--font);font-size:.72em;background:var(--sidebar-hover);border:1px solid var(--border);padding:.12rem .35rem;border-radius:3px;color:var(--accent)}
.code-block{--hljs-bg:#1e1e2e;border-radius:var(--br);overflow:hidden;margin:.55rem 0;border:1px solid var(--code-border);background:#1e1e2e;position:relative;box-shadow:0 2px 8px rgba(0,0,0,.3)}
.code-header{background:#2a2a3e;display:flex;align-items:center;padding:.5rem .8rem;border-bottom:1px solid var(--code-border);position:relative}
.code-lang{font-family:var(--font);font-size:.68rem;color:#a9b1d6;text-transform:uppercase;letter-spacing:.08em;font-weight:600;background:#3a3a5c;padding:.2rem .6rem;border-radius:4px}
.copy-btn{background:#3a3a5c;border:none;color:#a9b1d6;border-radius:4px;padding:.3rem .65rem;font-size:.67rem;cursor:button;font-family:var(--font);transition:.18s;display:flex;align-items:center;gap:.3rem;font-weight:500}
.copy-btn:hover{background:#4a4a6c;color:#fff}
.copy-btn:active{transform:scale(.95)}
.code-block pre{background:#1e1e2e;padding:0;overflow-x:auto;margin:0;counter-reset:line}
.code-block pre code{font-family:var(--font);font-size:.78rem;color:#a9b1d6;line-height:1.68;white-space:pre;display:block;padding:.85rem .95rem}
.code-block pre code .hljs{background:transparent;padding:0}
.line-numbers{display:inline-block;width:2.7rem;text-align:right;padding-right:.85rem;color:#565869;user-select:none;border-right:1px solid #3a3a5c;margin-right:.8rem}
.thinking-dots{display:inline-flex;gap:4px;padding:.35rem 0}
.thinking-dots span{width:5px;height:5px;background:var(--accent);border-radius:50%;opacity:.55;animation:dot .85s infinite}
.thinking-dots span:nth-child(2){animation-delay:.17s}
.thinking-dots span:nth-child(3){animation-delay:.34s}
@keyframes dot{0%,60%,100%{opacity:.55;transform:scale(1)}30%{opacity:1;transform:scale(1.2)}}
.streaming-text{white-space:pre-wrap;min-width:0;animation:textStream .3s steps(40,1) forwards}
@keyframes textStream{from{opacity:0;width:0}to{opacity:1}}
.finalized{opacity:1!important}
.msg-copy-btn{display:none;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.65rem;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0;margin-top:.3rem;font-family:var(--font);transition:.15s}
.msg-copy-btn:hover{color:var(--accent);border-color:var(--accent)}
.msg:hover .msg-copy-btn{display:flex;align-items:center;gap:.25rem}
.input-zone{border-top:1px solid var(--border);background:var(--surface);padding:.8rem 1rem;flex-shrink:0;transition:background .25s ease}
.input-inner{max-width:840px;margin:0 auto;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);transition:.2s ease}
.input-inner:focus-within{border-color:var(--accent);box-shadow:0 0 0 2px var(--accent),0 0 8px var(--accent)}
textarea{width:100%;background:none;border:none;padding:.65rem .85rem .45rem;color:var(--text);font-family:var(--font);font-size:.75rem;resize:none;outline:none;line-height:1.55;min-height:48px;max-height:184px}
textarea::placeholder{color:var(--muted)}
.input-toolbar{display:flex;align-items:center;padding:.4rem .6rem;gap:.5rem}
.input-hint-txt{font-size:.62rem;color:var(--muted);flex:1;font-family:var(--font)}
.char-count{font-size:.6rem;color:var(--muted);flex:1;font-family:var(--font)}
.char-count.warn{color:#f59e0b}.char-count.over{color:#ef4444;font-weight:600}
.send-btn{background:var(--accent);border:none;color:#fff;width:28px;height:28px;border-radius:4px;display:flex;align-items:center;justify-content-center;cursor:pointer;font-size:.8rem;transition:.1s;flex-shrink:0}
.send-btn:hover:not(:disabled){background:var(--accent-hover);transform:translateY(-1px)}
.send-btn:disabled{opacity:.3;cursor:not-allowed;transform:none}
.msg-btn{display:none;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.7rem;cursor:pointer;align-items:center;justify-content-center;flex-shrink:0;margin-left:.25rem}
.msg-btn:hover{background:var(--sidebar-hover)}
.hamburger{display:none;background:none;border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.9rem;cursor:pointer;align-items:center;justify-content-center;flex-shrink:0}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(38,38,38,.85);z-index:99}
.sidebar-overlay.open{display:block;backdrop-filter:blur(4px)}
.sidebar-toggle{display:none;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.8rem;cursor:pointer;align-items:center;justify-content-center;flex-shrink:0}
.model-badge{display:inline-block;padding:.15rem .4rem;border-radius:6px;font-size:.6rem;font-weight:500;background:rgba(124,58,237,.15);color:var(--accent)}
.clear-conv-btn{display:none;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.7rem;cursor:button;align-items:center;justify-content-center;flex-shrink:0;margin-left:.25rem}
.clear-conv-btn:hover{background:var(--sidebar-hover)}
.timestamp{font-size:.6rem;color:var(--muted);margin-top:.3rem;text-align:right}
.focus-mode .sidebar,.focus-mode .topbar{display:none}
.focus-mode .main{margin-left:0}
.focus-mode .chat-area{padding-top:1rem}
.focus-mode .input-zone{padding-bottom:1rem}
@media(max-width:720px){
  .sidebar{display:flex;position:fixed;left:-260px;top:0;bottom:0;z-index:100;transition:left .25s cubic-bezier(.4,.0,.23,1);width:260px}
  .hamburger{display:flex}
  .topbar{margin-left:0}
  body.sidebar-collapsed .topbar{margin-left:0}
  .msg-group{max-width:96vw;padding:.3rem .8rem}
}
@media(max-width:480px){
  .sidebar{width:100vw}
  .sidebar.open{width:100vw}
  .conv-list{padding-bottom:3rem}
  .welcome{padding-bottom:5rem}
}
</style>
</head>
<body>
<nav class="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">C</div>
      <div><span>Claude Code</span><small>v<?= VERSION ?></small></div>
    </div>
    <button class="new-chat-btn" onclick="newConv()" title="New conversation (Ctrl+N)">⟐ New Chat</button>
  </div>
  <div style="flex:1;overflow:hidden;display:flex;flex-direction:column">
    <?php if (!empty($conversations)): ?><div class="sidebar-section">Recent</div><?php endif; ?>
    <div class="conv-list" id="convList">
      <?php foreach ($conversations as $c): ?>
      <div class="conv-item <?= $c['id'] == current($conversations)['id'] ? 'active' : '' ?>" id="ci-<?= $c['id'] ?>" onclick="loadConv(<?= $c['id'] ?>)">
        <span class="conv-icon"><?= h($PERSONAS[$c['persona']]['icon'] ?? '◇') ?></span>
        <span class="conv-title"><?= h($c['title']) ?></span>
        <span class="conv-time"><?= timeAgo($c['updated_at']) ?></span>
        <button class="conv-del" onclick="event.stopPropagation();delConv(<?= $c['id'] ?>)" title="Delete">×</button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?><div style="padding:1.5rem .75rem;font-size:.7rem;color:var(--muted);text-align:center;line-height:1.6">No conversations yet.<br>Click "New Chat" to start.</div><?php endif; ?>
    </div>
  </div>
  <div class="sidebar-bottom">
    <div class="api-status" onclick="showKeyPopup()">
      <div class="api-dot <?= $api_key ? 'ok' : 'no' ?>"></div>
      <span class="api-label"><?= $api_key ? 'API Key set' : 'No API Key' ?></span>
      <span class="api-edit">⚙</span>
    </div>
  </div>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<div class="main">
  <div class="topbar" id="topbar">
    <button class="hamburger" onclick="toggleSidebar()" title="Menu (☰)">☰</button>
    <div class="topbar-title" id="topbarTitle">Claude Code</div>
    <select class="model-select model-badge" id="modelSelect" onchange="updateConvModel()"><?php foreach ($MODELS as $k => $m): ?><option value="<?= h($k) ?>" <?= h($k) == current($conversations)['model'] ? 'selected' : '' ?>><?= h($m['label']) ?> <?= h($m['desc']) ?></option><?php endforeach; ?></select>
    <select class="persona-select model-badge" id="personaSelect" onchange="updateConvPersona()"><?php foreach ($PERSONAS as $k => $p): ?><option value="<?= h($k) ?>" <?= h($k) == current($conversations)['persona'] ? 'selected' : '' ?>><?= h($p['icon']) ?> <?= h($p['label']) ?></option><?php endforeach; ?></select>
  </div>
  <div id="welcomeScreen" class="welcome">
    <div class="welcome-logo">C</div>
    <h1>Welcome to Claude Code</h1>
    <p>Your local AI coding assistant powered by Mistral AI.<br>Start a new conversation or try a suggestion below.</p>
    <div class="suggestions">
      <div class="suggestion" onclick="quickStart('Write a PHP function to call a REST API with error handling')">⟐ PHP REST API client</div>
      <div class="suggestion" onclick="quickStart('Explain how to implement JWT authentication in Node.js')">⟐ JWT Auth in Node.js</div>
      <div class="suggestion" onclick="quickStart('Create a responsive CSS grid layout with 3 columns')">⟐ CSS Grid Layout</div>
      <div class="suggestion" onclick="quickStart('Write a Python script to parse JSON and export to CSV')">⟐ Python JSON to CSV</div>
    </div>
  </div>
  <div id="chatScreen" style="display:none;flex:1;overflow:hidden;flex-direction:column;position:relative">
    <div class="chat-area" id="chatArea"><div class="msg-group" id="msgContainer"></div></div>
    <div id="dragOverlay" style="display:none;position:absolute;inset:0;background:rgba(38,38,38,.85);border:2px dashed var(--accent);border-radius:var(--br);margin:1rem;z-index:100;align-items:center;justify-content-center;pointer-events:none"><div style="text-align:center;color:var(--text);font-size:.9rem;font-family:var(--font)"><div style="font-size:2rem;margin-bottom:.5rem">🖼️</div>Drop image here</div></div>
  </div>
  <div class="input-zone">
    <div class="input-inner">
      <div id="imagePreview" style="display:none;padding:.5rem .75rem;border-bottom:1px solid var(--border);gap:.5rem;flex-wrap:wrap"></div>
      <div id="filePreview" style="display:none;padding:.5rem .75rem;border-bottom:1px solid var(--border);gap:.5rem;flex-wrap:wrap"></div>
      <textarea id="msgInput" rows="1" placeholder="Ask anything... (Enter to send, Shift+Enter for new line)" onkeydown="handleKey(event)" oninput="autoResize(this);updateCharCount(this); updateTokensEstimate(this)" accesskey="n"></textarea>
      <div class="input-toolbar">
        <span class="char-count" id="charCount">0 / 0 / <?= MAX_TOKENS ?></span>
        <span class="input-hint-txt" id="convInfo" style="display:none">Select or create a conversation</span>
        <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="handleImageSelect(event)">
        <button class="send-btn" id="attachBtn" onclick="document.getElementById('imageInput').click()" title="Attach image (Ctrl+I)" type="button">📎</button>
        <input type="file" id="fileInput" accept=".php,.js,.ts,.py,.html,.css,.txt,.csv,.json,.md,.sql" style="display:none" onchange="handleFileSelect(event)">
        <button class="send-btn" id="fileAttachBtn" onclick="document.getElementById('fileInput').click()" title="Attach file">📄</button>
        <button class="clear-conv-btn" id="clearConvBtn" onclick="clearCurrentConv()" title="Clear conversation">🗑️</button>
        <button class="send-btn" id="sendBtn" onclick="sendMsg()" disabled title="Send (Enter)">➤</button>
      </div>
    </div>
  </div>
</div>
<div class="key-popup" id="keyPopup" style="display:<?= $api_key ? 'none' : 'flex' ?>">
  <div class="key-box">
    <h2>⚙ Configure API Key</h2>
    <p>Enter your Mistral AI API key. It will be stored in your local session.</p>
    <form method="post" class="key-input-row">
      <input type="password" name="api_key" class="key-input" id="keyInput" placeholder="sk-..." autocomplete="off">
      <button type="submit" name="set_key" value="1" class="key-save-btn">Save</button>
    </form>
    <?php if ($api_key): ?><button onclick="hideKeyPopup()" style="margin-top:.8rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.75rem;font-family:var(--font)">Cancel</button><?php endif; ?>
  </div>
</div>
<script>
const API_KEY_SET = <?= $api_key ? 'true' : 'false' ?>;
let currentConvId = null, sending = false, selectedImageBase64 = null, selectedImageType = null, selectedFileContent = null, selectedFileName = null, abortController = null, currentStreamingMsgEl = null;

// Drag and drop image handling
function setupDragAndDrop(){
    const chatScreen = document.getElementById('chatScreen');
    const dragOverlay = document.getElementById('dragOverlay');
    const msgInput = document.getElementById('msgInput');

    chatScreen.addEventListener('dragover', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer.types.includes('Files')) {
            dragOverlay.style.display = 'flex';
        }
    });

    chatScreen.addEventListener('dragleave', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (e.target === chatScreen || e.target === document.getElementById('chatArea') || e.target === document.getElementById('msgContainer')) {
            dragOverlay.style.display = 'none';
        }
    });

    chatScreen.addEventListener('drop', function(e){
        e.preventDefault();
        e.stopPropagation();
        dragOverlay.style.display = 'none';

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleImageFile(files[0]);
        }
    });

    msgInput.addEventListener('dragover', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer.types.includes('Files')) {
            e.dataTransfer.dropEffect = 'copy';
        }
    });

    msgInput.addEventListener('drop', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer.files.length > 0) {
            handleImageFile(e.dataTransfer.files[0]);
        }
    });
}

// Handle image file (from drag-drop or paste)
function handleImageFile(file){
    if (!file) return;
    const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        showToast('Format non supporté. Veuillez utiliser jpg, png, gif ou webp.');
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e){
        selectedImageBase64 = e.target.result;
        selectedImageType = file.type;
        showImagePreview();
    };
    reader.readAsDataURL(file);
}

// Paste handler for images from clipboard
function setupPasteHandler(){
    const msgInput = document.getElementById('msgInput');
    msgInput.addEventListener('paste', function(e){
        const items = e.clipboardData.items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                const file = items[i].getAsFile();
                handleImageFile(file);
                break;
            }
        }
    });
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        background: rgba(30, 30, 38, 0.95);
        border: 1px solid var(--border);
        border-radius: var(--r);
        padding: .75rem 1rem;
        font-size: .7rem;
        color: var(--text);
        font-family: var(--font);
        z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,.3);
        animation: toastIn .3s ease, toastOut .3s ease .3s forwards;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function esc(str){return str.replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function toggleSidebar(){const s=document.querySelector('.sidebar'),o=document.getElementById('sidebarOverlay');s.classList.toggle('open'),o.classList.toggle('open');document.body.classList.toggle('sidebar-collapsed')}
function toggleFocusMode(){document.body.classList.toggle('focus-mode');document.getElementById('msgInput').focus()}
function copyCode(btn){const code=btn.nextElementSibling.querySelector('code');navigator.clipboard.writeText(code.textContent).then(()=>{btn.innerHTML='✓';btn.style.background='#22c55e';btn.style.color='#fff';setTimeout(()=>{btn.innerHTML='⧉';btn.style.color=''},1500)})}
function copyMsg(btn){const t=btn.getAttribute('data-text');navigator.clipboard.writeText(t).then(()=>{btn.innerHTML='✓';btn.style.background='#22c55e';btn.style.color='#fff';setTimeout(()=>{btn.innerHTML='📋';btn.style.color=''},1500)})}
function updateCharCount(el){const l=el.value.length,m=<?= MAX_TOKENS ?>,c=document.getElementById('charCount');if(!c)return;c.textContent=l+' / '+m;c.className='char-count'+(l>m?' over':l>m*.8?' warn':'')}
function updateTokensEstimate(el) {
    const wordCount = el.value.trim().split(/\s+/).length;
    const estimatedTokens = wordCount * 1.3; // Rough estimate: 1 word ≈ 1.3 tokens
    const charCountEl = document.getElementById('charCount');
    if (charCountEl) {
        const parts = charCountEl.textContent.split(' / ');
        charCountEl.textContent = parts[0] + ' / ' + estimatedTokens + ' / ' + parts[1];
    }
}
function showKeyPopup(){document.getElementById('keyPopup').style.display='flex'}
function hideKeyPopup(){document.getElementById('keyPopup').style.display='none'}
function handleImageSelect(event){const file=event.target.files[0];if(!file)return;const validTypes=['image/jpeg','image/png','image/gif','image/webp'];if(!validTypes.includes(file.type)){showToast('Please select a valid image (jpg, png, gif, webp)');return}const reader=new FileReader();reader.onload=function(e){selectedImageBase64=e.target.result;selectedImageType=file.type;showImagePreview()};reader.readAsDataURL(file)}
function showImagePreview(){const container=document.getElementById('imagePreview');container.style.display='flex';container.innerHTML=`<div style="position:relative"><img src="${selectedImageBase64}" style="height:60px;border-radius:4px;border:1px solid var(--border)"><button onclick="clearImage()" style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content-center">×</button></div>`}
function clearImage(){selectedImageBase64=null;selectedImageType=null;document.getElementById('imagePreview').style.display='none';document.getElementById('imagePreview').innerHTML='';document.getElementById('imageInput').value=''}
function handleFileSelect(event){const file=event.target.files[0];if(!file)return;const validExts=['.php','.js','.ts','.py','.html','.css','.txt','.csv','.json','.md','.sql'];const ext='.'+file.name.split('.').pop().toLowerCase();if(!validExts.includes(ext)){showToast('Please select a valid file type (.php, .js, .ts, .py, .html, .css, .txt, .csv, .json, .md, .sql)');return}if(file.size>512000){showToast('File size exceeds 500KB limit');return}const reader=new FileReader();reader.onload=function(e){selectedFileContent=e.target.result;selectedFileName=file.name;showFilePreview()};reader.readAsText(file)}
function showFilePreview(){const container=document.getElementById('filePreview');container.style.display='flex';container.innerHTML=`<div style="display:flex;align-items:center;gap:.5rem;background:var(--surface);padding:.3rem .5rem;border-radius:4px;border:1px solid var(--border);font-size:.7rem;max-width:200px"><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">📄 ${esc(selectedFileName)}</span><button onclick="clearFile()" style="background:#ef4444;color:#fff;border:none;border-radius:50%;width:16px;height:16px;font-size:.6rem;cursor:pointer;display:flex;align-items:center

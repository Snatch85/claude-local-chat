<?php
/**
 * ClaudeLocal — Interface style "Claude Code" (Terminal UI)
 * Design minimaliste sombre inspiré du terminal Claude Code
 */

define('VERSION',      '2.0.0');
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
    $s = preg_replace('/^\d+\. (.+)$/m', '<oli>$1</oli>', $s);
    $s = preg_replace('/(<oli>[\s\S]*?<\/oli>\n?)+/', '<ol>$0</ol>', $s);
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
    if ($act === 'rename') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = substr(trim($_POST['title'] ?? ''), 0, 80);
        db()->prepare("UPDATE conversations SET title=? WHERE id=?")->execute([$title, $id]);
        echo json_encode(['success' => true]);
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
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id");
        $history->execute([$conv_id]);
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $m) {
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
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0d0d0d;--sidebar:#0a0a0a;--sidebar-hover:#1a1a1a;--sidebar-active:#262626;--surface:#141414;--border:#262626;--text:#f5f5f5;--muted:#737373;--accent:#d4a574;--accent-hover:#c99663;--user-bg:#1a1a1a;--ai-bg:#0d0d0d;--code-bg:#0a0a0a;--code-text:#e5e5e5;--code-border:#262626;--font:'SF Mono','Monaco','Inconsolata','Fira Code',monospace;--r:6px}
html,body{height:100%;overflow:hidden}
body{font-family:var(--font);background:var(--bg);color:var(--text);display:flex}
.sidebar{width:240px;background:var(--sidebar);color:#a3a3a3;display:flex;flex-direction:column;height:100vh;flex-shrink:0;border-right:1px solid var(--border)}
.sidebar-top{padding:.75rem;border-bottom:1px solid var(--border)}
.new-chat-btn{width:100%;background:transparent;border:1px solid var(--border);color:#a3a3a3;border-radius:var(--r);padding:.5rem .75rem;font-family:var(--font);font-size:.75rem;cursor:pointer;display:flex;align-items:center;gap:.5rem;transition:.15s}
.new-chat-btn:hover{background:var(--sidebar-hover);border-color:#404040;color:#fff}
.sidebar-logo{display:flex;align-items:center;gap:.5rem;padding:0 0 .75rem}
.sidebar-logo-icon{width:24px;height:24px;background:var(--accent);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#000}
.sidebar-logo span{font-size:.8rem;font-weight:600;color:#fff;letter-spacing:-.02em}
.sidebar-logo small{font-size:.6rem;color:var(--muted);display:block;line-height:1;text-transform:uppercase;letter-spacing:.05em}
.sidebar-section{padding:.5rem .75rem .25rem;font-size:.6rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.1em}
.conv-list{flex:1;overflow-y:auto;padding:.25rem .5rem}
.conv-list::-webkit-scrollbar{width:4px}
.conv-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.conv-item{display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;border-radius:var(--r);cursor:pointer;transition:.1s}
.conv-item:hover{background:var(--sidebar-hover)}
.conv-item.active{background:var(--sidebar-active)}
.conv-icon{font-size:.7rem;flex-shrink:0;opacity:.6}
.conv-title{font-size:.7rem;color:#a3a3a3;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;line-height:1.4;font-family:var(--font)}
.conv-time{font-size:.55rem;color:var(--muted);flex-shrink:0}
.conv-del{display:none;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.65rem;padding:.1rem .25rem;border-radius:3px;flex-shrink:0}
.conv-item:hover .conv-del{display:block}
.conv-del:hover{color:#ef4444;background:rgba(239,68,68,.1)}
.sidebar-bottom{padding:.5rem .75rem;border-top:1px solid var(--border)}
.api-status{display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;background:var(--sidebar-hover);border-radius:var(--r);font-size:.65rem;cursor:pointer;transition:.1s}
.api-status:hover{background:#262626}
.api-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.api-dot.ok{background:#22c55e;box-shadow:0 0 4px rgba(34,197,94,.5)}
.api-dot.no{background:#ef4444}
.api-label{color:#a3a3a3;flex:1}
.api-edit{color:var(--muted);font-size:.55rem}
.main{flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:.5rem 1rem;display:flex;align-items:center;gap:.75rem;flex-shrink:0}
.topbar-title{font-size:.75rem;font-weight:500;color:var(--text);flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-family:var(--font)}
.model-select,.persona-select{background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:.25rem .5rem;font-size:.65rem;color:var(--muted);font-family:var(--font);cursor:pointer}
.model-select:hover,.persona-select:hover{border-color:#404040;color:#a3a3a3}
.model-select:focus,.persona-select:focus{outline:none;border-color:var(--accent)}
.key-popup{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;z-index:999}
.key-box{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1.5rem;width:400px;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.key-box h2{font-size:.9rem;margin-bottom:.3rem;font-family:var(--font);font-weight:500;color:#fff}
.key-box p{font-size:.7rem;color:var(--muted);margin-bottom:1rem;line-height:1.5;font-family:var(--font)}
.key-input-row{display:flex;gap:.5rem}
.key-input{flex:1;border:1px solid var(--border);border-radius:4px;padding:.5rem .6rem;font-family:var(--font);font-size:.75rem;color:var(--text);background:var(--bg)}
.key-input:focus{outline:none;border-color:var(--accent)}
.key-save-btn{background:var(--accent);border:none;color:#000;padding:.5rem 1rem;border-radius:4px;font-weight:500;font-size:.7rem;cursor:pointer;font-family:var(--font);white-space:nowrap}
.key-save-btn:hover{background:var(--accent-hover)}
.welcome{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.25rem;padding:2rem;text-align:center}
.welcome-logo{width:48px;height:48px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;box-shadow:0 4px 16px rgba(212,165,116,.2)}
.welcome h1{font-size:1.1rem;font-weight:500;color:var(--text);font-family:var(--font)}
.welcome p{font-size:.75rem;color:var(--muted);max-width:380px;line-height:1.6;font-family:var(--font)}
.suggestions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;max-width:560px}
.suggestion{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:.5rem .75rem;font-size:.7rem;color:var(--text);cursor:pointer;transition:.15s;text-align:left;display:flex;align-items:center;gap:.5rem;font-family:var(--font)}
.suggestion:hover{border-color:var(--accent);background:var(--sidebar-hover);transform:translateY(-1px)}
.chat-area{flex:1;overflow-y:auto;padding:1rem 0;scroll-behavior:smooth}
.chat-area::-webkit-scrollbar{width:4px}
.chat-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.msg-group{max-width:800px;margin:0 auto;padding:.25rem 1rem}
.msg{display:flex;gap:.75rem;padding:.5rem 0;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
.msg-avatar{width:28px;height:28px;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.7rem;margin-top:2px;font-family:var(--font);font-weight:600}
.msg-avatar.user{background:var(--user-bg);color:var(--muted);border:1px solid var(--border)}
.msg-avatar.ai{background:var(--accent);color:#000}
.msg-content{flex:1;min-width:0}
.msg-name{font-size:.65rem;font-weight:500;color:var(--muted);margin-bottom:.25rem;font-family:var(--font)}
.msg-text{font-size:.75rem;line-height:1.6;color:var(--text);font-family:var(--font)}
.msg-text h1{font-size:.95rem;font-weight:600;margin:.75rem 0 .3rem;color:#fff}
.msg-text h2{font-size:.85rem;font-weight:600;margin:.6rem 0 .25rem;color:#fff;border-bottom:1px solid var(--border);padding-bottom:.2rem}
.msg-text h3{font-size:.8rem;font-weight:600;margin:.5rem 0 .2rem;color:#e5e5e5}
.msg-text p{margin-bottom:.5rem}.msg-text p:last-child{margin-bottom:0}
.msg-text ul,.msg-text ol{padding-left:1.25rem;margin:.3rem 0 .5rem}
.msg-text li{margin-bottom:.15rem}
.msg-text strong{font-weight:600;color:#fff}
.msg-text em{font-style:italic;color:var(--muted)}
.msg-text hr{border:none;border-top:1px solid var(--border);margin:.6rem 0}
.msg-text blockquote{border-left:2px solid var(--accent);padding:.25rem .6rem;background:var(--sidebar-hover);border-radius:0 4px 4px 0;margin:.4rem 0;color:var(--muted);font-style:italic}
.msg-text table{width:100%;border-collapse:collapse;margin:.5rem 0;font-size:.7rem}
.msg-text th{background:var(--sidebar-hover);border:1px solid var(--border);padding:.3rem .5rem;font-weight:600;text-align:left;color:#fff}
.msg-text td{border:1px solid var(--border);padding:.25rem .5rem;color:var(--text)}
.msg-text tr:nth-child(even) td{background:#1a1a1a}
.inline-code{font-family:var(--font);font-size:.7em;background:var(--sidebar-hover);border:1px solid var(--border);padding:.1rem .3rem;border-radius:3px;color:var(--accent)}
.code-block{border-radius:6px;overflow:hidden;margin:.5rem 0;border:1px solid var(--code-border)}
.code-header{background:var(--sidebar-hover);display:flex;align-items:center;padding:.3rem .6rem;border-bottom:1px solid var(--code-border)}
.code-lang{font-family:var(--font);font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;flex:1}
.copy-btn{background:transparent;border:1px solid var(--border);color:var(--muted);border-radius:3px;padding:.15rem .4rem;font-size:.6rem;cursor:pointer;font-family:var(--font);transition:.15s}
.copy-btn:hover{border-color:#404040;color:#fff}
.code-block pre{background:var(--code-bg);padding:.75rem .85rem;overflow-x:auto;margin:0}
.code-block pre code{font-family:var(--font);font-size:.7rem;color:var(--code-text);line-height:1.6;white-space:pre}
.thinking-dots{display:inline-flex;gap:3px;padding:.3rem 0}
.thinking-dots span{width:5px;height:5px;background:var(--accent);border-radius:50%;opacity:.5;animation:dot .8s infinite}
.thinking-dots span:nth-child(2){animation-delay:.15s}
.thinking-dots span:nth-child(3){animation-delay:.3s}
@keyframes dot{0%,60%,100%{opacity:.5;transform:scale(1)}30%{opacity:1;transform:scale(1.15)}}
.input-zone{border-top:1px solid var(--border);background:var(--surface);padding:.75rem 1rem;flex-shrink:0}
.input-inner{max-width:800px;margin:0 auto;background:var(--bg);border:1px solid var(--border);border-radius:6px;transition:.15s}
.input-inner:focus-within{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
.input-inner textarea{width:100%;background:none;border:none;padding:.6rem .75rem .4rem;color:var(--text);font-family:var(--font);font-size:.75rem;resize:none;outline:none;line-height:1.5;min-height:44px;max-height:180px;display:block}
.input-inner textarea::placeholder{color:var(--muted)}
.input-toolbar{display:flex;align-items:center;padding:.35rem .5rem;gap:.5rem}
.input-hint-txt{font-size:.6rem;color:var(--muted);flex:1;font-family:var(--font)}
.send-btn{background:var(--accent);border:none;color:#000;width:28px;height:28px;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.8rem;transition:.15s;flex-shrink:0}
.send-btn:hover:not(:disabled){background:var(--accent-hover);transform:translateY(-1px)}
.send-btn:disabled{opacity:.3;cursor:not-allowed;transform:none}
.msg-copy-btn{display:none;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--muted);font-size:.6rem;padding:.15rem .4rem;cursor:pointer;margin-top:.25rem;font-family:var(--font);transition:.15s}
.msg-copy-btn:hover{color:var(--accent);border-color:var(--accent)}
.msg:hover .msg-copy-btn{display:inline-flex;align-items:center;gap:.25rem}
.char-count{font-size:.6rem;color:var(--muted);flex:1;font-family:var(--font)}
.char-count.warn{color:#f59e0b}.char-count.over{color:#ef4444;font-weight:600}
.hamburger{display:none;background:none;border:1px solid var(--border);border-radius:4px;color:var(--text);width:28px;height:28px;font-size:.9rem;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99}
.sidebar-overlay.open{display:block}
@media(max-width:700px){.sidebar{display:flex;position:fixed;left:-240px;top:0;bottom:0;z-index:100;transition:left .25s ease;width:240px}.sidebar.open{left:0}.hamburger{display:flex}.msg-group{padding:.25rem .75rem}}
</style>
</head>
<body>
<nav class="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">C</div>
      <div><span>Claude Code</span><small>v<?= VERSION ?></small></div>
    </div>
    <button class="new-chat-btn" onclick="newConv()">⟐ New Chat</button>
  </div>
  <div style="flex:1;overflow:hidden;display:flex;flex-direction:column">
    <?php if (!empty($conversations)): ?><div class="sidebar-section">Recent</div><?php endif; ?>
    <div class="conv-list" id="convList">
      <?php foreach ($conversations as $c): ?>
      <div class="conv-item" id="ci-<?= $c['id'] ?>" onclick="loadConv(<?= $c['id'] ?>)">
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
<div class="main">
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
  <div class="topbar" id="topbar">
    <button class="hamburger" onclick="toggleSidebar()" title="Menu">☰</button>
    <div class="topbar-title" id="topbarTitle">Claude Code</div>
    <select class="model-select" id="modelSelect" onchange="updateConvModel()"><?php foreach ($MODELS as $k => $m): ?><option value="<?= h($k) ?>"><?= h($m['label']) ?></option><?php endforeach; ?></select>
    <select class="persona-select" id="personaSelect" onchange="updateConvPersona()"><?php foreach ($PERSONAS as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p['icon']) ?> <?= h($p['label']) ?></option><?php endforeach; ?></select>
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
  <div id="chatScreen" style="display:none;flex:1;overflow:hidden;flex-direction:column">
    <div class="chat-area" id="chatArea"><div class="msg-group" id="msgContainer"></div></div>
  </div>
  <div class="input-zone">
    <div class="input-inner">
      <textarea id="msgInput" rows="1" placeholder="Ask anything... (Enter to send, Shift+Enter for new line)" onkeydown="handleKey(event)" oninput="autoResize(this);updateCharCount(this)"></textarea>
      <div class="input-toolbar">
        <span class="char-count" id="charCount">0 / 8000</span>
        <span class="input-hint-txt" id="convInfo" style="display:none">Select or create a conversation</span>
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
let currentConvId = null, sending = false;
function toggleSidebar(){const s=document.querySelector('.sidebar'),o=document.getElementById('sidebarOverlay');s.classList.toggle('open'),o.classList.toggle('open')}
function copyMsg(btn){const t=btn.getAttribute('data-text');navigator.clipboard.writeText(t).then(()=>{btn.textContent='✓';setTimeout(()=>btn.textContent='📋 Copy',1500)})}
function updateCharCount(el){const l=el.value.length,m=8000,c=document.getElementById('charCount');if(!c)return;c.textContent=l+' / '+m;c.className='char-count'+(l>m?' over':l>m*.8?' warn':'')}
function showKeyPopup(){document.getElementById('keyPopup').style.display='flex'}
function hideKeyPopup(){document.getElementById('keyPopup').style.display='none'}
async function newConv(){const m=document.getElementById('modelSelect').value,p=document.getElementById('personaSelect').value;const r=await fetch('?api=new_conv',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`model=${encodeURIComponent(m)}&persona=${encodeURIComponent(p)}`});const d=await r.json();if(d.success){addConvToSidebar(d.id,'New conversation');await loadConv(d.id)}}
function addConvToSidebar(id,title){const l=document.getElementById('convList'),e=l.querySelector('[style]');if(e)e.remove();const el=document.createElement('div');el.className='conv-item';el.id='ci-'+id;el.onclick=()=>loadConv(id);el.innerHTML=`<span class="conv-icon">◇</span><span class="conv-title">${esc(title)}</span><span class="conv-time">now</span><button class="conv-del" onclick="event.stopPropagation();delConv(${id})">×</button>`;l.prepend(el)}
async function loadConv(id){const r=await fetch(`?api=load&id=${id}`);const d=await r.json();if(!d.success)return;currentConvId=id;document.querySelectorAll('.conv-item').forEach(el=>el.classList.remove('active'));const ci=document.getElementById('ci-'+id);if(ci)ci.classList.add('active');document.getElementById('topbarTitle').textContent=d.conv.title;document.getElementById('modelSelect').value=d.conv.model||'mistral-large-latest';document.getElementById('personaSelect').value=d.conv.persona||'assistant';document.getElementById('welcomeScreen').style.display='none';const cs=document.getElementById('chatScreen');cs.style.display='flex';const container=document.getElementById('msgContainer');container.innerHTML='';for(const m of d.messages)appendMessage(m.role,m.content,false);document.getElementById('convInfo').textContent=d.conv.model;document.getElementById('sendBtn').disabled=!API_KEY_SET;document.getElementById('msgInput').focus();scrollBottom()}
async function delConv(id){if(!confirm('Delete this conversation?'))return;await fetch('?api=del_conv',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}`});const el=document.getElementById('ci-'+id);if(el)el.remove();if(currentConvId===id){currentConvId=null;document.getElementById('chatScreen').style.display='none';document.getElementById('welcomeScreen').style.display='flex';document.getElementById('topbarTitle').textContent='Claude Code';document.getElementById('sendBtn').disabled=true}}
async function sendMsg(){if(sending||!currentConvId)return;const input=document.getElementById('msgInput'),msg=input.value.trim();if(!msg)return;sending=true;input.value='';input.style.height='auto';updateCharCount(input);document.getElementById('sendBtn').disabled=true;appendMessage('user',msg);const thinkId='think-'+Date.now();appendThinking(thinkId);scrollBottom();try{const r=await fetch('?api=send',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`conv_id=${currentConvId}&content=${encodeURIComponent(msg)}`});const d=await r.json();removeThinking(thinkId);if(d.success){appendMessage('assistant',d.reply);const ci=document.getElementById('ci-'+currentConvId);if(ci){const t=ci.querySelector('.conv-title');if(t&&t.textContent==='New conversation'){t.textContent=msg.length>35?msg.slice(0,35)+'…':msg;document.getElementById('topbarTitle').textContent=t.textContent}const tm=ci.querySelector('.conv-time');if(tm)tm.textContent='now'}}else{appendError(d.error||'Unknown error')}}catch(e){removeThinking(thinkId);appendError('Network error: '+e.message)}sending=false;document.getElementById('sendBtn').disabled=!API_KEY_SET;scrollBottom()}
function appendMessage(role,content,scroll=true){const container=document.getElementById('msgContainer'),div=document.createElement('div');div.className='msg';const name=role==='user'?'You':'Claude',avatar=role==='user'?'👤':'C',avClass=role==='user'?'user':'ai';const rendered=role==='user'?`<p>${esc(content).replace(/\n/g,'<br>')}</p>`:renderMd(content);div.innerHTML=`<div class="msg-avatar ${avClass}">${avatar}</div><div class="msg-content"><div class="msg-name">${name}</div><div class="msg-text">${rendered}</div><button class="msg-copy-btn" onclick="copyMsg(this)" data-text="${esc(content)}">📋 Copy</button></div>`;container.appendChild(div);if(scroll)scrollBottom()}
function appendThinking(id){const c=document.getElementById('msgContainer'),d=document.createElement('div');d.className='msg';d.id=id;d.innerHTML=`<div class="msg-avatar ai">C</div><div class="msg-content"><div class="msg-name">Claude</div><div class="msg-text"><div class="thinking-dots"><span></span><span></span><span></span></div></div></div>`;c.appendChild(d)}
function removeThinking(id){const el=document.getElementById(id);if(el)el.remove()}
function appendError(msg){const c=document.getElementById('msgContainer'),d=document.createElement('div');d.style.cssText='max-width:800px;margin:0 auto;padding:.5rem 1rem';d.innerHTML=`<div style="background:#2a1a1a;border:1px solid #ef4444;border-radius:6px;padding:.6rem .8rem;color:#ef4444;font-size:.7rem">✗ ${esc(msg)}</div>`;c.appendChild(d)}
function renderMd(s){s=s.replace(/```(\w*)\n?([\s\S]*?)```/gm,(_,lang,code)=>`<div class="code-block"><div class="code-header"><span class="code-lang">${esc(lang||'text')}</span><button class="copy-btn" onclick="copyCode(this)">⧉</button></div><pre><code>${esc(code)}</code></pre></div>`);s=s.replace(/`([^`\n]+)`/g,'<code class="inline-code">$1</code>');s=s.replace(/^### (.+)$/gm,'<h3>$1</h3>');s=s.replace(/^## (.+)$/gm,'<h2>$1</h2>');s=s.replace(/^# (.+)$/gm,'<h1>$1</h1>');s=s.replace(/\*\*\*(.+?)\*\*\*/g,'<strong><em>$1</em></strong>');s=s.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');s=s.replace(/\*(.+?)\*/g,'<em>$1</em>');s=s.replace(/^> (.+)$/gm,'<blockquote>$1</blockquote>');s=s.replace(/^[-*•] (.+)$/gm,'<li>$1</li>');s=s.replace(/(<li>.*?<\/li>\n?)+/g,m=>'<ul>'+m+'</ul>');s=s.replace(/^\d+\. (.+)$/gm,'<li>$1</li>');s=s.replace(/^---$/gm,'<hr>');const blocks=s.split(/\n{2,}/);s=blocks.map(b=>{b=b.trim();if(!b)return'';if(/^<(h[1-3]|ul|ol|blockquote|div|hr)/.test(b))return b;return'<p>'+b.replace(/\n/g,'<br>')+'</p>'}).join('\n');return s}
function copyCode(btn){const code=btn.closest('.code-block').querySelector('code').textContent;navigator.clipboard.writeText(code).then(()=>{btn.textContent='✓';setTimeout(()=>btn.textContent='⧉',1500)})}
async function quickStart(text){await newConv();document.getElementById('msgInput').value=text;sendMsg()}
async function updateConvModel(){if(!currentConvId)return;const m=document.getElementById('modelSelect').value;await fetch('?api=rename',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${currentConvId}&title=${encodeURIComponent(document.getElementById('topbarTitle').textContent)}`});document.getElementById('convInfo').textContent=m}
async function updateConvPersona(){}
function esc(s){const d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML}
function scrollBottom(){const ca=document.getElementById('chatArea');if(ca)setTimeout(()=>ca.scrollTop=ca.scrollHeight,50)}
function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg()}}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,180)+'px'}
document.addEventListener('DOMContentLoaded',()=>{document.getElementById('msgInput').focus();const fc=document.querySelector('.conv-item');if(fc){const id=parseInt(fc.id.replace('ci-',''));if(id)loadConv(id)}});
</script>
</body>
</html>

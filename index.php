<?php
/**
 * ClaudeLocal — Clone de Claude.ai
 * Interface complète avec sidebar, conversations multiples, rendu Markdown/code
 */

define('VERSION',      '1.0.7');
define('API_URL',      'https://api.mistral.ai/v1/chat/completions');
define('DB_FILE',      __DIR__ . '/chat.sqlite');
define('MAX_TOKENS',   4096);
define('DEFAULT_KEY',  'cZZD8FUXV7C3OYcrrlESoDC3KhS7eEsJ'); // Clé Mistral pré-configurée

// ── Modèles disponibles ──────────────────────────────────────────────────────
$MODELS = [
    'mistral-large-latest'  => ['label' => 'Mistral Large',  'desc' => 'Plus intelligent'],
    'mistral-small-latest'  => ['label' => 'Mistral Small',  'desc' => 'Plus rapide'],
    'codestral-latest'      => ['label' => 'Codestral',      'desc' => 'Expert code'],
];

// ── Personnalités ────────────────────────────────────────────────────────────
$PERSONAS = [
    'assistant' => [
        'label'  => 'Assistant général',
        'icon'   => '🤖',
        'prompt' => 'Tu es un assistant IA intelligent, précis et bienveillant. Tu réponds en français par défaut. Tu structures tes réponses avec des titres, listes et code quand c\'est utile.',
    ],
    'dev' => [
        'label'  => 'Développeur PHP',
        'icon'   => '💻',
        'prompt' => 'Tu es un expert PHP/JS/SQL senior. Tu fournis du code propre, commenté et fonctionnel. Tu expliques chaque décision technique. Tu signales les failles de sécurité.',
    ],
    'marin' => [
        'label'  => 'Expert marées',
        'icon'   => '🌊',
        'prompt' => 'Tu es un expert des marées, de la navigation et de la pêche en Loire-Atlantique. Tu connais les ports, coefficients, spots de pêche à pied, et conditions météo marines.',
    ],
    'science' => [
        'label'  => 'Chercheur scientifique',
        'icon'   => '🔬',
        'prompt' => 'Tu es un chercheur biomédicale expert. Tu analyses les études scientifiques, expliques les mécanismes biologiques et cites tes sources. Tu restes factuel et nuancé.',
    ],
];

session_start();

// ── Base de données SQLite ───────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            title      TEXT NOT NULL DEFAULT 'Nouvelle conversation',
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

// ── Helpers ──────────────────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'À l\'instant';
    if ($diff < 3600)   return floor($diff/60) . ' min';
    if ($diff < 86400)  return floor($diff/3600) . 'h';
    if ($diff < 604800) return floor($diff/86400) . 'j';
    return date('d/m', strtotime($dt));
}

function md(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    // Blocs de code avec langage
    $s = preg_replace_callback('/```(\w*)\n?([\s\S]*?)```/m', function($m) {
        $lang = h($m[1] ?: 'code');
        $code = $m[2];
        return '<div class="code-block"><div class="code-header"><span class="code-lang">' . $lang . '</span>'
             . '<button class="copy-btn" onclick="copyCode(this)">📋 Copier</button></div>'
             . '<pre><code class="lang-' . $lang . '">' . $code . '</code></pre></div>';
    }, $s);
    // Code inline
    $s = preg_replace('/`([^`\n]+)`/', '<code class="inline-code">$1</code>', $s);
    // Titres
    $s = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $s);
    $s = preg_replace('/^## (.+)$/m',  '<h2>$1</h2>', $s);
    $s = preg_replace('/^# (.+)$/m',   '<h1>$1</h1>', $s);
    // Gras / italique
    $s = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>',          $s);
    $s = preg_replace('/\*(.+?)\*/',         '<em>$1</em>',                  $s);
    // Citations
    $s = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $s);
    // Listes à puces
    $s = preg_replace('/^[-*•] (.+)$/m', '<li>$1</li>', $s);
    $s = preg_replace('/(<li>[\s\S]*?<\/li>\n?)+/', '<ul>$0</ul>', $s);
    // Listes numérotées
    $s = preg_replace('/^\d+\. (.+)$/m', '<oli>$1</oli>', $s);
    $s = preg_replace('/(<oli>[\s\S]*?<\/oli>\n?)+/', '<ol>$0</ol>', $s);
    $s = str_replace(['<oli>', '</oli>'], ['<li>', '</li>'], $s);
    // Séparateurs
    $s = preg_replace('/^---$/m', '<hr>', $s);
    // Tableaux
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
    // Paragraphes
    $blocks = preg_split('/\n{2,}/', $s);
    $s = implode("\n", array_map(function($b) {
        $b = trim($b);
        if (!$b) return '';
        if (preg_match('/^<(h[1-3]|ul|ol|blockquote|pre|div|table|hr)/', $b)) return $b;
        return '<p>' . str_replace("\n", '<br>', $b) . '</p>';
    }, $blocks));
    return $s;
}

// ── Actions AJAX / POST ──────────────────────────────────────────────────────
// Utilise la clé de session, sinon la clé par défaut pré-configurée
if (empty($_SESSION['api_key'])) {
    $_SESSION['api_key'] = DEFAULT_KEY;
}
$api_key = $_SESSION['api_key'];

// Enregistrer la clé API
if (isset($_POST['set_key'])) {
    $_SESSION['api_key'] = trim($_POST['api_key']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// API JSON
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['api'];

    // Créer une conversation
    if ($act === 'new_conv') {
        $model   = $_POST['model']   ?? 'mistral-large-latest';
        $persona = $_POST['persona'] ?? 'assistant';
        db()->prepare("INSERT INTO conversations (model, persona) VALUES (?,?)")->execute([$model, $persona]);
        $id = db()->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    // Supprimer une conversation
    if ($act === 'del_conv') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM conversations WHERE id=?")->execute([$id]);
        db()->prepare("DELETE FROM messages WHERE conversation_id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Renommer
    if ($act === 'rename') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = substr(trim($_POST['title'] ?? ''), 0, 80);
        db()->prepare("UPDATE conversations SET title=?, updated_at=datetime('now') WHERE id=?")->execute([$title, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Charger les messages d'une conversation
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

    // Envoyer un message
    if ($act === 'send') {
        $conv_id = (int)($_POST['conv_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        if (!$conv_id || !$content || !$api_key) {
            echo json_encode(['success' => false, 'error' => 'Paramètres manquants ou clé API non configurée']);
            exit;
        }

        // Récupérer la conversation
        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation introuvable']); exit; }

        // Sauvegarder le message utilisateur
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")
            ->execute([$conv_id, 'user', $content]);

        // Auto-titre si premier message
        $count = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=?");
        $count->execute([$conv_id]);
        if ($count->fetchColumn() <= 1) {
            $title = mb_substr($content, 0, 50);
            db()->prepare("UPDATE conversations SET title=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$title, $conv_id]);
        } else {
            db()->prepare("UPDATE conversations SET updated_at=datetime('now') WHERE id=?")->execute([$conv_id]);
        }

        // Construire les messages pour l'API
        global $MODELS, $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id");
        $history->execute([$conv_id]);
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        // Appel Mistral
        $payload = json_encode([
            'model'       => $conv['model'],
            'messages'    => $api_messages,
            'max_tokens'  => MAX_TOKENS,
            'temperature' => 0.7,
        ]);
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr) { echo json_encode(['success' => false, 'error' => 'Réseau : ' . $cerr]); exit; }
        if ($http >= 400) {
            $d = json_decode($raw, true);
            echo json_encode(['success' => false, 'error' => 'API ' . $http . ' : ' . ($d['message'] ?? substr($raw,0,200))]);
            exit;
        }

        $data  = json_decode($raw, true);
        $reply = trim($data['choices'][0]['message']['content'] ?? '');
        if (!$reply) { echo json_encode(['success' => false, 'error' => 'Réponse vide']); exit; }

        // Sauvegarder la réponse
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")
            ->execute([$conv_id, 'assistant', $reply]);

        echo json_encode(['success' => true, 'reply' => $reply]);
        exit;
    }

    // Régénérer la dernière réponse
    if ($act === 'regenerate') {
        $conv_id = (int)($_POST['conv_id'] ?? 0);
        if (!$conv_id) {
            echo json_encode(['success' => false, 'error' => 'Paramètres manquants']);
            exit;
        }

        // Supprimer le dernier message assistant
        db()->prepare("DELETE FROM messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1")->execute([$conv_id]);

        // Récupérer le dernier message utilisateur
        $last_user = db()->prepare("SELECT content FROM messages WHERE conversation_id=? AND role='user' ORDER BY id DESC LIMIT 1");
        $last_user->execute([$conv_id]);
        $last_user = $last_user->fetchColumn();
        if (!$last_user) {
            echo json_encode(['success' => false, 'error' => 'Aucun message utilisateur trouvé']);
            exit;
        }

        // Récupérer la conversation
        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation introuvable']); exit; }

        // Construire les messages pour l'API
        global $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id");
        $history->execute([$conv_id]);
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        // Appel Mistral
        $payload = json_encode([
            'model'       => $conv['model'],
            'messages'    => $api_messages,
            'max_tokens'  => MAX_TOKENS,
            'temperature' => 0.7,
        ]);
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr) { echo json_encode(['success' => false, 'error' => 'Réseau : ' . $cerr]); exit; }
        if ($http >= 400) {
            $d = json_decode($raw, true);
            echo json_encode(['success' => false, 'error' => 'API ' . $http . ' : ' . ($d['message'] ?? substr($raw,0,200))]);
            exit;
        }

        $data  = json_decode($raw, true);
        $reply = trim($data['choices'][0]['message']['content'] ?? '');
        if (!$reply) { echo json_encode(['success' => false, 'error' => 'Réponse vide']); exit; }

        // Sauvegarder la réponse
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")
            ->execute([$conv_id, 'assistant', $reply]);

        echo json_encode(['success' => true, 'reply' => $reply]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Action inconnue']);
    exit;
}

// ── Charger les conversations pour la sidebar ─────────────────────────────────
$conversations = db()->query("SELECT * FROM conversations ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ClaudeLocal</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f4ef;--sidebar:#1c1917;--sidebar-hover:#292524;--sidebar-active:#44403c;
  --surface:#fff;--border:#e7e5e4;--text:#1c1917;--muted:#78716c;
  --accent:#7c3aed;--accent2:#6d28d9;--user-bg:#f5f4ef;--ai-bg:#fff;
  --code-bg:#1e1e2e;--code-text:#cdd6f4;
  --shadow:0 1px 3px rgba(0,0,0,.1);
  --font:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --mono:'JetBrains Mono','Fira Code','Cascadia Code',monospace;
  --r:8px;
}
html,body{height:100%;overflow:hidden}body{font-family:var(--font);background:var(--bg);color:var(--text);display:flex}

/* ━━ SIDEBAR ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.sidebar{
  width:260px;background:var(--sidebar);color:#e7e5e4;
  display:flex;flex-direction:column;height:100vh;flex-shrink:0;
}
.sidebar-top{padding:1rem;border-bottom:1px solid #ffffff10}
.new-chat-btn{
  width:100%;background:#292524;border:1px solid #ffffff15;color:#e7e5e4;
  border-radius:var(--r);padding:.65rem 1rem;font-family:var(--font);
  font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:.6rem;
  transition:.15s;font-weight:500;
}
.new-chat-btn:hover{background:#3c3836}
.sidebar-logo{display:flex;align-items:center;gap:.6rem;padding:.5rem 0 1rem}
.sidebar-logo-icon{
  width:28px;height:28px;background:var(--accent);border-radius:6px;
  display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;color:#fff;
}
.sidebar-logo span{font-size:.9rem;font-weight:600;color:#e7e5e4}
.sidebar-logo small{font-size:.65rem;color:#78716c;display:block;line-height:1}

.sidebar-section{padding:.5rem .75rem .25rem;font-size:.65rem;font-weight:600;
  color:#78716c;text-transform:uppercase;letter-spacing:.08em}

.conv-list{flex:1;overflow-y:auto;padding:.25rem .5rem}
.conv-list::-webkit-scrollbar{width:3px}
.conv-list::-webkit-scrollbar-thumb{background:#44403c;border-radius:2px}

.conv-item{
  display:flex;align-items:center;gap:.5rem;padding:.5rem .65rem;
  border-radius:var(--r);cursor:pointer;transition:.1s;
  position:relative;group:true;
}
.conv-item:hover{background:var(--sidebar-hover)}
.conv-item.active{background:var(--sidebar-active)}
.conv-icon{font-size:.85rem;flex-shrink:0;opacity:.7}
.conv-title{font-size:.8rem;color:#d6d3d1;flex:1;overflow:hidden;
  white-space:nowrap;text-overflow:ellipsis;line-height:1.4}
.conv-time{font-size:.62rem;color:#78716c;flex-shrink:0}
.conv-del{
  display:none;background:none;border:none;color:#78716c;cursor:pointer;
  font-size:.75rem;padding:.1rem .3rem;border-radius:4px;flex-shrink:0;
}
.conv-item:hover .conv-del{display:block}
.conv-del:hover{color:#f87171;background:rgba(248,113,113,.1)}

.sidebar-bottom{padding:.75rem;border-top:1px solid #ffffff10}
.api-status{
  display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;
  background:#292524;border-radius:var(--r);font-size:.75rem;cursor:pointer;transition:.1s;
}
.api-status:hover{background:#3c3836}
.api-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.api-dot.ok{background:#22c55e;box-shadow:0 0 6px rgba(34,197,94,.4)}
.api-dot.no{background:#ef4444}
.api-label{color:#d6d3d1;flex:1}
.api-edit{color:#78716c;font-size:.65rem}

/* ━━ MAIN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.main{flex:1;display:flex;flex-direction:column;height:100vh;overflow:hidden}

/* ── Topbar ── */
.topbar{
  background:var(--surface);border-bottom:1px solid var(--border);
  padding:.7rem 1.5rem;display:flex;align-items:center;gap:1rem;flex-shrink:0;
  position:relative;
}
.topbar-title{font-size:.9rem;font-weight:600;color:var(--text);flex:1;
  overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.model-select{
  background:var(--bg);border:1px solid var(--border);border-radius:6px;
  padding:.3rem .6rem;font-size:.78rem;color:var(--muted);font-family:var(--font);cursor:pointer;
}
.persona-select{
  background:var(--bg);border:1px solid var(--border);border-radius:6px;
  padding:.3rem .6rem;font-size:.78rem;color:var(--muted);font-family:var(--font);cursor:pointer;
}

/* ── Clé API popup ── */
.key-popup{
  position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);
  display:flex;align-items:center;justify-content:center;z-index:999;
}
.key-box{
  background:var(--surface);border-radius:12px;padding:2rem;width:440px;
  box-shadow:0 20px 60px rgba(0,0,0,.3);
}
.key-box h2{font-size:1.1rem;margin-bottom:.4rem}
.key-box p{font-size:.85rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.5}
.key-input-row{display:flex;gap:.5rem}
.key-input{
  flex:1;border:1px solid var(--border);border-radius:8px;padding:.6rem .9rem;
  font-family:var(--font);font-size:.9rem;color:var(--text);background:var(--bg);
}
.key-input:focus{outline:none;border-color:var(--accent)}
.key-save-btn{
  background:var(--accent);border:none;color:white;padding:.6rem 1.2rem;
  border-radius:8px;font-weight:600;font-size:.88rem;cursor:pointer;font-family:var(--font);
  white-space:nowrap;
}
.key-save-btn:hover{background:var(--accent2)}
/* ── Welcome ── */
.welcome{
  flex:1;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:1.5rem;padding:2rem;text-align:center;
}
.welcome-logo{
  width:56px;height:56px;background:var(--accent);border-radius:14px;
  display:flex;align-items:center;justify-content:center;font-size:1.6rem;
  box-shadow:0 8px 24px rgba(124,58,237,.3);
}
.welcome h1{font-size:1.6rem;font-weight:700;color:var(--text)}
.welcome p{font-size:.9rem;color:var(--muted);max-width:420px;line-height:1.6}
.suggestions{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center;max-width:600px}
.suggestion{
  background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:.65rem 1rem;font-size:.82rem;color:var(--text);cursor:pointer;
  transition:.15s;text-align:left;display:flex;align-items:center;gap:.5rem;
  box-shadow:var(--shadow);
}
.suggestion:hover{border-color:var(--accent);background:#faf5ff;transform:translateY(-1px)}

/* ── Chat ── */
.chat-area{flex:1;overflow-y:auto;padding:1.5rem 0;scroll-behavior:smooth}
.chat-area::-webkit-scrollbar{width:4px}
.chat-area::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}

.msg-group{max-width:760px;margin:0 auto;padding:.25rem 1.5rem}

.msg{
  display:flex;gap:1rem;padding:.5rem 0;
  animation:fadeIn .3s ease-out forwards;
  opacity:0;
  transform:translateY(10px);
}
@keyframes fadeIn{
  to{opacity:1;transform:translateY(0)}
}
.msg-avatar{
  width:32px;height:32px;border-radius:8px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-top:2px;
}
.msg-avatar.user{background:#e7e5e4;color:var(--text)}
.msg-avatar.ai{background:var(--accent);color:#fff}

.msg-content{flex:1;min-width:0}
.msg-name{font-size:.75rem;font-weight:600;color:var(--muted);margin-bottom:.3rem}
.msg-text{font-size:.9rem;line-height:1.75;color:var(--text)}
.msg-time{font-size:.65rem;color:#78716c;margin-top:.3rem;text-align:right}

/* Markdown */
.msg-text h1{font-size:1.2rem;font-weight:700;margin:1rem 0 .4rem;color:var(--text)}
.msg-text h2{font-size:1.05rem;font-weight:700;margin:.9rem 0 .35rem;color:var(--text);
  border-bottom:1px solid var(--border);padding-bottom:.25rem}
.msg-text h3{font-size:.95rem;font-weight:700;margin:.8rem 0 .3rem;color:var(--text)}
.msg-text p{margin-bottom:.7rem}
.msg-text p:last-child{margin-bottom:0}
.msg-text ul,.msg-text ol{padding-left:1.5rem;margin:.4rem 0 .7rem}
.msg-text li{margin-bottom:.2rem}
.msg-text strong{font-weight:700}
.msg-text em{font-style:italic;color:var(--muted)}
.msg-text hr{border:none;border-top:1px solid var(--border);margin:.8rem 0}
.msg-text blockquote{
  border-left:3px solid var(--accent);padding:.4rem .9rem;
  background:#faf5ff;border-radius:0 6px 6px 0;margin:.5rem 0;
  color:var(--muted);font-style:italic;
}
.msg-text table{width:100%;border-collapse:collapse;margin:.6rem 0;font-size:.85rem}
.msg-text th{background:var(--bg);border:1px solid var(--border);padding:.4rem .7rem;
  font-weight:600;text-align:left;color:var(--text)}
.msg-text td{border:1px solid var(--border);padding:.35rem .7rem;color:var(--text)}
.msg-text tr:nth-child(even) td{background:#fafaf9}

.inline-code{
  font-family:var(--mono);font-size:.82em;background:#f5f4ef;
  border:1px solid #e7e5e4;padding:.1rem .35rem;border-radius:4px;color:#7c3aed;
}

.code-block{
  border-radius:12px;
  overflow:hidden;
  margin:.6rem 0;
  border:1px solid rgba(0,0,0,.1);
  box-shadow:0 4px 12px rgba(0,0,0,.08);
  background:#fff;
  transition:box-shadow .2s ease;
}
.code-block:hover{
  box-shadow:0 6px 20px rgba(0,0,0,.12);
}
.code-header{
  background:#f8f9fa;
  display:flex;align-items:center;
  padding:.6rem 1rem;
  border-bottom:1px solid rgba(0,0,0,.05);
}
.code-lang{
  font-family:var(--mono);
  font-size:.7rem;
  color:#6c7086;
  text-transform:uppercase;
  letter-spacing:.06em;
  flex:1
}
.copy-btn{
  background:none;border:1px solid #e7e5e4;color:#78716c;
  border-radius:5px;padding:.2rem .55rem;font-size:.68rem;cursor:pointer;
  font-family:var(--font);
  transition:.15s;
}
.copy-btn:hover{border-color:var(--accent);color:var(--accent)}
.code-block pre{background:var(--code-bg);padding:.9rem 1rem;overflow-x:auto;margin:0}
.code-block pre code{
  font-family:var(--mono);font-size:.82rem;color:var(--code-text);
  line-height:1.65;white-space:pre;
}

/* Thinking */
.thinking-dots{display:inline-flex;gap:4px;padding:.4rem 0}
.thinking-dots span{
  width:7px;height:7px;background:var(--accent);border-radius:50%;
  opacity:.4;
  animation:dot .9s infinite;
}
.thinking-dots span:nth-child(2){animation-delay:.2s}
.thinking-dots span:nth-child(3){animation-delay:.4s}
@keyframes dot{0%,60%,100%{opacity:.4;transform:scale(1)}30%{opacity:1;transform:scale(1.2)}}

/* ── Input ── */
.input-zone{
  border-top:1px solid var(--border);background:var(--surface);
  padding:1rem 1.5rem 1.2rem;flex-shrink:0;
}
.input-inner{
  max-width:760px;margin:0 auto;
  background:var(--bg);border:1.5px solid var(--border);
  border-radius:12px;transition:.1s;
  box-shadow:0 2px 8px rgba(0,0,0,.06);
}
.input-inner:focus-within{border-color:var(--accent);box-shadow:0 2px 12px rgba(124,58,237,.15)}
.input-inner textarea{
  width:100%;background:none;border:none;
  padding:.85rem 1rem .4rem;color:var(--text);font-family:var(--font);
  font-size:.9rem;resize:none;outline:none;line-height:1.5;
  min-height:52px;max-height:200px;display:block;
}
.input-inner textarea::placeholder{color:#a8a29e}
.input-toolbar{
  display:flex;align-items:center;padding:.4rem .7rem;
  gap:.5rem;
}
.input-hint-txt{font-size:.7rem;color:#a8a29e;flex:1}
.send-btn{
  background:var(--accent);border:none;color:white;
  width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:1rem;transition:.1s;flex-shrink:0;
}
.send-btn:hover:not(:disabled){background:var(--accent2);transform:translateY(-1px)}
.send-btn:disabled{opacity:.35;cursor:not-allowed;transform:none}

/* ── Bouton copier message ── */
.msg-copy-btn{
  display:none;
  background:none;
  border:1px solid var(--border);
  border-radius:6px;
  color:var(--muted);
  font-size:.7rem;
  padding:.2rem .55rem;
  cursor:pointer;
  margin-top:.35rem;
  font-family:var(--font);
  transition:.1s;
  box-shadow:0 1px 3px rgba(0,0,0,.1);
}
.msg-copy-btn:hover{color:var(--accent);border-color:var(--accent)}
.msg:hover .msg-copy-btn{display:inline-flex;align-items:center;gap:.3rem}

/* ── Compteur caractères ── */
.char-count{font-size:.68rem;color:var(--muted);flex:1}
.char-count.warn{color:#f59e0b}
.char-count.over{color:#ef4444;font-weight:600}

/* ── Hamburger mobile ── */
.hamburger{
  display:none;background:none;border:1px solid var(--border);border-radius:6px;
  color:var(--text);width:34px;height:34px;font-size:1.1rem;cursor:pointer;
  align-items:center;justify-content:center;flex-shrink:0;
}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.sidebar-overlay.open{display:block}

/* ── Mode focus ── */
.focus-mode .sidebar{display:none}
.focus-mode .main{width:100%}
.focus-mode .topbar{display:none}
.focus-mode .chat-area{padding:0}
.focus-mode .input-zone{padding:0 1.5rem 1.2rem}
.focus-mode .msg-group{max-width:none}

/* ━━ DARK MODE ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
.dark-mode{
  --bg:#0f0f0f;
  --sidebar:#1a1a1a;
  --sidebar-hover:#262626;
  --sidebar-active:#3a3a3a;
  --surface:#1a1a1a;
  --border:#3a3a3a;
  --text:#e5e5e5;
  --muted:#a3a3a3;
  --accent:#a855f7;
  --accent2:#9333ea;
  --user-bg:#1a1a1a;
  --ai-bg:#262626;
  --code-bg:#1e1e2e;
  --code-text:#cdd6f4;
  --shadow:0 1px 3px rgba(0,0,0,.3);
}
.dark-mode .code-block{
  background:#262626;
  border-color:rgba(255,255,255,.1);
}
.dark-mode .code-header{
  background:#2d2d2d;
  border-bottom-color:rgba(255,255,255,.1);
}
.dark-mode .msg-avatar.user{
  background:#262626;
  color:#e5e5e5;
}
.dark-mode .msg-avatar.ai{
  background:#a855f7;
}
.dark-mode .msg-text{
  color:#e5e5e5;
}
.dark-mode .msg-name{
  color:#a3a3a3;
}
.dark-mode .msg-time{
  color:#737373;
}
.dark-mode .inline-code{
  background:#2d2d2d;
  border-color:#3a3a3a;
  color:#a855f7;
}
.dark-mode .suggestion{
  background:#262626;
  border-color:#3a3a3a;
  color:#e5e5e5;
}
.dark-mode .suggestion:hover{
  background:#2d2d2d;
  border-color:#a855f7;
}
.dark-mode .welcome{
  color:#e5e5e5;
}
.dark-mode .welcome-logo{
  background:#a855f7;
}
.dark-mode .topbar{
  background:#1a1a1a;
  border-bottom-color:#3a3a3a;
}
.dark-mode .model-select,
.dark-mode .persona-select{
  background:#262626;
  border-color:#3a3a3a;
  color:#e5e5e5;
}
.dark-mode .input-inner{
  background:#262626;
  border-color:#3a3a3a;
}
.dark-mode .input-inner textarea{
  color:#e5e5e5;
}
.dark-mode .input-inner textarea::placeholder{
  color:#737373;
}
.dark-mode .input-inner:focus-within{
  box-shadow:0 2px 12px rgba(168,85,247,.25);
}
.dark-mode .send-btn{
  background:#a855f7;
}
.dark-mode .send-btn:hover:not(:disabled){
  background:#9333ea;
}
.dark-mode .char-count{
  color:#737373;
}
.dark-mode .char-count.warn{
  color:#fbbf24;
}
.dark-mode .char-count.over{
  color:#f87171;
}
.dark-mode .conv-item:hover{
  background:#262626;
}
.dark-mode .conv-item.active{
  background:#3a3a3a;
}
.dark-mode .conv-title{
  color:#d4d4d4;
}
.dark-mode .conv-del:hover{
  color:#f87171;
  background:rgba(248,113,113,.1);
}
.dark-mode .api-status:hover{
  background:#262626;
}
.dark-mode .api-label{
  color:#d4d4d4;
}
.dark-mode .api-edit{
  color:#737373;
}
.dark-mode .key-box{
  background:#262626;
}
.dark-mode .key-input{
  background:#2d2d2d;
  border-color:#3a3a3a;
  color:#e5e5e5;
}
.dark-mode .key-input:focus{
  border-color:#a855f7;
}
.dark-mode .key-save-btn{
  background:#a855f7;
}
.dark-mode .key-save-btn:hover{
  background:#9333ea;
}
.dark-mode blockquote{
  background:#2d2d2d;
  border-left-color:#a855f7;
  color:#a3a3a3;
}
.dark-mode .thinking-dots span{
  background:#a855f7;
}
.dark-mode .msg-copy-btn{
  border-color:#3a3a3a;
  color:#737373;
}
.dark-mode .msg-copy-btn:hover{
  color:#a855f7;
  border-color:#a855f7;
}
.dark-mode .conv-del{
  color:#737373;
}
.dark-mode .conv-time{
  color:#737373;
}
.dark-mode .sidebar-logo small{
  color:#737373;
}
.dark-mode .sidebar-section{
  color:#737373;
}
.dark-mode .hamburger{
  border-color:#3a3a3a;
  color:#e5e5e5;
}
.dark-mode .sidebar-overlay{
  background:rgba(0,0,0,.7);
}

@media(max-width:700px){
  .sidebar{
    display:flex;position:fixed;left:-260px;top:0;bottom:0;
    z-index:100;transition:left .25s ease;width:260px;
  }
  .sidebar.open{left:0}
  .hamburger{display:flex}
  .msg-group{padding:.25rem 1rem}
}
</style>
</head>
<body>

<!-- ━━ SIDEBAR ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<nav class="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">C</div>
      <div><span>ClaudeLocal</span><small>v<?= VERSION ?> · Mistral AI</small></div>
    </div>
    <button class="new-chat-btn" onclick="newConv()">
      ✏️ Nouvelle conversation
    </button>
  </div>

  <div style="flex:1;overflow:hidden;display:flex;flex-direction:column">
    <?php if (!empty($conversations)): ?>
    <div class="sidebar-section">Récent</div>
    <?php endif; ?>
    <div class="conv-list" id="convList">
      <?php foreach ($conversations as $c): ?>
      <div class="conv-item" id="ci-<?= $c['id'] ?>" onclick="loadConv(<?= $c['id'] ?>)">
        <span class="conv-icon">💬</span>
        <span class="conv-title"><?= h($c['title']) ?></span>
        <span class="conv-time"><?= timeAgo($c['updated_at']) ?></span>
        <button class="conv-del" onclick="event.stopPropagation();delConv(<?= $c['id'] ?>)" title="Supprimer">✕</button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?>
      <div style="padding:1.5rem .75rem;font-size:.78rem;color:#78716c;text-align:center;line-height:1.6">
        Aucune conversation.<br>Clique sur « Nouvelle conversation »
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sidebar-bottom">
    <div class="api-status" onclick="showKeyPopup()">
      <div class="api-dot <?= $api_key ? 'ok' : 'no' ?>"></div>
      <span class="api-label"><?= $api_key ? 'Clé API configurée' : 'Clé API manquante' ?></span>
      <span class="api-edit">✏️</span>
    </div>
  </div>
</nav>

<!-- ━━ MAIN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="main">

  <!-- Topbar -->
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
  <div class="topbar" id="topbar">
    <button class="hamburger" onclick="toggleSidebar()" title="Menu">☰</button>
    <div class="topbar-title" id="topbarTitle">ClaudeLocal</div>
    <select class="model-select" id="modelSelect" onchange="updateConvModel()">
      <?php foreach ($MODELS as $k => $m): ?>
      <option value="<?= h($k) ?>"><?= h($m['label']) ?> — <?= h($m['desc']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="persona-select" id="personaSelect" onchange="updateConvPersona()">
      <?php foreach ($PERSONAS as $k => $p): ?>
      <option value="<?= h($k) ?>"><?= h($p['icon']) ?> <?= h($p['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="hamburger" onclick="toggleFocusMode()" title="Mode focus">⛶</button>
    <button class="hamburger" onclick="toggleDarkMode()" title="Mode sombre">🌙</button>
  </div>

  <!-- Zone chat ou welcome -->
  <div id="welcomeScreen" class="welcome">
    <div class="welcome-logo">C</div>
    <h1>Bonjour 👋</h1>
    <p>Ton assistant IA local propulsé par Mistral.<br>Crée une conversation ou clique sur une suggestion.</p>
    <div class="suggestions">
      <div class="suggestion" onclick="quickStart('Explique-moi comment fonctionne le coefficient de marée')">🌊 <div><strong>Coefficient de marée</strong><br><small>Comment ça fonctionne ?</small></div></div>
      <div class="suggestion" onclick="quickStart('Écris-moi une fonction PHP pour appeler une API REST')">💻 <div><strong>Code PHP</strong><br><small>Appel API REST</small></div></div>
      <div class="suggestion" onclick="quickStart('Quels sont les meilleurs endroits pour pêcher à pied en Loire-Atlantique ?')">🦀 <div><strong>Pêche à pied</strong><br><small>Loire-Atlantique</small></div></div>
      <div class="suggestion" onclick="quickStart('Résume-moi les dernières avancées sur la myocardite et les vaccins ARNm')">🔬 <div><strong>Recherche médicale</strong><br><small>Résumé scientifique</small></div></div>
    </div>
  </div>

  <div id="chatScreen" style="display:none;flex:1;overflow:hidden;display:none;flex-direction:column">
    <div class="chat-area" id="chatArea">
      <div class="msg-group" id="msgContainer"></div>
    </div>
  </div>

  <!-- Input -->
  <div class="input-zone">
    <div class="input-inner">
      <textarea id="msgInput" rows="1"
        placeholder="Envoyer un message… (Entrée pour envoyer, Maj+Entrée pour nouvelle ligne, Ctrl+N nouvelle conversation, Ctrl+/ focus input)"
        onkeydown="handleKey(event)" oninput="autoResize(this);updateCharCount(this)"></textarea>
      <div class="input-toolbar">
        <span class="char-count" id="charCount">0 / 4000</span>
        <span class="input-hint-txt" id="convInfo" style="display:none">Crée ou sélectionne une conversation</span>
        <button class="send-btn" id="sendBtn" onclick="sendMsg()" disabled title="Envoyer (Entrée)">➤</button>
      </div>
    </div>
  </div>
</div>

<!-- ━━ POPUP CLÉ API ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="key-popup" id="keyPopup" style="display:<?= $api_key ? 'none' : 'flex' ?>">
  <div class="key-box">
    <h2>🔑 Configurer la clé API Mistral</h2>
    <p>Colle ta clé API Mistral pour commencer. Elle sera sauvegardée en session sur ton PC local.</p>
    <form method="post" class="key-input-row">
      <input type="password" name="api_key" class="key-input" id="keyInput"
        placeholder="Colle ta clé ici..." autocomplete="off">
      <button type="submit" name="set_key" value="1" class="key-save-btn">Sauvegarder</button>
    </form>
    <?php if ($api_key): ?>
    <button onclick="hideKeyPopup()" style="margin-top:.8rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.85rem">Annuler</button>
    <?php endif; ?>
  </div>
</div>

<script>
const API_KEY_SET = <?= $api_key ? 'true' : 'false' ?>;
let currentConvId = null;
let sending = false;
let isFocusMode = false;
let lastScrollPosition = 0;
let isDarkMode = false;

// ── Mode sombre ──────────────────────────────────────────────────────────────
function toggleDarkMode() {
    isDarkMode = !isDarkMode;
    document.body.classList.toggle('dark-mode');
    localStorage.setItem('darkMode', isDarkMode);
    updateThemeIcon();
}

// Mettre à jour l'icône du bouton en fonction du thème
function updateThemeIcon() {
    const btn = document.querySelector('.topbar button[onclick="toggleDarkMode()"]');
    if (btn) {
        btn.textContent = isDarkMode ? '☀️' : '🌙';
        btn.title = isDarkMode ? 'Mode clair' : 'Mode sombre';
    }
}

// Charger le mode sombre depuis localStorage
function loadDarkModePreference() {
    const saved = localStorage.getItem('darkMode');
    if (saved === 'true') {
        isDarkMode = true;
        document.body.classList.add('dark-mode');
    }
    updateThemeIcon();
}

// ── Sidebar mobile ───────────────────────────────────────────────────────────
function toggleSidebar() {
    const sidebar  = document.querySelector('.sidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const isOpen   = sidebar.classList.contains('open');
    sidebar.classList.toggle('open', !isOpen);
    overlay.classList.toggle('open', !isOpen);
}

// Mode focus
function toggleFocusMode() {
    document.body.classList.toggle('focus-mode');
    isFocusMode = document.body.classList.contains('focus-mode');
}

// ── Copier message ───────────────────────────────────────────────────────────
function copyMsg(btn) {
    const text = btn.getAttribute('data-text');
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    }).catch(() => {
        // Fallback si clipboard indisponible
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// ── Compteur de caractères ────────────────────────────────────────────────────
function updateCharCount(el) {
    const len = el.value.length;
    const max = 4000;
    const cc  = document.getElementById('charCount');
    if (!cc) return;
    cc.textContent = len + ' / ' + max;
    cc.className   = 'char-count' + (len > max ? ' over' : len > max * 0.8 ? ' warn' : '');
}

// ── Popup clé API ────────────────────────────────────────────────────────────
function showKeyPopup() { document.getElementById('keyPopup').style.display='flex'; }
function hideKeyPopup() { document.getElementById('keyPopup').style.display='none'; }

// ── Nouvelle conversation ────────────────────────────────────────────────────
async function newConv() {
    const model   = document.getElementById('modelSelect').value;
    const persona = document.getElementById('personaSelect').value;
    const r = await fetch('?api=new_conv', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `model=${encodeURIComponent(model)}&persona=${encodeURIComponent(persona)}`
    });
    const d = await r.json();
    if (d.success) {
        addConvToSidebar(d.id, 'Nouvelle conversation');
        await loadConv(d.id);
    }
}

function addConvToSidebar(id, title) {
    const list = document.getElementById('convList');
    const empty = list.querySelector('[style]');
    if (empty) empty.remove();
    const el = document.createElement('div');
    el.className = 'conv-item';
    el.id = 'ci-' + id;
    el.onclick = () => loadConv(id);
    el.innerHTML = `<span class="conv-icon">💬</span>
      <span class="conv-title">${esc(title)}</span>
      <span class="conv-time">À l'instant</span>
      <button class="conv-del" onclick="event.stopPropagation();delConv(${id})" title="Supprimer">✕</button>`;
    list.prepend(el);
}

// ── Charger une conversation ─────────────────────────────────────────────────
async function loadConv(id) {
    const r = await fetch(`?api=load&id=${id}`);
    const d = await r.json();
    if (!d.success) return;

    currentConvId = id;

    // Activer dans la sidebar
    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
    const ci = document.getElementById('ci-' + id);
    if (ci) ci.classList.add('active');

    // Topbar
    document.getElementById('topbarTitle').textContent = d.conv.title;
    document.getElementById('modelSelect').value   = d.conv.model   || 'mistral-large-latest';
    document.getElementById('personaSelect').value = d.conv.persona || 'assistant';

    // Afficher les messages
    document.getElementById('welcomeScreen').style.display = 'none';
    const cs = document.getElementById('chatScreen');
    cs.style.display = 'flex';
    const container = document.getElementById('msgContainer');
    container.innerHTML = '';
    for (const m of d.messages) appendMessage(m.role, m.content, false);

    // Input
    document.getElementById('convInfo').textContent = d.conv.model;
    document.getElementById('sendBtn').disabled = !API_KEY_SET;
    document.getElementById('msgInput').focus();

    scrollBottom();
}

// ── Supprimer une conversation ───────────────────────────────────────────────
async function delConv(id) {
    if (!confirm('Supprimer cette conversation ?')) return;
    await fetch('?api=del_conv', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${id}`
    });
    const el = document.getElementById('ci-' + id);
    if (el) el.remove();
    if (currentConvId === id) {
        currentConvId = null;
        document.getElementById('chatScreen').style.display = 'none';
        document.getElementById('welcomeScreen').style.display = 'flex';
        document.getElementById('topbarTitle').textContent = 'ClaudeLocal';
        document.getElementById('sendBtn').disabled = true;
    }
}

// ── Envoyer un message ───────────────────────────────────────────────────────
async function sendMsg() {
    if (sending || !currentConvId) return;
    const input = document.getElementById('msgInput');
    const msg = input.value.trim();
    if (!msg) return;

    sending = true;
    input.value = '';
    input.style.height = 'auto';
    updateCharCount(input);
    document.getElementById('sendBtn').disabled = true;

    appendMessage('user', msg);

    // Indicateur "en train de réfléchir"
    const thinkId = 'think-' + Date.now();
    appendThinking(thinkId);
    scrollBottom();

    try {
        const r = await fetch('?api=send', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`conv_id=${currentConvId}&content=${encodeURIComponent(msg)}`
        });
        const d = await r.json();
        removeThinking(thinkId);

        if (d.success) {
            appendMessage('assistant', d.reply);
            // Mettre à jour le titre dans la sidebar
            const ci = document.getElementById('ci-' + currentConvId);
            if (ci) {
                const titleEl = ci.querySelector('.conv-title');
                if (titleEl && titleEl.textContent === 'Nouvelle conversation') {
                    titleEl.textContent = msg.length > 40 ? msg.slice(0,40)+'…' : msg;
                    document.getElementById('topbarTitle').textContent = titleEl.textContent;
                }
                const timeEl = ci.querySelector('.conv-time');
                if (timeEl) timeEl.textContent = 'À l\'instant';
            }
        } else {
            appendError(d.error || 'Erreur inconnue');
        }
    } catch(e) {
        removeThinking(thinkId);
        appendError('Erreur réseau : ' + e.message);
    }

    sending = false;
    document.getElementById('sendBtn').disabled = !API_KEY_SET;
    scrollBottom();
}

// ── Régénérer la dernière réponse ────────────────────────────────────────────
async function regenerate() {
    if (sending || !currentConvId) return;

    sending = true;
    document.getElementById('sendBtn').disabled = true;

    // Indicateur "en train de réfléchir"
    const thinkId = 'think-regenerate-' + Date.now();
    appendThinking(thinkId);
    scrollBottom();

    try {
        const r = await fetch('?api=regenerate', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`conv_id=${currentConvId}`
        });
        const d = await r.json();
        removeThinking(thinkId);

        if (d.success) {
            // Supprimer le dernier message assistant
            const lastAssistantMsg = document.querySelector('.msg:last-child .msg-avatar.ai')?.closest('.msg');
            if (lastAssistantMsg) lastAssistantMsg.remove();

            appendMessage('assistant', d.reply);
        } else {
            appendError(d.error || 'Erreur inconnue');
        }
    } catch(e) {
        removeThinking(thinkId);
        appendError('Erreur réseau : ' + e.message);
    }

    sending = false;
    document.getElementById('sendBtn').disabled = !API_KEY_SET;
    scrollBottom();
}

// ── Afficher un message ──────────────────────────────────────────────────────
function appendMessage(role, content, scroll=true) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.className = 'msg';
    const name   = role === 'user' ? 'Vous' : 'ClaudeLocal';
    const avatar = role === 'user' ? '👤' : 'C';
    const avClass = role === 'user' ? 'user' : 'ai';
    const rendered = role === 'user'
        ? `<p>${esc(content).replace(/\n/g,'<br>')}</p>`
        : renderMd(content);

    const rawText = content;
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

    div.innerHTML = `
      <div class="msg-avatar ${avClass}">${avatar}</div>
      <div class="msg-content">
        <div class="msg-name">${name}</div>
        <div class="msg-text">${rendered}</div>
        <div class="msg-time" style="color:#78716c;margin-top:.3rem;text-align:right">${timeStr}</div>
        <button class="msg-copy-btn" onclick="copyMsg(this)" data-text="${esc(content)}">📋 Copier</button>
        ${role === 'assistant' ? '<button class="msg-copy-btn" onclick="regenerate()" style="margin-left:.5rem">🔄 Régénérer</button>' : ''}
      </div>`;
    container.appendChild(div);
    if (scroll) scrollBottom();
}

function appendThinking(id) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.className = 'msg'; div.id = id;
    div.innerHTML = `<div class="msg-avatar ai">C</div>
      <div class="msg-content">
        <div class="msg-name">ClaudeLocal</div>
        <div class="msg-text"><div class="thinking-dots"><span></span><span></span><span></span></div></div>
      </div>`;
    container.appendChild(div);
}
function removeThinking(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
}
function appendError(msg) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.style.cssText = 'max-width:760px;margin:0 auto;padding:.5rem 1.5rem';
    div.innerHTML = `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.7rem 1rem;color:#dc2626;font-size:.85rem">❌ ${esc(msg)}</div>`;
    container.appendChild(div);
}

// ── Markdown renderer ────────────────────────────────────────────────────────
function renderMd(s) {
    // Blocs de code
    s = s.replace(/```(\w*)\n?([\s\S]*?)```/gm, (_, lang, code) =>
        `<div class="code-block"><div class="code-header"><span class="code-lang">${esc(lang||'code')}</span><button class="copy-btn" onclick="copyCode(this)">📋 Copier</button></div><pre><code>${esc(code)}</code></pre></div>`);
    // Code inline
    s = s.replace(/`([^`\n]+)`/g, '<code class="inline-code">$1</code>');
    // Titres
    s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    s = s.replace(/^## (.+)$/gm,  '<h2>$1</h2>');
    s = s.replace(/^# (.+)$/gm,   '<h1>$1</h1>');
    // Gras / italique
    s = s.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    s = s.replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g,         '<em>$1</em>');
    // Citations
    s = s.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');
    // Listes
    s = s.replace(/^[-*•] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>.*?<\/li>\n?)+/g, m => '<ul>'+m+'</ul>');
    s = s.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
    // Séparateurs
    s = s.replace(/^---$/gm, '<hr>');
    // Paragraphes
    const blocks = s.split(/\n{2,}/);
    s = blocks.map(b => {
        b = b.trim();
        if (!b) return '';
        if (/^<(h[1-3]|ul|ol|blockquote|div|hr)/.test(b)) return b;
        return '<p>' + b.replace(/\n/g,'<br>') + '</p>';
    }).join('\n');
    return s;
}

function copyCode(btn) {
    const code = btn.closest('.code-block').querySelector('code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// ── Quickstart ───────────────────────────────────────────────────────────────
async function quickStart(text) {
    await newConv();
    document.getElementById('msgInput').value = text;
    sendMsg();
}

// ── Mise à jour modèle/persona ────────────────────────────────────────────────
async function updateConvModel() {
    if (!currentConvId) return;
    const model = document.getElementById('modelSelect').value;
    await fetch('?api=rename', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${currentConvId}&title=${encodeURIComponent(document.getElementById('topbarTitle').textContent)}`});
    document.getElementById('convInfo').textContent = model;
}
async function updateConvPersona() { /* persona stocké à la création */ }

// ── Helpers ──────────────────────────────────────────────────────────────────
function esc(s) {
    const d = document.createElement('div');
    d.text
function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s||'');
    return d.innerHTML;
}
function scrollBottom() {
    const ca = document.getElementById('chatArea');
    if (ca) {
        // Ne pas scroller si l'utilisateur est en haut
        if (ca.scrollTop + ca.clientHeight >= ca.scrollHeight - 10) {
            setTimeout(() => ca.scrollTop = ca.scrollHeight, 50);
        }
    }
}
function handleKey(e) {
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        newConv();
    } else if (e.ctrlKey && e.key === '/') {
        e.preventDefault();
        document.getElementById('msgInput').focus();
    } else if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMsg();
    }
}
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 200) + 'px';
}

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('msgInput').focus();
    // Charger le mode sombre depuis localStorage
    loadDarkModePreference();
    // Charger la dernière conversation si elle existe
    const firstConv = document.querySelector('.conv-item');
    if (firstConv) {
        const id = parseInt(firstConv.id.replace('ci-',''));
        if (id) loadConv(id);
    }
});
</script>
</body>
</html>
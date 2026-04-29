<?php
/**
 * ClaudeLocal — Clone de Claude.ai
 * Interface complète avec sidebar, conversations multiples, rendu Markdown/code
 */

define('VERSION',      '1.1.0');
define('API_URL',      'https://api.mistral.ai/v1/chat/completions');
define('DB_FILE',      __DIR__ . '/chat.sqlite');
define('MAX_TOKENS',   4096);
define('DEFAULT_KEY',  'cZZD8FUXV7C3OYcrrlESoDC3KhS7eEsJ'); // Clé Mistral pré-configurée

// ── Modèles disponibles ──────────────────────────────────────────────────────
$MODELS = [
    'mistral-large-latest'  => ['label' => 'Mistral Large',   'desc' => 'Plus intelligent'],
    'mistral-small-latest'  => ['label' => 'Mistral Small',   'desc' => 'Plus rapide'],
    'codestral-latest'      => ['label' => 'Codestral',       'desc' => 'Expert code'],
    'pixtral-large-latest'  => ['label' => 'Pixtral Large 🖼️', 'desc' => 'Lit les images'],
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
        $conv_id   = (int)($_POST['conv_id'] ?? 0);
        $content   = trim($_POST['content'] ?? '');
        $image_b64 = trim($_POST['image_b64'] ?? '');
        $image_mime= trim($_POST['image_mime'] ?? 'image/png');
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
        // Si image jointe — utiliser Pixtral et message multimodal
        if ($image_b64) {
            $conv['model'] = 'pixtral-large-latest';
            array_pop($api_messages);
            $api_messages[] = [
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',       'text'      => $content],
                    ['type' => 'image_url',  'image_url' => ['url' => 'data:' . $image_mime . ';base64,' . $image_b64]],
                ]
            ];
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

        db()->prepare("DELETE FROM messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1")->execute([$conv_id]);

        $last_user = db()->prepare("SELECT content FROM messages WHERE conversation_id=? AND role='user' ORDER BY id DESC LIMIT 1");
        $last_user->execute([$conv_id]);
        $last_user = $last_user->fetchColumn();
        if (!$last_user) {
            echo json_encode(['success' => false, 'error' => 'Aucun message utilisateur trouvé']);
            exit;
        }

        $conv = db()->prepare("SELECT * FROM conversations WHERE id=?");
        $conv->execute([$conv_id]);
        $conv = $conv->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation introuvable']); exit; }

        global $PERSONAS;
        $persona     = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
        $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];
        $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id=? ORDER BY id");
        $history->execute([$conv_id]);
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

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
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
    <button class="topbar-btn" onclick="captureScreen()" title="Capturer la conversation">📸</button>
    <button class="topbar-btn" onclick="toggleDarkMode()" title="Mode sombre" id="darkModeToggle">🌙</button>
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
      <div id="imgPreviewBar" style="display:none;padding:.4rem 1rem .2rem;border-top:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:.6rem">
          <img id="imgPreview" style="height:48px;border-radius:6px;border:1px solid var(--border)">
          <span id="imgPreviewName" style="font-size:.75rem;color:var(--muted);flex:1"></span>
          <button onclick="removeImage()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.9rem" title="Supprimer">✕</button>
        </div>
      </div>
      <div class="input-toolbar">
        <span class="char-count" id="charCount">0 / 4000</span>
        <input type="file" id="imgInput" accept="image/*" style="display:none" onchange="handleImageSelect(this)">
        <button class="topbar-btn" onclick="document.getElementById('imgInput').click()" title="Joindre une image" style="width:30px;height:30px;font-size:.85rem">📎</button>
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
// Passer les variables PHP vers JS
window._API_KEY_SET = <?= $api_key ? 'true' : 'false' ?>;
</script>
<script src="app.js"></script>
</body>
</html>
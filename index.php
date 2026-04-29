
<?php
define('VERSION', '1.2.0');
define('API_URL', 'https://api.mistral.ai/v1/chat/completions');
define('DB_FILE', __DIR__ . '/chat.sqlite');
define('MAX_TOKENS', 4096);
define('MAX_CONVERSATIONS', 100);
define('MAX_MESSAGE_LENGTH', 4000);
define('DEFAULT_KEY', '');

$MODELS = [
    'mistral-large-latest' => ['label' => 'Mistral Large', 'desc' => 'Modèle le plus intelligent', 'max_tokens' => 8192],
    'mistral-small-latest' => ['label' => 'Mistral Small', 'desc' => 'Modèle rapide et efficace', 'max_tokens' => 4096],
    'codestral-latest' => ['label' => 'Codestral', 'desc' => 'Expert en développement', 'max_tokens' => 8192],
    'pixtral-large-latest' => ['label' => 'Pixtral Large 🖼️', 'desc' => 'Analyse d\'images', 'max_tokens' => 4096],
];

$PERSONAS = [
    'assistant' => [
        'label' => 'Assistant général',
        'icon' => '🤖',
        'prompt' => 'Tu es un assistant IA intelligent, précis et bienveillant. Tu réponds en français par défaut. Tu structures tes réponses avec des titres, listes et code quand c\'est utile. Tu restes concis et pertinent.',
        'system' => true
    ],
    'dev' => [
        'label' => 'Développeur PHP',
        'icon' => '💻',
        'prompt' => 'Tu es un expert PHP/JS/SQL senior. Tu fournis du code propre, commenté et fonctionnel avec des exemples complets. Tu expliques chaque décision technique et signales les failles de sécurité potentielles. Tu utilises les bonnes pratiques modernes.',
        'system' => true
    ],
    'marin' => [
        'label' => 'Expert marées',
        'icon' => '🌊',
        'prompt' => 'Tu es un expert des marées, de la navigation et de la pêche en Loire-Atlantique et Bretagne. Tu connais parfaitement les ports, coefficients, spots de pêche à pied, conditions météo marines et réglementations locales. Tu donnes des conseils pratiques et précis.',
        'system' => true
    ],
    'science' => [
        'label' => 'Chercheur scientifique',
        'icon' => '🔬',
        'prompt' => 'Tu es un chercheur biomédical expert. Tu analyses les études scientifiques récentes, expliques les mécanismes biologiques complexes de manière accessible. Tu cites tes sources et restes factuel et nuancé. Tu distingues bien faits scientifiques et hypothèses.',
        'system' => true
    ],
    'writer' => [
        'label' => 'Rédacteur',
        'icon' => '✍️',
        'prompt' => 'Tu es un rédacteur professionnel spécialisé dans la création de contenus clairs, engageants et bien structurés. Tu adaptes ton style au public cible et au format demandé (article, email, post réseaux sociaux, etc.).',
        'system' => true
    ],
];

session_start();
session_regenerate_id(true);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!file_exists(DB_FILE)) {
        touch(DB_FILE);
        chmod(DB_FILE, 0640);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA synchronous = NORMAL");
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL DEFAULT 'Nouvelle conversation',
            model TEXT NOT NULL DEFAULT 'mistral-large-latest',
            persona TEXT NOT NULL DEFAULT 'assistant',
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            CONSTRAINT model_check CHECK (model IN ('mistral-large-latest', 'mistral-small-latest', 'codestral-latest', 'pixtral-large-latest')),
            CONSTRAINT persona_check CHECK (persona IN ('assistant', 'dev', 'marin', 'science', 'writer'))
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system')),
            content TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            tokens INTEGER DEFAULT 0,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_conversations_updated ON conversations(updated_at);
        CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id);
        CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at);
    ");

    return $pdo;
}

function h(string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'À l\'instant';
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . 'h';
    if ($diff < 2592000) return floor($diff/86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}

function sanitizeInput(string $input): string {
    $input = trim($input);
    $input = preg_replace('/\s+/', ' ', $input);
    return mb_substr($input, 0, MAX_MESSAGE_LENGTH, 'UTF-8');
}

function estimateTokens(string $text): int {
    return (int)ceil(str_word_count($text) * 1.3);
}

function md(string $s): string {
    if (empty($s)) return '';

    $s = h($s);

    $s = preg_replace_callback('/```(\w*)\n?([\s\S]*?)```/m', function($m) {
        $lang = h($m[1] ?: 'code');
        $code = h($m[2]);
        $escaped = str_replace(['&lt;?php', '?&gt;'], ['<?php', '?>'], $code);
        return '<div class="code-block" tabindex="0" role="region" aria-label="Bloc de code ' . $lang . '">
            <div class="code-header">
                <span class="code-lang">' . $lang . '</span>
                <button class="copy-btn" onclick="copyCode(this)" aria-label="Copier le code">📋 Copier</button>
            </div>
            <pre><code class="lang-' . $lang . '">' . $escaped . '</code></pre>
        </div>';
    }, $s);

    $s = preg_replace('/`([^`\n]+)`/', '<code class="inline-code" tabindex="0">$1</code>', $s);

    $s = preg_replace('/^### (.+)$/m', '<h3 tabindex="0">$1</h3>', $s);
    $s = preg_replace('/^## (.+)$/m', '<h2 tabindex="0">$1</h2>', $s);
    $s = preg_replace('/^# (.+)$/m', '<h1 tabindex="0">$1</h1>', $s);

    $s = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $s);
    $s = preg_replace('/_(.+?)_/', '<em>$1</em>', $s);

    $s = preg_replace('/^&gt; (.+)$/m', '<blockquote tabindex="0">$1</blockquote>', $s);

    $s = preg_replace('/^[-*•] (.+)$/m', '<li>$1</li>', $s);
    $s = preg_replace('/(<li>[\s\S]*?<\/li>\n?)+/', '<ul role="list">$0</ul>', $s);

    $s = preg_replace('/^\d+\. (.+)$/m', '<oli>$1</oli>', $s);
    $s = preg_replace('/(<oli>[\s\S]*?<\/oli>\n?)+/', '<ol role="list">$0</ol>', $s);
    $s = str_replace(['<oli>', '</oli>'], ['<li>', '</li>'], $s);

    $s = preg_replace('/^---$/m', '<hr role="separator">', $s);

    $s = preg_replace_callback('/(\|.+\|\n)+/', function($m) {
        $rows = array_filter(explode("\n", trim($m[0])));
        $html = '<div class="table-container" role="region" aria-label="Tableau"><table>';
        foreach ($rows as $i => $row) {
            if (preg_match('/^\|[-| :]+\|$/', $row)) continue;
            $cells = array_slice(explode('|', $row), 1, -1);
            $tag = $i === 0 ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(fn($c) => "<{$tag}>" . trim($c) . "</{$tag}>", $cells)) . '</tr>';
        }
        return $html . '</table></div>';
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

function cleanupOldConversations(): void {
    $pdo = db();
    $count = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
    if ($count > MAX_CONVERSATIONS) {
        $pdo->exec("DELETE FROM conversations WHERE id NOT IN (
            SELECT id FROM conversations ORDER BY updated_at DESC LIMIT " . MAX_CONVERSATIONS . "
        )");
    }
}

if (empty($_SESSION['api_key'])) {
    $_SESSION['api_key'] = DEFAULT_KEY;
}
$api_key = $_SESSION['api_key'] ?? DEFAULT_KEY;

if (isset($_POST['set_key'])) {
    $new_key = trim($_POST['api_key'] ?? '');
    if (!empty($new_key)) {
        $_SESSION['api_key'] = $new_key;
    } else {
        unset($_SESSION['api_key']);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    $act = $_GET['api'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        switch ($act) {
            case 'new_conv':
                $model = $input['model'] ?? 'mistral-large-latest';
                $persona = $input['persona'] ?? 'assistant';

                if (!isset($MODELS[$model])) $model = 'mistral-large-latest';
                if (!isset($PERSONAS[$persona])) $persona = 'assistant';

                $stmt = db()->prepare("INSERT INTO conversations (model, persona) VALUES (?, ?)");
                $stmt->execute([$model, $persona]);
                $id = db()->lastInsertId();

                echo json_encode(['success' => true, 'id' => $id]);
                break;

            case 'del_conv':
                $id = (int)($input['id'] ?? 0);
                if ($id <= 0) throw new Exception("ID invalide");

                $stmt = db()->prepare("DELETE FROM conversations WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true]);
                break;

            case 'rename':
                $id = (int)($input['id'] ?? 0);
                $title = sanitizeInput($input['title'] ?? '');

                if ($id <= 0) throw new Exception("ID invalide");
                if (empty($title)) $title = 'Nouvelle conversation';

                $stmt = db()->prepare("UPDATE conversations SET title = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                $stmt->execute([$title, $id]);

                echo json_encode(['success' => true]);
                break;

            case 'load':
                $id = (int)($_GET['id'] ?? 0);
                if ($id <= 0) throw new Exception("ID invalide");

                $conv = db()->prepare("SELECT * FROM conversations WHERE id = ?");
                $conv->execute([$id]);
                $conv = $conv->fetch();

                if (!$conv) throw new Exception("Conversation introuvable");

                $msgs = db()->prepare("SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at");
                $msgs->execute([$id]);

                echo json_encode([
                    'success' => true,
                    'conv' => $conv,
                    'messages' => $msgs->fetchAll()
                ]);
                break;

            case 'send':
                $conv_id = (int)($input['conv_id'] ?? 0);
                $content = sanitizeInput($input['content'] ?? '');
                $image_b64 = $input['image_b64'] ?? '';
                $image_mime = $input['image_mime'] ?? 'image/png';

                if ($conv_id <= 0 || empty($content) || empty($api_key)) {
                    throw new Exception("Paramètres manquants");
                }

                $conv = db()->prepare("SELECT * FROM conversations WHERE id = ?");
                $conv->execute([$conv_id]);
                $conv = $conv->fetch();

                if (!$conv) throw new Exception("Conversation introuvable");

                $stmt = db()->prepare("INSERT INTO messages (conversation_id, role, content, tokens) VALUES (?, 'user', ?, ?)");
                $stmt->execute([$conv_id, $content, estimateTokens($content)]);

                $count = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
                $count->execute([$conv_id]);
                if ($count->fetchColumn() <= 1) {
                    $title = mb_substr($content, 0, 50);
                    $stmt = db()->prepare("UPDATE conversations SET title = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                    $stmt->execute([$title, $conv_id]);
                } else {
                    $stmt = db()->prepare("UPDATE conversations SET updated_at = datetime('now', 'localtime') WHERE id = ?");
                    $stmt->execute([$conv_id]);
                }

                $persona = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
                $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];

                $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY created_at");
                $history->execute([$conv_id]);

                foreach ($history->fetchAll() as $m) {
                    $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
                }

                if (!empty($image_b64)) {
                    $conv['model'] = 'pixtral-large-latest';
                    array_pop($api_messages);
                    $api_messages[] = [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $content],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image_mime . ';base64,' . $image_b64]]
                        ]
                    ];
                }

                $payload = [
                    'model' => $conv['model'],
                    'messages' => $api_messages,
                    'max_tokens' => $MODELS[$conv['model']]['max_tokens'] ?? MAX_TOKENS,
                    'temperature' => 0.7,
                    'stream' => false
                ];

                $ch = curl_init(API_URL);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $api_key,
                        'Accept: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2
                ]);

                $raw = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);

                if ($cerr) throw new Exception('Erreur réseau: ' . $cerr);
                if ($http >= 400) {
                    $error = json_decode($raw, true);
                    throw new Exception('API Error ' . $http . ': ' . ($error['message'] ?? $raw));
                }

                $data = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Erreur de décodage JSON');
                }

                $reply = trim($data['choices'][0]['message']['content'] ?? '');
                if (empty($reply)) throw new Exception('Réponse vide de l\'API');

                $stmt = db()->prepare("INSERT INTO messages (conversation_id, role, content, tokens) VALUES (?, 'assistant', ?, ?)");
                $stmt->execute([$conv_id, $reply, estimateTokens($reply)]);

                cleanupOldConversations();

                echo json_encode(['success' => true, 'reply' => $reply]);
                break;

            case 'regenerate':
                $conv_id = (int)($input['conv_id'] ?? 0);
                if ($conv_id <= 0) throw new Exception("ID invalide");

                $stmt = db()->prepare("DELETE FROM messages WHERE conversation_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$conv_id]);

                $last_user = db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 1");
                $last_user->execute([$conv_id]);
                $last_user = $last_user->fetchColumn();

                if (!$last_user) throw new Exception("Aucun message utilisateur trouvé");

                $conv = db()->prepare("SELECT * FROM conversations WHERE id = ?");
                $conv->execute([$conv_id]);
                $conv = $conv->fetch();

                if (!$conv) throw new Exception("Conversation introuvable");

                $persona = $PERSONAS[$conv['persona']] ?? $PERSONAS['assistant'];
                $api_messages = [['role' => 'system', 'content' => $persona['prompt']]];

                $history = db()->prepare("SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY created_at");
                $history->execute([$conv_id]);

                foreach ($history->fetchAll() as $m) {
                    $api_messages[] = ['role' => $m['role'], 'content' => $m['content']];
                }

                $payload = [
                    'model' => $conv['model'],
                    'messages' => $api_messages,
                    'max_tokens' => $MODELS[$conv['model']]['max_tokens'] ?? MAX_TOKENS,
                    'temperature' => 0.7
                ];

                $ch = curl_init(API_URL);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $api_key
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 300
                ]);

                $raw = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);

                if ($cerr) throw new Exception('Erreur réseau: ' . $cerr);
                if ($http >= 400) {
                    $error = json_decode($raw, true);
                    throw new Exception('API Error ' . $http . ': ' . ($error['message'] ?? $raw));
                }

                $data = json_decode($raw, true);
                $reply = trim($data['choices'][0]['message']['content'] ?? '');
                if (empty($reply)) throw new Exception('Réponse vide');

                $stmt = db()->prepare("INSERT INTO messages (conversation_id, role, content, tokens) VALUES (?, 'assistant', ?, ?)");
                $stmt->execute([$conv_id, $reply, estimateTokens($reply)]);

                echo json_encode(['success' => true, 'reply' => $reply]);
                break;

            case 'list_convs':
                $conversations = db()->query("SELECT * FROM conversations ORDER BY updated_at DESC LIMIT " . MAX_CONVERSATIONS)->fetchAll();
                echo json_encode(['success' => true, 'conversations' => $conversations]);
                break;

            default:
                throw new Exception("Action inconnue");
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

cleanupOldConversations();
$conversations = db()->query("SELECT * FROM conversations ORDER BY updated_at DESC LIMIT " . MAX_CONVERSATIONS)->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0">
<meta name="description" content="ClaudeLocal - Assistant IA local propulsé par Mistral AI">
<title>ClaudeLocal - Assistant IA local</title>
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body>

<nav class="sidebar" role="navigation" aria-label="Conversations">
  <div class="sidebar-top">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon" aria-hidden="true">C</div>
      <div>
        <span>ClaudeLocal</span>
        <small>v<?= VERSION ?> · Mistral AI</small>
      </div>
    </div>
    <button class="new-chat-btn" onclick="newConv()" aria-label="Nouvelle conversation">
      ✏️ Nouvelle conversation
    </button>
  </div>

  <div style="flex:1;overflow:hidden;display:flex;flex-direction:column">
    <?php if (!empty($conversations)): ?>
    <div class="sidebar-section" role="heading" aria-level="2">Récent</div>
    <?php endif; ?>
    <div class="conv-list" id="convList" role="list">
      <?php foreach ($conversations as $c): ?>
      <div class="conv-item" id="ci-<?= $c['id'] ?>" onclick="loadConv(<?= $c['id'] ?>)" role="listitem" aria-label="<?= h($c['title']) ?>, dernière mise à jour <?= timeAgo($c['updated_at']) ?>">
        <span class="conv-icon" aria-hidden="true">💬</span>
        <span class="conv-title"><?= h($c['title']) ?></span>
        <span class="conv-time" aria-label="Dernière mise à jour <?= timeAgo($c['updated_at']) ?>"><?= timeAgo($c['updated_at']) ?></span>
        <button class="conv-del" onclick="event.stopPropagation();delConv(<?= $c['id'] ?>)" title="Supprimer" aria-label="Supprimer la conversation <?= h($c['title']) ?>">✕</button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?>
      <div class="conv-empty" role="status">
        Aucune conversation.<br>Clique sur « Nouvelle conversation »
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="sidebar-bottom">
    <div class="api-status" onclick="showKeyPopup()" role="button" aria-label="Configurer la clé API">
      <div class="api-dot <?= $api_key ? 'ok' : 'no' ?>" aria-hidden="true"></div>
      <span class="api-label"><?= $api_key ? 'Clé API configurée' : 'Clé API manquante' ?></span>
      <span class="api-edit" aria-hidden="true">✏️</span>
    </div>
    <button class="sidebar-btn" onclick="toggleSidebar()" aria-label="Fermer la barre latérale" aria-expanded="true">
      &lt;&lt; Réduire
    </button>
  </div>
</nav>

<div class="main" role="main">
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()" aria-hidden="true"></div>

  <header class="topbar" id="topbar" role="banner">
    <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu" aria-expanded="true">☰</button>
    <div class="topbar-title" id="topbarTitle">ClaudeLocal</div>

    <div class="model-select-container">
      <label for="modelSelect" class="visually-hidden">Modèle d'IA</label>
      <select class="model-select" id="modelSelect" onchange="updateConvModel()" aria-label="Sélectionner le modèle d'IA">
        <?php foreach ($MODELS as $k => $m): ?>
        <option value="<?= h($k) ?>"><?= h($m['label']) ?> — <?= h($m['desc']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="persona-select-container">
      <label for="personaSelect" class="visually-hidden">Personnalité</label>
      <select class="persona-select" id="personaSelect" onchange="updateConvPersona()" aria-label="Sélectionner la personnalité">
        <?php foreach ($PERSONAS as $k => $p): ?>
        <option value="<?= h($k) ?>"><?= h($p['icon']) ?> <?= h($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="topbar-btn" onclick="toggleFocusMode()" title="Mode focus" aria-label="Activer le mode focus">⛶</button>
    <button class="topbar-btn" onclick="captureScreen()" title="Capturer la conversation" aria-label="Capturer l'écran">📸</button>
    <button class="topbar-btn" onclick="toggleDarkMode()" title="Mode sombre" aria-label="Basculer en mode sombre" id="darkModeToggle">🌙</button>
    <button class="topbar-btn" onclick="showShortcuts()" title="Raccourcis clavier" aria-label="Afficher les raccourcis clavier">⌨️</button>
  </header>

  <div id="welcomeScreen" class="welcome" role="region" aria-label="Écran d'accueil">
    <div class="welcome-logo" aria-hidden="true">C</div>
    <h1>Bonjour 👋</h1>
    <p>Ton assistant IA local propulsé par Mistral.<br>Crée une conversation ou sélectionne une suggestion.</p>
    <div class="suggestions" role="list">
      <div class="suggestion" onclick="quickStart('Explique-moi comment fonctionne le coefficient de marée')" role="listitem" tabindex="0" aria-label="Suggestion: Coefficient de marée">
        <span aria-hidden="true">🌊</span>
        <div>
          <strong>Coefficient de marée</strong>
          <small>Comment ça fonctionne ?</small>
        </div>
      </div>
      <div class="suggestion" onclick="quickStart('Écris-moi une fonction PHP pour appeler une API REST avec gestion des erreurs')" role="listitem" tabindex="0" aria-label="Suggestion: Code PHP">
        <span aria-hidden="true">💻</span>
        <div>
          <strong>Code PHP</strong>
          <small>Appel API REST sécurisé</small>
        </div>
      </div>
      <div class="suggestion" onclick="quickStart('Quels sont les meilleurs endroits pour pêcher à pied en Loire-Atlantique en respectant la réglementation ?')" role="listitem" tabindex="0" aria-label="Suggestion: Pêche à pied">
        <span aria-hidden="true">🦀</span>
        <div>
          <strong>Pêche à pied</strong>
          <small>Loire-Atlantique</small>
        </div>
      </div>
      <div class="suggestion" onclick="quickStart('Résume-moi les dernières avancées sur la myocardite et les vaccins ARNm avec des sources scientifiques fiables')" role="listitem" tabindex="0" aria-label="Suggestion: Recherche médicale">
        <span aria-hidden="true">🔬</span>
        <div>
          <strong>Recherche médicale</strong>
          <small>Myocardite et vaccins ARNm</small>
        </div>
      </div>
    </div>
  </div>

  <div id="chatScreen" style="display:none" role="region" aria-label="Zone de chat">
    <div class="chat-area" id="chatArea" role="log" aria-live="polite">
      <div class="msg-group" id="msgContainer" role="list"></div>
    </div>
  </div>

  <div class="input-zone" role="form" aria-label="Zone de saisie">
    <div class="input-inner">
      <label for="msgInput" class="visually-hidden">Message</label>
      <textarea id="msgInput" rows="1"
        placeholder="Envoyer un message… (Entrée pour envoyer, Maj+Entrée pour nouvelle ligne)"
        onkeydown="handleKey(event)" oninput="autoResize(this);updateCharCount(this)"
        aria-label="Saisissez votre message ici" aria-multiline="true"></textarea>

      <div id="imgPreviewBar" style="display:none" role="region" aria-label="Prévisualisation de l'image">
        <div style="display:flex;align-items:center;gap:.6rem">
          <img id="imgPreview" style="height:48px;border-radius:6px;border:1px solid var(--border)" alt="Prévisualisation de l'image jointe">
          <span id="imgPreviewName" style="font-size:.75rem;color:var(--muted);flex:1"></span>
          <button onclick="removeImage()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.9rem" title="Supprimer" aria-label="Supprimer l'image">✕</button>
        </div>
      </div>

      <div class="input-toolbar">
        <span class="char-count" id="charCount" aria-live="polite">0 / <?= MAX_MESSAGE_LENGTH ?></span>
        <input type="file" id="imgInput" accept="image/*,.heic,.heif" style="display:none" onchange="handleImageSelect(this)" aria-label="Joindre une image">
        <button class="topbar-btn" onclick="document.getElementById('imgInput').click()" title="Joindre une image" aria-label="Joindre une image">📎</button>
        <span class="input-hint-txt" id="convInfo" style="display:none" aria-live="polite"></span>
        <button class="send-btn" id="sendBtn" onclick="sendMsg()" disabled title="Envoyer" aria-label="Envoyer le message">➤</button>
      </div>
    </div>
  </div>
</div>

<div class="key-popup" id="keyPopup" style="display:<?= $api_key ? 'none' : 'flex' ?>" role="dialog" aria-modal="true" aria-labelledby="keyPopupTitle">
  <div class="key-box">
    <h2 id="keyPopupTitle">🔑 Configurer la clé API Mistral</h2>
    <p>Colle ta clé API Mistral pour commencer. Elle sera sauvegardée uniquement dans ta session de navigateur.</p>
    <form method="post" class="key-input-row" onsubmit="return validateKey()">
      <label for="keyInput" class="visually-hidden">Clé API</label>
      <input type="password" name="api_key" class="key-input" id="keyInput"
        placeholder="Colle ta clé ici..." autocomplete="off" required
        aria-label="Saisissez votre clé API Mistral">
      <button type="submit" name="set_key" value="1" class="key-save-btn">Sauvegarder</button>
    </form>
    <button onclick="hideKeyPopup()" style="margin-top:.8rem;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.85rem" aria-label="Annuler">Annuler</button>
    <div class="key-hint">
      <p>Tu n'as pas de clé API ? <a href="https://console.mistral.ai/" target="_blank" rel="noopener noreferrer">Obtiens-en une ici</a></p>
    </div>
  </div>
</div>

<div class="shortcuts-popup" id="shortcutsPopup" style="display:none" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle">
  <div class="shortcuts-box">
    <div class="shortcuts-header">
      <h2 id="shortcutsTitle">Raccourcis clavier</h2>
      <button onclick="hideShortcuts()" aria-label="Fermer">✕</button>
    </div>
    <div class="shortcuts-grid">
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Ctrl</kbd> + <kbd>N</kbd></div>
        <div class="shortcut-desc">Nouvelle conversation</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Ctrl</kbd> + <kbd>/</kbd></div>
        <div class="shortcut-desc">Focus sur la zone de saisie</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>↑</kbd></div>
        <div class="shortcut-desc">Éditer le dernier message</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Maj</kbd> + <kbd>Entrée</kbd></div>
        <div class="shortcut-desc">Nouvelle ligne</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Entrée</kbd></div>
        <div class="shortcut-desc">Envoyer le message</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Ctrl</kbd> + <kbd>↑</kbd></div>
        <div class="shortcut-desc">Défilement vers le haut</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Ctrl</kbd> + <kbd>↓</kbd></div>
        <div class="shortcut-desc">Défilement vers le bas</div>
      </div>
      <div class="shortcut-item">
        <div class="shortcut-keys"><kbd>Ctrl</kbd> + <kbd>B</kbd></div>
        <div class="shortcut-desc">Mode sombre</div>
      </div>
    </div>
  </div>
</div>

<script>
window._CONFIG = {
    API_KEY_SET: <?= $api_key ? 'true' : 'false' ?>,
    MAX_MESSAGE_LENGTH: <?= MAX_MESSAGE_LENGTH ?>,
    MODELS: <?= json_encode($MODELS) ?>,
    PERSONAS: <?= json_encode($PERSONAS) ?>
};
</script>
<script src="app.js"></script>
</body>
</html>

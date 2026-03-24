<?php

declare(strict_types=1);

/**
 * Single-file PHP snippets manager. Persists to ../private/snippets.json (outside web root).
 */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function snippets_path(): string
{
    return dirname(__DIR__) . '/private/snippets.json';
}

/**
 * @return list<array{id: string, title: string, code: string, created_at: string, updated_at: string}>
 */
function load_snippets(): array
{
    $path = snippets_path();
    if (!is_readable($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
        $title = isset($row['title']) && is_string($row['title']) ? $row['title'] : '';
        $code = isset($row['code']) && is_string($row['code']) ? $row['code'] : '';
        $created = isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '';
        $updated = isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : '';
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'title' => $title,
            'code' => $code,
            'created_at' => $created,
            'updated_at' => $updated,
        ];
    }

    return $out;
}

/**
 * @param list<array{id: string, title: string, code: string, created_at: string, updated_at: string}> $snippets
 */
function save_snippets(array $snippets): bool
{
    $path = snippets_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }
    try {
        $json = json_encode($snippets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/** @param list<array{id: string, title: string, code: string, created_at: string, updated_at: string}> $snippets */
function find_snippet_by_id(array $snippets, string $id): ?array
{
    foreach ($snippets as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }

    return null;
}

function now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
}

$error = '';

// ——— POST handling ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($postAction === 'delete') {
        $id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
        $snippets = load_snippets();
        $filtered = array_values(array_filter($snippets, static fn (array $s): bool => $s['id'] !== $id));
        if (count($filtered) === count($snippets)) {
            $error = 'Snippet not found.';
        } elseif (!save_snippets($filtered)) {
            $error = 'Could not save after delete.';
        } else {
            header('Location: ?action=list', true, 302);
            exit;
        }
    } elseif ($postAction === 'save') {
        $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
        $code = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
        $id = isset($_POST['id']) && is_string($_POST['id']) ? trim($_POST['id']) : '';

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $snippets = load_snippets();
            $ts = now_iso();
            if ($id === '') {
                $new = [
                    'id' => bin2hex(random_bytes(16)),
                    'title' => $title,
                    'code' => $code,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
                $snippets[] = $new;
            } else {
                $found = false;
                foreach ($snippets as $i => $s) {
                    if ($s['id'] === $id) {
                        $snippets[$i]['title'] = $title;
                        $snippets[$i]['code'] = $code;
                        $snippets[$i]['updated_at'] = $ts;
                        if ($snippets[$i]['created_at'] === '') {
                            $snippets[$i]['created_at'] = $ts;
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $error = 'Snippet not found.';
                }
            }
            if ($error === '' && !save_snippets($snippets)) {
                $error = 'Could not save snippets file. Check that private/ is writable.';
            }
            if ($error === '') {
                $redirectId = $id !== '' ? $id : $new['id'];
                header('Location: ?action=view&id=' . rawurlencode($redirectId), true, 302);
                exit;
            }
        }
    }
}

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : 'list';
$getId = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';

$snippets = load_snippets();

if ($action === 'view' || $action === 'edit' || $action === 'delete') {
    $snippet = $getId !== '' ? find_snippet_by_id($snippets, $getId) : null;
    if ($snippet === null) {
        $error = $error !== '' ? $error : 'Snippet not found.';
        $action = 'list';
    }
}

$pageTitle = 'PHP Snippets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/themes/prism-okaidia.min.css">
    <style>
        :root {
            --bg: #0f1419;
            --surface: #1a2332;
            --border: #2d3a4f;
            --text: #e6edf3;
            --muted: #8b9cb3;
            --accent: #58a6ff;
            --accent-hover: #79b8ff;
            --danger: #f85149;
            --danger-hover: #ff7b72;
            --radius: 10px;
            --font: "SF Pro Text", system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            --mono: ui-monospace, "Cascadia Code", "SF Mono", Menlo, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--font);
            background: linear-gradient(165deg, var(--bg) 0%, #121c28 100%);
            color: var(--text);
            line-height: 1.5;
        }
        .wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
        }
        header {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        h1 {
            margin: 0;
            font-size: 1.65rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 8px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .btn-primary {
            background: var(--accent);
            color: #0d1117;
        }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-ghost {
            background: transparent;
            color: var(--muted);
            border-color: var(--border);
        }
        .btn-ghost:hover { color: var(--text); border-color: var(--muted); }
        .btn-danger {
            background: transparent;
            color: var(--danger);
            border-color: var(--danger);
        }
        .btn-danger:hover { background: rgba(248, 81, 73, 0.12); color: var(--danger-hover); border-color: var(--danger-hover); }
        .alert {
            padding: 0.85rem 1rem;
            border-radius: var(--radius);
            background: rgba(248, 81, 73, 0.12);
            border: 1px solid rgba(248, 81, 73, 0.35);
            color: #ffb4b0;
            margin-bottom: 1.25rem;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            font-weight: 600;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }
        .snippet-title { font-weight: 500; }
        .snippet-meta { font-size: 0.8rem; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .empty {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--muted);
        }
        .empty p { margin: 0 0 1rem; }
        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 0.35rem;
            color: var(--muted);
        }
        .list-toolbar {
            padding: 1rem 1.25rem 0;
        }
        .list-toolbar label {
            margin-bottom: 0.35rem;
        }
        .search-empty {
            margin: 0;
            padding: 0 1.25rem 1rem;
            font-size: 0.9rem;
            color: var(--muted);
        }
        input[type="text"], input[type="search"], textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-family: inherit;
            font-size: 1rem;
        }
        textarea {
            min-height: 280px;
            font-family: var(--mono);
            font-size: 0.9rem;
            line-height: 1.45;
            resize: vertical;
        }
        .field { margin-bottom: 1.15rem; }
        .form-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
        .view-head {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .view-head h2 { margin: 0 0 0.35rem; font-size: 1.25rem; }
        pre[class*="language-"] {
            margin: 0;
            border-radius: 0;
            max-height: 70vh;
            overflow: auto;
        }
        .toolbar { margin-top: 1rem; display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .confirm-box { padding: 1.25rem; }
        .confirm-box p { margin: 0 0 1rem; color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1><a href="?action=list" style="color:inherit;text-decoration:none"><?= h($pageTitle) ?></a></h1>
        <a class="btn btn-primary" href="?action=new">New snippet</a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="card">
            <?php if (count($snippets) === 0): ?>
                <div class="empty">
                    <p>No snippets yet. Create your first one.</p>
                    <a class="btn btn-primary" href="?action=new">Add snippet</a>
                </div>
            <?php else: ?>
                <div class="list-toolbar">
                    <label for="snippet-search">Search titles</label>
                    <input type="search" id="snippet-search" autocomplete="off" placeholder="Search titles…" aria-controls="snippet-table-body">
                </div>
                <p class="search-empty" id="snippet-search-empty" hidden>No snippets match your search.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="snippet-table-body">
                        <?php foreach ($snippets as $s): ?>
                            <tr>
                                <td>
                                    <div class="snippet-title"><?= h($s['title']) ?></div>
                                </td>
                                <td class="snippet-meta"><?= h($s['updated_at'] !== '' ? $s['updated_at'] : '—') ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn btn-ghost" href="?action=view&id=<?= rawurlencode($s['id']) ?>">View</a>
                                        <a class="btn btn-ghost" href="?action=edit&id=<?= rawurlencode($s['id']) ?>">Edit</a>
                                        <a class="btn btn-danger" href="?action=delete&id=<?= rawurlencode($s['id']) ?>">Delete</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'view' && isset($snippet)): ?>
        <div class="card">
            <div class="view-head">
                <h2><?= h($snippet['title']) ?></h2>
                <div class="snippet-meta">
                    <?php if ($snippet['updated_at'] !== ''): ?>
                        Updated <?= h($snippet['updated_at']) ?>
                    <?php endif; ?>
                </div>
                <div class="toolbar">
                    <a class="btn btn-primary" href="?action=edit&id=<?= rawurlencode($snippet['id']) ?>">Edit</a>
                    <a class="btn btn-ghost" href="?action=list">Back to list</a>
                </div>
            </div>
            <pre><code class="language-php"><?= h($snippet['code']) ?></code></pre>
        </div>

    <?php elseif ($action === 'new' || ($action === 'edit' && isset($snippet))): ?>
        <?php
        $formPrefillFromPost = $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['action']) && $_POST['action'] === 'save';
        if ($formPrefillFromPost) {
            $formId = isset($_POST['id']) && is_string($_POST['id']) ? trim($_POST['id']) : '';
            $formTitle = isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : '';
            $formCode = isset($_POST['code']) && is_string($_POST['code']) ? $_POST['code'] : '';
        } elseif ($action === 'edit' && isset($snippet)) {
            $formId = $snippet['id'];
            $formTitle = $snippet['title'];
            $formCode = $snippet['code'];
        } else {
            $formId = '';
            $formTitle = '';
            $formCode = '';
        }
        ?>
        <div class="card" style="padding: 1.25rem;">
            <form method="post" action="<?= $formId !== '' ? '?action=edit&id=' . rawurlencode($formId) : '?action=new' ?>">
                <input type="hidden" name="action" value="save">
                <?php if ($formId !== ''): ?>
                    <input type="hidden" name="id" value="<?= h($formId) ?>">
                <?php endif; ?>
                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required maxlength="500" value="<?= h($formTitle) ?>">
                </div>
                <div class="field">
                    <label for="code">PHP code</label>
                    <textarea id="code" name="code" spellcheck="false"><?= h($formCode) ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $formId !== '' ? 'Save changes' : 'Create snippet' ?></button>
                    <a class="btn btn-ghost" href="<?= $formId !== '' ? '?action=view&id=' . rawurlencode($formId) : '?action=list' ?>">Cancel</a>
                </div>
            </form>
        </div>

    <?php elseif ($action === 'delete' && isset($snippet)): ?>
        <div class="card confirm-box">
            <p>Delete <strong><?= h($snippet['title']) ?></strong>? This cannot be undone.</p>
            <form method="post" action="?action=delete&id=<?= rawurlencode($snippet['id']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h($snippet['id']) ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
                <a class="btn btn-ghost" href="?action=view&id=<?= rawurlencode($snippet['id']) ?>">Cancel</a>
            </form>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-core.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-markup-templating.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/components/prism-php.min.js"></script>
<script>
    if (window.Prism) {
        Prism.highlightAll();
    }
</script>
<script>
(function () {
    var input = document.getElementById('snippet-search');
    var tbody = document.getElementById('snippet-table-body');
    var emptyMsg = document.getElementById('snippet-search-empty');
    if (!input || !tbody) {
        return;
    }
    var debounceMs = 1000;
    var timer = null;

    function applyFilter() {
        var q = input.value.trim().toLowerCase();
        var rows = tbody.querySelectorAll('tr');
        var visible = 0;
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var titleEl = tr.querySelector('.snippet-title');
            var title = titleEl ? titleEl.textContent : '';
            var matches = q === '' || title.toLowerCase().indexOf(q) !== -1;
            tr.hidden = !matches;
            if (matches) {
                visible++;
            }
        }
        if (emptyMsg) {
            emptyMsg.hidden = !(q !== '' && visible === 0);
        }
    }

    input.addEventListener('input', function () {
        if (timer !== null) {
            clearTimeout(timer);
        }
        timer = setTimeout(function () {
            timer = null;
            applyFilter();
        }, debounceMs);
    });
})();
</script>
</body>
</html>

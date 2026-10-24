<?php
/**
 * core/support_articles.php — help articles + categories (support_center_plan.md, Phase 2).
 *
 * Written once by BJP in the superadmin, stored in the control DB, shown to every tenant
 * in that tenant's own words: article text uses placeholders that are resolved per user
 * at render time —
 *   {nav:page_key}            → the real menu path ("Shop › Shops" / "Inventory › Warehouses")
 *   {shop} {shops} {Shop} {Shops} → shop / warehouse wording (core/terminology.php rule)
 *
 * Body format (no HTML is ever stored or trusted — everything is escaped first):
 *   "1. step"  numbered steps      "- item"  bullet list
 *   "Tip: …"   tip box             **bold**  blank line = new paragraph
 */

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/help_nav.php';

const SUPPORT_VIDEO_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com', 'loom.com', 'www.loom.com'];
const SUPPORT_CATEGORY_ICONS = ['book', 'rocket-takeoff', 'cart-check', 'box', 'shop', 'people', 'receipt', 'graph-up', 'gear', 'file-text', 'person-badge', 'cash-stack', 'truck', 'question-circle', 'play-circle', 'shield-check'];

// ── Rendering ────────────────────────────────────────────────────────────────

if (!function_exists('supportResolveTerms')) {
    /** Replace {shop}-family placeholders. Input and output are plain text (escape afterwards). */
    function supportResolveTerms(string $s, array $ctx): string
    {
        $shop = !empty($ctx['shop']);
        $t = $ctx['t'] ?? fn($x) => $x;
        return strtr($s, [
            '{Shops}' => $t($shop ? 'Shops' : 'Warehouses'),
            '{shops}' => mb_strtolower($t($shop ? 'Shops' : 'Warehouses')),
            '{Shop}'  => $t($shop ? 'Shop' : 'Warehouse'),
            '{shop}'  => mb_strtolower($t($shop ? 'Shop' : 'Warehouse')),
        ]);
    }
}

if (!function_exists('supportInline')) {
    /** One line of body text → safe HTML (escape, then placeholders, then **bold**). */
    function supportInline(string $text, array $ctx): string
    {
        $text = supportResolveTerms($text, $ctx);
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('/\{nav:([a-z0-9_]{1,64})\}/', function ($m) use ($ctx) {
            $path = helpNavPath($m[1], $ctx);
            if ($path === null) {
                $cat = helpNavCatalog()[$m[1]] ?? null;
                $path = $cat ? helpNavLabel($cat['shop_label'] !== null ? [$cat['label'], $cat['shop_label']] : $cat['label'], $ctx) : $m[1];
            }
            return '<span class="help-path">' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</span>';
        }, $html);
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
    }
}

if (!function_exists('supportRenderBody')) {
    function supportRenderBody(string $src, array $ctx): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $src);
        $out = [];
        $list = null;   // 'ol' | 'ul'
        $para = [];
        $flushPara = function () use (&$para, &$out, $ctx) {
            if ($para) { $out[] = '<p>' . supportInline(implode(' ', $para), $ctx) . '</p>'; $para = []; }
        };
        $closeList = function () use (&$list, &$out) {
            if ($list) { $out[] = "</{$list}>"; $list = null; }
        };
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') { $flushPara(); $closeList(); continue; }
            if (preg_match('/^\d{1,2}[.)]\s+(.*)$/u', $line, $m)) {
                $flushPara();
                if ($list !== 'ol') { $closeList(); $out[] = '<ol>'; $list = 'ol'; }
                $out[] = '<li>' . supportInline($m[1], $ctx) . '</li>';
            } elseif (preg_match('/^[-*•]\s+(.*)$/u', $line, $m)) {
                $flushPara();
                if ($list !== 'ul') { $closeList(); $out[] = '<ul>'; $list = 'ul'; }
                $out[] = '<li>' . supportInline($m[1], $ctx) . '</li>';
            } elseif (preg_match('/^(Tip|Kidokezo)\s*:\s*(.*)$/iu', $line, $m)) {
                $flushPara(); $closeList();
                $out[] = '<div class="help-tip"><strong>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . ':</strong> ' . supportInline($m[2], $ctx) . '</div>';
            } else {
                $closeList();
                $para[] = $line;
            }
        }
        $flushPara(); $closeList();
        return implode("\n", $out);
    }
}

if (!function_exists('supportRenderTitle')) {
    /** Title/category name → plain text (terms and nav paths resolved; caller escapes). */
    function supportTitleText(string $s, array $ctx): string
    {
        $s = supportResolveTerms(str_replace('**', '', $s), $ctx);
        return preg_replace_callback('/\{nav:([a-z0-9_]{1,64})\}/', fn($m) => helpNavPath($m[1], $ctx) ?? $m[1], $s);
    }

    /** Title/category name → safe HTML. */
    function supportRenderTitle(string $s, array $ctx): string
    {
        return htmlspecialchars(supportTitleText($s, $ctx), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('supportArticleLocalized')) {
    /** Pick the language + Simple Mode variant, English fallback. */
    function supportArticleLocalized(array $a, string $lang, bool $simple): array
    {
        $sw = $lang === 'sw';
        $title = ($sw && trim((string)$a['title_sw']) !== '') ? $a['title_sw'] : $a['title_en'];
        $body = null;
        if ($simple) {
            $body = ($sw && trim((string)$a['simple_body_sw']) !== '') ? $a['simple_body_sw']
                  : (trim((string)$a['simple_body_en']) !== '' ? $a['simple_body_en'] : null);
        }
        if ($body === null) {
            $body = ($sw && trim((string)$a['body_sw']) !== '') ? $a['body_sw'] : $a['body_en'];
        }
        return ['title' => (string)$title, 'body' => (string)$body];
    }
}

if (!function_exists('supportArticleVisible')) {
    /**
     * Untagged = general (everyone). Tagged = at least one of its pages is reachable from
     * THIS user's own menu (module + role + how the menu is laid out for this company), so
     * every "Open …" step in the article matches something the reader can actually click.
     */
    function supportArticleVisible(array $a, array $ctx): bool
    {
        $keys = is_array($a['page_keys'] ?? null) ? $a['page_keys'] : (json_decode((string)($a['page_keys'] ?? ''), true) ?: []);
        if (!$keys) return true;
        foreach ($keys as $k) {
            if (helpNavPath((string)$k, $ctx) !== null) return true;
        }
        return false;
    }
}

// ── Validation ───────────────────────────────────────────────────────────────

if (!function_exists('supportValidVideoUrl')) {
    function supportValidVideoUrl(string $url): bool
    {
        if ($url === '') return true;
        if (mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $p = parse_url($url);
        return ($p['scheme'] ?? '') === 'https' && empty($p['user']) && empty($p['pass'])
            && in_array(strtolower($p['host'] ?? ''), SUPPORT_VIDEO_HOSTS, true);
    }
}

if (!function_exists('supportValidateArticle')) {
    /** @return array{ok:bool, errors:array, values:array} */
    function supportValidateArticle(array $in, ?PDO $pdo = null): array
    {
        $e = [];
        $s = fn(string $k, int $max) => trim((string)($in[$k] ?? ''));
        $v = [
            'title_en' => $s('title_en', 255), 'title_sw' => $s('title_sw', 255),
            'body_en' => $s('body_en', 20000), 'body_sw' => $s('body_sw', 20000),
            'simple_body_en' => $s('simple_body_en', 20000), 'simple_body_sw' => $s('simple_body_sw', 20000),
            'video_url' => $s('video_url', 500),
            'is_faq' => !empty($in['is_faq']) ? 1 : 0,
        ];
        $len = fn(string $k) => mb_strlen($v[$k]);
        if ($len('title_en') < 3 || $len('title_en') > 255) $e['title_en'] = 'Enter an English title (3–255 characters).';
        if ($len('title_sw') > 255) $e['title_sw'] = 'The Swahili title is too long (255 characters max).';
        if ($len('body_en') < 10) $e['body_en'] = 'Write the English steps (at least 10 characters).';
        foreach (['body_en', 'body_sw', 'simple_body_en', 'simple_body_sw'] as $k) {
            if ($len($k) > 20000) $e[$k] = 'This text is too long (20,000 characters max).';
        }
        if (!supportValidVideoUrl($v['video_url'])) $e['video_url'] = 'Use an https link from YouTube, Vimeo or Loom.';

        $catalog = helpNavCatalog();
        $keys = $in['page_keys'] ?? [];
        if (!is_array($keys)) $keys = array_filter(array_map('trim', explode(',', (string)$keys)));
        $keys = array_values(array_unique(array_map('strval', $keys)));
        foreach ($keys as $k) {
            if (!isset($catalog[$k])) { $e['page_keys'] = "Unknown page '" . mb_substr($k, 0, 40) . "'."; break; }
        }
        if (count($keys) > 10) $e['page_keys'] = 'Choose up to 10 pages.';
        $v['page_keys'] = $keys;

        // Every {nav:...} used in any text must be a known page too.
        foreach (['title_en', 'title_sw', 'body_en', 'body_sw', 'simple_body_en', 'simple_body_sw'] as $k) {
            if (preg_match_all('/\{nav:([^}]*)\}/', $v[$k], $mm)) {
                foreach ($mm[1] as $nk) {
                    if (!isset($catalog[$nk])) { $e[$k] = "Unknown page in {nav:" . mb_substr($nk, 0, 40) . "}."; break 2; }
                }
            }
        }

        $cat = $in['category_id'] ?? '';
        $v['category_id'] = ($cat === '' || $cat === null) ? null : (ctype_digit((string)$cat) ? (int)$cat : -1);
        if ($v['category_id'] === -1) {
            $e['category_id'] = 'Choose a valid category.';
        } elseif ($v['category_id'] !== null && $pdo) {
            $st = $pdo->prepare('SELECT 1 FROM support_categories WHERE id = ?');
            $st->execute([$v['category_id']]);
            if (!$st->fetchColumn()) $e['category_id'] = 'Choose a valid category.';
        }
        return ['ok' => !$e, 'errors' => $e, 'values' => $v];
    }
}

if (!function_exists('supportValidateCategory')) {
    function supportValidateCategory(array $in): array
    {
        $e = [];
        $v = [
            'name_en' => trim((string)($in['name_en'] ?? '')),
            'name_sw' => trim((string)($in['name_sw'] ?? '')),
            'icon' => trim((string)($in['icon'] ?? 'book')),
            'sort_order' => trim((string)($in['sort_order'] ?? '0')),
        ];
        if (mb_strlen($v['name_en']) < 2 || mb_strlen($v['name_en']) > 120) $e['name_en'] = 'Enter an English name (2–120 characters).';
        if (mb_strlen($v['name_sw']) > 120) $e['name_sw'] = 'The Swahili name is too long (120 characters max).';
        if (!in_array($v['icon'], SUPPORT_CATEGORY_ICONS, true)) $e['icon'] = 'Choose an icon from the list.';
        if (!preg_match('/^-?\d{1,4}$/', $v['sort_order'])) $e['sort_order'] = 'Order must be a whole number.';
        $v['sort_order'] = (int)$v['sort_order'];
        return ['ok' => !$e, 'errors' => $e, 'values' => $v];
    }
}

// ── Data access (control DB) ────────────────────────────────────────────────

if (!function_exists('supportCategories')) {
    function supportCategories(bool $activeOnly = false): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM support_articles a WHERE a.category_id = c.id AND a.status <> \'archived\') AS article_count
                FROM support_categories c' . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name_en';
        return getControlPdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('supportCategorySave')) {
    function supportCategorySave(array $v, ?int $id): int
    {
        $pdo = getControlPdo();
        if ($id) {
            $pdo->prepare('UPDATE support_categories SET name_en = ?, name_sw = ?, icon = ?, sort_order = ? WHERE id = ?')
                ->execute([$v['name_en'], $v['name_sw'] !== '' ? $v['name_sw'] : null, $v['icon'], $v['sort_order'], $id]);
            return $id;
        }
        $pdo->prepare('INSERT INTO support_categories (name_en, name_sw, icon, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$v['name_en'], $v['name_sw'] !== '' ? $v['name_sw'] : null, $v['icon'], $v['sort_order']]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('supportCategorySetActive')) {
    function supportCategorySetActive(int $id, bool $active): bool
    {
        $pdo = getControlPdo();
        $chk = $pdo->prepare('SELECT 1 FROM support_categories WHERE id = ?');
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) return false;
        $pdo->prepare('UPDATE support_categories SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        return true;
    }
}

if (!function_exists('supportArticleGet')) {
    function supportArticleGet(int $id): ?array
    {
        $st = getControlPdo()->prepare('SELECT * FROM support_articles WHERE id = ?');
        $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;
        $a['page_keys'] = json_decode((string)$a['page_keys'], true) ?: [];
        return $a;
    }
}

if (!function_exists('supportArticleSave')) {
    /** Insert or update; status only changes through supportArticleSetStatus() unless $status is given. */
    function supportArticleSave(array $v, ?int $id, int $actorId, ?string $status = null): int
    {
        $pdo = getControlPdo();
        $cols = [$v['category_id'], $v['title_en'], $v['title_sw'] ?: null, $v['body_en'], $v['body_sw'] ?: null,
                 $v['simple_body_en'] ?: null, $v['simple_body_sw'] ?: null, $v['video_url'] ?: null, $v['is_faq'],
                 json_encode(array_values($v['page_keys'])), $actorId];
        if ($id) {
            $pdo->prepare('UPDATE support_articles SET category_id = ?, title_en = ?, title_sw = ?, body_en = ?, body_sw = ?,
                           simple_body_en = ?, simple_body_sw = ?, video_url = ?, is_faq = ?, page_keys = ?, updated_by = ?
                           WHERE id = ?')->execute(array_merge($cols, [$id]));
        } else {
            $pdo->prepare('INSERT INTO support_articles (category_id, title_en, title_sw, body_en, body_sw, simple_body_en,
                           simple_body_sw, video_url, is_faq, page_keys, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($cols);
            $id = (int)$pdo->lastInsertId();
        }
        if ($status !== null) supportArticleSetStatus($id, $status, $actorId);
        return $id;
    }
}

if (!function_exists('supportArticleSetStatus')) {
    function supportArticleSetStatus(int $id, string $status, int $actorId): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'], true)) return false;
        $st = getControlPdo()->prepare('UPDATE support_articles SET status = ?, updated_by = ? WHERE id = ?');
        $st->execute([$status, $actorId, $id]);
        return supportArticleGet($id) !== null;
    }
}

if (!function_exists('supportArticleList')) {
    /** Superadmin list. Filters: status (draft|published|archived|all; default excludes archived), category_id, q. */
    function supportArticleList(array $f = []): array
    {
        $where = []; $args = [];
        $status = (string)($f['status'] ?? '');
        if (in_array($status, ['draft', 'published', 'archived'], true)) { $where[] = 'a.status = ?'; $args[] = $status; }
        elseif ($status !== 'all') { $where[] = "a.status <> 'archived'"; }
        if (!empty($f['category_id']) && ctype_digit((string)$f['category_id'])) { $where[] = 'a.category_id = ?'; $args[] = (int)$f['category_id']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') { $where[] = '(a.title_en LIKE ? OR a.title_sw LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $args[] = $like; $args[] = $like; }
        $sql = 'SELECT a.id, a.title_en, a.title_sw, a.status, a.is_faq, a.page_keys, a.video_url, a.views, a.helpful_yes, a.helpful_no,
                       a.updated_at, a.created_at, c.name_en AS category
                FROM support_articles a LEFT JOIN support_categories c ON c.id = a.category_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.status = \'published\' DESC, COALESCE(a.updated_at, a.created_at) DESC LIMIT 500';
        $st = getControlPdo()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['page_keys'] = json_decode((string)$r['page_keys'], true) ?: [];
        return $rows;
    }
}

// ── Tenant side (published + visible only) ───────────────────────────────────

const SUPPORT_SYNONYMS = [
    ['warehouse', 'warehouses', 'shop', 'shops', 'store', 'stores', 'branch', 'ghala', 'maghala', 'duka', 'maduka'],
    ['sell', 'sale', 'sales', 'pos', 'checkout', 'uza', 'mauzo'],
    ['invoice', 'invoices', 'bill', 'ankara'],
    ['refund', 'return', 'returns', 'rudisha'],
    ['salary', 'salaries', 'payroll', 'wage', 'mshahara'],
    ['credit', 'debt', 'madeni', 'deni', 'account'],
    ['expense', 'expenses', 'cost', 'matumizi', 'gharama'],
    ['user', 'users', 'staff', 'mtumiaji', 'watumiaji'],
    ['password', 'nenosiri', 'login'],
    ['product', 'products', 'item', 'items', 'bidhaa'],
    ['customer', 'customers', 'client', 'mteja', 'wateja'],
    ['supplier', 'suppliers', 'vendor', 'msambazaji'],
];

if (!function_exists('supportPublishedArticles')) {
    /** Published articles whose category is shown (or none). Cached per request. */
    function supportPublishedArticles(): array
    {
        if (isset($GLOBALS['__support_published'])) return $GLOBALS['__support_published'];
        $rows = [];
        try {
            $rows = getControlPdo()->query("
                SELECT a.*, c.name_en AS cat_en, c.name_sw AS cat_sw, c.icon AS cat_icon, c.sort_order AS cat_sort
                FROM support_articles a LEFT JOIN support_categories c ON c.id = a.category_id
                WHERE a.status = 'published' AND (a.category_id IS NULL OR c.is_active = 1)
                ORDER BY a.views DESC, a.id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('supportPublishedArticles: ' . $e->getMessage());
        }
        foreach ($rows as &$r) $r['page_keys'] = json_decode((string)$r['page_keys'], true) ?: [];
        return $GLOBALS['__support_published'] = $rows;
    }
}

if (!function_exists('supportTenantList')) {
    /** Everything this user may read, localized, as plain-text titles (escape when printing). */
    function supportTenantList(array $ctx, string $lang): array
    {
        $out = [];
        foreach (supportPublishedArticles() as $a) {
            if (!supportArticleVisible($a, $ctx)) continue;
            $loc = supportArticleLocalized($a, $lang, !empty($ctx['simple']));
            $cat = $a['category_id'] ? (($lang === 'sw' && trim((string)$a['cat_sw']) !== '') ? $a['cat_sw'] : $a['cat_en']) : '';
            $out[] = [
                'id' => (int)$a['id'], 'title' => supportTitleText($loc['title'], $ctx),
                'category' => $cat !== '' ? supportTitleText($cat, $ctx) : '', 'category_id' => $a['category_id'] ? (int)$a['category_id'] : null,
                'category_icon' => $a['cat_icon'] ?? 'book', 'category_sort' => (int)($a['cat_sort'] ?? 999),
                'page_keys' => $a['page_keys'], 'is_faq' => (bool)$a['is_faq'], 'has_video' => trim((string)$a['video_url']) !== '',
                'views' => (int)$a['views'], '_body' => supportTitleText($loc['body'], $ctx),
            ];
        }
        return $out;
    }
}

if (!function_exists('supportSearchTerms')) {
    /** Query → list of synonym groups (each word becomes the set of words it can match). */
    function supportSearchTerms(string $q): array
    {
        $q = mb_strtolower(mb_substr($q, 0, 100));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['how', 'do', 'i', 'to', 'the', 'a', 'an', 'my', 'in', 'on', 'of', 'for', 'is', 'can', 'what', 'where', 'and', 'jinsi', 'ya', 'kwa', 'na', 'la', 'wa', 'ku'];
        $groups = [];
        foreach (array_slice($words, 0, 8) as $w) {
            if (mb_strlen($w) < 2 || in_array($w, $stop, true)) continue;
            $g = [$w];
            foreach (SUPPORT_SYNONYMS as $set) if (in_array($w, $set, true)) { $g = $set; break; }
            $groups[] = $g;
        }
        return $groups;
    }
}

if (!function_exists('supportTenantSearch')) {
    /** Visible articles matching every term group, best first (title hits weigh more). */
    function supportTenantSearch(string $q, array $ctx, string $lang): array
    {
        $groups = supportSearchTerms($q);
        if (!$groups) return [];
        $hits = [];
        foreach (supportTenantList($ctx, $lang) as $a) {
            $title = mb_strtolower($a['title']);
            $hay = $title . ' ' . mb_strtolower($a['category'] . ' ' . $a['_body']);
            $score = 0;
            foreach ($groups as $g) {
                $in = false;
                foreach ($g as $w) {
                    if (str_contains($hay, $w)) { $in = true; $score += str_contains($title, $w) ? 3 : 1; break; }
                }
                if (!$in) continue 2;
            }
            $a['_score'] = $score;
            $hits[] = $a;
        }
        usort($hits, fn($x, $y) => [$y['_score'], $y['views']] <=> [$x['_score'], $x['views']]);
        return array_slice($hits, 0, 20);
    }
}

if (!function_exists('supportSearchNote')) {
    /** "This company calls them shops" style hint when the reader used the other wording. */
    function supportSearchNote(string $q, array $ctx): string
    {
        $l = mb_strtolower($q);
        $t = $ctx['t'] ?? fn($x) => $x;
        if (!empty($ctx['shop']) && preg_match('/warehouse|ghala/u', $l)) return $t('In this system warehouses are called shops.');
        if (empty($ctx['shop']) && preg_match('/\bshops?\b|duka/u', $l)) return $t('In this system shops are managed as warehouses.');
        return '';
    }
}

if (!function_exists('supportTenantArticle')) {
    /** One published article this user may read, rendered. Null when hidden or missing. */
    function supportTenantArticle(int $id, array $ctx, string $lang, ?string $currentKey = null): ?array
    {
        foreach (supportPublishedArticles() as $a) {
            if ((int)$a['id'] !== $id) continue;
            if (!supportArticleVisible($a, $ctx)) return null;
            $loc = supportArticleLocalized($a, $lang, !empty($ctx['simple']));
            $take = null;
            foreach ($a['page_keys'] as $k) {
                if ($k === $currentKey) continue;
                $path = helpNavPath($k, $ctx);
                $url = $path !== null ? helpNavUrl($k) : null;
                if ($url !== null && $path !== null) { $take = ['url' => $url, 'label' => $path]; break; }
            }
            $cat = $a['category_id'] ? (($lang === 'sw' && trim((string)$a['cat_sw']) !== '') ? $a['cat_sw'] : $a['cat_en']) : '';
            return [
                'id' => (int)$a['id'],
                'title' => supportTitleText($loc['title'], $ctx),
                'category' => $cat !== '' ? supportTitleText($cat, $ctx) : '',
                'html' => supportRenderBody($loc['body'], $ctx),
                'video_url' => supportValidVideoUrl((string)$a['video_url']) ? (string)$a['video_url'] : '',
                'take' => $take,
                'updated' => (string)($a['updated_at'] ?? $a['created_at']),
            ];
        }
        return null;
    }
}

if (!function_exists('supportArticleCountView')) {
    /** +1 view, once per article per session. */
    function supportArticleCountView(int $id): bool
    {
        $seen = $_SESSION['support_viewed'] ?? [];
        if (!empty($seen[$id])) return false;
        try {
            getControlPdo()->prepare("UPDATE support_articles SET views = views + 1 WHERE id = ? AND status = 'published'")->execute([$id]);
        } catch (Throwable $e) { error_log('supportArticleCountView: ' . $e->getMessage()); return false; }
        $seen[$id] = 1;
        $_SESSION['support_viewed'] = array_slice($seen, -200, null, true);
        return true;
    }
}

if (!function_exists('supportArticleVote')) {
    /** Helpful yes/no, once per article per session. Returns false when already voted. */
    function supportArticleVote(int $id, bool $helpful): bool
    {
        $voted = $_SESSION['support_voted'] ?? [];
        if (isset($voted[$id])) return false;
        $col = $helpful ? 'helpful_yes' : 'helpful_no';
        getControlPdo()->prepare("UPDATE support_articles SET {$col} = {$col} + 1 WHERE id = ? AND status = 'published'")->execute([$id]);
        $voted[$id] = $helpful ? 1 : 0;
        $_SESSION['support_voted'] = array_slice($voted, -200, null, true);
        return true;
    }
}

if (!function_exists('supportTenantProductKey')) {
    /** The signed-in company's product (bms_shop / bms_full / …) or null. */
    function supportTenantProductKey(): ?string
    {
        $tid = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
        if (!$tid) return null;
        try {
            $st = getControlPdo()->prepare('SELECT p.product_key FROM tenants t JOIN plans p ON p.plan_key = t.plan WHERE t.id = ?');
            $st->execute([$tid]);
            $k = $st->fetchColumn();
            return $k !== false ? (string)$k : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('supportLogSearch')) {
    /** Remember a finished search (zero-result ones tell BJP what to write). Once per query per 10 minutes per session. */
    function supportLogSearch(string $q, int $results): bool
    {
        $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $q)));
        if (mb_strlen($norm) < 2) return false;
        $norm = mb_substr($norm, 0, 255);
        $recent = array_filter($_SESSION['support_searched'] ?? [], fn($t) => $t > time() - 600);
        if (isset($recent[$norm])) return false;
        try {
            $tid = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
            getControlPdo()->prepare('INSERT INTO support_search_log (tenant_id, query, results_count, product_key) VALUES (?, ?, ?, ?)')
                ->execute([$tid, $norm, $results, supportTenantProductKey()]);
        } catch (Throwable $e) { error_log('supportLogSearch: ' . $e->getMessage()); return false; }
        $recent[$norm] = time();
        $_SESSION['support_searched'] = array_slice($recent, -50, null, true);
        return true;
    }
}

// ── Starter content (inserted once, as DRAFTS for BJP to review) ────────────

if (!function_exists('supportSeedDefaults')) {
    /** Idempotent: only seeds an empty table. Returns [categoriesAdded, articlesAdded]. */
    function supportSeedDefaults(PDO $pdo, string $db): array
    {
        $catAdded = 0; $artAdded = 0;
        $cats = [
            'start' => ['Getting started', 'Kuanza', 'rocket-takeoff', 10],
            'sell'  => ['Selling', 'Kuuza', 'cart-check', 20],
            'stock' => ['Products & stock', 'Bidhaa na stoo', 'box', 30],
            'shops' => ['{Shops}', '{Shops}', 'shop', 40],
            'people'=> ['Customers & suppliers', 'Wateja na wasambazaji', 'people', 50],
            'exp'   => ['Expenses', 'Matumizi', 'cash-stack', 60],
            'rep'   => ['Reports', 'Ripoti', 'graph-up', 70],
            'set'   => ['Settings & users', 'Mipangilio na watumiaji', 'gear', 80],
        ];
        $ids = [];
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`support_categories`")->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO `{$db}`.`support_categories` (name_en, name_sw, icon, sort_order) VALUES (?,?,?,?)");
            foreach ($cats as $k => $c) { $ins->execute($c); $ids[$k] = (int)$pdo->lastInsertId(); $catAdded++; }
        } else {
            foreach ($cats as $k => $c) {
                $st = $pdo->prepare("SELECT id FROM `{$db}`.`support_categories` WHERE name_en = ? LIMIT 1");
                $st->execute([$c[0]]);
                $ids[$k] = ($x = $st->fetchColumn()) ? (int)$x : null;
            }
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$db}`.`support_articles`")->fetchColumn() > 0) {
            return [$catAdded, 0];
        }
        $arts = [
            ['start', [], 'Find your way around',
             "The blue menu bar holds every area you can use. You only see the areas your role allows.\n\n1. Click a menu name to open it, then choose a page.\n2. Click **? Help** at any time for help with the page you are on.\n3. Your name at the top right holds My Profile & Settings, Help and Logout."],
            ['start', ['my_settings'], 'Change your password',
             "1. Open {nav:my_settings}.\n2. Open the password section and enter your current password, then the new one twice.\n3. Save.\n\nTip: Forgot your password? Ask your company admin to set a new one for you."],
            ['sell', ['pos'], 'Sell items and give a receipt',
             "1. Open {nav:pos}.\n2. Tap products to add them to the sale, or search by name or barcode.\n3. Choose the **Payment Method** and enter the amount received.\n4. Complete the sale and print or share the receipt."],
            ['sell', ['pos'], 'Sell on credit (on account)',
             "1. In {nav:pos}, choose the customer before completing the sale.\n2. Choose **Credit (on account)** as the payment method.\n3. Complete the sale. The amount is added to what the customer owes.\n\nTip: The customer must exist first. Add them in {nav:customers}."],
            ['sell', ['pos'], 'Return an item a customer brought back',
             "1. Open {nav:pos} and choose **Returns**.\n2. Find the sale and enter the quantity being returned.\n3. Confirm the refund.\n\nTip: Returned stock goes back to the {shop} it was sold from."],
            ['sell', ['pos'], 'Hold a sale and finish it later',
             "1. In {nav:pos}, add the items as usual.\n2. Choose **Hold Sale**. The cart is saved and cleared.\n3. Open the held sale when the customer is ready and complete it."],
            ['stock', ['products'], 'Add a product',
             "1. Open {nav:products} and choose **Add New Product**.\n2. Enter the name, buying price and selling price.\n3. Save. The product can now be sold in {nav:pos}."],
            ['stock', ['restock'], 'Receive new stock',
             "1. Open {nav:restock}.\n2. Choose the {shop} receiving the stock and add the products and quantities.\n3. Save. Stock levels update immediately."],
            ['shops', ['warehouses'], 'Add a new {shop}',
             "1. Open {nav:warehouses} and choose **Add New {Shop}**.\n2. Enter the name and location.\n3. Save. You can now keep stock in this {shop}."],
            ['shops', ['warehouses'], 'Move stock between {shops}',
             "1. Open {nav:warehouses} and choose **Transfer Stock**.\n2. Choose the {shop} sending the stock, the {shop} receiving it, and the products and quantities.\n3. Confirm the transfer."],
            ['people', ['customers'], 'Add a customer',
             "1. Open {nav:customers} and choose **Add New Customer**.\n2. Enter the name and phone number.\n3. Save. You can now choose this customer in {nav:pos}."],
            ['people', ['suppliers'], 'Add a supplier',
             "1. Open {nav:suppliers} and choose **Add New Supplier**.\n2. Enter the name and phone number.\n3. Save."],
            ['exp', ['expenses'], 'Record a business expense',
             "1. Open {nav:expenses} and choose **Add New Expense**.\n2. Choose the type, enter the amount and how it was paid.\n3. Save. It appears in the Expense Report."],
            ['rep', ['sales_report'], 'See your sales for a day or a month',
             "1. Open {nav:sales_report}.\n2. Choose the dates.\n3. Read the totals, or export the list."],
            ['rep', ['pos_profit_report'], 'See how much profit you made',
             "1. Open {nav:pos_profit_report}.\n2. Choose the period, e.g. this month.\n3. It shows what you sold, what those items cost you, and the profit."],
            ['set', ['system_settings'], 'Add a user and choose their role',
             "1. Open {nav:system_settings} and go to Users.\n2. Add the user's name, login and password.\n3. Choose a role. The role decides which menus they see.\n\nTip: Change what a role can do in Roles & Permissions on the same page."],
        ];
        $ins = $pdo->prepare("INSERT INTO `{$db}`.`support_articles` (category_id, title_en, body_en, page_keys, status) VALUES (?,?,?,?, 'draft')");
        foreach ($arts as [$ck, $keys, $title, $body]) {
            $ins->execute([$ids[$ck] ?? null, $title, $body, json_encode($keys)]);
            $artAdded++;
        }
        return [$catAdded, $artAdded];
    }
}

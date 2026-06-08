<?php
/**
 * core/ai_help.php
 * ----------------
 * Lightweight "how-to" knowledge base for the AI Assistant. Parses the system
 * user guide (docs/BUSINESS_MANAGEMENT_SYSTEM_GUIDE.md) into sections and does
 * keyword retrieval, so the assistant can answer usage/how-to questions
 * ("how do I create an invoice?", "where do I add a supplier?") grounded in the
 * real guide — never made up.
 *
 * Public API:
 *   aiHelpAvailable(): bool
 *   aiSearchHelp(string $query, int $k = 4): array  // [['title'=>..,'text'=>..,'score'=>..], …]
 */

if (!function_exists('aiHelpGuidePath')) {
    function aiHelpGuidePath(): ?string
    {
        $p = __DIR__ . '/../docs/BUSINESS_MANAGEMENT_SYSTEM_GUIDE.md';
        return is_file($p) ? $p : null;
    }
}

if (!function_exists('aiHelpAvailable')) {
    function aiHelpAvailable(): bool { return aiHelpGuidePath() !== null; }
}

if (!function_exists('aiHelpSections')) {
    /** Parse the guide into [{title, text}] sections, split at ## / ### headings. Cached per request. */
    function aiHelpSections(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $path = aiHelpGuidePath();
        if ($path === null) return $cache;

        $lines = preg_split('/\r\n|\r|\n/', (string)file_get_contents($path));
        $title = 'Overview';
        $buf = [];
        $flush = function () use (&$cache, &$title, &$buf) {
            $text = trim(implode("\n", $buf));
            if ($text !== '') $cache[] = ['title' => $title, 'text' => $text];
            $buf = [];
        };
        foreach ($lines as $ln) {
            if (preg_match('/^#{2,3}\s+(.*)$/', $ln, $m)) {   // new ## or ### section
                $flush();
                $title = trim(preg_replace('/[#*`]/', '', $m[1]));
            } else {
                $buf[] = $ln;
            }
        }
        $flush();
        return $cache;
    }
}

if (!function_exists('aiSearchHelp')) {
    /**
     * Keyword-retrieval over the guide. Returns the top-$k sections most relevant
     * to $query (title matches weighted higher). Each section's text is trimmed
     * to a sane length so prompts stay small.
     */
    function aiSearchHelp(string $query, int $k = 4): array
    {
        $sections = aiHelpSections();
        if (!$sections) return [];

        $stop = ['the','a','an','to','how','do','i','can','my','in','on','of','for','is','are','what','where','and','it','this','with','add','create','make','new','use','using'];
        $terms = array_filter(preg_split('/[^a-z0-9]+/', strtolower($query)), function ($w) use ($stop) {
            return strlen($w) >= 3 && !in_array($w, $stop, true);
        });
        $terms = array_values(array_unique($terms));
        if (!$terms) return [];

        $scored = [];
        foreach ($sections as $i => $sec) {
            $titleL = strtolower($sec['title']);
            $bodyL  = strtolower($sec['text']);
            $score = 0;
            foreach ($terms as $t) {
                $score += substr_count($titleL, $t) * 5;          // title hits weigh most
                $score += min(substr_count($bodyL, $t), 6);        // capped body hits
            }
            if ($score > 0) $scored[] = ['title' => $sec['title'], 'text' => $sec['text'], 'score' => $score];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, max(1, $k));
        // Trim each section so the combined help context stays compact.
        foreach ($top as &$s) {
            if (mb_strlen($s['text']) > 1200) $s['text'] = mb_substr($s['text'], 0, 1200) . '…';
        }
        return $top;
    }
}

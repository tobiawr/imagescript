<?php
/** Shared workspace shell. Keep this file, workspace.css and workspace.js in sync in both apps. */
function workspaceEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function workspaceIcon(string $name): string {
    $paths = [
        'book' => '<path d="M12 7C9 5 5 5 3 6v14c3-1 6-1 9 1 3-2 6-2 9-1V6c-2-1-6-1-9 1v14"/>',
        'review' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 13l3 3 5-6"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/>',
        'status' => '<path d="M4 20V10m8 10V4m8 16v-7"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'arrow' => '<path d="m9 5 7 7-7 7"/>',
        'folder' => '<path d="M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['book']) . '</svg>';
}

function workspaceHeader(string $view, string $language, array $languages): void {
    $translationBase = rtrim(getenv('GBS_TRANSLATE_URL') ?: 'https://translate.globalbibleschool.org', '/');
    $assetsBase = rtrim(getenv('GBS_ASSETS_URL') ?: 'https://assets.globalbibleschool.org', '/');
    $isTranslation = in_array($view, ['lesson', 'translation'], true);
    $items = [
        'lesson' => ['Lessons', 'book', $isTranslation ? '' : $translationBase . '/'],
        'translation' => ['Translation review', 'review', $isTranslation ? '' : $translationBase . '/'],
        'gallery' => ['Asset library', 'image', $isTranslation ? $assetsBase . '/' : ''],
        'status' => ['Asset status', 'status', $isTranslation ? $assetsBase . '/' : ''],
    ];
    echo '<a class="skip-link" href="#main-content">Skip to content</a><header class="workspace-header"><div class="header-inner">';
    echo '<a class="brand" href="' . workspaceEscape($items['lesson'][2] . '?tab=lesson&lang=' . rawurlencode($language)) . '" title="Global Bible School">GBS</a>';
    echo '<nav class="workspace-nav" aria-label="Main navigation">';
    foreach ($items as $key => [$label, $icon, $base]) {
        echo '<a href="' . workspaceEscape($base . '?' . http_build_query(['tab' => $key, 'lang' => $language])) . '"' . ($key === $view ? ' aria-current="page"' : '') . '><span>' . $label . '</span></a>';
    }
    echo '</nav><form method="get" class="workspace-language"><input type="hidden" name="tab" value="' . workspaceEscape($view) . '"><label for="workspace-language"><span class="sr-only">Language</span></label><select id="workspace-language" name="lang" onchange="this.form.submit()">';
    if ($language === '') {
        echo '<option value="">Original assets</option>';
    }
    foreach ($languages as $code => $label) {
        echo '<option value="' . workspaceEscape($code) . '"' . ($code === $language ? ' selected' : '') . '>' . workspaceEscape($label) . '</option>';
    }
    echo '</select><noscript><button type="submit">Apply</button></noscript></form></div></header>';
}

function workspaceToolbar(string $placeholder, bool $expand = false): void {
    echo '<div class="toolbar"><label class="search-field">' . workspaceIcon('search') . '<span class="sr-only">' . workspaceEscape($placeholder) . '</span><input type="search" id="workspace-search" placeholder="' . workspaceEscape($placeholder) . '" autocomplete="off"></label><div class="toolbar-actions"><span id="result-count" role="status"></span>';
    if ($expand) echo '<button type="button" class="button subtle" id="expand-all">Expand all</button>';
    echo '</div></div><div class="empty-state" id="no-results" hidden><strong>No matches found</strong><p>Try a different name or clear your search.</p><button type="button" class="button" id="clear-search">Clear search</button></div>';
}

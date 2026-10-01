<?php
// Array of three-letter language codes to hide as separate folders
$languageCodes = [
    'eng', 'mlg', 'spa', 'deu', 'fra', 'ita', 'por', 'rus', 'chi', 'jpn', 'kor',
    'ara', 'hin', 'ben', 'urd', 'ind', 'tur', 'per', 'tha', 'vie', 'msa',
    'tam', 'tel', 'mar', 'guj', 'kan', 'mal', 'ori', 'pan', 'asm', 'mai',
    'nep', 'sin', 'bur', 'khm', 'lao', 'mon', 'tib', 'uig', 'kaz', 'kir',
    'tjk', 'tkm', 'uzb', 'aze', 'geo', 'arm', 'bel', 'ukr', 'bul', 'mac',
    'slo', 'cze', 'pol', 'hun', 'alb', 'bos', 'hrv', 'srp', 'slv', 'rom',
    'mol', 'gre', 'heb', 'yid', 'ara', 'fas', 'kur', 'pus', 'snd', 'bal',
    'bra', 'kok', 'mni', 'san', 'bho', 'awa', 'bjj', 'mag', 'mai', 'bho',
    'new', 'bih', 'bho', 'dhi', 'dot', 'kha', 'khn', 'khr', 'kjp', 'kdt',
    'khm', 'kha', 'kjg', 'krr', 'kdt', 'khm', 'krr', 'kxv', 'kha', 'kjp',
    'kdt', 'khm', 'krr', 'kxv', 'kha', 'kjp', 'kdt', 'khm', 'krr', 'kxv'
];

// Base directory to scan for images
$baseDir = __DIR__ . '/images'; // Change this to the path of your images folder

// Base URL for the images
$baseUrl = getenv('APP_BASE_URL') ?: '/images/';
// $baseUrl = 'http://images.gbs.adventistinbox.org/';

// Function to get top-level folders
function getTopLevelFolders($dir) {
    $folders = [];
    if (!is_dir($dir)) {
        return $folders;
    }
    
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $folders[] = $item->getFilename();
        }
    }
    return $folders;
}

function getAllowedExtensions() {
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'epub', 'zip'];
}

function isPreviewableExtension($extension) {
    return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function getAbsoluteImageUrl($baseUrl, $path) {
    $normalizedBase = trim($baseUrl);
    if ($normalizedBase === '') {
        return '/' . ltrim($path, '/');
    }

    if (preg_match('#^https?://#i', $normalizedBase) || strpos($normalizedBase, '//') === 0) {
        return rtrim($normalizedBase, '/') . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/' . ltrim(rtrim($normalizedBase, '/') . '/' . ltrim($path, '/'), '/');
}

// Function to get subfolders for a specific top-level folder
function getSubfoldersForFolder($baseDir, $folder) {
    $subfolders = [];
    $folderPath = $baseDir . '/' . $folder;
    
    if (!is_dir($folderPath)) {
        return $subfolders;
    }
    
    $iterator = new DirectoryIterator($folderPath);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $subfolderName = $item->getFilename();
            // Skip language folders identified by 3-letter codes (e.g. eng, mlg)
            if (!preg_match('/^[a-z]{3}$/i', $subfolderName)) {
                $subfolders[] = $subfolderName;
            }
        }
    }
    // Natural sort so names like ubp-1, ubp-2, ubp-10 sort as expected
    usort($subfolders, 'strnatcasecmp');
    
    return $subfolders;
}

// Function to get images for a specific folder path
function getImagesInFolder($folderPath, $baseUrl) {
    $images = [];
    
    if (!is_dir($folderPath)) {
        return $images;
    }
    
    $iterator = new DirectoryIterator($folderPath);
    foreach ($iterator as $item) {
        if ($item->isFile() && in_array(strtolower($item->getExtension()), getAllowedExtensions(), true)) {
            $images[] = $item->getFilename();
        }
    }
    
    return $images;
}

// Function to get language-specific images for a specific subfolder
function getLangSpecificImagesForSubfolder($baseDir, $folder, $subfolder) {
    $langImages = [];
    $subfolderPath = $baseDir . '/' . $folder . '/' . $subfolder;

    if (!is_dir($subfolderPath)) {
        return $langImages;
    }

    // Detect language subfolders by scanning directories and matching 3-letter names
    $iterator = new DirectoryIterator($subfolderPath);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $name = $item->getFilename();
            if (preg_match('/^[a-z]{3}$/i', $name)) {
                $langPath = $subfolderPath . '/' . $name;
                $fileIter = new DirectoryIterator($langPath);
                foreach ($fileIter as $file) {
                    if ($file->isFile() && in_array(strtolower($file->getExtension()), getAllowedExtensions(), true)) {
                        $langImages[$name][] = $folder . '/' . $subfolder . '/' . $name . '/' . $file->getFilename();
                    }
                }
            }
        }
    }

    return $langImages;
}

// Get the top-level folders
$topLevelFolders = getTopLevelFolders($baseDir);
usort($topLevelFolders, 'strnatcasecmp');

// Function to get all language folders in a subfolder
function getLanguageFoldersInSubfolder($baseDir, $folder, $subfolder) {
    $languages = [];
    $subfolderPath = $baseDir . '/' . $folder . '/' . $subfolder;
    
    if (!is_dir($subfolderPath)) {
        return $languages;
    }
    
    $iterator = new DirectoryIterator($subfolderPath);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $name = $item->getFilename();
            if (preg_match('/^[a-z]{3}$/i', $name)) {
                $languages[] = $name;
            }
        }
    }
    
    sort($languages);
    return $languages;
}

// Function to get images for a specific language folder
function getImagesInLanguageFolder($folderPath) {
    $images = [];
    
    if (!is_dir($folderPath)) {
        return $images;
    }
    
    $iterator = new DirectoryIterator($folderPath);
    foreach ($iterator as $item) {
        if ($item->isFile() && in_array(strtolower($item->getExtension()), getAllowedExtensions(), true)) {
            $images[] = $item->getFilename();
        }
    }
    
    sort($images);
    return $images;
}

function getAvailableLanguages($baseDir, $topLevelFolders) {
    $languages = [];

    foreach ($topLevelFolders as $folder) {
        $subfolders = getSubfoldersForFolder($baseDir, $folder);
        foreach ($subfolders as $subfolder) {
            $subfolderPath = $baseDir . '/' . $folder . '/' . $subfolder;
            if (!is_dir($subfolderPath)) {
                continue;
            }

            $iterator = new DirectoryIterator($subfolderPath);
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isDot()) {
                    $name = $item->getFilename();
                    if (preg_match('/^[a-z]{3}$/i', $name)) {
                        $languages[$name] = true;
                    }
                }
            }
        }
    }

    $result = array_keys($languages);
    sort($result);
    return $result;
}

// Get current tab from query parameter
$currentTab = ($_GET['tab'] ?? '') === 'status' ? 'status' : 'gallery';
$requestedLanguage = isset($_GET['lang']) ? strtolower(trim($_GET['lang'])) : '';
$availableLanguages = getAvailableLanguages($baseDir, $topLevelFolders);

if (in_array($requestedLanguage, $availableLanguages, true)) {
    $currentLanguage = $requestedLanguage;
} elseif (in_array('eng', $availableLanguages, true)) {
    $currentLanguage = 'eng';
} else {
    $currentLanguage = '';
}

require_once __DIR__ . '/workspace.php';
$languageNames = ['afr'=>'Afrikaans','eng'=>'English','fra'=>'French','grc'=>'Ancient Greek','hin'=>'Hindi','hun'=>'Hungarian','ita'=>'Italian','lus'=>'Mizo','mlg'=>'Malagasy','mni'=>'Manipuri','nep'=>'Nepali','nld'=>'Dutch','por'=>'Portuguese','rus'=>'Russian','spa'=>'Spanish','tgl'=>'Tagalog','tur'=>'Turkish','ukr'=>'Ukrainian','deu'=>'German'];
$languageLabels = [];
foreach ($availableLanguages as $code) $languageLabels[$code] = $languageNames[$code] ?? strtoupper($code);
$collectionNames = ['atn'=>'All Things New','gcbook'=>'Great Controversy Book','ubp'=>'Unlock Bible Prophecy','ubp2'=>'Unlocking Bible Prophecies','trc'=>'Quiz Tract','tlw'=>'Thinking & Living Well','kg'=>'Knowing God','files'=>'Documents & downloads','logo'=>'Brand & logos','website'=>'Website assets','selector'=>'Starter automations'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $currentTab === 'gallery' ? 'Asset library' : 'Asset status' ?> · Global Bible School</title>
    <link rel="stylesheet" href="workspace.css?v=2">
    <script src="workspace.js?v=2" defer></script>
</head>
<body>
<?php workspaceHeader($currentTab, $currentLanguage, $languageLabels); ?>
<main class="workspace-main" id="main-content">
<h1 class="sr-only"><?= $currentTab === 'gallery' ? 'Asset library' : 'Asset status' ?></h1>
<?php
workspaceToolbar($currentTab === 'gallery' ? 'Search collections or filenames…' : 'Search image names or folders…', $currentTab === 'gallery');
if (!$topLevelFolders): ?>
<div class="empty-state"><strong>Your asset library is ready</strong><p>Assets will appear here when the image folders have been synced.</p></div>
<?php endif; ?>
<?php if ($currentTab === 'gallery'): ?>
    <p class="helper">Image: preview · Filename: copy link</p>
    <section id="gallery-tab" aria-label="Asset collections">
    <?php foreach ($topLevelFolders as $folder): ?>
        <?php
        $subfolders = getSubfoldersForFolder($baseDir, $folder);
        $folderPath = $baseDir . '/' . $folder;
        $folderImages = getImagesInFolder($folderPath, $baseUrl);
        ?>

        <div class="image-section" data-search-group>
            <button type="button" class="section-heading" data-disclosure aria-expanded="true" aria-controls="folder-<?= workspaceEscape($folder) ?>">
                <span class="card-label"><strong><?= workspaceEscape($collectionNames[$folder] ?? $folder) ?></strong><small><?= workspaceEscape($folder) ?></small></span>
                <span class="chevron"><?= workspaceIcon('arrow') ?></span>
            </button>
            <ul class="image-gallery" id="folder-<?= workspaceEscape($folder) ?>">
                <?php if (!$folderImages && !$subfolders): ?><li class="helper">No assets in this collection yet.</li><?php endif; ?>
                <!-- Images directly in the main folder -->
                <?php foreach ($folderImages as $image):
                    $url = getAbsoluteImageUrl($baseUrl, $folder . '/' . $image);
                    $extension = getFileExtension($image);
                    $previewable = isPreviewableExtension($extension);
                ?>
                    <li class="image-item" data-search-item data-search="<?= workspaceEscape(($collectionNames[$folder] ?? $folder) . ' ' . $folder . ' ' . $image) ?>">
                        <?php if ($previewable): ?>
                            <button type="button" class="preview-button" aria-label="Preview <?= workspaceEscape($image) ?>"><img loading="lazy" src="<?php echo htmlspecialchars($url); ?>" alt="<?php echo htmlspecialchars($image); ?>" data-copy-url="<?php echo htmlspecialchars($url); ?>"></button>
                        <?php else: ?>
                            <div class="file-placeholder"><?php echo strtoupper(htmlspecialchars($extension)); ?></div>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($url); ?>" title="<?php echo htmlspecialchars($image); ?>">
                            <?php echo htmlspecialchars($image); ?>
                        </a>
                    </li>
                <?php endforeach; ?>

                <!-- Subfolders -->
                <?php foreach ($subfolders as $subfolder): ?>
                    <?php
                        $subfolderPath = $folderPath . '/' . $subfolder;
                        $rootImages = getImagesInFolder($subfolderPath, $baseUrl);
                        $displayImages = [];
                        $selectedLanguageImages = [];
                        $hasSelectedLanguageImages = false;

                        foreach ($rootImages as $image) {
                            $displayImages[$image] = [
                                'name' => $image,
                                'label' => $image,
                                'isLanguageImage' => false,
                            ];
                        }

                        if ($currentLanguage !== '') {
                            $selectedLanguageImages = getImagesInLanguageFolder($subfolderPath . '/' . $currentLanguage);
                            $hasSelectedLanguageImages = !empty($selectedLanguageImages);

                            if ($hasSelectedLanguageImages) {
                                foreach ($selectedLanguageImages as $image) {
                                    $displayImages[$image] = [
                                        'name' => $image,
                                        'label' => $image . ' (' . $currentLanguage . ')',
                                        'isLanguageImage' => true,
                                    ];
                                }
                            }
                        }

                        $displayImages = array_values($displayImages);
                        usort($displayImages, function ($a, $b) {
                            return strnatcasecmp($a['name'], $b['name']);
                        });
                    ?>
                    <li class="subfolder-section" data-search-group>
                        <button type="button" class="subfolder-heading" data-disclosure aria-expanded="true" aria-controls="subfolder-<?= workspaceEscape($folder . '-' . $subfolder) ?>">
                            <span><?= workspaceEscape($subfolder) ?> <span class="count-tag"><?= count($displayImages) ?> files</span></span>
                            <span class="chevron"><?= workspaceIcon('arrow') ?></span>
                        </button>
                        <ul class="subfolder-gallery" id="subfolder-<?= workspaceEscape($folder . '-' . $subfolder) ?>">
                            <?php if (!$displayImages): ?><li class="helper">No assets in this folder for the selected language.</li><?php endif; ?>
                            <?php foreach ($displayImages as $imageData):
                                $image = $imageData['name'];
                                $extension = getFileExtension($image);
                                $label = $imageData['label'];

                                $languagePath = '';
                                if ($currentLanguage !== '') {
                                    $languageFilePath = $baseDir . '/' . $folder . '/' . $subfolder . '/' . $currentLanguage . '/' . $image;
                                    if (file_exists($languageFilePath) || $imageData['isLanguageImage']) {
                                        $languagePath = $currentLanguage;
                                    }
                                }

                                if ($languagePath !== '') {
                                    $previewUrl = getAbsoluteImageUrl($baseUrl, $folder . '/' . $subfolder . '/' . $currentLanguage . '/' . $image);
                                    $linkUrl = getAbsoluteImageUrl($baseUrl, $folder . '/' . $subfolder . '/_lang_/' . $image);
                                } else {
                                    $previewUrl = getAbsoluteImageUrl($baseUrl, $folder . '/' . $subfolder . '/' . $image);
                                    $linkUrl = $previewUrl;
                                }
                                $previewable = isPreviewableExtension($extension);
                            ?>
                                <li class="image-item" data-search-item data-search="<?= workspaceEscape(($collectionNames[$folder] ?? $folder) . ' ' . $folder . ' ' . $subfolder . ' ' . $label) ?>">
                                    <?php if ($previewable): ?>
                                        <button type="button" class="preview-button" aria-label="Preview <?= workspaceEscape($label) ?>"><img loading="lazy" src="<?php echo htmlspecialchars($previewUrl); ?>" alt="<?php echo htmlspecialchars($label); ?>" data-copy-url="<?php echo htmlspecialchars($linkUrl); ?>"></button>
                                    <?php else: ?>
                                        <div class="file-placeholder"><?php echo strtoupper(htmlspecialchars($extension)); ?></div>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($linkUrl); ?>" title="<?php echo htmlspecialchars($label); ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    </section>
<?php else: ?>
    <div class="status-legend"><span><span class="status-present" aria-hidden="true">✓</span> Available</span><span><span class="status-missing" aria-hidden="true">–</span> Missing</span></div>
    <section id="status-tab" aria-label="Asset status">
    <?php $statusRowCount = 0; ?>
    <?php foreach ($topLevelFolders as $folder): ?>
        <section data-search-group>
        <h2 class="top-level-title"><?= workspaceEscape($collectionNames[$folder] ?? $folder) ?></h2>
        <?php
            $subfolders = getSubfoldersForFolder($baseDir, $folder);
            foreach ($subfolders as $subfolder):
                $subfolderPath = $baseDir . '/' . $folder . '/' . $subfolder;
                $engPath = $subfolderPath . '/eng';
                $engImages = getImagesInLanguageFolder($engPath);
                
                if (empty($engImages)) {
                    continue;
                }
                
                $allLanguages = getLanguageFoldersInSubfolder($baseDir, $folder, $subfolder);
                $otherLanguages = array_diff($allLanguages, ['eng']);
                $otherLanguages = array_values($otherLanguages);
                sort($otherLanguages);
        ?>
            <section class="status-group" data-search-group>
            <h3 class="subfolder-title"><?php echo htmlspecialchars($subfolder); ?></h3>
            <div class="table-scroll" tabindex="0" role="region" aria-label="<?= workspaceEscape($subfolder) ?> asset status">
            <table>
                <thead>
                    <tr>
                        <th scope="col">English original</th>
                        <?php foreach ($otherLanguages as $lang): ?>
                            <th scope="col"><?= workspaceEscape($languageNames[$lang] ?? strtoupper($lang)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($engImages as $image): $statusRowCount++; ?>
                        <tr data-search-item data-search="<?= workspaceEscape(($collectionNames[$folder] ?? $folder) . ' ' . $folder . ' ' . $subfolder . ' ' . $image) ?>">
                            <td><?php echo htmlspecialchars($image); ?></td>
                            <?php foreach ($otherLanguages as $lang): ?>
                                <?php
                                    $langPath = $subfolderPath . '/' . $lang . '/' . $image;
                                    $exists = file_exists($langPath);
                                ?>
                                <td>
                                    <?php if ($exists): ?>
                                        <span class="status-present" role="img" aria-label="Available" title="Available">✓</span>
                                    <?php else: ?>
                                        <span class="status-missing" role="img" aria-label="Missing" title="Missing">–</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div></section>
        <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($topLevelFolders && !$statusRowCount): ?><div class="empty-state"><strong>No English reference assets yet</strong><p>Status comparisons will appear when English source files are available.</p></div><?php endif; ?>
    </section>
<?php endif; ?>
</main>


<div id="lightbox" class="lightbox" role="dialog" aria-modal="true" aria-labelledby="lightbox-title">
    <div class="lightbox-panel"><div class="lightbox-toolbar"><strong id="lightbox-title">Asset preview</strong><button type="button" class="button primary" id="copy-preview">Copy asset link</button><button type="button" class="close" id="close-preview" aria-label="Close preview">&times;</button></div><img id="lightbox-img" alt=""></div>
</div>
<div id="copy-dialog" class="modal" role="dialog" aria-modal="true" aria-labelledby="copy-title"><div class="modal-content"><button type="button" class="close" id="close-copy" aria-label="Close copy link">&times;</button><h2 id="copy-title">Copy asset link</h2><p>Automatic copying is unavailable. Select and copy this link.</p><input id="copy-url" aria-label="Asset link" readonly style="width:100%;padding:12px"></div></div>
<div class="toast" id="workspace-toast" role="status" hidden></div>
</body>
</html>

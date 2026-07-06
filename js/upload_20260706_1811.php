<?php
declare(strict_types=1);

/**
 * /js/upload.php
 *
 * Handles secure admin uploads, deletion of the slogan text file,
 * and provides a public manifest for index.html.
 *
 * Directory structure:
 *   /index.html
 *   /js/upload.php
 *   /images
 *   /documents
 */

header('Content-Type: application/json; charset=utf-8');

const ADMIN_UPLOAD_TOKEN = "RetzneiUpload_2026";
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // 15 MB

$rootDir = realpath(__DIR__ . '/..');
if ($rootDir === false) {
    jsonResponse(false, 'Server root not found.', [], 500);
}

$imageDir = $rootDir . DIRECTORY_SEPARATOR . 'images';
$documentDir = $rootDir . DIRECTORY_SEPARATOR . 'documents';
$manifestPath = $documentDir . DIRECTORY_SEPARATOR . 'site-content.json';

$slots = [
    // Images
    'hero_desktop' => [
        'directory' => 'images',
        'target_name' => 'bergbad_retznei_desktop',
        'kind' => 'image',
        'extensions' => ['png', 'jpg', 'jpeg'],
    ],
    'hero_mobile' => [
        'directory' => 'images',
        'target_name' => 'bergbad_retznei_mobile',
        'kind' => 'image',
        'extensions' => ['png', 'jpg', 'jpeg'],
    ],
    'poolparty' => [
        'directory' => 'images',
        'target_name' => 'poolparty',
        'kind' => 'image',
        'extensions' => ['png', 'jpg', 'jpeg'],
    ],
    'gallery' => [
        'directory' => 'images',
        'target_name' => null,
        'kind' => 'image',
        'extensions' => ['png', 'jpg', 'jpeg'],
    ],

    // Documents
    'slogan' => [
        'directory' => 'documents',
        'target_name' => 'slogan_text',
        'kind' => 'text',
        'extensions' => ['txt'],
    ],
    'speisekarte' => [
        'directory' => 'documents',
        'target_name' => 'speisekarte',
        'kind' => 'pdf',
        'extensions' => ['pdf'],
    ],
    'tarife' => [
        'directory' => 'documents',
        'target_name' => 'tarife',
        'kind' => 'pdf',
        'extensions' => ['pdf'],
    ],
    'sport_und_erholung' => [
        'directory' => 'documents',
        'target_name' => 'sport_und_erholung',
        'kind' => 'pdf',
        'extensions' => ['pdf'],
    ],
    'kursangebote' => [
        'directory' => 'documents',
        'target_name' => 'kursangebote',
        'kind' => 'pdf',
        'extensions' => ['pdf'],
    ],
    'events' => [
        'directory' => 'documents',
        'target_name' => 'events',
        'kind' => 'pdf',
        'extensions' => ['pdf'],
    ],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

if ($action === 'manifest') {
    $manifest = loadManifest($manifestPath);
    enrichManifest($manifest, $documentDir);
    jsonResponse(true, 'Manifest loaded.', ['manifest' => $manifest]);
}

if (!in_array($action, ['upload', 'delete'], true)) {
    jsonResponse(false, 'Invalid action.', [], 400);
}

$token = $_POST['token'] ?? '';
if (!hash_equals(ADMIN_UPLOAD_TOKEN, $token)) {
    jsonResponse(false, 'Unauthorized request.', [], 401);
}

$slot = $_POST['slot'] ?? '';
if (!isset($slots[$slot])) {
    jsonResponse(false, 'Invalid slot.', [], 400);
}

$config = $slots[$slot];

if ($action === 'delete') {
    if ($slot !== 'slogan') {
        jsonResponse(false, 'Delete is currently only enabled for the slogan text file.', [], 400);
    }

    ensureDirectory($documentDir);

    // Delete canonical and legacy names. This prevents stale behavior after renaming.
    $deleted = false;
    foreach (['slogan_text.txt', 'SLOGAN_TEXT.txt'] as $fileName) {
        $path = $documentDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($path)) {
            $deleted = @unlink($path) || $deleted;
        }
    }

    $manifest = loadManifest($manifestPath);
    unset($manifest['files']['slogan']);
    $manifest['sloganText'] = '';
    $manifest['version'] = time();

    saveManifest($manifestPath, $manifest);
    enrichManifest($manifest, $documentDir);

    jsonResponse(true, $deleted ? 'Slogan text file deleted.' : 'No slogan text file existed.', [
        'slot' => $slot,
        'manifest' => $manifest,
    ]);
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    jsonResponse(false, 'No uploaded file received.', [], 400);
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'Upload failed with error code: ' . $file['error'], [], 400);
}

if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_BYTES) {
    jsonResponse(false, 'File size is invalid or exceeds the limit.', [], 400);
}

$originalName = $file['name'] ?? '';
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($extension === 'jpeg') {
    $extension = 'jpg';
}

if (!in_array($extension, $config['extensions'], true)) {
    jsonResponse(false, 'Invalid file extension for this upload field.', [], 400);
}

$tmpPath = $file['tmp_name'];
validateUploadedFile($tmpPath, $config['kind'], $extension);

$targetDirectory = $config['directory'] === 'images' ? $imageDir : $documentDir;
ensureDirectory($targetDirectory);

if ($config['target_name'] === null) {
    $safeBase = sanitizeFilename(pathinfo($originalName, PATHINFO_FILENAME));
    $targetFile = 'gallery_' . $safeBase . '.' . $extension;
} else {
    $targetFile = $config['target_name'] . '.' . $extension;
}

$targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $targetFile;
$publicPath = $config['directory'] . '/' . $targetFile;

// Remove older canonical file with different extension, e.g. poolparty.png -> poolparty.jpg.
if ($config['target_name'] !== null) {
    foreach ($config['extensions'] as $oldExt) {
        $oldExt = $oldExt === 'jpeg' ? 'jpg' : $oldExt;
        $oldPath = $targetDirectory . DIRECTORY_SEPARATOR . $config['target_name'] . '.' . $oldExt;
        if ($oldPath !== $targetPath && is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
}

// Slogan legacy cleanup: if the older uppercase file exists, remove it.
if ($slot === 'slogan') {
    $legacyPath = $documentDir . DIRECTORY_SEPARATOR . 'SLOGAN_TEXT.txt';
    if ($legacyPath !== $targetPath && is_file($legacyPath)) {
        @unlink($legacyPath);
    }
}

if (is_file($targetPath)) {
    @unlink($targetPath);
}

if (!move_uploaded_file($tmpPath, $targetPath)) {
    jsonResponse(false, 'Could not save uploaded file. Check folder permissions.', [], 500);
}

@chmod($targetPath, 0644);

$manifest = loadManifest($manifestPath);

if ($slot === 'gallery') {
    $galleryItem = [
        'id' => sha1($publicPath),
        'path' => $publicPath,
        'title' => pathinfo($targetFile, PATHINFO_FILENAME),
        'type' => 'image',
        'updatedAt' => date(DATE_ATOM),
    ];

    $manifest['gallery'] = array_values(array_filter(
        $manifest['gallery'] ?? [],
        static fn($item) => ($item['path'] ?? '') !== $publicPath
    ));

    $manifest['gallery'][] = $galleryItem;
} else {
    $manifest['files'][$slot] = [
        'path' => $publicPath,
        'type' => $config['kind'] === 'image' ? 'image' : 'document',
        'updatedAt' => date(DATE_ATOM),
    ];
}

$manifest['version'] = time();

saveManifest($manifestPath, $manifest);
enrichManifest($manifest, $documentDir);

jsonResponse(true, 'File uploaded successfully.', [
    'slot' => $slot,
    'path' => $publicPath,
    'manifest' => $manifest,
]);

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            jsonResponse(false, 'Could not create directory: ' . basename($directory), [], 500);
        }
    }

    /*
     * Windows/XAMPP:
     * is_writable() may be misleading. A real write test is more reliable.
     */
    $testFile = $directory . DIRECTORY_SEPARATOR . '.write_test_' . uniqid('', true) . '.tmp';
    $writeResult = @file_put_contents($testFile, 'test');

    if ($writeResult === false) {
        jsonResponse(false, 'Directory write test failed: ' . basename($directory), [], 500);
    }

    @unlink($testFile);
}

function loadManifest(string $manifestPath): array
{
    $default = [
        'version' => time(),
        'files' => [
            'hero_desktop' => ['path' => 'images/bergbad_retznei_desktop.png', 'type' => 'image'],
            'hero_mobile' => ['path' => 'images/bergbad_retznei_mobile.png', 'type' => 'image'],
            'poolparty' => ['path' => 'images/poolparty.png', 'type' => 'image'],
            'slogan' => ['path' => 'documents/slogan_text.txt', 'type' => 'document'],
            'speisekarte' => ['path' => 'documents/speisekarte.pdf', 'type' => 'document'],
            'tarife' => ['path' => 'documents/tarife.pdf', 'type' => 'document'],
            'sport_und_erholung' => ['path' => 'documents/sport_und_erholung.pdf', 'type' => 'document'],
            'kursangebote' => ['path' => 'documents/kursangebote.pdf', 'type' => 'document'],
            'events' => ['path' => 'documents/events.pdf', 'type' => 'document'],
        ],
        'gallery' => [],
        'sloganText' => '',
    ];

    if (!is_file($manifestPath)) {
        return $default;
    }

    $json = file_get_contents($manifestPath);
    $data = json_decode((string)$json, true);

    if (!is_array($data)) {
        return $default;
    }

    return array_replace_recursive($default, $data);
}

function saveManifest(string $manifestPath, array $manifest): void
{
    ensureDirectory(dirname($manifestPath));

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        jsonResponse(false, 'Could not encode manifest.', [], 500);
    }

    if (file_put_contents($manifestPath, $json, LOCK_EX) === false) {
        jsonResponse(false, 'Could not write manifest.', [], 500);
    }

    @chmod($manifestPath, 0644);
}

function enrichManifest(array &$manifest, string $documentDir): void
{
    // Prefer the manifest path, but also support the previous uppercase filename.
    $candidatePaths = [];

    if (!empty($manifest['files']['slogan']['path'])) {
        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $manifest['files']['slogan']['path']);
        $candidatePaths[] = realpath(dirname($documentDir) . DIRECTORY_SEPARATOR . $relativePath) ?: dirname($documentDir) . DIRECTORY_SEPARATOR . $relativePath;
    }

    $candidatePaths[] = $documentDir . DIRECTORY_SEPARATOR . 'slogan_text.txt';
    $candidatePaths[] = $documentDir . DIRECTORY_SEPARATOR . 'SLOGAN_TEXT.txt';

    $manifest['sloganText'] = '';

    foreach ($candidatePaths as $sloganPath) {
        if (is_file($sloganPath)) {
            $text = file_get_contents($sloganPath);
            $trimmed = trim((string)$text);

            if ($trimmed !== '') {
                $manifest['sloganText'] = $trimmed;
                $manifest['files']['slogan'] = [
                    'path' => 'documents/' . basename($sloganPath),
                    'type' => 'document',
                ];
                return;
            }
        }
    }

    unset($manifest['files']['slogan']);
}

function validateUploadedFile(string $tmpPath, string $kind, string $extension): void
{
    if ($kind === 'image') {
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            jsonResponse(false, 'Uploaded file is not a valid image.', [], 400);
        }

        $allowedMime = ['image/jpeg', 'image/png'];
        if (!in_array($imageInfo['mime'], $allowedMime, true)) {
            jsonResponse(false, 'Only JPG and PNG images are allowed.', [], 400);
        }

        return;
    }

    if ($kind === 'pdf') {
        $handle = fopen($tmpPath, 'rb');
        $header = $handle ? fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($extension !== 'pdf' || $header !== '%PDF') {
            jsonResponse(false, 'Uploaded file is not a valid PDF.', [], 400);
        }

        return;
    }

    if ($kind === 'text') {
        if ($extension !== 'txt') {
            jsonResponse(false, 'Only TXT files are allowed for the slogan.', [], 400);
        }

        $sample = file_get_contents($tmpPath, false, null, 0, 65536);
        if ($sample === false || str_contains($sample, "\0")) {
            jsonResponse(false, 'Uploaded TXT file is invalid.', [], 400);
        }

        return;
    }

    jsonResponse(false, 'Unsupported file type.', [], 400);
}

function sanitizeFilename(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $name);
    $name = trim((string)$name, '_-');

    if ($name === '') {
        $name = 'upload_' . time();
    }

    return substr($name, 0, 80);
}

function jsonResponse(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

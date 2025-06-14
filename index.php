<?php
// Require Composer autoload
require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env file
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

// Configuration from .env
$debug = filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN);
$language = $_ENV['APP_LANGUAGE'];
$filesPath = rtrim($_ENV['FILES_PATH'], '/');
$displayReadmes = filter_var($_ENV['DISPLAY_READMES'], FILTER_VALIDATE_BOOLEAN);
$zipDownloads = filter_var($_ENV['ZIP_DOWNLOADS'], FILTER_VALIDATE_BOOLEAN);
$sortOrder = in_array($_ENV['SORT_ORDER'], ['name', 'size', 'time', 'type']) ? $_ENV['SORT_ORDER'] : 'name';
$reverseSort = filter_var($_ENV['REVERSE_SORT'], FILTER_VALIDATE_BOOLEAN);
$hideDotFiles = filter_var($_ENV['HIDE_DOT_FILES'], FILTER_VALIDATE_BOOLEAN);
$siteTitle = $_ENV['SITE_TITLE'];
$customLogo = $_ENV['CUSTOM_LOGO'];
$ignoreFiles = array_filter(explode(',', $_ENV['IGNORE_FILES']));
$modalFileTypes = array_filter(explode(',', $_ENV['MODAL_FILE_TYPES']));
$dateFormat = $_ENV['DATE_FORMAT'];

// Validate FILES_PATH
if (!is_dir($filesPath)) {
    die('Invalid FILES_PATH in .env');
}

// Change to the specified directory
chdir($filesPath);

// Handle ZIP download
if ($zipDownloads && isset($_POST['zip_file'])) {
    $file = basename($_POST['zip_file']);
    $path = getcwd() . '/' . $file;
    if (file_exists($path) && !is_dir($path)) {
        $zip = new ZipArchive();
        $zipName = $file . '.zip';
        if ($zip->open($zipName, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($path, $file);
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($zipName));
            readfile($zipName);
            unlink($zipName);
            exit;
        }
    }
}

// Get browsing path
$browse = isset($_GET['b']) ? trim(str_replace('\\', '/', $_GET['b']), '/ ') : '';
$browse = str_replace(['/../', '/..'], '', $browse); // Prevent directory traversal
$fullPath = $browse ? realpath($filesPath . '/' . $browse) : $filesPath;

// Validate browse path
if (!$fullPath || !is_dir($fullPath) || strpos($fullPath, $filesPath) !== 0) {
    $browse = '';
    $fullPath = $filesPath;
}

// Read directory
$total = 0;
$totalSize = 0;
$items = [];

if ($dh = @opendir($fullPath)) {
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        if ($hideDotFiles && strpos($file, '.') === 0) continue;
        if (in_array($file, $ignoreFiles)) continue;

        $itemPath = $fullPath . '/' . $file;
        $isDir = is_dir($itemPath);
        $isSymlink = is_link($itemPath);

        // Handle symlinks safely
        $stat = @lstat($itemPath);
        $size = $isSymlink ? 0 : ($stat ? $stat['size'] : 0);
        $mtime = $stat ? $stat['mtime'] : 0;

        $items[] = [
            'name' => $file,
            'isdir' => $isDir,
            'issymlink' => $isSymlink,
            'size' => $size,
            'time' => $mtime,
            'type' => $isDir ? 'directory' : pathinfo($file, PATHINFO_EXTENSION)
        ];

        $total++;
        if (!$isDir && !$isSymlink) $totalSize += $size;
    }
    closedir($dh);
}

// Sort items
usort($items, function ($a, $b) use ($sortOrder, $reverseSort) {
    if ($sortOrder === 'type') {
        if ($a['isdir'] !== $b['isdir']) return $a['isdir'] ? -1 : 1;
        return strcmp(strtolower($a['name']), strtolower($b['name']));
    } elseif ($sortOrder === 'size') {
        if ($a['isdir'] !== $b['isdir']) return $a['isdir'] ? -1 : 1;
        return $a['size'] <=> $b['size'];
    } elseif ($sortOrder === 'time') {
        return $a['time'] <=> $b['time'];
    } else {
        return strcmp(strtolower($a['name']), strtolower($b['name']));
    }
});

if ($reverseSort) {
    $items = array_reverse($items);
}

// Add parent directory
if ($browse) {
    array_unshift($items, [
        'name' => '..',
        'isdir' => true,
        'issymlink' => false,
        'size' => 0,
        'time' => 0,
        'type' => 'directory'
    ]);
}

// Humanize file size
function humanizeFilesize($bytes, $decimals = 1) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $units[$factor]);
}

// Build URL
function buildUrl($params) {
    $base = basename($_SERVER['PHP_SELF']);
    $query = http_build_query($params);
    return $query ? "$base?$query" : $base;
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($siteTitle); ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Boxicons Web Component -->
    <script src="https://unpkg.com/boxicons@2.1.4/dist/boxicons.js"></script>
    <style>
        body {
            background-color: #212529;
            color: #f8f9fa;
        }
        .table-dark {
            --bs-table-bg: #343a40;
        }
        .table-dark th, .table-dark td {
            border-color: #495057;
        }
        .navbar-brand img {
            max-height: 40px;
        }
        .file-icon {
            color: #ffc107;
        }
        .dir-icon {
            color: #0d6efd;
        }
        .symlink-icon {
            color: #6c757d;
        }
        .action-icon {
            cursor: pointer;
            margin-left: 0.5rem;
        }
        .footer-text {
            font-size: 13px;
        }
        .modal-content {
            background-color: #343a40;
            color: #f8f9fa;
        }
        .modal-header, .modal-footer {
            border-color: #495057;
        }
        @media (max-width: 576px) {
            .table {
                font-size: 0.875rem;
            }
            .action-icon {
                font-size: 0.875rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo buildUrl(['b' => '']); ?>" aria-label="Home">
                <img src="<?php echo htmlspecialchars($customLogo); ?>" alt="Site Logo" class="img-fluid">
            </a>
            <span class="navbar-text"><?php echo htmlspecialchars($siteTitle); ?></span>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container my-4">
        <h1 class="mb-4" aria-label="Current Directory"><?php echo htmlspecialchars($browse ?: '/'); ?></h1>
        <p><?php echo sprintf('%d items, %s total size', $total, humanizeFilesize($totalSize)); ?></p>

        <!-- File List -->
        <div class="table-responsive">
            <table class="table table-dark table-hover" aria-label="Directory Contents">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Name</th>
                        <th scope="col" class="text-end">Size</th>
                        <th scope="col" class="text-end">Modified</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemUrl = $item['isdir']
                            ? buildUrl(['b' => ($item['name'] === '..' ? dirname($browse) : ($browse ? "$browse/{$item['name']}" : $item['name']))])
                            : ($browse ? "$browse/{$item['name']}" : $item['name']);
                        $isModalFile = in_array(strtolower($item['type']), array_map('strtolower', $modalFileTypes));
                        $isImage = in_array(strtolower($item['type']), ['jpg', 'jpeg', 'png', 'gif']);
                        ?>
                        <tr>
                            <td>
                                <?php if ($item['issymlink']): ?>
                                    <box-icon type="solid" name="link" class="symlink-icon" aria-label="Symlink"></box-icon>
                                <?php elseif ($item['isdir']): ?>
                                    <box-icon type="solid" name="folder" class="dir-icon" aria-label="Directory"></box-icon>
                                <?php else: ?>
                                    <box-icon type="solid" name="file" class="file-icon" aria-label="File"></box-icon>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isModalFile && !$item['isdir']): ?>
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#fileModal" data-file="<?php echo htmlspecialchars($itemUrl); ?>" data-type="<?php echo htmlspecialchars($item['type']); ?>" class="text-decoration-none"><?php echo htmlspecialchars($item['name']); ?></a>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars($itemUrl); ?>" class="text-decoration-none"><?php echo htmlspecialchars($item['name']); ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo $item['isdir'] || $item['issymlink'] ? '-' : humanizeFilesize($item['size']); ?></td>
                            <td class="text-end"><?php echo $item['time'] ? date($dateFormat, $item['time']) : '-'; ?></td>
                            <td class="text-end">
                                <?php if (!$item['isdir'] && !$item['issymlink']): ?>
                                    <a href="<?php echo htmlspecialchars($itemUrl); ?>" download aria-label="Download <?php echo htmlspecialchars($item['name']); ?>">
                                        <box-icon type="solid" name="download" class="action-icon text-primary"></box-icon>
                                    </a>
                                    <?php if ($zipDownloads): ?>
                                        <form method="POST" class="d-inline" aria-label="Zip and Download <?php echo htmlspecialchars($item['name']); ?>">
                                            <input type="hidden" name="zip_file" value="<?php echo htmlspecialchars($item['name']); ?>">
                                            <button type="submit" class="btn p-0 border-0 bg-transparent">
                                                <box-icon type="solid" name="archive" class="action-icon text-warning"></box-icon>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($itemUrl); ?>" target="_blank" aria-label="Open <?php echo htmlspecialchars($item['name']); ?> in new window">
                                        <box-icon type="solid" name="window-open" class="action-icon text-success"></box-icon>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- README Display -->
        <?php if ($displayReadmes && file_exists($fullPath . '/README.md')): ?>
            <div class="card bg-dark border-secondary mt-4">
                <div class="card-body">
                    <h5 class="card-title">README.md</h5>
                    <pre class="text-light"><?php echo htmlspecialchars(file_get_contents($fullPath . '/README.md')); ?></pre>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal for File Preview -->
    <div class="modal fade" id="fileModal" tabindex="-1" aria-labelledby="fileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fileModalLabel">File Preview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="fileModalContent"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-light py-3">
        <div class="container text-end footer-text">
            Built with <box-icon type="solid" name="heart" class="text-danger bg-danger"></box-icon> by <a href="https://huement.com" class="text-light" target="_blank">huement.com</a>
        </div>
    </footer>

    <!-- Bootstrap 5 JS and Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle modal content loading
        document.addEventListener('DOMContentLoaded', () => {
            const fileModal = document.getElementById('fileModal');
            fileModal.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                const file = button.getAttribute('data-file');
                const type = button.getAttribute('data-type').toLowerCase();
                const modalContent = document.getElementById('fileModalContent');
                const modalTitle = document.getElementById('fileModalLabel');

                modalTitle.textContent = file.split('/').pop();
                
                if (['jpg', 'jpeg', 'png', 'gif'].includes(type)) {
                    modalContent.innerHTML = `<img src="${file}" class="img-fluid" alt="Image Preview">`;
                } else {
                    fetch(file)
                        .then(response => response.text())
                        .then(data => {
                            modalContent.innerHTML = `<pre class="text-start">${data}</pre>`;
                        })
                        .catch(() => {
                            modalContent.innerHTML = `<p>Error loading file content.</p>`;
                        });
                }
            });
        });
    </script>
</body>
</html>
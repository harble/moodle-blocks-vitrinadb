<?php
// Minimal cache-serving endpoint for block_vitrinadb thumbnails and cached files.
// Serves files from $CFG->localcachedir/block_vitrinadb/<widthkey>/<hash> without auth,
// allowing browsers to cache them and avoiding pluginfile.php login redirects.

require(__DIR__ . '/../../config.php');

require_once($CFG->libdir . '/filelib.php');

$hash = required_param('h', PARAM_ALPHANUMEXT);
$width = optional_param('w', 0, PARAM_INT);
$width = max(0, (int)$width);

if (empty($hash)) {
    http_response_code(400);
    die;
}

// Locate cached file.
$cachedir = make_localcache_directory('block_vitrinadb/' . $width, false);
if (!$cachedir) {
    http_response_code(404);
    die;
}

$cachepath = $cachedir . '/' . $hash;

if (!is_readable($cachepath)) {
    // If the cached file does not exist yet, try to regenerate it from the
    // original stored_file using the content hash.
    $fs = get_file_storage();
    $file = $fs->get_file_by_hash($hash);
    if ($file) {
        \block_vitrinadb\local\controller::stored_file_to_cached_url($file, $width ?: 0);
    }
}

if (!is_readable($cachepath)) {
    http_response_code(404);
    die;
}

// Try to determine mimetype from the original stored_file.
$mimetype = 'application/octet-stream';
$fs = get_file_storage();
$file = $fs->get_file_by_hash($hash);
if ($file) {
    $mimetype = $file->get_mimetype();
}

$lastmodified = filemtime($cachepath) ?: time();

header('Content-Type: ' . $mimetype);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmodified) . ' GMT');
header('Cache-Control: public, max-age=31536000, immutable');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

// Handle conditional GET.
if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $ifmodifiedsince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($ifmodifiedsince && $ifmodifiedsince >= $lastmodified) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
}

$filesize = filesize($cachepath);
if ($filesize !== false) {
    header('Content-Length: ' . $filesize);
}

readfile($cachepath);
exit;

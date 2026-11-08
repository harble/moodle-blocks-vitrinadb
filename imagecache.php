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

$lastmodified = filemtime($cachepath) ?: time();

// IMPORTANT: this conditional-GET short-circuit must stay AFTER the
// is_readable($cachepath) check above (the file must exist, so filemtime() is
// valid). If moved before the existence check, a missing cache file would make
// filemtime() fall back to "now" and every first request would wrongly get a
// 304. Placing it early, before the mimetype lookup, lets a revalidated
// request (304) skip the get_file_by_hash() files-table query and the
// filesize() stat below, cutting per-304 DB load while keeping the 200 path
// identical.
// PHP's session cache limiter injects "Pragma: no-cache" on any response that
// goes through config.php. That header makes browsers/Cloudflare treat this
// image as uncacheable and revalidate it on every page load (304 each time),
// ignoring the immutable Cache-Control set below. This endpoint is a pure
// read-only file server with no session-dependent output, so it is safe to
// drop that pragma and let the immutable cache header take effect.
header_remove('Pragma');

header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmodified) . ' GMT');
header('Cache-Control: public, max-age=31536000, immutable');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

// Handle conditional GET before any DB/filesystem lookups that are only
// needed to serve the body.
if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $ifmodifiedsince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($ifmodifiedsince && $ifmodifiedsince >= $lastmodified) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
}

// Try to determine mimetype from the original stored_file.
$mimetype = 'application/octet-stream';
$fs = get_file_storage();
$file = $fs->get_file_by_hash($hash);
if ($file) {
    $mimetype = $file->get_mimetype();
}

header('Content-Type: ' . $mimetype);

$filesize = filesize($cachepath);
if ($filesize !== false) {
    header('Content-Length: ' . $filesize);
}

readfile($cachepath);
exit;
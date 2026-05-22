<?php
// Storage proxy — serves files from laravel/storage/app/public/
$file = $_GET['f'] ?? '';
$file = str_replace(['..', "\0"], '', $file); // sanitize
$path = __DIR__ . '/../laravel/storage/app/public/' . ltrim($file, '/');

if (!$file || !file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);

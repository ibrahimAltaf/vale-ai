<?php

declare(strict_types=1);

$root = '/var/www/html';
file_put_contents($root . '/marketplace-api.php', "<?php\n\nrequire __DIR__ . '/modules/marketplaceplatform/api.php';\n");
$file = $root . '/.htaccess';
$contents = (string) file_get_contents($file);
$mode = $argv[1] ?? 'slim';
$targets = [
    'RewriteRule ^api/v1(?:/.*)?$ index.php [QSA,END]',
    'RewriteRule ^api/v1(?:/.*)?$ modules/marketplaceplatform/api.php [QSA,END]',
    'RewriteRule ^api/v1(?:/.*)?$ marketplace-api.php [QSA,END]',
];
$new = $mode === 'index'
    ? 'RewriteRule ^api/v1(?:/.*)?$ index.php [QSA,END]'
    : 'RewriteRule ^api/v1(?:/.*)?$ marketplace-api.php [QSA,END]';
$updated = $contents;
foreach ($targets as $old) {
    $updated = str_replace($old, $new, $updated);
}
if ($updated === $contents && !str_contains($contents, $new)) {
    fwrite(STDERR, "API rewrite was not found.\n");
    exit(1);
}
file_put_contents($file, $updated);
echo "updated\n";

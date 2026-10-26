<?php

declare(strict_types=1);

// Executes unmodified, hash-pinned upstream ToolsCore (not a Tools test double).
// Run each version in its own PHP process: ToolsCore cannot be redeclared.
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tests/native-tools.php SOURCE_ROOT VERSION\n");
    exit(2);
}
$source = rtrim($argv[1], '/\\') . '/' . $argv[2];
define('_COOKIE_KEY_', 'native-compatibility-fixture-cookie-key');
define('_PS_VERSION_', $argv[2]);
if (is_file($source . '/src/Core/Security/Hashing.php')) {
    require $source . '/src/Core/Security/Hashing.php';
}
require $source . '/classes/Tools.php';
class_alias('ToolsCore', 'Tools');

// Independent legacy-token fixture, calculated before the compatibility fix.
$expected = '1364877974c2f46137161dcbb5a17a96';
if (Tools::hash('paymos/reconcile') !== $expected
    || Tools::hash('paymos/reconcile') !== $expected) {
    throw new RuntimeException('Cron token changed or is unstable.');
}
if (method_exists('Tools', 'encrypt') && Tools::encrypt('paymos/reconcile') !== $expected) {
    throw new RuntimeException('Existing merchant cron URLs would be invalidated.');
}
foreach (array('paymos.php', 'controllers/front/reconcile.php', 'cron/reconcile.php') as $file) {
    $code = file_get_contents(__DIR__ . '/../paymos/' . $file);
    if (strpos($code, "Tools::hash('paymos/reconcile')") === false
        || preg_match('/Tools::encrypt\s*\(/', $code)) {
        throw new RuntimeException($file . ' still depends on a removed PrestaShop API.');
    }
}
echo 'PASS native ToolsCore ' . _PS_VERSION_ . ': stable legacy token; all three module call sites compatible.' . "\n";

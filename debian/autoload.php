<?php
require_once '/usr/share/php/MultiFlexi/autoload.php';

require_once '/usr/share/php/Composer/InstalledVersions.php';

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $_cj  = @json_decode(@file_get_contents(__DIR__ . '/composer.json'), true);
    $name = defined('APP_NAME') ? APP_NAME : ($_cj['name'] ?? basename(__DIR__));
    $version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();

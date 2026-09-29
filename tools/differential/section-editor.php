<?php

declare(strict_types=1);

// Isolated probe: all files written here belong to the temporary oracle fixture.
[$script, $autoload, $composerPhar, $profile, $mode, $inventoryPath] = $argv;
if ($mode === 'oracle') {
    Phar::loadPhar($composerPhar, 'composer.phar');
    require 'phar://composer.phar/vendor/autoload.php';
}
$loader = require $autoload;
$root = getcwd();
$target = $root . ($profile === 'release-3.0.1' ? '/public/wp-config.php' : '/wp-config.php');
if ($mode === 'oracle') {
    $composer = Composer\Factory::create(new Composer\IO\NullIO(), $root . '/composer.json', true, true);
    $loader->register();
    (new WeCodeMore\WpStarter\ComposerPlugin())->setupAutoload();
    $filesystem = new Composer\Util\Filesystem();
    $paths = WeCodeMore\WpStarter\Util\Paths::withRoot($root, $composer->getConfig(), $composer->getPackage()->getExtra(), $filesystem);
    $editor = $profile === 'release-3.0.1'
        ? new WeCodeMore\WpStarter\Util\WpConfigSectionEditor($paths)
        : new WeCodeMore\WpStarter\Util\WpConfigSectionEditor($paths, $filesystem);
} else {
    $paths = new SymPress\Runtime\Filesystem\Paths($root, wp: 'public/wp', content: 'public/content');
    $config = new SymPress\Runtime\Config\Config(['compatibility-profile' => $profile], new SymPress\Runtime\Config\Validator($paths));
    $editor = new SymPress\Runtime\Generation\WpConfigSectionEditor($paths, $config, new SymPress\Runtime\Filesystem\Filesystem());
}
$inventory = json_decode(file_get_contents($inventoryPath), true, flags: JSON_THROW_ON_ERROR);
$names = array_unique([...array_column($inventory['baselines']['release']['sections'], 'name'), ...array_column($inventory['baselines']['dev']['sections'], 'name')]);
$normalize = static function (string $source): string {
    // Only indentation, empty lines and call-site-dependent edit-marker hashes are cosmetic.
    $source = preg_replace('/^\s*# <\/?[APR]-[a-f0-9]{32}>\s*$/m', '', $source);
    return implode("\n", array_filter(array_map('trim', explode("\n", $source)), static fn (string $line): bool => $line !== ''));
};
$result = [];
$sectionContent = static function (string $name) use ($editor, $target): string {
    if (method_exists($editor, 'sectionContent')) {
        return $editor->sectionContent($name);
    }
    // The release editor has no reader method; inspect the actual file it changed.
    $name = preg_quote($name, '~');
    preg_match('~' . $name . '\s*:\s*\{(.*?)\}\s*#@@/' . $name . '~s', file_get_contents($target), $matches);
    return $matches[1] ?? '';
};
foreach ($names as $name) {
    $seed = "<?php\n{$name} : {\n    \$GLOBALS['trace'][] = 'seed';\n} #@@/{$name}\n";
    file_put_contents($target, $seed);
    $editor->prepend($name, '$GLOBALS["trace"][] = "before";');
    for ($iteration = 0; $iteration < 2; ++$iteration) {
        $editor->append($name, '$GLOBALS["trace"][] = "after";');
    }
    $GLOBALS['trace'] = [];
    require $target;
    $result[$name]['trace'] = $GLOBALS['trace'];
    $result[$name]['edited'] = $normalize($sectionContent($name));
    $beforeMissing = file_get_contents($target);
    $result[$name]['missing_error'] = false;
    try {
        $editor->replace('MISSING_SECTION', 'throw new RuntimeException();');
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), "Failed replacing section 'MISSING_SECTION'")) {
            throw $error;
        }
        $result[$name]['missing_error'] = true;
    }
    $result[$name]['missing_noop'] = $beforeMissing === file_get_contents($target);
    $editor->replace($name, '$GLOBALS["trace"][] = "replacement";');
    $result[$name]['replaced'] = $normalize($sectionContent($name));
    $editor->delete($name);
    $result[$name]['deleted'] = $normalize($sectionContent($name));
    file_put_contents($target, $seed);
    $editor->replace($name, '$literal = "$1";');
    $result[$name]['literal'] = $normalize($sectionContent($name));
}
echo json_encode($result, JSON_THROW_ON_ERROR);

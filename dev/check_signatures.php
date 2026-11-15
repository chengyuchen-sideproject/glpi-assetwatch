<?php

/**
 * Asset Watch - development helper, run INSIDE the glpi container:
 *
 *   php plugins/assetwatch/dev/check_signatures.php
 *
 * For every method of the plugin classes (inc/*.class.php) that overrides a GLPI method,
 * print the parent signature when it differs, so that GLPI upgrades that add parameter or
 * return types are caught before PHP refuses to compile the class.
 * Exit code 0 = all overrides compatible.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$problems = 0;
foreach (glob(__DIR__ . '/../inc/*.class.php') as $file) {
    $source = file_get_contents($file);
    if (!preg_match('/^class\s+(\w+)(?:\s+extends\s+(\w+))?/m', $source, $m) || empty($m[2])) {
        continue;
    }
    [, $class, $parent] = $m;
    // Resolve "use" imports (e.g. Glpi\Dashboard\Filters\AbstractFilter) through the loaded class.
    if (class_exists($class) && get_parent_class($class) !== false) {
        $parent = get_parent_class($class);
    }
    if (!class_exists($parent)) {
        echo "?? $class: parent $parent not found\n";
        $problems++;
        continue;
    }
    preg_match_all('/^\s*(?:(?:public|protected|private|static|final)\s+)*function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*([?\w|\\\\]+))?/m', $source, $methods, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    foreach ($methods as $method) {
        [, $name, $params] = $method;
        $return = $method[3] ?? null;
        if (!method_exists($parent, $name)) {
            continue;
        }
        $ref = new ReflectionMethod($parent, $name);
        if ($ref->isPrivate()) {
            continue;
        }
        $parent_return = $ref->hasReturnType() ? (string) $ref->getReturnType() : '';
        $parent_params = implode(', ', array_map(static function (ReflectionParameter $p): string {
            $type = $p->hasType() ? $p->getType() . ' ' : '';
            $default = '';
            if ($p->isDefaultValueAvailable()) {
                $default = ' = ' . var_export($p->getDefaultValue(), true);
            }
            return $type . '$' . $p->getName() . $default;
        }, $ref->getParameters()));
        $child_return = trim((string) ($return ?? ''));
        $param_types_needed = preg_match('/\w+\s+\$/', $parent_params) && !preg_match('/\w+\s+\$/', $params);
        if (($parent_return !== '' && $child_return === '') || $param_types_needed) {
            $static = $ref->isStatic() ? 'static ' : '';
            echo sprintf("%s::%s\n    plugin: function %s(%s)%s\n    GLPI:   %sfunction %s(%s)%s\n",
                $class, $name, $name, trim($params), $child_return !== '' ? ": $child_return" : '',
                $static, $name, $parent_params, $parent_return !== '' ? ": $parent_return" : '');
            $problems++;
        }
    }
}
echo $problems === 0 ? "all overrides compatible\n" : "$problems override(s) to fix\n";
exit($problems === 0 ? 0 : 1);

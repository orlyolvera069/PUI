<?php

declare(strict_types=1);

/**
 * Mismo esquema de autoload que public/index.php para clases bajo Core/ y App/.
 */
function autoload_pui_classes(string $class_name): void
{
    $filename = PROJECTPATH . '/' . str_replace('\\', '/', $class_name) . '.php';
    if (is_file($filename)) {
        include_once $filename;
    }
}

spl_autoload_register('autoload_pui_classes');

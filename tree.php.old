<?php
function tree($dir, $prefix = '') {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        echo $prefix . $item . "\n";
        if (is_dir("$dir/$item")) {
            tree("$dir/$item", $prefix . "  ");
        }
    }
}
tree(__DIR__);

<?php

/**
 * Loads, constructs, and initializes a concrete archivist implementation
 * given the "archivist" options sub-array read directly from the options
 * JSON file (see config.example.json).
 *
 * Expected shape of $archivist_options:
 *   {
 *     "class": "fs_archivist",
 *     "path": "archivists/fs_archivist/fs_archivist.php",
 *     "config_file": "/etc/atropos/fs_archivist.json"
 *   }
 */
class archivist_loader
{
    /**
     * @param array $archivist_options
     * @return archivist
     */
    public static function load($archivist_options)
    {
        $class_name = $archivist_options['class'];
        $path = $archivist_options['path'];

        if (!class_exists($class_name, false)) {
            if (!@include_once $path) {
                throw new RuntimeException("could not load archivist class file '{$path}' for class '{$class_name}'");
            }

            if (!class_exists($class_name, false)) {
                throw new RuntimeException("file '{$path}' was loaded but class '{$class_name}' was not defined");
            }
        }

        /** @var archivist $instance */
        $instance = new $class_name();
        $instance->init($archivist_options['config_file']);

        return $instance;
    }
}

<?php
/**
 * Simple, Fast & Secure Framework
 * @author Fran López <fran.lopez84@hotmail.es>
 * @version 0.1
 */
if(!defined("BASE_DIR"))
    define("BASE_DIR", dirname( dirname(__DIR__) ) );

// register the autoloader
spl_autoload_register( "PSFSAutoloader" );


// autoloader
function PSFSAutoloader( $class ){
    // it only autoload class into the Rain scope
    if (strpos($class,'PSFS') !== false){

        // Change order src
        $class = str_replace('PSFS', 'PSFS\\src', $class);
        // transform the namespace in path
        $path = str_replace("\\", DIRECTORY_SEPARATOR, $class );

        // filepath
        $abs_path = LIB_DIR . DIRECTORY_SEPARATOR . $path . ".php";
        if (!file_exists($abs_path)) {
            pre('&rarr;&nbsp;'.$class);
            pre('&rarr;&nbsp;'.$path);
            pre('&rarr;&nbsp;'.$abs_path);
        }

        // require the file
        if(file_exists($abs_path)) require_once $abs_path;
    }
    return false;
}
<?php

foreach( array(__DIR__ . '/../lib/autoload.php', __DIR__ . '/../vendor/autoload.php') as $file ) {
    if( file_exists($file) ) {
        require_once $file;
    }
}

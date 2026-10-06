<?php
// PHPUnit Bootstrap file

// Ensure we are in global scope when loading the app files
$GLOBALS['LOCATION_MAP'] = [];
$GLOBALS['t'] = [];
$GLOBALS['L'] = [];

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lang.php';
require_once dirname(__DIR__) . '/lib.php';

// Force globals explicitly just in case
$GLOBALS['LOCATION_MAP'] = $LOCATION_MAP;
$GLOBALS['t'] = $L[get_lang()];

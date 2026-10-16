<?php
require_once(__DIR__ . "/../../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../../includes/clases/conexion.php");

function db_connect_sqlsrv() {
    global $serverName, $connectionInfo;
    // UTF-8 so accents/symbols round-trip and json_encode never gets invalid bytes
    $cn = sqlsrv_connect($serverName, $connectionInfo + ['CharacterSet' => 'UTF-8']);
    if (!$cn) {
        throw new Exception("DB connection error.");
    }
    return $cn;
}

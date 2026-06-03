<?php
require_once(__DIR__ . "/../../../../includes/config.ini.php");
require_once(__DIR__ . "/../../../../includes/clases/conexion.php");

function db_connect_sqlsrv() {
    global $serverName, $connectionInfo;
    $cn = sqlsrv_connect($serverName, $connectionInfo);
    if (!$cn) {
        throw new Exception("DB connection error.");
    }
    return $cn;
}

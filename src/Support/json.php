<?php
function json_out(bool $ok, string $msg, $data = null, int $code = 200): void {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => $ok, "message" => $msg, "data" => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

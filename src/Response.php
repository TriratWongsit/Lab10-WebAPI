<?php
namespace App;

class Response {
    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error($message, $status = 400, $details = null) {
        $payload = ['error' => $message];
        if ($details !== null) $payload['details'] = $details;
        self::json($payload, $status);
    }
}
?>

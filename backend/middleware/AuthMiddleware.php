<?php
require_once __DIR__ . '/../core/Session.php';

class AuthMiddleware
{

    public static function check()
    {
        Session::start();
        return Session::get('user_id') !== null;
    }

    public static function requireRole($role)
    {
        Session::start();

        if (!self::check()) {
            http_response_code(401);
            echo json_encode(["error" => "Authentication required"]);
            exit;
        }

        if (!Session::hasRole($role)) {
            http_response_code(403);
            echo json_encode(["error" => "Unauthorized"]);
            exit;
        }
    }
}

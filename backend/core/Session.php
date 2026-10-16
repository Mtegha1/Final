<?php
class Session
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 86400,
                'path' => '/',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public static function get($key)
    {
        return $_SESSION[$key] ?? null;
    }

    public static function currentRole()
    {
        self::start();
        $userId = self::get('user_id');
        if (!$userId) {
            return null;
        }

        require_once __DIR__ . '/../config/database.php';
        $stmt = Database::getInstance()->conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        if (!$role || $role !== self::get('role')) {
            self::destroy();
            return null;
        }
        return $role;
    }

    public static function hasRole(string $role): bool
    {
        return self::currentRole() === $role;
    }

    public static function destroy()
    {
        session_unset();
        session_destroy();
    }
}

<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../helpers/validator.php';

class AuthController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    // REGISTER
    public function register()
    {
        try {
            $input = json_decode(file_get_contents("php://input"), true);

            $name = $input['full_name'] ?? '';
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            $role = $input['role'] ?? 'client';

            if (!$name || !$email || !$password) {
                Response::error("All fields are required", 400);
            }
            if (!in_array($role, ['agent', 'client'], true)) {
                Response::error("Only agent and client accounts can be registered here", 400);
            }

            $passwordErrors = Validator::validatePassword($password);
            if (!empty($passwordErrors)) {
                Response::error($passwordErrors, 400);
            }

            if ($this->userModel->exists($email)) {
                Response::error("Email already exists", 409);
            }

            $userId = $this->userModel->register($name, $email, $password, $role);

            if (!$userId) {
                Response::error("Registration failed", 500);
            }

            Session::start();
            Session::set("user_id", $userId);
            Session::set("role", $role);

            Response::success([
                "user" => [
                    "id" => $userId,
                    "role" => $role
                ]
            ], "User registered successfully");
        } catch (Exception $e) {
            Response::error("Server error: " . $e->getMessage(), 500);
        }
    }

    // login
    public function login()
    {
        try {
            $input = json_decode(file_get_contents("php://input"), true);

            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';

            if (!$email || !$password) {
                Response::error("Email and password required", 400);
            }

            $user = $this->userModel->authenticate($email, $password);

            if (!$user) {
                Response::error("Invalid credentials", 401);
            }

            Session::start();
            Session::set('user_id', $user['id']);
            Session::set('role', $user['role']);
            Session::set('user_name', $user['full_name']); // Storing name for the dashboard

            Response::success([
                "user" => [
                    "id" => $user['id'],
                    "role" => $user['role'],
                    "name" => $user['full_name']
                ]
            ], "Login successful");
        } catch (Exception $e) {
            Response::error("Server error: " . $e->getMessage(), 500);
        }
    }

    // CHECK AUTH
    public function check()
    {
        Session::start();

        $userId = Session::get('user_id');
        $role = Session::get('role');

        if (!$userId) {
            Response::error("Not authenticated", 401);
            return;
        }

        $user = $this->userModel->getUserById($userId);
        if (!$user || $user['role'] !== $role) {
            Session::destroy();
            Response::error("Not authenticated", 401);
            return;
        }
        $userName = $user['full_name'];

        Response::success([
            "user" => [
                "id" => $userId,
                "role" => $role,
                "name" => $userName ?? 'User'
            ]
        ]);
    }

    // LOGOUT
    public function logout()
    {
        Session::start();
        Session::destroy();

        Response::success([], "Logged out");
    }
}

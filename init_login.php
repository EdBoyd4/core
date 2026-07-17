<?php
declare(strict_types=1);

// 1. Tell Clarium where the Login Library's Autoloader is located!
require_once dirname(__DIR__, 2) . '/boyds-little-login-library-for-php/vendor/autoload.php';

// 2. Load the environment variables from the root directory
$dotenv = Dotenv\Dotenv::createImmutable([__DIR__, dirname(__DIR__, 2)]);
$dotenv->safeLoad();

use Boyd\LoginLibrary\Config\LoginConfig;
use Boyd\LoginLibrary\Database\Database;
use Boyd\LoginLibrary\Repositories\UserRepository;
use Boyd\LoginLibrary\Repositories\AttemptRepository;
use Boyd\LoginLibrary\Repositories\SecurityAuditRepository;
use Boyd\LoginLibrary\Security\SessionManager;
use Boyd\LoginLibrary\Security\AuthManager;

// 3. Initialize the Library Configuration using the .env variables
$config = new LoginConfig(
    dbHost: $_ENV['AUTH_DB_HOST'] ?? $_ENV['DB_HOST'] ?? $_SERVER['AUTH_DB_HOST'] ?? getenv('AUTH_DB_HOST') ?: 'localhost',
    dbName: $_ENV['AUTH_DB_NAME'] ?? $_ENV['DB_NAME'] ?? $_SERVER['AUTH_DB_NAME'] ?? getenv('AUTH_DB_NAME') ?: '',
    dbUser: $_ENV['AUTH_DB_USER'] ?? $_ENV['DB_USER'] ?? $_SERVER['AUTH_DB_USER'] ?? getenv('AUTH_DB_USER') ?: '',
    dbPass: $_ENV['AUTH_DB_PASS'] ?? $_ENV['DB_PASS'] ?? $_SERVER['AUTH_DB_PASS'] ?? getenv('AUTH_DB_PASS') ?: '',
    loginRoute: 'login.php',
    unauthorizedRoute: 'unauthorized.php'
);

// 4. Wire up the library dependencies
$database = new Database($config);
$userRepository = new UserRepository($database, $config);
$attemptRepository = new AttemptRepository($database);
$auditRepository = new SecurityAuditRepository($database);
$sessionManager = new SessionManager($config);

// 5. Expose the $authManager to whatever file includes this script
$authManager = new AuthManager($userRepository, $sessionManager, $config, $attemptRepository, $auditRepository);

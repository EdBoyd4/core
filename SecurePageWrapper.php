<?php
declare(strict_types=1);

/**
 * Base abstract controller for ClarAssign pages.
 * Handles unified session constraints and standard routing lifecycle.
 */
abstract class SecurePageWrapper
{
    /**
     * Optional roles required to access the page extending this wrapper.
     * Can be overridden by child controllers.
     */
    protected array $requiredRoles = [];

    public function __construct()
    {
        $this->startSessionSafely();
        $this->enforceSecurityConstraints();
    }

    /**
     * Start the session safely if not already started.
     */
    protected function startSessionSafely(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('BoydLoginSession');
            session_start();
        }
    }

    /**
     * Enforce session constraints and Role-Based Access Control (RBAC).
     */
    protected function enforceSecurityConstraints(): void
    {
        global $authManager;
        
        // If the AuthManager hasn't been instantiated yet (e.g. by login script or workspace router), 
        // bootstrap the authentication system.
        if (!isset($authManager)) {
            require_once __DIR__ . '/init_login.php';
        }

        // 1. Enforce that the user has an active, valid session
        $authManager->enforceLogin();

        // 2. Enforce RBAC
        if (!empty($this->requiredRoles)) {
            $userRoles = $_SESSION['user_roles'] ?? [];
            
            // System Admins bypass individual module restrictions
            if (in_array('admin', $userRoles, true)) {
                return;
            }

            $hasAccess = false;
            foreach ($this->requiredRoles as $role) {
                if (in_array($role, $userRoles, true)) {
                    $hasAccess = true;
                    break;
                }
            }

            if (!$hasAccess) {
                // Determine unauthorized route from config if possible, fallback to standard path
                global $config;
                $redirectUrl = isset($config) ? $config->getUnauthorizedRoute() : '/unauthorized.php';
                header("Location: " . $redirectUrl);
                exit;
            }
        }
    }

    /**
     * Main entry point for the controller. Routes to GET or POST handler.
     */
    public function handleRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->processPost();
        } else {
            $this->renderGet();
        }
    }

    /**
     * Logic for rendering the HTML view (GET requests).
     */
    abstract protected function renderGet(): void;

    /**
     * Logic for processing form submissions (POST requests).
     */
    abstract protected function processPost(): void;
}

<?php
// Shared guards for dashboard write requests.
// - Same-origin check (Origin, falling back to Referer) so other sites cannot
//   submit forms or fetches on a signed-in user's behalf.
// - Act-as check so admins viewing another channel cannot change it.

if (!function_exists('dashboard_request_is_ajax')) {
    function dashboard_request_is_ajax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    function dashboard_same_origin_ok(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $src = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($src === '' || $src === 'null') {
            $src = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($src === '' || $host === '') {
            return false;
        }
        $srcHost = strtolower((string) (parse_url($src, PHP_URL_HOST) ?? ''));
        return $srcHost !== '' && hash_equals($host, $srcHost);
    }

    function dashboard_is_act_as(): bool
    {
        return !defined('ADMIN_PANEL_CONTEXT')
            && isset($_SESSION['admin_act_as_active'])
            && $_SESSION['admin_act_as_active'] === true;
    }

    /**
     * Stop the request unless it is a same-origin write from the account owner.
     * $redirect is used for normal form posts; AJAX callers get JSON.
     */
    function dashboard_write_guard(string $redirect, bool $blockActAs = true): void
    {
        $problem = null;
        $code = 403;
        if (!dashboard_same_origin_ok()) {
            $problem = 'This request was blocked because it did not come from the dashboard. Reload the page and try again.';
        } elseif ($blockActAs && dashboard_is_act_as()) {
            $problem = 'Changes are disabled while acting as another user.';
        }
        if ($problem === null) {
            return;
        }
        if (dashboard_request_is_ajax() || $redirect === '') {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $problem, 'message' => $problem, 'status' => $problem]);
            exit();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['update_message'] = $problem;
        }
        header('Location: ' . $redirect);
        exit();
    }
}

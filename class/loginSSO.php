<?php
// Legacy constants (OAUTH_*) live in /app/.include/manager.php on legacy pods.
// On v1 pods Next is the IdP and these constants are irrelevant, so the file
// is optional and we no longer hard-exit when OAUTH_* are missing.
if (file_exists('/app/.include/manager.php')) {
    require_once('/app/.include/manager.php');
}

// Next IdP base (migration trou #1 §5.2). The main plugin file defines
// FAAASTER_API_BASE; define a fallback here in case loginSSO runs standalone.
if (!defined('FAAASTER_API_BASE')) {
    define(
        'FAAASTER_API_BASE',
        defined('CUSTOM_FAAASTER_API_BASE') ? CUSTOM_FAAASTER_API_BASE : 'https://app.faaaster.io'
    );
}

class LoginSSO
{
    /** v0/v1 discriminator. A v1 SSO token is a signed JWT (header.payload.sig);
     * legacy v0 tokens are opaque (no dots). Token SHAPE is the reliable signal —
     * NOT the presence of OAUTH_* constants: provisioning writes the legacy
     * /app/.include/manager.php (OAUTH_STATE, OAUTH_GET_USER) onto v1 pods too,
     * so constant-presence would wrongly flag every v1 pod as legacy. */
    private function isJwt($token)
    {
        return substr_count((string) $token, '.') === 2;
    }

    /** Token-validation endpoint, chosen by token shape so a single image serves
     * both fleets. Opaque v0 token (and legacy OAUTH_GET_USER present) → Symfony
     * IdP; signed JWT → Next /api/sso/userinfo. Both answer { success, name } via
     * the same `Authorization: Bearer` call, so only the URL differs. */
    private function validateUrl($token)
    {
        if (!$this->isJwt($token) && defined('OAUTH_GET_USER') && OAUTH_GET_USER) {
            return OAUTH_GET_USER;
        }
        return rtrim(FAAASTER_API_BASE, '/') . '/api/sso/userinfo';
    }

    public function authorize($param)
    {
        $access_token = $param['access_token'];

        // The legacy implicit-flow `state` check applies ONLY to opaque v0
        // tokens. v1 JWTs carry no `state` (signature + 90s exp + instance
        // audience replace it), and v1 pods STILL define OAUTH_STATE via the
        // legacy manager.php — so gate on the token being opaque, not on the
        // constant existing, otherwise every v1 login exits here.
        if (!$this->isJwt($access_token) && defined('OAUTH_STATE') && OAUTH_STATE) {
            if (!isset($param['state']) || OAUTH_STATE !== $param['state']) {
                exit;
            }
        }

        setcookie('tc_token', $access_token, time() + $param['expires_in']);

        $loginResult = $this->login($access_token);
        return $loginResult;
    }

    public function sso()
    {
        if (!empty($_COOKIE['tc_token'])) {
            $loginResult = $this->login($_COOKIE['tc_token']);
            return $loginResult;
        }

        // Legacy implicit-flow redirect to the Symfony IdP (only if still
        // configured). v1 sites have no interactive authorize endpoint — the
        // dashboard mints the token and hits /v1/authorize directly — so a
        // tokenless direct hit just falls back to the standard WP login.
        if (defined('OAUTH_ENDPOINT') && OAUTH_ENDPOINT && defined('OAUTH_STATE')) {
            $parameters = array(
                'response_type' => 'token',
                'client_id' => defined('OAUTH_CLIENT_ID') ? OAUTH_CLIENT_ID : '',
                'scope' => 'username',
                'state' => OAUTH_STATE,
            );
            header('Location: ' . OAUTH_ENDPOINT . '?' . http_build_query($parameters));
            return;
        }

        header('Location: ' . (function_exists('wp_login_url') ? wp_login_url() : '/wp-login.php'));
    }

    // 6368

    public function login($token)
    {

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        require_once ABSPATH . 'wp-includes/pluggable.php';

        $conn = curl_init($this->validateUrl($token));

        curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($conn, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token
        ));

        $result = curl_exec($conn);

        if (PHP_VERSION_ID < 80000) {
            curl_close($conn);
        }

        $_SESSION["lang"] = get_locale();

        if ($result === false) {
            // redirect to err page
            header('Cache-Control: no-cache');
            header('Content-Type: text/html');
            include(__DIR__ . '/../request/err.php');
            exit;
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            // redirect to err page
            parse_str($_SERVER['QUERY_STRING'], $get_array);

            $_GET["error_description"] = $get_array["error_description"] ?? '';

            header('Cache-Control: no-cache');
            header('Content-Type: text/html');
            include(__DIR__ . '/../request/err.php');
            exit;
        }

        define('WP_INSTALLING', true);

        $this->getUser($json['name']);
    }

    private function getUser($username)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        require_once ABSPATH . 'wp-includes/pluggable.php';


        $user_data = get_user_by('login', $username);
        if (isset($_GET['user'])) {
            $user_data = get_userdata($_GET["user"]);
        }

        // no user found
        if ($user_data === false || !isset($_GET['user'])) {
            $admin_users = get_users(array('role' => 'administrator'));
            if (count($admin_users)) {
                if (count($admin_users) > 1) {
                    // redirect to choose user
                    $users = array();

                    foreach ($admin_users as $admin) {
                        $users[$admin->ID] = array("username" => $admin->user_login, "gravatar" => get_avatar($admin->ID), "email" => $admin->user_email);
                    }

                    $_SESSION["admins"] = $users;
                    $_SESSION["lang"] = get_locale();

                    // redirect to choose a user
                    header('Cache-Control: no-cache');
                    header('Content-Type: text/html');
                    include(__DIR__ . '/../request/user.php');
                    exit;
                } else if (!isset($_GET["user"])) {
                    $user_data = get_userdata($admin_users[0]->ID);

                    // if user doesn't exist
                    if ($user_data === false) {
                        wp_redirect(admin_url('index.php'));
                        exit;
                    }
                } else {
                    $user_data = get_userdata($_GET["user"]);

                    // if user doesn't exist
                    if ($user_data === false) {
                        wp_redirect(admin_url('index.php'));
                        exit;
                    }
                }
            } else {
                // if user doesn't exist
                wp_redirect(admin_url('index.php'));
                exit;
            }
        }


        // connect the user
        wp_set_current_user($user_data->ID, $user_data->user_login);
        wp_set_auth_cookie($user_data->ID);
        do_action('wp_login', $user_data->user_login, $user_data);
        if (isset($_SERVER['HTTP_REFERER'])) {
            $parsed = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
        } else {
            $parsed = false;
        }

        // redirect home or where the user were
        if (!$parsed) {
            wp_redirect("/");
            exit;
        }
        parse_str($parsed, $query);

        if (isset($query['redirect_to'])) {
            wp_redirect($query['redirect_to']);
            exit;
        }

        wp_redirect("/");
        exit;
    }

    public function verifyTCToken($token)
    {
        $conn = curl_init($this->validateUrl($token));

        curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($conn, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token
        ));

        $result = curl_exec($conn);

        if (PHP_VERSION_ID < 80000) {
            curl_close($conn);
        }

        if ($result === false) {
            return false;
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            return false;
        }

        return true;
    }
}

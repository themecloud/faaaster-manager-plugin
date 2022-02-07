<?php
if (!file_exists('/app/conf/manager.php')) {
    exit;
}

require_once('/app/conf/manager.php');

if (!defined('OAUTH_STATE') || !defined('OAUTH_ENDPOINT') || !defined('OAUTH_CLIENT_ID') || !defined('OAUTH_GET_USER')) {
    exit;
}
class LoginSSO
{

    public function authorize($param)
    {

        if (OAUTH_STATE !== $param['state']) {
            exit;
        }

        $access_token = $param['access_token'];

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

        $parameters = array(
            'response_type' => 'token',
            'client_id' => OAUTH_CLIENT_ID,
            'scope' => 'username',
            'state' => OAUTH_STATE,
        );
        $uri = OAUTH_ENDPOINT . "?" . http_build_query($parameters);

        header("Location: $uri");
    }

    public function login($token)
    {

        session_start();

        require_once ABSPATH . 'wp-includes/pluggable.php';

        $conn = curl_init(OAUTH_GET_USER);

        curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($conn, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token
        ));

        $result = curl_exec($conn);

        curl_close($conn);

        $_SESSION["lang"] = get_locale();

        if ($result === false) {
            header('Location: err.php?error_description=' . $_GET["error_description"]);
            exit;
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            header('Location: err.php?' . $_SERVER['QUERY_STRING']);
            exit;
        }

        define('WP_INSTALLING', true);

        $this->getUser($json['name']);
    }

    private function getUser($username)
    {
        session_start();

        require_once ABSPATH . 'wp-includes/pluggable.php';


        $user_data = get_user_by('login', $username);

        // no user found
        if ($user_data === false) {
            $admin_users = get_users(array('role' => 'administrator'));
            if (count($admin_users)) {
                if (!isset($_GET["user"])) {
                    // redirect to choose user
                    $users = array();

                    foreach ($admin_users as $admin) {
                        $users[$admin->ID] = array("username" => $admin->user_login, "gravatar" => get_avatar($admin->ID), "email" => $admin->user_email);
                    }

                    $_SESSION["admins"] = $users;
                    $_SESSION["lang"] = get_locale();

                    header('Location: user.php?' . $_SERVER['QUERY_STRING']);
                    exit;
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
        do_action('wp_login', $user_data->user_login);

        $parsed = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);

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
}

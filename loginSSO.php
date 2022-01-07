<?php
require_once('/var/www/wp-content/themecloud_oauth.php');

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
        $conn = curl_init(OAUTH_GET_USER);

        curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($conn, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token
        ));

        $result = curl_exec($conn);

        curl_close($conn);

        if ($result === false) {
            $data_for_response = array(
                "code"    => "no_result",
                "message" => "OAuth didn't return a result.",
                "data"    => array("status" => 500)
            );

            return $data_for_response;
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            $data_for_response = array(
                "code"    => "no_success",
                "message" => "OAuth didn't return a success.",
                "data"    => array("status" => 500, "data" => $json)
            );

            return $data_for_response;
        }

        define('WP_INSTALLING', true);

        $this->getUser($json['name']);
    }

    private function getUser($username)
    {
        require_once ABSPATH . 'wp-includes/pluggable.php';


        $user_data = get_userdata('login', $username);

        // no user found
        if ($user_data === false) {
            $admin_users = get_users(array('role' => 'administrator'));
            if (count($admin_users)) {
                $user_data = $admin_users[0];
            } else {
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

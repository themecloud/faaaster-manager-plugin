<?php

class LoginSSO
{

    public function sso() {
        if(!empty($_COOKIE['tc_token'])) {
            $loginResult = $this->login($_COOKIE['tc_token']);
            if($loginResult !== false) {
                die();
            }   
        }
        
        $parameters = array(
            'response_type' => 'token',
            'client_id' => $_SERVER['OAUTH_CLIENT_ID'],
            'scope' => 'username',
            'state' => $_SERVER['OAUTH_STATE']
        );
        
        $uri = $_SERVER['OAUTH_ENDPOINT'] . "?" . http_build_query($parameters);
        
        header("Location: $uri");
    }

    public function login($token)
    {

        require_once ABSPATH . 'wp-content/wp-load.php';
        $conn = curl_init($_SERVER['OAUTH_GET_USER']);

        curl_setopt($conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($conn, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token
        ));

        $result = curl_exec($conn);

        curl_close($conn);

        if ($result === false) {
            return false;
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            return false;
        }

        define('WP_INSTALLING', true);

        $this->getUser($json['name']);
    }

    private function getUser($username)
    {
        $user_data = get_userdata('login', $username);

        if ($user_data === false) {
            wp_redirect(admin_url('index.php'));
            die();
        }

        wp_set_current_user($user_data->ID, $user_data->user_login);
        wp_set_auth_cookie($user_data->ID);
        do_action('wp_login', $user_data->user_login);

        $parsed = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);

        if (!$parsed) {
            wp_redirect("/");
        }
        parse_str($parsed, $query);

        if (isset($query['redirect_to'])) {
            wp_redirect($query['redirect_to']);
            die();
        }

        wp_redirect("/");
    }
}

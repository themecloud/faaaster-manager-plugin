<?php

class LoginSSO
{

    public function authorize($request) {
        $param = $request->get_query_params();

        $OAUTH_STATE = "qc0Bwo99CbYA619fOgsTBxTfBhVE";

        if ($OAUTH_STATE !== $param['state']) {
            exit;
        }

        $access_token = $param['access_token'];
        
        setcookie('tc_token', $access_token, time() + $param['expires_in']);

        $loginResult = $this->login($access_token);
        return $loginResult;
    }

    public function sso() {
        $OAUTH_ENDPOINT = "https://app.themecloud.io/oauth/api/v1.0/oauth2/authorize";
        $OAUTH_CLIENT_ID = "lIpU30BU7nW48OYX+YjYu+7FMTY0MDg4MjYwOA==";
        $OAUTH_STATE = "qc0Bwo99CbYA619fOgsTBxTfBhVE";

        if(!empty($_COOKIE['tc_token'])) {
            $loginResult = $this->login($_COOKIE['tc_token']);
            return $loginResult;
        }
        
        $parameters = array(
            'response_type' => 'token',
            'client_id' => $OAUTH_CLIENT_ID,
            'scope' => 'username',
            'state' => $OAUTH_STATE,
            'redirect_uri' => 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}/?rest_route=/hostmanager/v1/authorize"
        );
        
        $uri = $OAUTH_ENDPOINT . "?" . http_build_query($parameters);
        
        header("Location: $uri");
    }

    public function login($token)
    {
        $OAUTH_GET_USER = "https://app.themecloud.io/oauth/api/v1.0/user/getUser/lIpU30BU7nW48OYX+YjYu+7FMTY0MDg4MjYwOA==";

        require_once ABSPATH . 'wp-content/wp-load.php';
        $conn = curl_init($OAUTH_GET_USER);

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

            return new WP_REST_Response($data_for_response, 500);
        }

        $json = json_decode($result, true);

        if ($json['success'] != true) {
            $data_for_response = array(
                "code"    => "no_success",
                "message" => "OAuth didn't return a success.",
                "data"    => array("status" => 500, "data" => $json)
            );

            return new WP_REST_Response($data_for_response, 500);
        }

        define('WP_INSTALLING', true);

        $this->getUser($json['name']);
    }

    private function getUser($username)
    {
        $user_data = get_userdata('login', $username);

        // no user found
        if ($user_data === false) {
            wp_redirect(admin_url('index.php'));
            exit;
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

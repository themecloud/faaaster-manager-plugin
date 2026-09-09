<?php

/**
 * Short-lived proof that WordPress authenticated a user with editing rights.
 * The nginx/lua layer verifies the token without loading WordPress or its DB.
 */
final class FaaasterAuthCookieManager
{
    public const COOKIE_NAME = 'fstr_auth';
    public const VERSION = 'v1';
    public const TTL_SECONDS = 1800;
    public const RENEW_BEFORE_SECONDS = 900;

    public function init()
    {
        add_action('send_headers', array($this, 'refresh'));
        add_action('admin_init', array($this, 'refresh'));
        add_filter('rest_post_dispatch', array($this, 'refreshRestResponse'));
        add_action('clear_auth_cookie', array($this, 'clear'));
    }

    public static function buildToken($key, $userId, $level, $expiresAt)
    {
        $message = implode('|', array(
            self::COOKIE_NAME,
            self::VERSION,
            (string) $userId,
            (string) $level,
            (string) $expiresAt,
        ));
        $mac = hash_hmac('sha256', $message, (string) $key);

        return implode('.', array(
            self::VERSION,
            (string) $userId,
            (string) $level,
            (string) $expiresAt,
            $mac,
        ));
    }

    public function refresh()
    {
        $key = defined('WP_API_KEY') ? (string) WP_API_KEY : '';
        if ($key === '' || !is_user_logged_in() || !current_user_can('edit_posts')) {
            if (isset($_COOKIE[self::COOKIE_NAME])) {
                $this->clear();
            }
            return;
        }

        $level = current_user_can('manage_options') ? 'admin' : 'editor';
        $userId = get_current_user_id();
        $now = time();
        $existing = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (is_string($existing) && strlen($existing) <= 192) {
            $parts = explode('.', $existing);
            if (count($parts) === 5 && ctype_digit($parts[3])) {
                $expiresAt = (int) $parts[3];
                if ($expiresAt >= $now + self::RENEW_BEFORE_SECONDS
                    && $expiresAt <= $now + self::TTL_SECONDS
                    && hash_equals(self::buildToken($key, $userId, $level, $expiresAt), $existing)) {
                    return;
                }
            }
        }
        $expiresAt = $now + self::TTL_SECONDS;
        $token = self::buildToken($key, $userId, $level, $expiresAt);

        setcookie(self::COOKIE_NAME, $token, $this->cookieOptions($expiresAt));
    }

    public function refreshRestResponse($response)
    {
        // REST authentication (including the cookie nonce) and permissions have
        // already run. Never grant a proof from a rejected REST request.
        if (!is_wp_error($response) && $response->get_status() < 400) {
            $this->refresh();
        }
        return $response;
    }

    public function clear()
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(time() - 3600));
    }

    private function cookieOptions($expiresAt)
    {
        return array(
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        );
    }
}

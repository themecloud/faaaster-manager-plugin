<?php

/**
 * Short-lived proof that WordPress authenticated a user with editing rights.
 * The nginx/lua layer verifies the token without loading WordPress or its DB.
 */
final class FaaasterAuthCookieManager
{
    public const COOKIE_NAME = 'fstr_auth';
    public const VERSION = 'v1';
    public const TTL_SECONDS = 300;

    public function init()
    {
        add_action('send_headers', array($this, 'refresh'));
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
        $expiresAt = time() + self::TTL_SECONDS;
        $token = self::buildToken($key, get_current_user_id(), $level, $expiresAt);

        setcookie(self::COOKIE_NAME, $token, $this->cookieOptions($expiresAt));
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

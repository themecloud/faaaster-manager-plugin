<?php

class FaaasterErrorHandler
{
    private $app_id;
    private $branch;
    private $wp_api_key;

    public function __construct($app_id, $branch, $wp_api_key)
    {
        $this->app_id = $app_id;
        $this->branch = $branch;
        $this->wp_api_key = $wp_api_key;
    }

    public function init()
    {
        set_error_handler([$this, 'handler']);
        register_shutdown_function([$this, 'shutdownHandler']);
    }

    public function handler($code, $message, $file, $line, $ctx = [])
    {

        switch ($code) {
            case E_ERROR:
                $error_type = 'Fatal Error';
                break;
            case E_PARSE:
                $error_type = 'Parse Error';
                break;
            case E_CORE_ERROR:
                $error_type = 'Core Error';
                break;
            case E_COMPILE_ERROR:
                $error_type = 'Compile Error';
                break;
            case E_USER_ERROR:
                $error_type = 'User Error';
                break;
            case E_RECOVERABLE_ERROR:
                $error_type = 'Recoverable Error';
                break;
            default:
                $error_type = 'Other Error';
                break;
        }
        $params = [
            'message' => $message,
            'file' => $file,
            'code' => $code,
            'type' => $error_type,
            'line' => $line,
        ];

        if ($this->errorAlreadyExist($params)) {
            return;
        }

        $this->saveError($params);
    }

    public function shutdownHandler()
    {
        $lastError = error_get_last();
        if (null !== $lastError) {
            $this->handler(
                $lastError['type'],
                $lastError['message'],
                $lastError['file'],
                $lastError['line']
            );
        }
    }

    private function errorAlreadyExist($params)
    {
        $transient = get_transient('faaaster_errors_sent');
        $md5 = $this->serializeError($params);

        if (!$transient) {
            set_transient('faaaster_errors_sent', [$md5], 12 * HOUR_IN_SECONDS);
            return false;
        }

        if (in_array($md5, $transient)) {
            return true;
        }

        $transient[] = $md5;
        set_transient('faaaster_errors_sent', $transient, 12 * HOUR_IN_SECONDS);
        return false;
    }

    private function saveError($params)
    {
        // Immediately send to API if possible
        if (function_exists('wp_remote_post') && $params['type'] !== 'Other Error') {
            $this->sendErrorToAPI($params);
        } else {
            // Store for later if WordPress functions aren't available
            $errors = get_transient('faaaster_stored_errors') ?: [];
            $errors[] = $params;
            set_transient('faaaster_stored_errors', $errors, DAY_IN_SECONDS);
        }
    }

    private function sendErrorToAPI($params)
    {
        $url = FAAASTER_API_BASE . "/api/webhook-event/";
        $data = [
            'event' => "php_error",
            'data' => [
                'message' => $params['message'],
                'file' => $params['file'],
                'line' => $params['line'],
                'code' => $params['code'],
                'date' => current_time('mysql'),
            ],
            'app_id' => $this->app_id,
            'instance' => $this->branch,
        ];

        wp_remote_post($url, [
            'body' => json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->wp_api_key,
            ],
        ]);
    }

    private function serializeError($params)
    {
        return md5(vsprintf('%s-%s-%s-%s', [
            $params['file'],
            $params['line'],
            $params['code'],
            $params['message']
        ]));
    }
}

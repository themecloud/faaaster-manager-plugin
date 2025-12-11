<?php

class FaaasterErrorHandler
{
    private $app_id;
    private $branch;
    private $wp_api_key;

    // Maximum errors to send per hour
    private const MAX_ERRORS_PER_HOUR = 10;

    // Paths to ignore (errors from these paths won't be reported)
    private const IGNORED_PATHS = [
        'faaaster-manager-plugin',
    ];

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
        // Skip errors from ignored paths (like the manager plugin itself)
        if ($this->shouldIgnoreFile($file)) {
            return false;
        }

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

        // Check rate limit before sending
        if ($this->isRateLimited()) {
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

    /**
     * Check if the file path should be ignored
     */
    private function shouldIgnoreFile($file)
    {
        foreach (self::IGNORED_PATHS as $ignoredPath) {
            if (strpos($file, $ignoredPath) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if we've exceeded the rate limit
     */
    private function isRateLimited()
    {
        $count = get_transient('faaaster_error_count') ?: 0;

        if ($count >= self::MAX_ERRORS_PER_HOUR) {
            return true;
        }

        set_transient('faaaster_error_count', $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    /**
     * Normalize error message for better deduplication
     * Removes dynamic parts like IDs, timestamps, memory addresses
     */
    private function normalizeMessage($message)
    {
        // Remove memory addresses (e.g., Object(0x7f...))
        $message = preg_replace('/0x[0-9a-fA-F]+/', '0x...', $message);

        // Remove numeric IDs that might change
        $message = preg_replace('/\b\d{5,}\b/', 'ID', $message);

        // Remove timestamps in various formats
        $message = preg_replace('/\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}/', 'TIMESTAMP', $message);

        // Remove UUIDs
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'UUID', $message);

        return $message;
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

        // Limit the array size to prevent memory issues
        if (count($transient) > 100) {
            $transient = array_slice($transient, -50);
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
        // Use normalized message for better deduplication of similar errors
        $normalizedMessage = $this->normalizeMessage($params['message']);

        return md5(vsprintf('%s-%s-%s-%s', [
            $params['file'],
            $params['line'],
            $params['code'],
            $normalizedMessage
        ]));
    }
}

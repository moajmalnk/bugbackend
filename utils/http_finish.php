<?php
/**
 * Why: Mail / WhatsApp / FCM are slow. Flush the JSON response to the browser
 * first, then keep PHP alive for side-effects so Save/Verify never waits on SMTP.
 */

if (!function_exists('br_send_json_and_finish')) {
    /**
     * @param mixed $data
     */
    function br_send_json_and_finish(
        int $statusCode,
        string $message,
        $data = null,
        ?bool $success = null
    ): void {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }

        $response = [
            'success' => $success !== null ? $success : ($statusCode >= 200 && $statusCode < 300),
            'message' => $message,
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            return;
        }

        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}

if (!function_exists('br_after_response')) {
    /**
     * Run $work after the HTTP response has been flushed to the client.
     *
     * @param callable():void $work
     * @param mixed $data
     */
    function br_after_response(
        callable $work,
        int $statusCode,
        string $message,
        $data = null,
        ?bool $success = null
    ): void {
        br_send_json_and_finish($statusCode, $message, $data, $success);
        try {
            $work();
        } catch (Throwable $e) {
            error_log('br_after_response deferred work: ' . $e->getMessage());
        }
    }
}

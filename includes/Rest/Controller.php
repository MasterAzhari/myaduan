<?php
namespace MyAduan\Rest;

use MyAduan\Cases;
use MyAduan\WorkflowException;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Controller
{
    public const NS = 'myaduan/v1';

    public function __construct(protected Cases $cases)
    {
    }

    abstract public function register(): void;

    /** Runs a handler and turns rule violations into clean REST errors. */
    protected function respond(callable $fn, int $status = 200): \WP_REST_Response|\WP_Error
    {
        try {
            $response = rest_ensure_response($fn());
            $response->set_status($status);
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        } catch (FieldErrors $e) {
            return new \WP_Error('myaduan_invalid_fields', 'Sila semak maklumat yang ditanda.', ['status' => 422, 'fields' => $e->fields]);
        } catch (WorkflowException $e) {
            return new \WP_Error('myaduan_' . $e->error_code(), $e->getMessage(), ['status' => $e->status()]);
        } catch (\Throwable $e) {
            error_log('[myaduan] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return new \WP_Error('myaduan_error', 'Ralat sistem. Cuba sebentar lagi.', ['status' => 500]);
        }
    }
}

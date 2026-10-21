<?php
namespace MyAduan\Rest;

use MyAduan\Access;
use MyAduan\Catalog;
use MyAduan\Users;
use MyAduan\Workflow;
use MyAduan\WorkflowException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoints for the admin portal. Cookie auth with the X-WP-Nonce header;
 * without a valid nonce WordPress treats the request as logged out.
 */
final class AdminController extends Controller
{
    /** Text fields accepted by operations, and whether they keep line breaks. */
    private const OP_FIELDS = [
        'note' => true, 'title' => false, 'category_code' => false, 'dun_slug' => false, 'priority' => false,
        'next_action' => false, 'followup_at' => false, 'closure_reason' => false, 'resolution' => true,
        'public_draft' => true, 'public_message' => true,
    ];

    public function register(): void
    {
        $base = '/admin';
        register_rest_route(self::NS, $base . '/me', [
            'methods' => 'GET', 'callback' => [$this, 'me'], 'permission_callback' => [$this, 'can_access'],
        ]);
        register_rest_route(self::NS, $base . '/catalog', [
            'methods' => 'GET', 'callback' => [$this, 'catalog'], 'permission_callback' => [$this, 'can_access'],
        ]);
        register_rest_route(self::NS, $base . '/stats', [
            'methods' => 'GET', 'callback' => [$this, 'stats'], 'permission_callback' => [$this, 'can_access'],
        ]);
        register_rest_route(self::NS, $base . '/cases', [
            'methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'can_work'],
        ]);
        register_rest_route(self::NS, $base . '/cases/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'get'], 'permission_callback' => [$this, 'can_work'],
        ]);
        register_rest_route(self::NS, $base . '/files/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [$this, 'file'], 'permission_callback' => [$this, 'can_work'],
        ]);
        register_rest_route(self::NS, $base . '/cases/(?P<id>\d+)/operations', [
            'methods' => 'POST', 'callback' => [$this, 'operate'], 'permission_callback' => [$this, 'can_work'],
        ]);
        register_rest_route(self::NS, $base . '/users', [
            ['methods' => 'GET', 'callback' => [$this, 'users'], 'permission_callback' => [$this, 'can_manage']],
            ['methods' => 'POST', 'callback' => [$this, 'create_user'], 'permission_callback' => [$this, 'can_manage']],
        ]);
        register_rest_route(self::NS, $base . '/users/(?P<id>\d+)', [
            'methods' => 'POST', 'callback' => [$this, 'update_user'], 'permission_callback' => [$this, 'can_manage'],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can(Access::CAP_MANAGE);
    }

    public function can_access(): bool
    {
        return current_user_can(Access::CAP_ACCESS);
    }

    /** Case data is for Super Admin and officers, not report-only viewers. */
    public function can_work(): bool
    {
        return current_user_can(Access::CAP_MANAGE) || current_user_can(Access::CAP_WORK);
    }

    public function me(): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () {
            $a = Access::actor();
            return [
                'id' => $a['id'],
                'name' => $a['name'],
                'role' => $a['manager'] ? 'superadmin' : ($a['work'] ? 'officer' : 'viewer'),
                'can' => ['manage' => $a['manager'], 'work' => $a['work'], 'reports' => $a['reports']],
                'policy' => $a['policy'],
            ];
        });
    }

    public function catalog(): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () {
            $catalog = new Catalog();
            $officers = [];
            if (current_user_can(Access::CAP_MANAGE)) {
                $users = get_users(['capability__in' => [Access::CAP_WORK, Access::CAP_MANAGE], 'orderby' => 'display_name', 'fields' => 'all']);
                foreach ($users as $u) {
                    $a = Access::actor($u);
                    $officers[] = ['id' => $a['id'], 'name' => $a['name'], 'manager' => $a['manager'], 'policy' => $a['policy']];
                }
            }
            return [
                'categories' => $catalog->categories(),
                'areas' => $catalog->areas(),
                'statuses' => Workflow::STATUSES,
                'priorities' => Workflow::PRIORITIES,
                'closure_reasons' => Workflow::CLOSURE_REASONS,
                'operations' => Workflow::OPERATIONS,
                'officers' => $officers,
            ];
        });
    }

    public function stats(): \WP_REST_Response|\WP_Error
    {
        return $this->respond(fn() => $this->cases->stats(Access::actor()));
    }

    public function list(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () use ($req) {
            $filters = [];
            foreach (['status', 'owner', 'category', 'dun', 'area', 'flag', 'q', 'ic', 'from', 'to'] as $key) {
                $value = $req->get_param($key);
                if (is_scalar($value) && $value !== '') {
                    $filters[$key] = mb_substr(sanitize_text_field((string) $value), 0, 100);
                }
            }
            return $this->cases->list(Access::actor(), $filters, max(1, (int) $req->get_param('page')));
        });
    }

    public function get(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(fn() => $this->cases->get(Access::actor(), (int) $req['id']));
    }

    public function operate(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () use ($req) {
            $op = (string) $req->get_param('op');
            $version = $req->get_param('version');
            if (!is_numeric($version)) {
                throw new WorkflowException('Versi rekod diperlukan.', 'invalid_request', 400);
            }
            $params = $req->get_json_params() ?: $req->get_body_params();
            $input = [];
            foreach (self::OP_FIELDS as $field => $multiline) {
                if (array_key_exists($field, $params)) {
                    $value = $params[$field];
                    $input[$field] = $value === null ? null : ($multiline ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value));
                }
            }
            foreach (['owner_id', 'area_id'] as $field) {
                if (array_key_exists($field, $params)) {
                    $input[$field] = $params[$field] === null || $params[$field] === '' ? null : absint($params[$field]);
                }
            }
            return $this->cases->operate(Access::actor(), (int) $req['id'], (int) $version, $op, $input, strtolower((string) $req->get_param('request_id')));
        });
    }

    public function users(): \WP_REST_Response|\WP_Error
    {
        return $this->respond(fn() => $this->users_service()->list());
    }

    public function create_user(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(fn() => $this->users_service()->create($req->get_json_params() ?: $req->get_body_params()), 201);
    }

    public function update_user(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(fn() => $this->users_service()->update((int) $req['id'], $req->get_json_params() ?: $req->get_body_params(), get_current_user_id()));
    }

    private function users_service(): Users
    {
        return new Users($GLOBALS['wpdb'], new Catalog());
    }

    /** Streams a private attachment. Links work only for a logged-in user with access to the case. */
    public function file(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        try {
            $file = $this->cases->attachment(Access::actor(), (int) $req['id']);
        } catch (WorkflowException $e) {
            return new \WP_Error('myaduan_' . $e->error_code(), $e->getMessage(), ['status' => $e->status()]);
        }
        if (apply_filters('myaduan_stream_files', true)) {
            nocache_headers();
            header('Content-Type: ' . $file['mime']);
            header('Content-Length: ' . filesize($file['path']));
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $file['name']) . '"');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
            readfile($file['path']);
            exit;
        }
        // Tests turn streaming off and inspect the resolved file instead.
        return rest_ensure_response(['mime' => $file['mime'], 'name' => $file['name'], 'size' => filesize($file['path']), 'sha256' => hash_file('sha256', $file['path'])]);
    }
}

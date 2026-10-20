<?php
namespace MyAduan\Rest;

use MyAduan\Access;
use MyAduan\Catalog;
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
        register_rest_route(self::NS, $base . '/cases/(?P<id>\d+)/operations', [
            'methods' => 'POST', 'callback' => [$this, 'operate'], 'permission_callback' => [$this, 'can_work'],
        ]);
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
            foreach (['status', 'owner', 'category', 'dun', 'area', 'q', 'ic', 'from', 'to'] as $key) {
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
}

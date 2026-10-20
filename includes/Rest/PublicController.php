<?php
namespace MyAduan\Rest;

use MyAduan\RateLimit;
use MyAduan\Validator;
use MyAduan\WorkflowException;

if (!defined('ABSPATH')) {
    exit;
}

/** Anonymous endpoints: submit a complaint and check its status. */
final class PublicController extends Controller
{
    public const SUBMIT_PER_HOUR = 5;
    public const TRACK_PER_HOUR = 30;

    public function register(): void
    {
        register_rest_route(self::NS, '/complaints', [
            'methods' => 'POST',
            'callback' => [$this, 'submit'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/track', [
            'methods' => 'POST',
            'callback' => [$this, 'track'],
            'permission_callback' => '__return_true',
            'args' => ['query' => ['type' => 'string', 'required' => true]],
        ]);
    }

    public function submit(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () use ($req) {
            // Honeypot: real users never fill this hidden field.
            if ((string) $req->get_param('website') !== '') {
                throw new WorkflowException('Penghantaran ditolak.', 'rejected', 400);
            }
            $client_key = strtolower((string) $req->get_param('client_key'));
            $check = Validator::complaint([
                'name' => (string) $req->get_param('name'),
                'ic' => (string) $req->get_param('ic'),
                'phone' => (string) $req->get_param('phone'),
                'details' => (string) $req->get_param('details'),
                'location' => (string) $req->get_param('location'),
                'lat' => $req->get_param('lat'),
                'lng' => $req->get_param('lng'),
                'consent' => in_array($req->get_param('consent'), [true, 'true', '1', 1], true),
            ]);
            if ($check['errors']) {
                throw new FieldErrors($check['errors']);
            }
            $data = $check['data'];
            $hash = hash('sha256', wp_json_encode($data, JSON_UNESCAPED_UNICODE));

            if (!RateLimit::allows('submit', self::SUBMIT_PER_HOUR)) {
                // An exact retry of an earlier submission still gets its reference back.
                $result = $this->cases->existing_submission($client_key, $hash);
                if (!$result) {
                    throw new WorkflowException('Terlalu banyak aduan dari peranti ini. Cuba lagi dalam sejam.', 'rate_limited', 429);
                }
            } else {
                $result = $this->cases->create($data, $client_key, $hash);
                if (!$result['duplicate']) {
                    RateLimit::hit('submit', HOUR_IN_SECONDS);
                }
            }
            return ['reference' => $result['reference'], 'duplicate' => $result['duplicate']];
        }, 201);
    }

    public function track(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return $this->respond(function () use ($req) {
            if (!RateLimit::allows('track', self::TRACK_PER_HOUR)) {
                throw new WorkflowException('Terlalu banyak carian. Cuba lagi kemudian.', 'rate_limited', 429);
            }
            RateLimit::hit('track', HOUR_IN_SECONDS);
            return $this->cases->track((string) $req->get_param('query'));
        });
    }
}

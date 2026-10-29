<?php
namespace MyAduan\Rest;

use MyAduan\Files;
use MyAduan\ImageProcessor;
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
                'email' => (string) $req->get_param('email'),
                'details' => (string) $req->get_param('details'),
                'location' => (string) $req->get_param('location'),
                'lat' => $req->get_param('lat'),
                'lng' => $req->get_param('lng'),
                'consent' => in_array($req->get_param('consent'), [true, 'true', '1', 1], true),
            ]);
            $errors = $check['errors'];
            $uploads = [];
            try {
                $uploads = $this->uploads(Files::normalize_uploads($req->get_file_params()));
            } catch (WorkflowException $e) {
                $errors['images'] = $e->getMessage();
            }
            if ($check['data']['details'] === '' && !$uploads && !isset($errors['images'])) {
                $errors['details'] = 'Sertakan gambar atau tulis ringkas apa masalahnya.';
            }
            if ($errors) {
                throw new FieldErrors($errors);
            }
            $data = $check['data'];
            // The hash covers the photos too, so a retry with different photos is not a silent duplicate.
            $hash = hash('sha256', wp_json_encode($data, JSON_UNESCAPED_UNICODE) . implode('', array_column($uploads, 'sha256')));

            // An exact retry of an earlier submission gets its reference back, even over the limit.
            $result = $this->cases->existing_submission($client_key, $hash);
            if (!$result) {
                // Count the attempt before decoding any image, so the limit also caps that work.
                if (!RateLimit::allows('submit', self::SUBMIT_PER_HOUR)) {
                    throw new WorkflowException('Terlalu banyak aduan dari peranti ini. Cuba lagi dalam sejam.', 'rate_limited', 429);
                }
                RateLimit::hit('submit', HOUR_IN_SECONDS);
                $images = [];
                try {
                    foreach ($uploads as $i => $u) {
                        $img = ImageProcessor::process((string) file_get_contents($u['path']), 'Gambar ' . ($i + 1));
                        $img['name'] = $u['name'] ?: 'gambar-' . ($i + 1) . '.' . $img['ext'];
                        $images[] = $img;
                    }
                } catch (WorkflowException $e) {
                    throw new FieldErrors(['images' => $e->getMessage()]);
                }
                $result = $this->cases->create($data, $client_key, $hash, $images);
            }
            return ['reference' => $result['reference'], 'duplicate' => $result['duplicate'], 'images' => count($uploads)];
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

    /**
     * Cheap checks on uploaded files (count, size, origin) without decoding them.
     *
     * @param list<array{tmp_name:string, name:string, error:int, size:int}> $uploads
     * @return list<array{path:string, name:string, sha256:string}>
     */
    private function uploads(array $uploads): array
    {
        if (count($uploads) > Files::MAX_PUBLIC) {
            throw new WorkflowException('Maksimum ' . Files::MAX_PUBLIC . ' gambar.', 'invalid_image');
        }
        $tmp_root = realpath(ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
        $out = [];
        foreach ($uploads as $i => $u) {
            $label = 'Gambar ' . ($i + 1);
            if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new WorkflowException("$label mesti kurang daripada 5 MB.", 'invalid_image');
            }
            $path = realpath($u['tmp_name']);
            // Only PHP's own upload temp files are accepted, never an arbitrary server path.
            if ($u['error'] !== UPLOAD_ERR_OK || !$path || !$tmp_root || !str_starts_with($path, $tmp_root . DIRECTORY_SEPARATOR)) {
                throw new WorkflowException("$label gagal dimuat naik. Cuba lagi.", 'invalid_image');
            }
            if (filesize($path) > ImageProcessor::MAX_BYTES) {
                throw new WorkflowException("$label mesti kurang daripada 5 MB.", 'invalid_image');
            }
            $out[] = ['path' => $path, 'name' => $u['name'], 'sha256' => hash_file('sha256', $path)];
        }
        return $out;
    }
}

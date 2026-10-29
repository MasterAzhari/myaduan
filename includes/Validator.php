<?php
namespace MyAduan;

/** Pure input validation for the public two-page form. */
final class Validator
{
    public const NAME_MAX = 150;
    public const DETAILS_MIN = 5;
    public const DETAILS_MAX = 5000;
    public const LOCATION_MAX = 500;

    /** Returns 12 digits or null. Checks the YYMMDD birth date part. */
    public static function normalize_ic(string $ic): ?string
    {
        $digits = preg_replace('/[\s-]/', '', $ic);
        if (!preg_match('/^\d{12}$/D', $digits)) {
            return null;
        }
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        return $digits;
    }

    /** Malaysian numbers to 60XXXXXXXXX form, or null. */
    public static function normalize_phone(string $phone): ?string
    {
        $digits = preg_replace('/[\s\-()+]/', '', $phone);
        if (!preg_match('/^\d+$/D', $digits)) {
            return null;
        }
        if (str_starts_with($digits, '60')) {
            $digits = substr($digits, 1);
        }
        // Local form: 0 + 8 to 10 digits (landline 03-xxxx xxxx, mobile 01x-xxx xxxx(x)).
        if (!preg_match('/^0\d{8,10}$/D', $digits)) {
            return null;
        }
        return '6' . $digits;
    }

    /**
     * Validates the public submission.
     *
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public static function complaint(array $in): array
    {
        $errors = [];
        $text = static fn(string $k): string => trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')));

        $name = $text('name');
        // A short name is enough; the complainant is reached by phone.
        if (mb_strlen($name) < 2 || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'Nama diperlukan (2 hingga 150 aksara).';
        }

        $ic = self::normalize_ic((string) ($in['ic'] ?? ''));
        if ($ic === null) {
            $errors['ic'] = 'No. IC mesti 12 digit yang sah.';
        }

        $phone = self::normalize_phone((string) ($in['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'No. telefon tidak sah.';
        }

        // Optional: only used to send the complainant their reference number.
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && (mb_strlen($email) > 190 || !is_email($email))) {
            $errors['email'] = 'Alamat e-mel tidak sah. Kosongkan jika tiada.';
        }

        // Optional when photos come with it: the caller checks that one of the two is there.
        // Keep line breaks in the narrative; only trim the ends.
        $details = trim(str_replace("\r\n", "\n", (string) ($in['details'] ?? '')));
        $len = mb_strlen($details);
        if ($len > 0 && ($len < self::DETAILS_MIN || $len > self::DETAILS_MAX)) {
            $errors['details'] = 'Keterangan masalah perlu 5 hingga 5000 aksara.';
        }

        $location = $text('location');
        if (mb_strlen($location) < 5 || mb_strlen($location) > self::LOCATION_MAX) {
            $errors['location'] = 'Lokasi diperlukan (5 hingga 500 aksara).';
        }

        if (($in['consent'] ?? false) !== true) {
            $errors['consent'] = 'Sila sahkan persetujuan dan maklumat yang benar.';
        }

        $lat = $lng = null;
        if (isset($in['lat'], $in['lng']) && $in['lat'] !== '' && $in['lng'] !== '') {
            $lat = filter_var($in['lat'], FILTER_VALIDATE_FLOAT);
            $lng = filter_var($in['lng'], FILTER_VALIDATE_FLOAT);
            // Rough Malaysia bounding box; GPS is optional, so drop rather than reject.
            if ($lat === false || $lng === false || $lat < 0.5 || $lat > 7.5 || $lng < 99.5 || $lng > 119.5) {
                $lat = $lng = null;
            }
        }

        return [
            'data' => compact('name', 'ic', 'phone', 'email', 'details', 'location', 'lat', 'lng'),
            'errors' => $errors,
        ];
    }

    /** Short provisional title from the narrative, or from the location when there is none; Super Admin can correct it. */
    public static function provisional_title(string $details, int $max = 80, string $location = ''): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $details));
        if ($flat === '') {
            $flat = 'Aduan di ' . trim(preg_replace('/\s+/u', ' ', $location));
        }
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $flat, $m) && mb_strlen($m[1]) <= $max) {
            return $m[1];
        }
        if (mb_strlen($flat) <= $max) {
            return $flat;
        }
        $cut = mb_substr($flat, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,;:-") . '…';
    }
}

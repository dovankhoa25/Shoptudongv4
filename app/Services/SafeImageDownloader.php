<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\Downloaders\Downloader;

/** Public image imports only. Pin the checked IP so DNS cannot change at connect time. */
class SafeImageDownloader implements Downloader
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function getTempFile(string $url): string
    {
        $file = tempnam(sys_get_temp_dir(), 'safe-image-');
        if ($file === false) {
            $this->reject();
        }
        try {
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                [$host, $port, $ip] = $this->resolveTarget($url);
                $handle = fopen($file, 'wb');
                $curl = curl_init($url);
                $bytes = 0;
                $location = null;
                curl_setopt_array($curl, [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROXY => '',
                    CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip],
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'ShopImageImporter/1.0',
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                        if (stripos($header, 'Location:') === 0) {
                            $location = trim(substr($header, 9));
                        }

                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$bytes): int {
                        $bytes += strlen($data);

                        return $bytes > 10 * 1024 * 1024 ? 0 : (int) fwrite($handle, $data);
                    },
                ]);
                try {
                    $ok = curl_exec($curl);
                    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                } finally {
                    curl_close($curl);
                    fclose($handle);
                }
                if ($ok === false) {
                    $this->reject();
                }
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null) {
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }
                if ($status !== 200 || ! in_array(mime_content_type($file), self::MIME_TYPES, true)
                    || @getimagesize($file) === false) {
                    $this->reject();
                }

                return $file;
            }
            $this->reject();
        } catch (\Throwable $exception) {
            @unlink($file);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $this->reject();
        }
    }

    /** IPv4 public destinations on standard web ports; fail closed on DNS errors. */
    public function resolveTarget(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->reject();
        }
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true) || ! preg_match('/\A[a-z0-9.-]+\z/', $host)) {
            $this->reject();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? [$host] : $this->resolveAddresses($host);
        if ($addresses === []) {
            $this->reject();
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                $this->reject();
            }
        }

        return [$host, $port, $addresses[0]];
    }

    protected function resolveAddresses(string $host): array
    {
        return array_column(@dns_get_record($host, DNS_A) ?: [], 'ip');
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'image_url' => 'Chỉ chấp nhận ảnh JPG, PNG, GIF, WebP hoặc AVIF tối đa 10 MB từ URL công khai hợp lệ.',
        ]);
    }
}

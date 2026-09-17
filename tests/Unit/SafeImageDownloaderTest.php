<?php

namespace Tests\Unit;

use App\Services\SafeImageDownloader;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeImageDownloaderTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_rejects_non_public_or_ambiguous_urls_before_connecting(string $url): void
    {
        $this->expectException(ValidationException::class);
        (new SafeImageDownloader)->resolveTarget($url);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://127.0.0.1/a.png', 'http://10.0.0.1/a', 'http://169.254.169.254/a',
            'http://192.168.1.2/a', 'http://172.16.0.1/a', 'http://100.64.0.1/a',
            'http://0.0.0.0/a', 'http://[::1]/a', 'http://[::ffff:127.0.0.1]/a',
            'file:///etc/passwd', 'https://user:pass@8.8.8.8/a', 'https://8.8.8.8:8080/a',
            "https://8.8.8.8/\r\nHost:x", 'https://8.8.8.8\\@127.0.0.1/a',
        ]);
    }

    public function test_checks_every_dns_answer_and_pins_public_address(): void
    {
        $downloader = new class extends SafeImageDownloader
        {
            public array $addresses = ['8.8.8.8'];

            protected function resolveAddresses(string $host): array
            {
                return $this->addresses;
            }
        };
        $this->assertSame(['images.example.com', 443, '8.8.8.8'], $downloader->resolveTarget('https://images.example.com/a.png'));
        $downloader->addresses[] = '127.0.0.1';
        $this->expectException(ValidationException::class);
        $downloader->resolveTarget('https://images.example.com/a.png');
    }

    public function test_rejects_html_svg_and_document_extensions_at_media_boundary(): void
    {
        $observer = new \App\Observers\ImageMediaObserver;
        foreach ([['text/html', 'x.png'], ['image/svg+xml', 'x.svg'], ['image/png', 'x.html']] as [$mime, $name]) {
            $media = new \Spatie\MediaLibrary\MediaCollections\Models\Media(['mime_type' => $mime, 'file_name' => $name]);
            try {
                $observer->creating($media);
                $this->fail('Unsafe media accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image', $exception->errors());
            }
        }
        $observer->creating(new \Spatie\MediaLibrary\MediaCollections\Models\Media(['mime_type' => 'image/png', 'file_name' => 'safe.png']));
    }
}

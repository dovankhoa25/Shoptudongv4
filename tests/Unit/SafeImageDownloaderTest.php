<?php

namespace Tests\Unit;

use App\Services\SafeImageDownloader;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeImageDownloaderTest extends TestCase
{
    #[DataProvider('imageUrls')]
    public function test_rejects_all_remote_imports_including_valid_public_urls(string $url): void
    {
        $this->expectException(ValidationException::class);
        (new SafeImageDownloader)->getTempFile($url);
    }

    public static function imageUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'https://images.example.com/image.png', 'https://8.8.8.8/image.png',
            'http://127.0.0.1/a.png', 'http://10.0.0.1/a', 'http://169.254.169.254/a',
            'http://192.168.1.2/a', 'http://172.16.0.1/a', 'http://100.64.0.1/a',
            'http://0.0.0.0/a', 'http://[::1]/a', 'http://[::ffff:127.0.0.1]/a',
            'file:///etc/passwd', 'https://user:pass@8.8.8.8/a', 'https://8.8.8.8:8080/a',
            "https://8.8.8.8/\r\nHost:x", 'https://8.8.8.8\\@127.0.0.1/a',
        ]);
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

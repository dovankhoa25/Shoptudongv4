<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\Downloaders\Downloader;

/** Block remote imports, including legacy queued jobs, before any network I/O. */
class SafeImageDownloader implements Downloader
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public const DISABLED_MESSAGE = 'Không hỗ trợ nhập ảnh bằng URL. Vui lòng tải ảnh từ thiết bị.';

    public function getTempFile(string $url): string
    {
        throw ValidationException::withMessages(['image_url' => self::DISABLED_MESSAGE]);
    }
}

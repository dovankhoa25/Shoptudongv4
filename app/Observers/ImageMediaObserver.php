<?php

namespace App\Observers;

use App\Services\SafeImageDownloader;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImageMediaObserver
{
    public function creating(Media $media): void
    {
        // All current media collections contain images. Reject executable/document extensions too:
        // a valid image saved as .html may otherwise be served by the web server as HTML.
        if (! in_array($media->mime_type, SafeImageDownloader::MIME_TYPES, true)
            || ! in_array(strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            throw ValidationException::withMessages(['image' => 'Tệp phải là ảnh JPG, PNG, GIF, WebP hoặc AVIF hợp lệ.']);
        }
    }
}

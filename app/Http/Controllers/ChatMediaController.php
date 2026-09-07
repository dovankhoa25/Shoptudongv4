<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMediaController extends Controller
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        abort_unless(
            $request->hasValidSignature()
                && $media->collection_name === ChatMessage::MEDIA_COLLECTION_IMAGES
                && $media->model_type === ChatMessage::class,
            404,
        );

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $media->file_name, [
            'Content-Type' => $media->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}

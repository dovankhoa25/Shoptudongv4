<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SepayWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $key = (string) config('services.sepay.webhook_key');
        $authorization = (string) $this->header('Authorization');

        return $key !== '' && hash_equals('Apikey '.$key, $authorization);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'gateway' => ['required', 'string', 'max:50'],
            'transactionDate' => ['required', 'date_format:Y-m-d H:i:s'],
            'accountNumber' => ['required', 'string', 'max:100'],
            'subAccount' => ['nullable', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100'],
            'content' => ['nullable', 'string', 'max:5000'],
            'transferType' => ['required', 'in:in'],
            'description' => ['nullable', 'string', 'max:5000'],
            'transferAmount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'referenceCode' => ['nullable', 'string', 'max:191'],
            'accumulated' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401));
    }
}

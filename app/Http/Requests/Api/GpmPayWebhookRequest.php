<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GpmPayWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = (string) config('services.gpmpay.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $mode = strtolower((string) config('services.gpmpay.auth_mode', 'hmac'));

        if ($mode === 'api_key') {
            // GPMPay sends the raw secret, without an Apikey or Bearer prefix.
            return hash_equals($secret, (string) $this->header('Authorization'));
        }

        if ($mode !== 'hmac'
            || preg_match('/\At=([0-9]{1,12}),v1=([a-f0-9]{64})\z/i', (string) $this->header('X-GPMPay-Signature'), $parts) !== 1) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $parts[1]) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts[1].'.'.$this->getContent(), $secret);

        return hash_equals($expected, strtolower($parts[2]));
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'gateway' => ['required', 'string', 'max:50'],
            'transactionDate' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
            'accountNumber' => ['required', 'string', 'max:100'],
            'subAccount' => ['nullable', 'string', 'max:100'],
            'content' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'transferType' => ['required', 'in:in,out'],
            'transferAmount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'referenceCode' => ['nullable', 'string', 'max:191'],
            'accumulated' => ['nullable', 'integer', 'min:0'],
            'source' => ['required', 'in:REAL,SIMULATED'],
            'test' => ['sometimes', 'boolean'],
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

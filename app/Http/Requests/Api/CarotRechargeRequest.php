<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CarotRechargeRequest extends FormRequest
{
    private const VALID_AMOUNTS = [
        10000,
        20000,
        30000,
        50000,
        100000,
        200000,
        300000,
        500000,
        1000000,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['one', 'list'])],
            'account_name' => ['required_if:type,one', 'string', 'max:255'],
            'server_id' => ['required_if:type,one', 'integer', 'min:1', 'max:100'],
            'amount' => ['required_if:type,one', 'integer', Rule::in(self::VALID_AMOUNTS)],
            'list' => ['required_if:type,list', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Vui long chon kieu nap',
            'type.in' => 'Kieu nap chi ho tro one hoac list',
            'account_name.required_if' => 'Vui long nhap tai khoan can nap',
            'server_id.required_if' => 'Vui long nhap server',
            'server_id.min' => 'Server phai tu 1 den 100',
            'server_id.max' => 'Server phai tu 1 den 100',
            'amount.required_if' => 'Vui long nhap menh gia',
            'amount.in' => 'Menh gia khong duoc ho tro',
            'list.required_if' => 'Vui long nhap danh sach tai khoan can nap',
        ];
    }
}

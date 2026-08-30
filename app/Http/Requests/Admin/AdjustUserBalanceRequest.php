<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustUserBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'direction.required' => 'Vui lòng chọn cộng hoặc trừ tiền.',
            'direction.in' => 'Loại điều chỉnh số dư không hợp lệ.',
            'amount.required' => 'Vui lòng nhập số tiền.',
            'amount.integer' => 'Số tiền phải là số nguyên VND.',
            'amount.min' => 'Số tiền phải lớn hơn 0.',
            'amount.max' => 'Số tiền vượt giới hạn cho phép.',
            'description.required' => 'Vui lòng nhập lý do điều chỉnh.',
            'description.min' => 'Lý do phải có ít nhất 3 ký tự.',
            'description.max' => 'Lý do tối đa 1000 ký tự.',
            'idempotency_key.required' => 'Yêu cầu thiếu mã chống trùng giao dịch.',
            'idempotency_key.uuid' => 'Mã chống trùng giao dịch không hợp lệ.',
        ];
    }
}

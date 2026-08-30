<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telco' => strtolower(trim((string) $this->input('telco'))),
            'card_code' => trim((string) $this->input('card_code')),
            'card_serial' => trim((string) $this->input('card_serial')),
        ]);
    }

    public function rules(): array
    {
        return [
            'telco' => [
                'required',
                'string',
                'max:30',
                Rule::exists('card_types', 'telco')->where('status', true),
            ],
            'amount' => ['required', 'integer', Rule::in(config('services.card_partner.amounts', []))],
            'card_code' => [
                'required',
                'string',
                'min:6',
                'max:255',
                Rule::unique('cards', 'code')->where(
                    fn ($query) => $query->where('serial', $this->input('card_serial'))
                ),
            ],
            'card_serial' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'telco.required' => 'Vui lòng chọn nhà mạng',
            'telco.exists' => 'Nhà mạng không được hỗ trợ',
            'amount.required' => 'Vui lòng nhập mệnh giá thẻ',
            'amount.integer' => 'Mệnh giá phải là số nguyên',
            'amount.in' => 'Mệnh giá thẻ không được hỗ trợ',
            'card_code.required' => 'Vui lòng nhập mã thẻ',
            'card_code.min' => 'Mã thẻ phải có ít nhất 6 ký tự',
            'card_code.max' => 'Mã thẻ không được quá 255 ký tự',
            'card_code.unique' => 'Mã thẻ và serial này đã được gửi trước đó',
            'card_serial.required' => 'Vui lòng nhập serial thẻ',
            'card_serial.min' => 'Serial thẻ phải có ít nhất 6 ký tự',
            'card_serial.max' => 'Serial thẻ không được quá 255 ký tự',
        ];
    }
}

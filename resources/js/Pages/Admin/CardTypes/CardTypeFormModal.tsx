// Components/Modals/CardTypeFormModal.tsx
import React, { useState, useEffect } from 'react';
import { X, Smartphone, Percent, CheckCircle } from 'lucide-react';

interface ICardType {
    id: number;
    telco: string;
    discount_rate: number;
    status: boolean;
    created_at: string;
    updated_at: string;
}

interface CardTypeFormModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSubmit: (data: any) => void;
    cardType?: ICardType | null;
    mode: 'create' | 'edit';
}

export default function CardTypeFormModal({
    isOpen,
    onClose,
    onSubmit,
    cardType,
    mode
}: CardTypeFormModalProps) {
    const [formData, setFormData] = useState({
        telco: '',
        discount_rate: 0,
        status: true
    });

    const [errors, setErrors] = useState<Record<string, string>>({});

    // Reset form khi modal mở/đóng
    useEffect(() => {
        if (isOpen) {
            if (mode === 'edit' && cardType) {
                setFormData({
                    telco: cardType.telco,
                    discount_rate: cardType.discount_rate,
                    status: cardType.status
                });
            } else {
                setFormData({
                    telco: '',
                    discount_rate: 0,
                    status: true
                });
            }
            setErrors({});
        }
    }, [isOpen, mode, cardType]);

    const handleInputChange = (field: string, value: any) => {
        setFormData(prev => ({
            ...prev,
            [field]: value
        }));

        // Clear error khi user nhập
        if (errors[field]) {
            setErrors(prev => ({
                ...prev,
                [field]: ''
            }));
        }
    };

    const validateForm = () => {
        const newErrors: Record<string, string> = {};

        if (!formData.telco.trim()) {
            newErrors.telco = 'Tên nhà mạng là bắt buộc';
        }

        if (formData.discount_rate < 0 || formData.discount_rate > 100) {
            newErrors.discount_rate = 'Chiết khấu phải từ 0 đến 100%';
        }

        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (validateForm()) {
            onSubmit(formData);
        }
    };

    if (!isOpen) return null;

    // Danh sách nhà mạng phổ biến
    const popularTelcos = [
        { code: 'viettel', name: 'Viettel', color: 'text-red-600' },
        { code: 'mobifone', name: 'MobiFone', color: 'text-blue-600' },
        { code: 'vinaphone', name: 'VinaPhone', color: 'text-purple-600' },
        { code: 'vietnamobile', name: 'Vietnamobile', color: 'text-yellow-600' },
        { code: 'gmobile', name: 'Gmobile', color: 'text-green-600' },
        { code: 'zing', name: 'Zing', color: 'text-orange-600' },
        { code: 'gate', name: 'Gate', color: 'text-gray-600' },
    ];

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            {/* Backdrop */}
            <div className="fixed inset-0 bg-black bg-opacity-50 transition-opacity" onClick={onClose} />

            {/* Modal */}
            <div className="flex min-h-full items-center justify-center p-4">
                <div className="relative bg-white rounded-lg shadow-xl max-w-md w-full">
                    {/* Header */}
                    <div className="flex items-center justify-between p-6 border-b border-gray-200">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <Smartphone className="w-5 h-5 text-blue-600" />
                            </div>
                            <div>
                                <h2 className="text-xl font-semibold text-gray-900">
                                    {mode === 'create' ? 'Thêm nhà mạng mới' : 'Chỉnh sửa nhà mạng'}
                                </h2>
                                <p className="text-sm text-gray-500">
                                    {mode === 'create' ? 'Tạo cấu hình cho nhà mạng mới' : 'Cập nhật thông tin nhà mạng'}
                                </p>
                            </div>
                        </div>
                        <button
                            onClick={onClose}
                            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="p-6 space-y-4">
                        {/* Telco */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Nhà mạng *
                            </label>

                            {/* Quick select cho create mode */}
                            {mode === 'create' && (
                                <div className="mb-3">
                                    <p className="text-xs text-gray-500 mb-2">Chọn nhanh:</p>
                                    <div className="flex flex-wrap gap-2">
                                        {popularTelcos.map(telco => (
                                            <button
                                                key={telco.code}
                                                type="button"
                                                onClick={() => handleInputChange('telco', telco.code)}
                                                className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${formData.telco === telco.code
                                                        ? 'bg-blue-100 text-blue-800 border-blue-300'
                                                        : 'bg-gray-50 text-gray-700 border-gray-200 hover:bg-gray-100'
                                                    }`}
                                            >
                                                {telco.name}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <input
                                type="text"
                                value={formData.telco}
                                onChange={(e) => handleInputChange('telco', e.target.value)}
                                className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 ${errors.telco ? 'border-red-300' : 'border-gray-300'
                                    }`}
                                placeholder="Nhập mã nhà mạng (vd: viettel, mobifone)"
                            />
                            {errors.telco && (
                                <p className="mt-1 text-sm text-red-600">{errors.telco}</p>
                            )}
                        </div>

                        {/* Discount Rate */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Chiết khấu (%)
                            </label>
                            <div className="relative">
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={formData.discount_rate}
                                    onChange={(e) => handleInputChange('discount_rate', parseFloat(e.target.value) || 0)}
                                    className={`w-full px-3 py-2 pr-10 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 ${errors.discount_rate ? 'border-red-300' : 'border-gray-300'
                                        }`}
                                    placeholder="0.00"
                                />
                                <Percent className="absolute right-3 top-2.5 w-4 h-4 text-gray-400" />
                            </div>
                            {errors.discount_rate && (
                                <p className="mt-1 text-sm text-red-600">{errors.discount_rate}</p>
                            )}
                            <p className="mt-1 text-xs text-gray-500">
                                Mức chiết khấu áp dụng cho nhà mạng này (0-100%)
                            </p>
                        </div>

                        {/* Status */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Trạng thái
                            </label>
                            <div className="flex items-center gap-4">
                                <label className="flex items-center">
                                    <input
                                        type="radio"
                                        checked={formData.status === true}
                                        onChange={() => handleInputChange('status', true)}
                                        className="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700 flex items-center gap-1">
                                        <CheckCircle className="w-4 h-4 text-green-500" />
                                        Hoạt động
                                    </span>
                                </label>
                                <label className="flex items-center">
                                    <input
                                        type="radio"
                                        checked={formData.status === false}
                                        onChange={() => handleInputChange('status', false)}
                                        className="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                    />
                                    <span className="ml-2 text-sm text-gray-700 flex items-center gap-1">
                                        <X className="w-4 h-4 text-red-500" />
                                        Tạm dừng
                                    </span>
                                </label>
                            </div>
                        </div>
                    </form>

                    {/* Footer */}
                    <div className="flex justify-end gap-3 p-6 border-t border-gray-200 bg-gray-50">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
                        >
                            Hủy
                        </button>
                        <button
                            onClick={handleSubmit}
                            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            {mode === 'create' ? 'Tạo mới' : 'Cập nhật'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
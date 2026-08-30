// Tạo file mới: Admin/Nicks/components/ImageSection.tsx
import React from 'react';
import { Form, Radio, Space, Tag } from 'antd';
import { Upload } from 'lucide-react';
import { UploadFull } from '@/Components/Upload/CustomUpload';
import TextArea from 'antd/es/input/TextArea';


interface ImageSectionProps {
    imageMode: 'upload' | 'url';
    images: File[] | null;
    imageUrls: string;
    onImageModeChange: (mode: 'upload' | 'url') => void;
    onImagesChange: (files: File[] | null) => void;
    onImageUrlsChange: (urls: string) => void;
}

// ✅ Dùng React.memo để prevent re-render không cần thiết
export const ImageSection = React.memo(({
    imageMode,
    images,
    imageUrls,
    onImageModeChange,
    onImagesChange,
    onImageUrlsChange
}: ImageSectionProps) => {
    return (
        <div className="space-y-4">
            {/* Image Mode Selection */}
            <Form.Item label="Chọn cách thêm ảnh">
                <Radio.Group
                    value={imageMode}
                    onChange={(e) => {
                        onImageModeChange(e.target.value);
                    }}
                    className="w-full"
                >
                    <Space direction="vertical" className="w-full">
                        <Radio value="upload" className="w-full">
                            <span className="flex items-center gap-2">
                                <Upload className="w-4 h-4 text-blue-500 dark:text-blue-400 flex-shrink-0" />
                                <span className="text-sm">Upload ảnh từ máy</span>
                            </span>
                        </Radio>
                        <Radio value="url" className="w-full">
                            <span className="flex items-center gap-2">
                                <span className="flex-shrink-0">🔗</span>
                                <span className="text-sm">Nhập URL ảnh</span>
                            </span>
                        </Radio>
                    </Space>
                </Radio.Group>
            </Form.Item>

            {/* Upload Mode */}
            {imageMode === 'upload' && (
                <div className="space-y-3">
                    <UploadFull
                        value={images}
                        onChange={onImagesChange}
                        maxCount={100}
                        maxSize={5}
                    />

                    {images && images.length > 0 && (
                        <div className="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                            <div className="text-sm text-blue-700 dark:text-blue-400 font-medium">
                                📸 Đã chọn {images.length} ảnh
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* URL Mode */}
            {imageMode === 'url' && (
                <div className="space-y-3">
                    <Form.Item
                        label="Danh sách URL ảnh"
                        help="Mỗi URL một dòng"
                        className="mb-0"
                    >
                        <TextArea
                            value={imageUrls}
                            onChange={(e) => onImageUrlsChange(e.target.value)}
                            placeholder={`https://example.com/image1.jpg\nhttps://example.com/image2.png`}
                            rows={6}
                            showCount
                            maxLength={2000}
                        />
                    </Form.Item>

                    {imageUrls.trim() && (
                        <>
                            <div className="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                                <div className="text-sm text-green-700 dark:text-green-400 font-medium">
                                    🔗 {imageUrls.split('\n').filter(url => url.trim()).length} URL đã nhập
                                </div>
                            </div>

                            {/* Preview URLs */}
                            <div className="p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Preview URLs:</p>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                                    {imageUrls.split('\n')
                                        .map(url => url.trim())
                                        .filter(url => url.length > 0)
                                        .slice(0, 10)
                                        .map((url, index) => (
                                            <div
                                                key={index}
                                                className="relative w-full h-24 sm:h-20 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-900"
                                            >
                                                <img
                                                    src={url}
                                                    alt={`Preview ${index + 1}`}
                                                    className="w-full h-full object-cover"
                                                    onError={(e) => {
                                                        const target = e.currentTarget;
                                                        const parent = target.parentElement!;
                                                        parent.innerHTML = `
                                                            <div class="w-full h-full bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg flex flex-col items-center justify-center p-2">
                                                                <span class="text-red-400 text-xs mb-1">❌</span>
                                                                <span class="text-red-400 text-xs text-center">Lỗi ảnh</span>
                                                            </div>
                                                        `;
                                                    }}
                                                    onLoad={(e) => {
                                                        const target = e.currentTarget;
                                                        target.style.border = '2px solid #10b981';
                                                        setTimeout(() => {
                                                            if (target.style) {
                                                                target.style.border = '';
                                                            }
                                                        }, 1000);
                                                    }}
                                                />
                                                <div className="absolute top-1 left-1 bg-black bg-opacity-60 text-white text-xs px-1.5 py-0.5 rounded">
                                                    #{index + 1}
                                                </div>
                                                {index === 0 && (
                                                    <div className="absolute top-1 right-1 bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded">
                                                        Main
                                                    </div>
                                                )}
                                            </div>
                                        ))
                                    }
                                </div>

                                <div className="mt-3 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                                    <p className="font-medium">💡 Mẹo:</p>
                                    <p>• Viền xanh = Load thành công</p>
                                    <p>• Khung đỏ = URL lỗi</p>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            )}

            {/* Guidelines */}
            <div className="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                <p className="font-medium mb-2">Lưu ý:</p>
                <ul className="space-y-1 text-xs">
                    {imageMode === 'upload' ? (
                        <>
                            <li>• Hỗ trợ: JPG, PNG, GIF</li>
                            <li>• Kích thước tối đa: 5MB/ảnh</li>
                            <li>• Tối đa 100 ảnh</li>
                        </>
                    ) : (
                        <>
                            <li>• Chỉ URL ảnh hợp lệ</li>
                            <li>• Mỗi URL một dòng</li>
                            <li>• Tối đa 100 URL</li>
                        </>
                    )}
                    <li>• Ảnh đầu tiên là ảnh đại diện</li>
                </ul>
            </div>
        </div>
    );
});

ImageSection.displayName = 'ImageSection';
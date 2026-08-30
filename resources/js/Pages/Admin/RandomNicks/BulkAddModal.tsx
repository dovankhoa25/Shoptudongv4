import { Modal, Form, Input, Alert, Select } from "antd";
import { useForm } from "@inertiajs/react";
import { IRandomBox } from "@/InterFaces/randombox";
import { useToast } from "@/Components/ToastProvider";
import { CustomUpload } from "@/Components/Upload/CustomUpload";
import { useState } from 'react';
import { Upload, Users, AlertCircle, CheckCircle, FileText, Package } from 'lucide-react';

const { TextArea } = Input;

interface IProps {
    onClose: () => void;
    randomBoxes?: IRandomBox[];
}

export default function BulkAddModal({ onClose, randomBoxes = [] }: IProps) {
    const [sharedImage, setSharedImage] = useState<File | null>(null);
    const [previewLines, setPreviewLines] = useState<string[]>([]);
    const [validCount, setValidCount] = useState(0);
    const [invalidCount, setInvalidCount] = useState(0);

    const { data, setData, post, processing, reset } = useForm({
        random_box_id: "",
        nick_data: "",
        shared_image: null as File | null,
    });

    const toast = useToast();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Validate form
        if (!data.random_box_id) {
            toast.error('Vui lòng chọn hộp random!');
            return;
        }

        if (!data.nick_data.trim()) {
            toast.error('Vui lòng nhập dữ liệu nick!');
            return;
        }

        if (validCount === 0) {
            toast.error('Không có dòng dữ liệu hợp lệ nào!');
            return;
        }

        // Prepare form data
        const formData = new FormData();
        formData.append('random_box_id', data.random_box_id.toString());
        formData.append('nick_data', data.nick_data);

        if (sharedImage) {
            formData.append('shared_image', sharedImage);
        }

        // Submit
        const submitOptions = {
            data: formData,
            headers: {
                'Content-Type': 'multipart/form-data'
            },
            onSuccess: () => {
                toast.success(`Đã thêm thành công ${validCount} nick vào hộp random!`);
                onClose();
                reset();
            },
            onError: (errors: any) => {
                console.error('Bulk add errors:', errors);
                toast.error('Thêm nick hàng loạt thất bại. Vui lòng thử lại!');
            }
        };

        post("/admin/random-nicks/bulk", submitOptions);
    };

    const handleNickDataChange = (value: string) => {
        setData("nick_data", value);

        // Parse and validate data
        const lines = value.split('\n').map(line => line.trim()).filter(line => line.length > 0);
        setPreviewLines(lines);

        let valid = 0;
        let invalid = 0;

        lines.forEach(line => {
            const parts = line.split('|').map(part => part.trim());
            if (parts.length >= 2 && parts[0] && parts[1]) {
                valid++;
            } else {
                invalid++;
            }
        });

        setValidCount(valid);
        setInvalidCount(invalid);
    };

    const handleImageChange = (file: File | null) => {
        setSharedImage(file);
        setData('shared_image', file);
    };

    const selectedRandomBox = data.random_box_id
        ? randomBoxes.find(box => box.id.toString() === data.random_box_id)
        : null;

    const exampleData = `user1|password1|Nick VIP có 100 tướng, rank Kim Cương
user2|password2|Account rank Thách Đấu, có skin hiếm
user3|password3
admin123|pass456|Nick sơ sinh, chưa chơi gì`;

    return (
        <Modal
            open
            onCancel={onClose}
            onOk={handleSubmit}
            okText="Thêm hàng loạt"
            cancelText="Hủy"
            centered
            className="custom-modal"
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-purple-500 to-pink-600 rounded-lg flex items-center justify-center">
                        <Upload className="w-4 h-4 text-white" />
                    </div>
                    Thêm nick hàng loạt
                </div>
            }
            confirmLoading={processing}
            width={900}
            style={{
                top: 20,
                maxHeight: 'calc(100vh - 40px)'
            }}
            styles={{
                body: {
                    maxHeight: 'calc(100vh - 200px)',
                    overflowY: 'auto',
                    overflowX: 'hidden',
                    paddingRight: '4px'
                }
            }}
        >
            <Form className="mt-4" layout="vertical">
                {/* Random Box Selection */}
                <Form.Item
                    label={
                        <div className="flex items-center gap-2 font-medium text-gray-700">
                            <Package className="w-4 h-4" />
                            Chọn hộp random
                        </div>
                    }
                    name="random_box_id"
                    rules={[{ required: true, message: "Vui lòng chọn hộp random!" }]}
                >
                    <Select
                        size="large"
                        placeholder="Chọn hộp random để thêm nick..."
                        value={data.random_box_id}
                        onChange={(value) => setData("random_box_id", value)}
                        showSearch
                        filterOption={(input, option) =>
                            (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
                        }
                        options={randomBoxes.map(box => ({
                            label: `${box.name} (${box.category?.name || 'Chưa phân loại'}) - ${box.price}`,
                            value: box.id.toString()
                        }))}
                        notFoundContent={randomBoxes.length === 0 ? "Chưa có hộp random nào" : "Không tìm thấy"}
                    />
                </Form.Item>

                {/* Selected Random Box Info */}
                {selectedRandomBox && (
                    <div className="mb-6 p-4 bg-purple-50 rounded-lg border border-purple-200">
                        <h3 className="font-medium text-purple-800 mb-2 flex items-center gap-2">
                            <Users className="w-4 h-4" />
                            Thêm vào hộp random: {selectedRandomBox.name}
                        </h3>
                        <div className="flex flex-wrap items-center gap-4 text-sm text-purple-700">
                            <span><strong>Giá:</strong> {selectedRandomBox.price_formatted}</span>
                            <span><strong>Danh mục:</strong> {selectedRandomBox.category?.name || 'Chưa phân loại'}</span>
                            {selectedRandomBox.total_nicks !== undefined && (
                                <span><strong>Nick hiện có:</strong> {selectedRandomBox.total_nicks}</span>
                            )}
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Cột trái - Input data */}
                    <div className="space-y-4">
                        <Form.Item
                            label={
                                <div className="flex items-center gap-2 font-medium text-gray-700">
                                    <FileText className="w-4 h-4" />
                                    Dữ liệu nick (mỗi dòng một nick)
                                </div>
                            }
                            name="nick_data"
                            rules={[{ required: true, message: "Vui lòng nhập dữ liệu nick!" }]}
                        >
                            <TextArea
                                placeholder="Nhập dữ liệu theo format: taikhoan|matkhau|mota"
                                value={data.nick_data}
                                onChange={(e) => handleNickDataChange(e.target.value)}
                                rows={12}
                                className="font-mono text-sm"
                            />
                        </Form.Item>

                        {/* Format guide */}
                        <div className="p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <h4 className="font-medium text-blue-800 mb-2 flex items-center gap-2">
                                <AlertCircle className="w-4 h-4" />
                                Format dữ liệu:
                            </h4>
                            <div className="text-sm text-blue-700 space-y-1">
                                <p><code className="bg-blue-100 px-2 py-1 rounded">taikhoan|matkhau|mota</code></p>
                                <p className="text-xs">• Mô tả là tùy chọn (có thể bỏ trống)</p>
                                <p className="text-xs">• Mỗi dòng là một nick</p>
                                <p className="text-xs">• Tài khoản trùng trong cùng hộp sẽ bị bỏ qua</p>
                            </div>
                        </div>

                        {/* Example */}
                        <div className="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <h4 className="font-medium text-gray-700 mb-2 text-sm">Ví dụ:</h4>
                            <pre className="text-xs text-gray-600 whitespace-pre-wrap">
                                {exampleData}
                            </pre>
                            <button
                                type="button"
                                onClick={() => handleNickDataChange(exampleData)}
                                className="mt-2 text-xs text-blue-600 hover:text-blue-800 underline"
                            >
                                Sử dụng ví dụ này
                            </button>
                        </div>
                    </div>

                    {/* Cột phải - Shared image & Preview */}
                    <div className="space-y-4">
                        {/* Shared Image Upload */}
                        <Form.Item
                            label={
                                <div className="font-medium text-gray-700">
                                    Ảnh chung cho tất cả nick (tùy chọn)
                                </div>
                            }
                            name="shared_image"
                        >
                            <div className="space-y-3">
                                <CustomUpload
                                    value={sharedImage}
                                    onChange={handleImageChange}
                                    maxSize={2}
                                />
                                <div className="text-xs text-gray-500">
                                    {selectedRandomBox
                                        ? `Nếu không chọn ảnh, nick sẽ dùng ảnh của hộp "${selectedRandomBox.name}"`
                                        : 'Nếu không chọn ảnh, nick sẽ dùng ảnh của hộp random'
                                    }
                                </div>
                            </div>
                        </Form.Item>

                        {/* Validation Status */}
                        {previewLines.length > 0 && (
                            <div className="space-y-3">
                                <h4 className="font-medium text-gray-700">Trạng thái validation:</h4>

                                {validCount > 0 && (
                                    <Alert
                                        message={`${validCount} dòng hợp lệ`}
                                        type="success"
                                        showIcon
                                        icon={<CheckCircle className="w-4 h-4" />}
                                        className="text-sm"
                                    />
                                )}

                                {invalidCount > 0 && (
                                    <Alert
                                        message={`${invalidCount} dòng không hợp lệ (thiếu tài khoản hoặc mật khẩu)`}
                                        type="warning"
                                        showIcon
                                        icon={<AlertCircle className="w-4 h-4" />}
                                        className="text-sm"
                                    />
                                )}

                                {/* Preview first few lines */}
                                {validCount > 0 && (
                                    <div className="p-3 bg-green-50 rounded-lg border border-green-200">
                                        <h5 className="text-sm font-medium text-green-800 mb-2">
                                            Preview {Math.min(3, validCount)} nick đầu tiên:
                                        </h5>
                                        <div className="text-xs text-green-700 space-y-1">
                                            {previewLines.slice(0, 3).map((line, index) => {
                                                const parts = line.split('|').map(p => p.trim());
                                                if (parts.length >= 2 && parts[0] && parts[1]) {
                                                    return (
                                                        <div key={index} className="font-mono">
                                                            <span className="font-medium">{parts[0]}</span>
                                                            <span className="text-green-600"> | </span>
                                                            <span>{'*'.repeat(Math.min(parts[1].length, 6))}</span>
                                                            {parts[2] && (
                                                                <>
                                                                    <span className="text-green-600"> | </span>
                                                                    <span className="italic">{parts[2].substring(0, 30)}{parts[2].length > 30 ? '...' : ''}</span>
                                                                </>
                                                            )}
                                                        </div>
                                                    );
                                                }
                                                return null;
                                            })}
                                            {validCount > 3 && (
                                                <div className="text-green-600 italic">
                                                    ... và {validCount - 3} nick khác
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Empty state when no randomBoxes */}
                        {randomBoxes.length === 0 && (
                            <div className="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                                <div className="flex items-center gap-2 text-yellow-800 mb-2">
                                    <AlertCircle className="w-4 h-4" />
                                    <span className="font-medium">Chưa có hộp random</span>
                                </div>
                                <p className="text-sm text-yellow-700">
                                    Bạn cần tạo ít nhất một hộp random trước khi thêm nick hàng loạt.
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                <div className="text-sm text-gray-500 mt-6 p-4 bg-gray-50 rounded-lg">
                    <p><strong>⚡ Lưu ý quan trọng:</strong></p>
                    <ul className="list-disc list-inside space-y-1 mt-2">
                        <li>Hệ thống sẽ tự động bỏ qua các nick có tài khoản trùng lặp trong cùng hộp</li>
                        <li>Mô tả có thể để trống, nhưng tài khoản và mật khẩu là bắt buộc</li>
                        <li>Ảnh chung sẽ được áp dụng cho tất cả nick được thêm</li>
                        <li>Tất cả nick sẽ có trạng thái "Có sẵn" sau khi được tạo</li>
                        <li>Quá trình này sử dụng transaction, nếu có lỗi sẽ rollback toàn bộ</li>
                    </ul>
                </div>
            </Form>
        </Modal>
    );
}
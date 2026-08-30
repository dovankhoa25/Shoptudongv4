// Admin/CongTacVien/UserCategoryModal.tsx
import React, { useState, useEffect } from 'react';
import { Modal, Checkbox, Spin, Alert, Button, Divider, Space, Tag as AntTag } from 'antd';
import { Tag, Save, Loader2 } from 'lucide-react';
import axios from 'axios';
import { IUser } from '@/InterFaces/user';
import { useToast } from '@/Components/ToastProvider';

interface Category {
    id: number;
    name: string;
    slug: string;
    template?: string;
}
interface UserCategory extends Category {
    can_post: boolean;
}

interface UserCategoryModalProps {
    user: IUser;
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
}

export default function UserCategoryModal({ user, open, onClose, onSuccess }: UserCategoryModalProps) {
    const toast = useToast();
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [allCategories, setAllCategories] = useState<Category[]>([]);
    const [selectedCategories, setSelectedCategories] = useState<Map<number, boolean>>(new Map());
    const [categoryGroups, setCategoryGroups] = useState<Record<string, Category[]>>({});
    // Fetch categories data
    useEffect(() => {
        if (open) {
            fetchCategories();
        }
    }, [user.id, open]);

    const fetchCategories = async () => {
        try {
            setLoading(true);
            const response = await axios.get(`/admin/users/${user.id}/categories`);
            const { all_categories, user_categories } = response.data.data;

            setCategoryGroups(all_categories); // Đây là object với key là template

            const categoryMap = new Map<number, boolean>();
            user_categories.forEach((cat: UserCategory) => {
                categoryMap.set(cat.id, cat.can_post);
            });
            setSelectedCategories(categoryMap);
        } catch (error) {
            toast.error('Không thể tải danh sách danh mục');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const handleToggleCategory = (categoryId: number) => {
        setSelectedCategories(prev => {
            const newMap = new Map(prev);
            if (newMap.has(categoryId)) {
                newMap.delete(categoryId);
            } else {
                newMap.set(categoryId, true); // Default can_post = true
            }
            return newMap;
        });
    };

    const handleToggleCanPost = (categoryId: number) => {
        setSelectedCategories(prev => {
            const newMap = new Map(prev);
            if (newMap.has(categoryId)) {
                newMap.set(categoryId, !newMap.get(categoryId));
            }
            return newMap;
        });
    };

    const handleSubmit = async () => {
        if (selectedCategories.size === 0) {
            toast.error('Vui lòng chọn ít nhất 1 danh mục');
            return;
        }

        try {
            setSubmitting(true);

            // Chuẩn bị dữ liệu gửi lên
            const categories = Array.from(selectedCategories.entries()).map(([id, can_post]) => ({
                id,
                can_post,
            }));

            await axios.post(`/admin/users/${user.id}/categories`, {
                categories,
            });

            toast.success('Cập nhật danh mục thành công!');
            onSuccess?.();
            onClose();
        } catch (error: any) {
            const message = error.response?.data?.message || 'Có lỗi xảy ra khi cập nhật danh mục';
            toast.error(message);
            console.error(error);
        } finally {
            setSubmitting(false);
        }
    };

    const handleCancel = () => {
        if (!submitting) {
            onClose();
        }
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="p-2 bg-orange-100 rounded-lg">
                        <Tag className="w-5 h-5 text-orange-600" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold text-gray-900">
                            Quản lý danh mục
                        </div>
                        <div className="text-sm text-gray-500 font-normal mt-0.5">
                            Cộng tác viên: <span className="font-medium">{user.username}</span>
                        </div>
                    </div>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            width={700}
            footer={[
                <Button
                    key="cancel"
                    onClick={handleCancel}
                    disabled={submitting}
                >
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    loading={submitting}
                    onClick={handleSubmit}
                    disabled={loading || selectedCategories.size === 0}
                    icon={!submitting && <Save className="w-4 h-4" />}
                    className="bg-orange-600 hover:bg-orange-700"
                >
                    {submitting ? 'Đang lưu...' : 'Lưu thay đổi'}
                </Button>,
            ]}
            styles={{
                body: {
                    maxHeight: '60vh',
                    overflowY: 'auto',
                }
            }}
        >
            {loading ? (
                <div className="flex items-center justify-center py-12">
                    <Spin size="large" tip="Đang tải danh sách danh mục..." />
                </div>
            ) : (
                <div className="space-y-4">
                    <Alert
                        message="Hướng dẫn"
                        description="Chọn các danh mục mà cộng tác viên được phép quản lý. Bật/tắt 'Cho phép đăng bài' để quyết định quyền đăng bài trong từng danh mục."
                        type="info"
                        showIcon
                        className="mb-4"
                    />

                    {Object.keys(categoryGroups).length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            Chưa có danh mục nào trong hệ thống
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {Object.entries(categoryGroups).map(([template, categories]) => (
                                <div key={template} className="border rounded-lg p-4 bg-gray-50">
                                    {/* Header template */}
                                    <div className="mb-3 pb-2 border-b border-gray-300">
                                        <h4 className="font-semibold text-gray-700 uppercase text-sm">
                                            {template}
                                        </h4>
                                    </div>

                                    {/* Danh sách categories trong template */}
                                    <div className="space-y-2">
                                        {categories.map((category: Category) => {
                                            const isSelected = selectedCategories.has(category.id);
                                            const canPost = selectedCategories.get(category.id) ?? true;

                                            return (
                                                <div
                                                    key={category.id}
                                                    className={`border rounded-lg p-3 transition-all ${isSelected
                                                        ? 'border-orange-300 bg-orange-50'
                                                        : 'border-gray-200 bg-white hover:border-gray-300'
                                                        }`}
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <Checkbox
                                                            checked={isSelected}
                                                            onChange={() => handleToggleCategory(category.id)}
                                                        >
                                                            <div className="ml-2">
                                                                <p className="font-medium text-gray-900">
                                                                    {category.name}
                                                                </p>
                                                                <p className="text-xs text-gray-500 mt-0.5">
                                                                    {category.slug}
                                                                </p>
                                                            </div>
                                                        </Checkbox>

                                                        {isSelected && (
                                                            <Checkbox
                                                                checked={canPost}
                                                                onChange={() => handleToggleCanPost(category.id)}
                                                            >
                                                                <span className="text-sm text-gray-700">
                                                                    Cho phép đăng bài
                                                                </span>
                                                            </Checkbox>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <Divider />

                    <div className="bg-gray-50 rounded-lg p-4">
                        <Space>
                            <span className="text-sm text-gray-600">Đã chọn:</span>
                            <AntTag color="orange" className="font-medium">
                                {selectedCategories.size} danh mục
                            </AntTag>
                        </Space>
                    </div>
                </div>
            )}
        </Modal>
    );
}
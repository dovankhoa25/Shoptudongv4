// AddSelectionModal.tsx - Modal chọn loại thêm mới
import { Modal, Button } from "antd";
import { Plus, Tag as TagIcon } from "lucide-react";

interface IProps {
    open: boolean;
    onClose: () => void;
    onSelectAttribute: () => void;
    onSelectOption: () => void;
}

export default function AddSelectionModal({ open, onClose, onSelectAttribute, onSelectOption }: IProps) {
    return (
        <Modal
            open={open}
            onCancel={onClose}
            footer={null}
            centered
            title={
                <div className="flex items-center gap-3 text-lg font-semibold text-gray-800">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <Plus className="w-4 h-4 text-white" />
                    </div>
                    Bạn muốn thêm gì?
                </div>
            }
            width={400}
        >
            <div className="py-4 space-y-3">
                <Button
                    type="primary"
                    size="large"
                    block
                    icon={<TagIcon className="w-4 h-4" />}
                    onClick={() => {
                        onSelectAttribute();
                        onClose();
                    }}
                    className="h-16 bg-blue-500 hover:bg-blue-600 border-blue-500 rounded-xl"
                >
                    <div className="text-left">
                        <div className="font-semibold">Thêm thuộc tính mới</div>
                        <div className="text-sm opacity-80">Tạo nhóm thuộc tính mới (VD: Màu sắc, Kích thước)</div>
                    </div>
                </Button>

                <Button
                    size="large"
                    block
                    icon={<Plus className="w-4 h-4" />}
                    onClick={() => {
                        onSelectOption();
                        onClose();
                    }}
                    className="h-16 border-green-500 text-green-600 hover:bg-green-50 hover:border-green-600 rounded-xl"
                >
                    <div className="text-left">
                        <div className="font-semibold">Thêm option cho thuộc tính</div>
                        <div className="text-sm opacity-80">Thêm giá trị cho thuộc tính hiện có (VD: Đỏ, Xanh)</div>
                    </div>
                </Button>
            </div>
        </Modal>
    );
}
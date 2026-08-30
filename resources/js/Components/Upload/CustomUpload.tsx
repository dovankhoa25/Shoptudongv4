// CustomUpload.tsx - Single file upload (giữ nguyên)
import React, { useState, useEffect } from "react";
import { Upload, Image, message } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import type { UploadFile, UploadProps } from "antd";
import type { RcFile } from "antd/es/upload/interface";

interface CustomUploadProps {
    value?: File | string | null;
    onChange?: (file: File | null) => void;
    maxSize?: number; // MB
}

const getBase64 = (file: RcFile): Promise<string> =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = (error) => reject(error);
    });

const CustomUpload: React.FC<CustomUploadProps> = ({
    value,
    onChange,
    maxSize = 2
}) => {
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState<string>("");
    const [fileList, setFileList] = useState<UploadFile[]>([]);

    // Sync fileList with value prop
    useEffect(() => {
        if (!value) {
            setFileList([]);
            return;
        }

        if (typeof value === "string") {
            // URL string
            setFileList([{
                uid: "-1",
                name: "Current image",
                status: "done",
                url: value,
            }]);
        } else {
            // File object
            const objectUrl = URL.createObjectURL(value);
            setFileList([{
                uid: "-1",
                name: value.name,
                status: "done",
                url: objectUrl,
                originFileObj: value as RcFile,
            }]);

            // Cleanup object URL
            return () => URL.revokeObjectURL(objectUrl);
        }
    }, [value]);

    const handlePreview = async (file: UploadFile) => {
        if (!file.url && !file.preview) {
            file.preview = await getBase64(file.originFileObj as RcFile);
        }
        setPreviewImage(file.url || (file.preview as string));
        setPreviewOpen(true);
    };

    const handleChange: UploadProps["onChange"] = ({ fileList: newFileList }) => {
        const latestFileList = newFileList.slice(-1); // Only keep the latest file
        setFileList(latestFileList);

        if (latestFileList.length > 0 && latestFileList[0].originFileObj) {
            onChange?.(latestFileList[0].originFileObj);
        } else {
            onChange?.(null);
        }
    };

    const beforeUpload: UploadProps["beforeUpload"] = (file) => {
        const isValidSize = file.size / 1024 / 1024 < maxSize;
        const isValidType = file.type.startsWith('image/');

        if (!isValidType) {
            message.error("Chỉ được upload file ảnh!");
            return Upload.LIST_IGNORE;
        }

        if (!isValidSize) {
            message.error(`Ảnh phải nhỏ hơn ${maxSize}MB!`);
            return Upload.LIST_IGNORE;
        }

        return false; // Prevent auto upload
    };

    const uploadButton = (
        <button
            style={{ border: 0, background: "none" }}
            type="button"
            className="flex flex-col items-center justify-center p-2 text-gray-400 hover:text-blue-500 transition-colors"
        >
            <PlusOutlined className="text-lg mb-2" />
            <div className="text-sm">Upload</div>
        </button>
    );

    return (
        <div className="space-y-2">
            <Upload
                listType="picture-card"
                fileList={fileList}
                onPreview={handlePreview}
                onChange={handleChange}
                beforeUpload={beforeUpload}
                maxCount={1}
                className="upload-list-inline"
            >
                {fileList.length >= 1 ? null : uploadButton}
            </Upload>

            {previewImage && (
                <Image
                    wrapperStyle={{ display: "none" }}
                    preview={{
                        visible: previewOpen,
                        onVisibleChange: (visible) => setPreviewOpen(visible),
                        afterOpenChange: (visible) => !visible && setPreviewImage(""),
                    }}
                    src={previewImage}
                />
            )}

            {fileList.length > 0 && (
                <div className="text-xs text-gray-500">
                    Kích thước tối đa: {maxSize}MB
                </div>
            )}
        </div>
    );
};

// UploadFull.tsx - Multiple files upload (IMPROVED)
interface UploadFullProps {
    value?: (File | string)[] | File | string | null; // ✨ Support mixed types
    onChange?: (files: File[] | null) => void;
    maxCount?: number;
    maxSize?: number; // MB
}

const UploadFull: React.FC<UploadFullProps> = ({
    value,
    onChange,
    maxCount = 20,
    maxSize = 2
}) => {
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState<string>("");
    const [fileList, setFileList] = useState<UploadFile[]>([]);

    // ✨ IMPROVED: Sync fileList with mixed value types
    useEffect(() => {
        if (!value) {
            setFileList([]);
            return;
        }

        // Normalize value to array
        const normalizedValue = Array.isArray(value) ? value : [value];

        const objectUrls: string[] = [];
        const newFileList = normalizedValue.map((item, index) => {
            if (typeof item === 'string') {
                // URL string
                return {
                    uid: `-${index}`,
                    name: `Image ${index + 1}`,
                    status: "done" as const,
                    url: item,
                };
            } else {
                // File object
                const objectUrl = URL.createObjectURL(item);
                objectUrls.push(objectUrl);
                return {
                    uid: `-${index}`,
                    name: item.name,
                    status: "done" as const,
                    url: objectUrl,
                    originFileObj: item as RcFile,
                };
            }
        });

        setFileList(newFileList);

        // Cleanup object URLs
        return () => {
            objectUrls.forEach(url => URL.revokeObjectURL(url));
        };
    }, [value]);

    const handlePreview: UploadProps["onPreview"] = async (file) => {
        if (!file.url && !file.preview) {
            file.preview = await getBase64(file.originFileObj as RcFile);
        }
        setPreviewImage(file.url || (file.preview as string));
        setPreviewOpen(true);
    };

    const handleChange: UploadProps["onChange"] = ({ fileList: newFileList }) => {
        const limitedList = newFileList.slice(0, maxCount);
        setFileList(limitedList);

        // Extract actual File objects (ignore URL strings)
        const files = limitedList
            .map(file => file.originFileObj)
            .filter(Boolean) as File[];

        onChange?.(files.length > 0 ? files : null);
    };

    const beforeUpload: UploadProps["beforeUpload"] = (file) => {
        const isValidSize = file.size / 1024 / 1024 < maxSize;
        const isValidType = file.type.startsWith('image/');

        if (!isValidType) {
            message.error("Chỉ được upload file ảnh!");
            return Upload.LIST_IGNORE;
        }

        if (!isValidSize) {
            message.error(`Ảnh phải nhỏ hơn ${maxSize}MB!`);
            return Upload.LIST_IGNORE;
        }

        return false; // Prevent auto upload
    };

    const uploadButton = (
        <div className="flex flex-col items-center justify-center p-4 text-gray-400 hover:text-blue-500 transition-colors border-2 border-dashed border-gray-200 rounded-lg hover:border-blue-300">
            <PlusOutlined className="text-2xl mb-2" />
            <div className="text-sm font-medium">Upload</div>
            <div className="text-xs text-gray-400 mt-1">
                {fileList.length}/{maxCount}
            </div>
        </div>
    );

    return (
        <div className="space-y-3">
            <Upload
                listType="picture-card"
                fileList={fileList}
                onPreview={handlePreview}
                onChange={handleChange}
                beforeUpload={beforeUpload}
                multiple
                maxCount={maxCount}
                showUploadList={{
                    showPreviewIcon: true,
                    showRemoveIcon: true,
                    showDownloadIcon: false
                }}
                className="upload-list-inline"
            >
                {fileList.length >= maxCount ? null : uploadButton}
            </Upload>

            <div className="flex justify-between items-center text-xs text-gray-500">
                <span>Đã chọn: {fileList.length}/{maxCount} ảnh</span>
                <span>Kích thước tối đa: {maxSize}MB/ảnh</span>
            </div>

            <Image
                wrapperStyle={{ display: "none" }}
                preview={{
                    visible: previewOpen,
                    onVisibleChange: (visible) => setPreviewOpen(visible),
                    afterOpenChange: (visible) => !visible && setPreviewImage(""),
                }}
                src={previewImage}
            />
        </div>
    );
};

export { CustomUpload, UploadFull };

// ✨ Usage Examples:
/*
// UploadFull với mixed types
<UploadFull
    value={[
        "https://example.com/image1.jpg", // URL string
        newFile1,                         // File object
        newFile2                          // File object
    ]}
    onChange={setFiles}
    maxCount={10}
    maxSize={3}
/>

// UploadFull với single value
<UploadFull
    value={singleFile}        // File | string | null
    onChange={setFiles}
    maxCount={5}
/>

// UploadFull empty
<UploadFull
    value={null}
    onChange={setFiles}
    maxCount={8}
/>
*/
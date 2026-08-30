// hooks/useUploadLogic.ts
import { useState, useEffect } from "react";
import { message } from "antd";
import type { UploadFile, UploadProps } from "antd";
import type { RcFile } from "antd/es/upload/interface";

const getBase64 = (file: RcFile): Promise<string> =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = (error) => reject(error);
    });

interface UseUploadLogicProps {
    maxSize: number;
    maxCount: number;
}

export const useUploadLogic = ({ maxSize, maxCount }: UseUploadLogicProps) => {
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewImage, setPreviewImage] = useState<string>("");
    const [fileList, setFileList] = useState<UploadFile[]>([]);

    const handlePreview = async (file: UploadFile) => {
        if (!file.url && !file.preview) {
            file.preview = await getBase64(file.originFileObj as RcFile);
        }
        setPreviewImage(file.url || (file.preview as string));
        setPreviewOpen(true);
    };

    const beforeUpload: UploadProps["beforeUpload"] = (file) => {
        const isValidSize = file.size / 1024 / 1024 < maxSize;
        const isValidType = file.type.startsWith('image/');

        if (!isValidType) {
            message.error("Chỉ được upload file ảnh!");
            return false;
        }

        if (!isValidSize) {
            message.error(`Ảnh phải nhỏ hơn ${maxSize}MB!`);
            return false;
        }

        return false; // Prevent auto upload
    };

    const closePreview = () => {
        setPreviewOpen(false);
        setPreviewImage("");
    };

    return {
        previewOpen,
        previewImage,
        fileList,
        setFileList,
        handlePreview,
        beforeUpload,
        closePreview,
    };
};
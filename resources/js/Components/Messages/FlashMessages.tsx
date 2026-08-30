import { useEffect } from "react";
import { usePage } from "@inertiajs/react";
import { useToast } from "@/Components/ToastProvider"; // Adjust path as needed
import { PageProps } from "@/types";

export default function FlashedMessages() {
    const { flash, errors } = usePage<PageProps>().props;

    const formErrors = Object.keys(errors).length;
    const message = useToast();

    useEffect(() => {
        if (flash.success) {
            message.success(flash.success);
        }
        if (flash.error) {
            message.error(flash.error);
        }
        if (formErrors > 0) {
            Object.keys(errors).forEach((field) => {
                message.error(`${field}: ${errors[field]}`);
            });
        }
    }, [flash, errors, message]);

    return null;
}
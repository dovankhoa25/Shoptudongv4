export const formatCurrency = (amount: number | string) => {
    const numericAmount = typeof amount === "string" ? Number(amount) : amount;
    return new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(numericAmount);
};

export function formatNumber(value: number | string) {
    if (!value) return "0";
    const number = typeof value === "string" ? parseFloat(value) : value;
    if (isNaN(number)) return "0";
    return number.toLocaleString("vi-VN", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

export const formatPrice = (price: number): string => {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND'
    }).format(price);
};

export const formatDate = (dateString: string): string => {
    return new Date(dateString).toLocaleDateString('vi-VN', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
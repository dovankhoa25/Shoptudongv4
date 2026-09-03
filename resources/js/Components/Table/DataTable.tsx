import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import { createPortal } from 'react-dom';
import {
    ADMIN_TABLE_SEARCH_FIELDS,
    AdminSearchPreset,
    SearchFieldOption,
} from '@/Config/adminTableSearch';
import {
    Search, Plus, Edit2, Trash2, Eye,
    ChevronLeft, ChevronRight, ChevronUp, ChevronDown,
    Settings, Check, Loader, MoreVertical,
    RotateCcw, X, FolderOpen, Calendar as CalendarIcon, Rows3
} from 'lucide-react';

/* ===========================
 *        TypeScrip Types
 * =========================== */
interface PaginationConfig {
    current: number;
    pageSize: number;
    total: number;
    showSizeChanger?: boolean;
    showQuickJumper?: boolean;
    pageSizeOptions?: string[];
    onChange?: (page: number, pageSize: number) => void;
    onShowSizeChange?: (current: number, size: number) => void;
}

interface Column<T = any> {
    key: keyof T | string;
    title: string;
    dataIndex?: string | string[];
    sortable?: boolean;
    searchable?: boolean;
    visible?: boolean;
    width?: number | string;
    align?: 'left' | 'center' | 'right';
    render?: (value: any, record: T, index: number) => React.ReactNode;
    filters?: { text: string; value: any }[];
    onFilter?: (value: any, record: T) => boolean;
}

interface FilterConfig {
    key: string;
    type: 'select' | 'input' | 'date' | 'dateRange' | 'range';
    label: string;
    placeholder?: string;
    options?: { label: string; value: string }[];
    value?: string | string[] | { start: string; end: string } | { min: number; max: number };
    dateFormat?: string;
    allowClear?: boolean;
}

interface RowSelection {
    selectedRowKeys: (string | number)[];
    onChange: (selectedRowKeys: (string | number)[]) => void;
    onSelectAll?: (selected: boolean, selectedRows: any[], changeRows: any[]) => void;
}

interface CustomAction<T = any> {
    label: string;
    icon: React.ComponentType<{ className?: string }>;
    handler: (record: T) => void;
    condition?: (record: T) => boolean;
    className?: string;
}

interface DataTableProps<T = any> {
    data?: T[];
    columns?: Column<T>[];
    pagination?: PaginationConfig;
    loading?: boolean;
    searchValue?: string;
    onSearch?: (value: string) => void;
    sortField?: string | null;
    sortOrder?: 'asc' | 'desc' | null;
    onSortChange?: (field: string, order: 'asc' | 'desc') => void;
    onAdd?: () => void;
    onEdit?: (record: T) => void;
    onDelete?: (record: T) => void;
    onView?: (record: T) => void;
    onReset?: () => void;
    title?: string;
    description?: string;
    rowSelection?: RowSelection;
    customActions?: { [key: string]: CustomAction<T> };
    onFiltersChange?: (filters: { [key: string]: any }) => void;

    // Enhanced Props
    filters?: FilterConfig[];
    searchFields?: readonly SearchFieldOption[];
    searchPreset?: AdminSearchPreset;
    searchPlaceholder?: string;
    addButtonText?: string;
    emptyText?: string;
    emptyDescription?: string;
    showAddButton?: boolean;
    showSearch?: boolean;
    showColumnSettings?: boolean;
    showResetButton?: boolean;
    storageKey?: string;
    density?: 'compact' | 'comfortable' | 'spacious';
    selectable?: boolean;
    striped?: boolean;
    stickyHeader?: boolean;
    rowKey?: keyof T | ((record: T) => string | number);
}

/* ===========================
 *        Helpers
 * =========================== */
const getNestedValue = (obj: any, path: string | string[]): any =>
    typeof path === 'string'
        ? obj?.[path]
        : path.reduce((value, key) => (value == null ? value : value[key]), obj);

const extractColumnValue = <T,>(record: T, column: Column<T>): any => {
    if (column.dataIndex) return getNestedValue(record, column.dataIndex);
    return getNestedValue(record, String(column.key));
};

const isEmptyVal = (v: any) => v === null || v === undefined || v === '';

/* ===========================
 *     Filter Input Family
 * =========================== */
const FilterInput = React.memo<{
    filter: FilterConfig;
    value: any;
    onChange: (value: any) => void;
}>(({ filter, value, onChange }) => {
    const [local, setLocal] = useState(value || '');
    useEffect(() => setLocal(value || ''), [value]);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onChange(local);
            }}
            className="flex gap-2"
        >
            <input
                type="text"
                value={local}
                onChange={(e) => setLocal(e.target.value)}
                placeholder={filter.placeholder || `Nhập ${filter.label.toLowerCase()}...`}
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
            />
            <button
                type="submit"
                className="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm"
            >
                Lọc
            </button>
        </form>
    );
});

const FilterSelect = React.memo<{
    filter: FilterConfig;
    value: any;
    onChange: (value: any) => void;
}>(({ filter, value, onChange }) => (
    <select
        value={value || ''}
        onChange={(e) => onChange(e.target.value)}
        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
    >
        <option value="">Tất cả</option>
        {filter.options?.map((o) => (
            <option key={o.value} value={o.value}>
                {o.label}
            </option>
        ))}
    </select>
));

const FilterDate = React.memo<{
    filter: FilterConfig;
    value: any;
    onChange: (value: any) => void;
}>(({ value, onChange }) => (
    <div className="relative">
        <CalendarIcon className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
            type="date"
            value={value || ''}
            onChange={(e) => onChange(e.target.value)}
            className="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
        />
    </div>
));

const FilterDateRange = React.memo<{
    filter: FilterConfig;
    value: any;
    onChange: (value: any) => void;
}>(({ value, onChange }) => {
    const range = value || { start: '', end: '' };
    return (
        <div className="flex gap-2">
            <input
                type="date"
                value={range.start || ''}
                onChange={(e) => onChange({ ...range, start: e.target.value })}
                placeholder="Từ ngày"
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
            />
            <input
                type="date"
                value={range.end || ''}
                onChange={(e) => onChange({ ...range, end: e.target.value })}
                placeholder="Đến ngày"
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
            />
        </div>
    );
});

const FilterRange = React.memo<{
    filter: FilterConfig;
    value: any;
    onChange: (value: any) => void;
}>(({ value, onChange }) => {
    const range = value || { min: '', max: '' };
    return (
        <div className="flex gap-2">
            <input
                type="number"
                value={range.min || ''}
                onChange={(e) => onChange({ ...range, min: e.target.value })}
                placeholder="Từ"
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
            />
            <input
                type="number"
                value={range.max || ''}
                onChange={(e) => onChange({ ...range, max: e.target.value })}
                placeholder="Đến"
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none text-sm bg-white dark:bg-slate-900 dark:border-slate-700 dark:text-gray-100"
            />
        </div>
    );
});

/* ===========================
 *     Column Filter (per col)
 * =========================== */
const ColumnFilter = React.memo<{
    column: Column<any>;
    columnFilters: { [key: string]: any };
    onFilterChange: (columnKey: string, filterValue: any) => void;
    onClearFilter: (columnKey: string) => void;
}>(({ column, columnFilters, onFilterChange, onClearFilter }) => {
    const [isOpen, setIsOpen] = useState(false);
    const currentFilter = columnFilters[column.key as string];
    const hasActive = !isEmptyVal(currentFilter);

    return (
        <div className="relative">
            <button
                onClick={() => setIsOpen((s) => !s)}
                className={`p-1 rounded transition-colors ${hasActive ? 'text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30' : 'text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-800'
                    }`}
                type="button"
                title="Bộ lọc cột"
            >
                <Search className="w-3.5 h-3.5" />
            </button>

            {isOpen && (
                <>
                    <div className="fixed inset-0 z-[9998]" onClick={() => setIsOpen(false)} />
                    <div className="absolute right-0 top-full mt-1 w-48 z-[9999] bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-lg shadow-xl ring-1 ring-black/5">
                        <div className="p-2">
                            <div className="space-y-1">
                                <button
                                    onClick={() => {
                                        onClearFilter(column.key as string);
                                        setIsOpen(false);
                                    }}
                                    className="w-full text-left px-2 py-1 text-sm rounded flex items-center justify-between hover:bg-gray-50 dark:hover:bg-slate-800 text-gray-700 dark:text-gray-300"
                                    type="button"
                                >
                                    <span>Tất cả</span>
                                    {!hasActive && <Check className="w-3.5 h-3.5 text-blue-600" />}
                                </button>

                                {column.filters?.map((f) => (
                                    <button
                                        key={f.value}
                                        onClick={() => {
                                            onFilterChange(column.key as string, f.value);
                                            setIsOpen(false);
                                        }}
                                        className="w-full text-left px-2 py-1 text-sm rounded flex items-center justify-between hover:bg-gray-50 dark:hover:bg-slate-800 text-gray-900 dark:text-gray-100"
                                        type="button"
                                    >
                                        <span>{f.text}</span>
                                        {currentFilter === f.value && <Check className="w-3.5 h-3.5 text-blue-600" />}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
});

/* ===========================
 *        Empty State
 * =========================== */
const EmptyState = React.memo<{
    emptyText: string;
    emptyDescription?: string;
    onAdd?: () => void;
    addButtonText?: string;
}>(({ emptyText, emptyDescription, onAdd, addButtonText }) => (
    <div className="flex flex-col items-center justify-center py-14 px-6 text-center">
        <div className="w-16 h-16 bg-blue-50 dark:bg-blue-900/30 text-blue-500 dark:text-blue-400 rounded-full flex items-center justify-center mb-4 shadow-sm">
            <FolderOpen size={28} />
        </div>
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{emptyText}</h3>
        {emptyDescription && <p className="text-sm text-gray-500 dark:text-gray-400 max-w-md mb-6">{emptyDescription}</p>}
        {onAdd && (
            <button
                onClick={onAdd}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm transition"
                type="button"
            >
                <Plus className="w-4 h-4" />
                {addButtonText || 'Thêm mới'}
            </button>
        )}
    </div>
));

/* ===========================
 *       Main DataTable
 * =========================== */
const DataTable = <T extends { id: string | number }>({
    data = [],
    columns = [],
    pagination = {
        current: 1,
        pageSize: 10,
        total: 0,
        showSizeChanger: true,
        showQuickJumper: false,
        pageSizeOptions: ['5', '10', '20', '50'],
    },
    loading = false,
    searchValue = '',
    onSearch,
    sortField = null,
    sortOrder = null,
    onSortChange,
    onAdd,
    onEdit,
    onDelete,
    onView,
    onReset,
    title = 'Bảng dữ liệu',
    description = 'Quản lý dữ liệu hệ thống',
    rowSelection,
    customActions,
    onFiltersChange,
    filters = [],
    searchFields = [],
    searchPreset,
    searchPlaceholder = 'Tìm tự do, nhập #ID hoặc chọn trường...',
    addButtonText = 'Thêm mới',
    emptyText = 'Không có dữ liệu',
    emptyDescription,
    showAddButton = true,
    showSearch = true,
    showColumnSettings: showColumnSettingsProp = true,
    showResetButton = true,
    storageKey,
    density: initialDensity = 'comfortable',
    selectable = false,
    striped = true,
    stickyHeader = true,
    rowKey = 'id' as keyof T,
}: DataTableProps<T>): JSX.Element => {
    /* ---------- State ---------- */
    const isServerSide = !!pagination.onChange;
    const [internalSearchTerm, setInternalSearchTerm] = useState<string>('');
    const [internalSortConfig, setInternalSortConfig] = useState<{ key: string | null; direction: 'asc' | 'desc' }>({
        key: null, direction: 'asc',
    });
    const [internalCurrentPage, setInternalCurrentPage] = useState<number>(1);
    const [internalPageSize, setInternalPageSize] = useState<number>(10);
    const [selectedRows, setSelectedRows] = useState<(string | number)[]>([]);
    const [showColumnSettingsDropdown, setShowColumnSettingsDropdown] = useState<boolean>(false);
    const [columnFilters, setColumnFilters] = useState<{ [key: string]: any }>({});
    const [openActionDropdown, setOpenActionDropdown] = useState<string | number | null>(null);
    const [actionDropdownPosition, setActionDropdownPosition] = useState<{
        top?: number;
        bottom?: number;
        right: number;
    } | null>(null);
    const [activeFilters, setActiveFilters] = useState<{ [key: string]: any }>({});
    const [jumpPageInput, setJumpPageInput] = useState<string>('');
    const [searchPickerOpen, setSearchPickerOpen] = useState(false);
    const [highlightedSearchField, setHighlightedSearchField] = useState(0);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const [density, setDensity] = useState<'compact' | 'comfortable' | 'spacious'>(() => {
        if (!storageKey) return initialDensity;
        try {
            const saved = localStorage.getItem(`${storageKey}:density`);
            return saved === 'compact' || saved === 'comfortable' || saved === 'spacious' ? saved : initialDensity;
        } catch {
            return initialDensity;
        }
    });

    // Column visibility
    const [visibleColumns, setVisibleColumns] = useState<{ [key: string]: boolean }>(() => {
        const init: Record<string, boolean> = {};
        columns.forEach((c) => (init[c.key as string] = c.visible !== false));
        if (storageKey) {
            try {
                return { ...init, ...JSON.parse(localStorage.getItem(`${storageKey}:columns`) || '{}') };
            } catch {
                return init;
            }
        }
        return init;
    });
    useEffect(() => {
        setVisibleColumns((prev) => {
            const updated = { ...prev };
            columns.forEach((c) => {
                if (!(c.key as string in updated)) updated[c.key as string] = c.visible !== false;
            });
            return updated;
        });
    }, [columns]);
    useEffect(() => {
        if (!storageKey) return;
        try {
            localStorage.setItem(`${storageKey}:columns`, JSON.stringify(visibleColumns));
            localStorage.setItem(`${storageKey}:density`, density);
        } catch {
            // Preferences remain optional if storage is unavailable.
        }
    }, [density, storageKey, visibleColumns]);

    /* ---------- Derived ---------- */
    const currentSearchTerm = isServerSide ? searchValue : internalSearchTerm;
    const resolvedSearchFields = searchPreset ? ADMIN_TABLE_SEARCH_FIELDS[searchPreset] : searchFields;
    const searchFieldOptions = useMemo<SearchFieldOption[]>(
        () => [
            {
                key: '#',
                label: 'ID bản ghi',
                description: 'Tìm chính xác ID của bảng hiện tại',
                mode: 'exact',
            },
            ...resolvedSearchFields,
        ],
        [resolvedSearchFields],
    );
    const matchingSearchFields = useMemo(() => {
        const token = currentSearchTerm.trim().toLowerCase();

        if (token.includes(':') || (token.startsWith('#') && token !== '#')) {
            return [];
        }

        return searchFieldOptions.filter((field) => {
            if (token === '') return true;

            return field.key.toLowerCase().includes(token)
                || field.label.toLowerCase().includes(token);
        });
    }, [currentSearchTerm, searchFieldOptions]);
    const currentSortConfig = isServerSide
        ? { key: sortField, direction: (sortOrder || 'asc') as 'asc' | 'desc' }
        : internalSortConfig;

    const currentPage = isServerSide ? pagination.current : internalCurrentPage;
    const currentPageSize = isServerSide ? pagination.pageSize : internalPageSize;

    const displayColumns = useMemo(
        () => columns.filter((c) => visibleColumns[c.key as string]),
        [columns, visibleColumns]
    );

    /* ---------- Data processing (client) ---------- */
    const processedData = useMemo(() => {
        if (isServerSide) return data;

        let filtered = data.filter((item: T) => {
            // text search
            if (currentSearchTerm) {
                const match = columns.some((col) => {
                    if (col.searchable === false) return false;
                    const val = extractColumnValue(item, col)?.toString().toLowerCase() || '';
                    return val.includes(currentSearchTerm.toLowerCase());
                });
                if (!match) return false;
            }

            // column filters
            for (const [colKey, fVal] of Object.entries(columnFilters)) {
                if (!isEmptyVal(fVal)) {
                    const col = columns.find((c) => c.key === colKey);
                    if (col?.onFilter) {
                        if (!col.onFilter(fVal, item)) return false;
                    } else {
                        const v = extractColumnValue(item, { key: colKey } as Column<T>);
                        if (Array.isArray(v)) {
                            if (!v.includes(fVal)) return false;
                        } else if (v !== fVal) return false;
                    }
                }
            }

            // filter bar (activeFilters)
            for (const [k, fVal] of Object.entries(activeFilters)) {
                if (isEmptyVal(fVal)) continue;
                const cfg = filters.find((f) => f.key === k);
                if (!cfg) continue;

                const v = extractColumnValue(item, { key: k } as Column<T>);

                if (cfg.type === 'dateRange' && typeof fVal === 'object') {
                    const { start, end } = fVal as any;
                    const d = new Date(v);
                    if (start && d < new Date(start)) return false;
                    if (end && d > new Date(end)) return false;
                } else if (cfg.type === 'range' && typeof fVal === 'object') {
                    const { min, max } = fVal as any;
                    const num = parseFloat(v);
                    if (!isEmptyVal(min) && num < parseFloat(min)) return false;
                    if (!isEmptyVal(max) && num > parseFloat(max)) return false;
                } else if (cfg.type === 'input') {
                    const s = v?.toString().toLowerCase() || '';
                    if (!s.includes(String(fVal).toLowerCase())) return false;
                } else {
                    if (v !== fVal) return false;
                }
            }

            return true;
        });

        // sort
        if (currentSortConfig.key) {
            filtered = [...filtered].sort((a: T, b: T) => {
                const col = columns.find((c) => c.key === currentSortConfig.key);
                const av = extractColumnValue(a, col || ({ key: currentSortConfig.key } as Column<T>));
                const bv = extractColumnValue(b, col || ({ key: currentSortConfig.key } as Column<T>));
                if (av < bv) return currentSortConfig.direction === 'asc' ? -1 : 1;
                if (av > bv) return currentSortConfig.direction === 'asc' ? 1 : -1;
                return 0;
            });
        }

        return filtered;
    }, [data, currentSearchTerm, currentSortConfig, columns, isServerSide, columnFilters, activeFilters, filters]);

    const totalRecords = isServerSide ? pagination.total : processedData.length;
    const totalPages = Math.ceil(Math.max(1, totalRecords) / currentPageSize);
    const paginatedData = isServerSide
        ? data
        : processedData.slice((currentPage - 1) * currentPageSize, currentPage * currentPageSize);

    const currentSelectedRows = rowSelection ? rowSelection.selectedRowKeys : selectedRows;
    const getRowKey = useCallback(
        (record: T): string | number => typeof rowKey === 'function'
            ? rowKey(record)
            : (record[rowKey] as string | number),
        [rowKey],
    );
    const cellPadding = density === 'compact' ? 'py-2.5' : density === 'spacious' ? 'py-5' : 'py-4';
    const hasActions =
        !!onView || !!onEdit || !!onDelete || (customActions && Object.keys(customActions).length > 0);

    const hasActiveFilters =
        (!!(isServerSide ? searchValue : currentSearchTerm) &&
            (isServerSide ? searchValue.trim() !== '' : currentSearchTerm.trim() !== '')) ||
        Object.keys(columnFilters).length > 0 ||
        Object.keys(activeFilters).length > 0 ||
        currentSortConfig.key !== null;

    /* ---------- Handlers ---------- */
    const handleColumnFilter = useCallback(
        (columnKey: string, filterValue: any) => {
            const nf = { ...columnFilters, [columnKey]: filterValue };
            setColumnFilters(nf);
            setInternalCurrentPage(1);
            if (isServerSide && onFiltersChange) onFiltersChange(nf);
        },
        [columnFilters, isServerSide, onFiltersChange]
    );

    const clearColumnFilter = useCallback(
        (columnKey: string) => {
            const nf = { ...columnFilters };
            delete nf[columnKey];
            setColumnFilters(nf);
            if (isServerSide && onFiltersChange) onFiltersChange(nf);
        },
        [columnFilters, isServerSide, onFiltersChange]
    );

    const handleActiveFilterChange = useCallback(
        (filterKey: string, filterValue: any) => {
            const nf = { ...activeFilters, [filterKey]: filterValue };
            setActiveFilters(nf);
            setInternalCurrentPage(1);
            if (isServerSide && onFiltersChange) onFiltersChange({ ...columnFilters, ...nf });
        },
        [activeFilters, columnFilters, isServerSide, onFiltersChange]
    );

    const clearActiveFilter = useCallback(
        (filterKey: string) => {
            const nf = { ...activeFilters };
            delete nf[filterKey];
            setActiveFilters(nf);
            if (isServerSide && onFiltersChange) onFiltersChange({ ...columnFilters, ...nf });
        },
        [activeFilters, columnFilters, isServerSide, onFiltersChange]
    );

    const handleSearch = (value: string) => {
        if (isServerSide && onSearch) onSearch(value);
        else {
            setInternalSearchTerm(value);
            setInternalCurrentPage(1);
        }
    };

    const selectSearchField = (field: SearchFieldOption) => {
        const value = field.key === '#' ? '#' : `${field.key}:`;
        handleSearch(value);
        setSearchPickerOpen(false);

        requestAnimationFrame(() => {
            searchInputRef.current?.focus();
            searchInputRef.current?.setSelectionRange(value.length, value.length);
        });
    };

    const handleSearchKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (!searchPickerOpen || matchingSearchFields.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlightedSearchField((current) => (current + 1) % matchingSearchFields.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlightedSearchField((current) => (current - 1 + matchingSearchFields.length) % matchingSearchFields.length);
        } else if ((event.key === 'Enter' || event.key === 'Tab') && matchingSearchFields[highlightedSearchField]) {
            event.preventDefault();
            selectSearchField(matchingSearchFields[highlightedSearchField]);
        } else if (event.key === 'Escape') {
            setSearchPickerOpen(false);
        }
    };

    const handleResetFilters = () => {
        if (isServerSide && onReset) {
            onReset();
            setColumnFilters({});
            setActiveFilters({});
        } else {
            setInternalSearchTerm('');
            setColumnFilters({});
            setActiveFilters({});
            setInternalCurrentPage(1);
            setInternalSortConfig({ key: null, direction: 'asc' });
        }
        if (isServerSide && onFiltersChange) onFiltersChange({});
    };

    const handleSort = (key: string) => {
        if (isServerSide && onSortChange) {
            let direction: 'asc' | 'desc' = 'asc';
            if (sortField === key && sortOrder === 'asc') direction = 'desc';
            onSortChange(key, direction);
        } else {
            let direction: 'asc' | 'desc' = 'asc';
            if (internalSortConfig.key === key && internalSortConfig.direction === 'asc') direction = 'desc';
            setInternalSortConfig({ key, direction });
        }
    };

    const handlePageChange = (page: number) => {
        if (isServerSide && pagination.onChange) pagination.onChange(page, currentPageSize);
        else setInternalCurrentPage(page);
    };

    const handlePageSizeChange = (newSize: number) => {
        if (isServerSide) {
            if (pagination.onShowSizeChange) pagination.onShowSizeChange(1, newSize);
            else if (pagination.onChange) pagination.onChange(1, newSize);
        } else {
            setInternalPageSize(newSize);
            setInternalCurrentPage(1);
        }
    };

    const handleJumpToPage = (e: React.KeyboardEvent<HTMLInputElement> | React.FormEvent) => {
        if ('key' in e && e.key !== 'Enter') return;
        e.preventDefault();
        const num = parseInt(jumpPageInput);
        if (!isNaN(num) && num >= 1 && num <= totalPages) {
            handlePageChange(num);
            setJumpPageInput('');
        }
    };

    const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
        const newSelected = e.target.checked ? paginatedData.map(getRowKey) : [];
        if (rowSelection?.onChange) rowSelection.onChange(newSelected);
        else setSelectedRows(newSelected);
    };

    const handleSelectRow = (id: string | number) => {
        const current = rowSelection ? rowSelection.selectedRowKeys : selectedRows;
        const next = current.includes(id) ? current.filter((v) => v !== id) : [...current, id];
        if (rowSelection?.onChange) rowSelection.onChange(next);
        else setSelectedRows(next);
    };

    const renderCell = (item: T, column: Column<T>, index: number): React.ReactNode => {
        const value = extractColumnValue(item, column);
        if (column.render) return column.render(value, item, index);
        if (isEmptyVal(value)) return <span className="text-gray-500 dark:text-gray-400">-</span>;
        return value as React.ReactNode;
    };

    const renderFilterComponent = (filter: FilterConfig): React.ReactNode => {
        const val = activeFilters[filter.key];
        switch (filter.type) {
            case 'input':
                return <FilterInput filter={filter} value={val} onChange={(v) => handleActiveFilterChange(filter.key, v)} />;
            case 'select':
                return <FilterSelect filter={filter} value={val} onChange={(v) => handleActiveFilterChange(filter.key, v)} />;
            case 'date':
                return <FilterDate filter={filter} value={val} onChange={(v) => handleActiveFilterChange(filter.key, v)} />;
            case 'dateRange':
                return <FilterDateRange filter={filter} value={val} onChange={(v) => handleActiveFilterChange(filter.key, v)} />;
            case 'range':
                return <FilterRange filter={filter} value={val} onChange={(v) => handleActiveFilterChange(filter.key, v)} />;
            default:
                return null;
        }
    };

    /* ---------- UI ---------- */
    return (
        <div className="bg-white/80 dark:bg-slate-900/80 backdrop-blur-md rounded-xl shadow-md border border-gray-200 dark:border-slate-700 overflow-hidden">
            {/* Header */}
            <div className="sticky top-0 z-20 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-gray-200 dark:border-slate-700 px-4 sm:px-6 py-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <h2 className="text-lg sm:text-xl font-semibold text-gray-900 dark:text-gray-100">{title}</h2>
                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{description}</p>
                    </div>
                    {isServerSide && (
                        <span className="text-[11px] font-medium text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 px-2 py-0.5 rounded">
                            Server-side Mode
                        </span>
                    )}
                    {onAdd && (
                        <div className="sm:ml-4">
                            {showAddButton && (
                                <button
                                    onClick={onAdd}
                                    className="inline-flex w-full items-center justify-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm transition sm:w-auto"
                                    type="button"
                                >
                                    <Plus className="w-4 h-4" />
                                    {addButtonText}
                                </button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Filter Bar */}
            {filters.length > 0 && (
                <div className="px-4 sm:px-6 py-4 bg-gray-50/60 dark:bg-slate-800/50 border-b border-gray-200 dark:border-slate-700">
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                            {filters.map((f) => (
                                <div key={f.key} className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <label className="text-sm font-medium text-gray-700 dark:text-gray-300">{f.label}</label>
                                        {activeFilters[f.key] && (
                                            <button
                                                onClick={() => clearActiveFilter(f.key)}
                                                className="text-xs text-gray-400 hover:text-red-600 transition-colors"
                                                type="button"
                                                title="Xóa bộ lọc"
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        )}
                                    </div>
                                    {renderFilterComponent(f)}
                                </div>
                            ))}
                        </div>

                        {/* Active filters summary */}
                        {Object.keys(activeFilters).length > 0 && (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm text-gray-500">Bộ lọc đang áp dụng:</span>
                                {Object.entries(activeFilters).map(([key, val]) => {
                                    const cfg = filters.find((f) => f.key === key);
                                    if (!cfg || isEmptyVal(val)) return null;

                                    let label = '';
                                    if (typeof val === 'object') {
                                        if (cfg.type === 'dateRange') label = `${(val as any).start || '...'} → ${(val as any).end || '...'}`;
                                        else if (cfg.type === 'range') label = `${(val as any).min || '...'} - ${(val as any).max || '...'}`;
                                    } else {
                                        const opt = cfg.options?.find((o) => o.value === val);
                                        label = opt ? opt.label : String(val);
                                    }

                                    return (
                                        <span
                                            key={key}
                                            className="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-xs rounded-full"
                                        >
                                            <span>{cfg.label}: {label}</span>
                                            <button
                                                onClick={() => clearActiveFilter(key)}
                                                className="hover:bg-blue-200/60 dark:hover:bg-blue-800/60 rounded-full p-0.5 transition-colors"
                                                type="button"
                                            >
                                                <X className="w-3 h-3" />
                                            </button>
                                        </span>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Toolbar */}
            <div className="p-4 sm:p-6 border-b border-gray-200 dark:border-slate-700 bg-white/60 dark:bg-slate-900/60">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div className="flex min-w-0 flex-1 items-center gap-4">
                        {showSearch && (
                            <div className="relative w-full sm:max-w-md">
                                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    placeholder={searchPlaceholder}
                                    value={currentSearchTerm}
                                    onChange={(e) => {
                                        handleSearch(e.target.value);
                                        setSearchPickerOpen(true);
                                        setHighlightedSearchField(0);
                                    }}
                                    onFocus={() => {
                                        setSearchPickerOpen(true);
                                        setHighlightedSearchField(0);
                                    }}
                                    onBlur={() => window.setTimeout(() => setSearchPickerOpen(false), 120)}
                                    onKeyDown={handleSearchKeyDown}
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-expanded={searchPickerOpen && matchingSearchFields.length > 0}
                                    className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-slate-700 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 placeholder:text-gray-400"
                                />
                                {searchPickerOpen && matchingSearchFields.length > 0 && (
                                    <div
                                        className="absolute left-0 right-0 top-full z-50 mt-1 max-h-72 overflow-y-auto rounded-lg border border-gray-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900"
                                        role="listbox"
                                    >
                                        <div className="px-2 py-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">
                                            Chọn trường rồi nhập nội dung phía sau
                                        </div>
                                        {matchingSearchFields.map((field, index) => (
                                            <button
                                                key={field.key}
                                                type="button"
                                                role="option"
                                                aria-selected={index === highlightedSearchField}
                                                onMouseDown={(event) => event.preventDefault()}
                                                onMouseEnter={() => setHighlightedSearchField(index)}
                                                onClick={() => selectSearchField(field)}
                                                className={`flex w-full items-center justify-between gap-3 rounded-md px-2 py-2 text-left transition-colors ${index === highlightedSearchField
                                                    ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                                    : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-slate-800'
                                                    }`}
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-medium">{field.label}</span>
                                                    {field.description && (
                                                        <span className="block truncate text-xs text-gray-400">{field.description}</span>
                                                    )}
                                                </span>
                                                <span className="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                    {field.key === '#' ? '#' : `${field.key}:`}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        {currentSelectedRows.length > 0 && (
                            <span className="text-sm text-gray-600 dark:text-gray-400">
                                Đã chọn {currentSelectedRows.length} mục
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            onClick={() => setDensity(current => current === 'compact' ? 'comfortable' : current === 'comfortable' ? 'spacious' : 'compact')}
                            className="flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-gray-600 transition-colors hover:bg-gray-50 dark:border-slate-700 dark:text-gray-300 dark:hover:bg-slate-800"
                            type="button"
                            title={`Mật độ: ${density}`}
                        >
                            <Rows3 className="h-4 w-4" />
                            <span className="hidden md:inline">{density === 'compact' ? 'Gọn' : density === 'spacious' ? 'Rộng' : 'Vừa'}</span>
                        </button>
                        {hasActiveFilters && showResetButton && (
                            <button
                                onClick={handleResetFilters}
                                className="flex items-center gap-2 px-3 py-2 border border-orange-300 text-orange-600 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/20 transition-colors"
                                type="button"
                                title="Xóa tất cả bộ lọc"
                            >
                                <RotateCcw className="w-4 h-4" />
                                <span className="hidden sm:inline">Đặt lại</span>
                            </button>
                        )}

                        {/* Column Settings */}
                        {showColumnSettingsProp && (
                            <div className="relative">
                                <button
                                    onClick={() => setShowColumnSettingsDropdown((s) => !s)}
                                    className="flex items-center gap-2 px-3 py-2 border border-gray-300 dark:border-slate-700 rounded-lg transition-colors text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-800"
                                    type="button"
                                >
                                    <Settings className="w-4 h-4" />
                                    <span className="hidden sm:inline">Cột hiển thị</span>
                                </button>

                                {showColumnSettingsDropdown && (
                                    <>
                                        <div className="fixed inset-0 z-[9998]" onClick={() => setShowColumnSettingsDropdown(false)} />
                                        <div className="absolute right-0 top-full mt-2 w-64 z-[9999] bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-lg shadow-xl max-h-[70vh] overflow-hidden">
                                            <div className="p-3 border-b border-gray-200 dark:border-slate-700">
                                                <h3 className="text-sm font-medium text-gray-900 dark:text-gray-100">Chọn cột hiển thị</h3>
                                            </div>
                                            <div className="p-2 overflow-y-auto max-h-64">
                                                {columns.map((c) => (
                                                    <label
                                                        key={c.key as string}
                                                        className="flex items-center gap-3 px-2 py-2 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-800"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={!!visibleColumns[c.key as string]}
                                                            onChange={() =>
                                                                setVisibleColumns((prev) => ({
                                                                    ...prev,
                                                                    [c.key as string]: !prev[c.key as string],
                                                                }))
                                                            }
                                                            className="rounded border-gray-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
                                                        />
                                                        <span className="text-sm text-gray-700 dark:text-gray-300">
                                                            {c.title}
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Table */}
            <div className="overflow-x-auto relative">
                {loading && (
                    <div className="absolute inset-0 bg-white/70 dark:bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-20">
                        <Loader className="w-6 h-6 text-blue-500 animate-spin" />
                    </div>
                )}

                <table className="w-full min-w-[680px]">
                    <thead className={`bg-gradient-to-b from-gray-50 to-gray-100 dark:from-slate-800 dark:to-slate-900 ${stickyHeader ? 'sticky top-0 z-10' : ''}`}>
                        <tr>
                            {selectable && <th className="px-4 sm:px-6 py-3 text-left w-12 border-b border-gray-200 dark:border-slate-700">
                                <input
                                    type="checkbox"
                                    checked={paginatedData.length > 0 && currentSelectedRows.length === paginatedData.length}
                                    onChange={handleSelectAll}
                                    className="rounded border-gray-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
                                />
                            </th>}

                            {displayColumns.map((column) => (
                                <th
                                    key={column.key as string}
                                    className="px-4 sm:px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-slate-700"
                                    style={{ width: column.width, textAlign: column.align || 'left' }}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-1">
                                            {column.sortable !== false ? (
                                                <button
                                                    onClick={() => handleSort(column.key as string)}
                                                    className="inline-flex items-center gap-1 hover:text-blue-600 dark:hover:text-blue-400 transition"
                                                    type="button"
                                                    title="Sắp xếp"
                                                >
                                                    {column.title}
                                                    {currentSortConfig.key === column.key &&
                                                        (currentSortConfig.direction === 'asc' ? (
                                                            <ChevronUp className="w-4 h-4" />
                                                        ) : (
                                                            <ChevronDown className="w-4 h-4" />
                                                        ))}
                                                </button>
                                            ) : (
                                                column.title
                                            )}
                                        </div>

                                        {column.filters && (
                                            <ColumnFilter
                                                column={column}
                                                columnFilters={columnFilters}
                                                onFilterChange={handleColumnFilter}
                                                onClearFilter={clearColumnFilter}
                                            />
                                        )}
                                    </div>
                                </th>
                            ))}

                            {hasActions && (
                                <th className="px-4 sm:px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-slate-700 w-28">
                                    Thao tác
                                </th>
                            )}
                        </tr>
                    </thead>

                    <tbody className="bg-white dark:bg-slate-900 divide-y divide-gray-100 dark:divide-slate-800">
                        {paginatedData.length === 0 ? (
                            <tr>
                                <td colSpan={displayColumns.length + (hasActions ? 1 : 0) + (selectable ? 1 : 0)} className="p-0">
                                    <EmptyState
                                        emptyText={emptyText}
                                        emptyDescription={emptyDescription}
                                        onAdd={onAdd}
                                        addButtonText={addButtonText}
                                    />
                                </td>
                            </tr>
                        ) : (
                            paginatedData.map((item: T, rowIndex: number) => (
                                <tr
                                    key={getRowKey(item)}
                                    className={`hover:bg-blue-50/50 dark:hover:bg-blue-900/10 transition-colors ${striped ? 'even:bg-gray-50/40 dark:even:bg-slate-800/30' : ''}`}
                                >
                                    {selectable && <td className={`px-4 sm:px-6 ${cellPadding}`}>
                                        <input
                                            type="checkbox"
                                            checked={currentSelectedRows.includes(getRowKey(item))}
                                            onChange={() => handleSelectRow(getRowKey(item))}
                                            className="rounded border-gray-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
                                        />
                                    </td>}

                                    {displayColumns.map((column) => (
                                        <td
                                            key={column.key as string}
                                            className={`px-4 sm:px-6 ${cellPadding} whitespace-nowrap text-gray-900 dark:text-gray-100 text-sm`}
                                            style={{ textAlign: column.align || 'left' }}
                                        >
                                            {renderCell(item, column, rowIndex)}
                                        </td>
                                    ))}

                                    {hasActions && (
                                        <td className={`px-4 sm:px-6 ${cellPadding} whitespace-nowrap text-sm`}>
                                            <div className="flex items-center gap-1">
                                                {!!onView && (
                                                    <button
                                                        onClick={() => onView(item)}
                                                        className="p-1.5 rounded-md text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-transform hover:scale-110"
                                                        type="button"
                                                        title="Xem chi tiết"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>
                                                )}
                                                {!!onEdit && (
                                                    <button
                                                        onClick={() => onEdit(item)}
                                                        className="p-1.5 rounded-md text-green-500 hover:bg-green-50 dark:hover:bg-green-900/30 transition-transform hover:scale-110"
                                                        type="button"
                                                        title="Chỉnh sửa"
                                                    >
                                                        <Edit2 className="w-4 h-4" />
                                                    </button>
                                                )}
                                                {!!onDelete && (
                                                    <button
                                                        onClick={() => onDelete(item)}
                                                        className="p-1.5 rounded-md text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 transition-transform hover:scale-110"
                                                        type="button"
                                                        title="Xóa"
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                )}

                                                {customActions && Object.keys(customActions).length > 0 && (
                                                    <div className="relative">
                                                        <button
                                                            onClick={(e) => {
                                                                if (openActionDropdown === getRowKey(item)) {
                                                                    setOpenActionDropdown(null);
                                                                    return;
                                                                }
                                                                const btn = e.currentTarget as HTMLElement;
                                                                const btnRect = btn.getBoundingClientRect();
                                                                const estimatedHeight = Math.min(Object.keys(customActions).length * 44 + 16, 264);
                                                                const opensAbove = window.innerHeight - btnRect.bottom < estimatedHeight
                                                                    && btnRect.top >= estimatedHeight;
                                                                setActionDropdownPosition({
                                                                    right: Math.max(8, window.innerWidth - btnRect.right),
                                                                    ...(opensAbove
                                                                        ? { bottom: window.innerHeight - btnRect.top + 4 }
                                                                        : { top: btnRect.bottom + 4 }),
                                                                });
                                                                setOpenActionDropdown(getRowKey(item));
                                                            }}
                                                            className="p-1.5 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-800"
                                                            type="button"
                                                            title="Thêm hành động"
                                                        >
                                                            <MoreVertical className="w-4 h-4" />
                                                        </button>

                                                        {openActionDropdown === getRowKey(item)
                                                            && actionDropdownPosition
                                                            && typeof document !== 'undefined'
                                                            && createPortal(
                                                            <>
                                                                <div
                                                                    className="fixed inset-0 z-[9998]"
                                                                    onClick={() => setOpenActionDropdown(null)}
                                                                />
                                                                <div
                                                                    className="fixed z-[9999] min-w-[190px] rounded-lg border border-gray-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900"
                                                                    style={{
                                                                        ...actionDropdownPosition,
                                                                        boxShadow:
                                                                            '0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04)',
                                                                    }}
                                                                >
                                                                    <div className="py-1 max-h-64 overflow-y-auto">
                                                                        {Object.entries(customActions).map(([key, action]) => {
                                                                            if (action.condition && !action.condition(item)) return null;
                                                                            const Icon = action.icon;
                                                                            return (
                                                                                <button
                                                                                    key={key}
                                                                                    onClick={() => {
                                                                                        action.handler(item);
                                                                                        setOpenActionDropdown(null);
                                                                                    }}
                                                                                    className={`flex items-center w-full px-4 py-2 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-slate-800 text-gray-900 dark:text-gray-100 ${action.className || ''}`}
                                                                                >
                                                                                    <Icon className="w-4 h-4 mr-2" />
                                                                                    {action.label}
                                                                                </button>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            </>,
                                                            document.body,
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            <div className="px-4 sm:px-6 py-4 border-t border-gray-200 dark:border-slate-700">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 text-sm">
                    <div className="text-gray-600 dark:text-gray-400">
                        Hiển thị {(currentPage - 1) * currentPageSize + (totalRecords ? 1 : 0)} đến{' '}
                        {Math.min(currentPage * currentPageSize, totalRecords)} của {totalRecords} kết quả
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {pagination.showSizeChanger && (
                            <div className="flex items-center gap-1">
                                <select
                                    value={currentPageSize}
                                    onChange={(e) => handlePageSizeChange(Number(e.target.value))}
                                    className="px-2 py-1 border border-gray-300 dark:border-slate-700 rounded bg-white dark:bg-slate-900 text-gray-700 dark:text-gray-200"
                                >
                                    {pagination.pageSizeOptions?.map((s) => (
                                        <option key={s} value={s}>
                                            {s} / page
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="flex items-center">
                            <button
                                onClick={() => handlePageChange(currentPage - 1)}
                                disabled={currentPage === 1}
                                className="flex items-center justify-center w-8 h-8 border border-gray-300 dark:border-slate-700 hover:border-blue-500 disabled:border-gray-200 dark:disabled:border-slate-800 disabled:cursor-not-allowed transition-colors rounded-l-md"
                                type="button"
                                title="Trang trước"
                            >
                                <ChevronLeft className="w-4 h-4 text-gray-600 dark:text-gray-300" />
                            </button>

                            {/* Page numbers with smart ellipsis */}
                            <div className="flex items-center">
                                {(() => {
                                    const pages: (number | '...')[] = [];
                                    const maxVisible = 7;

                                    if (totalPages <= maxVisible) {
                                        for (let i = 1; i <= totalPages; i++) pages.push(i);
                                    } else {
                                        pages.push(1);
                                        if (currentPage <= 4) {
                                            for (let i = 2; i <= 5; i++) pages.push(i);
                                            pages.push('...');
                                            pages.push(totalPages);
                                        } else if (currentPage >= totalPages - 3) {
                                            pages.push('...');
                                            for (let i = totalPages - 4; i <= totalPages; i++) pages.push(i);
                                        } else {
                                            pages.push('...');
                                            for (let i = currentPage - 1; i <= currentPage + 1; i++) pages.push(i);
                                            pages.push('...');
                                            pages.push(totalPages);
                                        }
                                    }

                                    return pages.map((p, idx) => {
                                        if (p === '...') {
                                            return (
                                                <span key={`e-${idx}`} className="w-8 h-8 flex items-center justify-center text-gray-400">
                                                    …
                                                </span>
                                            );
                                        }
                                        const active = p === currentPage;
                                        return (
                                            <button
                                                key={p}
                                                onClick={() => handlePageChange(p as number)}
                                                className={`w-8 h-8 text-sm border-y border-r first:border-l transition-colors ${active
                                                    ? 'bg-blue-600 border-blue-600 text-white'
                                                    : 'bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-700 text-gray-700 dark:text-gray-300 hover:border-blue-500 hover:text-blue-600'
                                                    }`}
                                                type="button"
                                                title={`Trang ${p}`}
                                            >
                                                {p}
                                            </button>
                                        );
                                    });
                                })()}
                            </div>

                            <button
                                onClick={() => handlePageChange(currentPage + 1)}
                                disabled={currentPage === totalPages || totalPages === 0}
                                className="flex items-center justify-center w-8 h-8 border border-gray-300 dark:border-slate-700 hover:border-blue-500 disabled:border-gray-200 dark:disabled:border-slate-800 disabled:cursor-not-allowed transition-colors rounded-r-md"
                                type="button"
                                title="Trang sau"
                            >
                                <ChevronRight className="w-4 h-4 text-gray-600 dark:text-gray-300" />
                            </button>
                        </div>

                        {pagination.showQuickJumper && totalPages > 1 && (
                            <div className="flex items-center gap-2 ml-2">
                                <span className="text-gray-600 dark:text-gray-400">Tới</span>
                                <form onSubmit={handleJumpToPage} className="inline-block">
                                    <input
                                        type="number"
                                        value={jumpPageInput}
                                        onChange={(e) => setJumpPageInput(e.target.value)}
                                        onKeyDown={handleJumpToPage}
                                        min={1}
                                        max={totalPages}
                                        className="w-14 h-8 px-2 border border-gray-300 dark:border-slate-700 text-center text-sm bg-white dark:bg-slate-900 text-gray-900 dark:text-gray-100 rounded outline-none"
                                    />
                                </form>
                                <span className="text-gray-600 dark:text-gray-400">Trang</span>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export { DataTable };
export type {
    DataTableProps,
    Column,
    PaginationConfig,
    RowSelection,
    CustomAction,
    FilterConfig,
    SearchFieldOption,
    AdminSearchPreset,
};

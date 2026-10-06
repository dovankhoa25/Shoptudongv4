export type NickUploadEntry = {
    file: File;
    status: 'waiting' | 'uploading' | 'ready' | 'failed';
    percent: number;
    id?: string;
    error?: string;
};

type Upload = (file: File, signal: AbortSignal, progress: (percent: number) => void) => Promise<string>;

// The queue survives React renders and preserves the selected order, regardless of completion order.
export class NickUploadQueue {
    private entries = new Map<File, NickUploadEntry>();
    private controllers = new Map<File, AbortController>();
    private selected: File[] = [];
    private active = 0;
    private disposed = false;

    constructor(private upload: Upload, private remove: (id: string) => void,
        private changed: (entries: NickUploadEntry[]) => void, private concurrency = 20) {}

    setFiles(files: File[]) {
        this.selected = files;
        for (const [file, entry] of this.entries) {
            if (!files.includes(file)) {
                this.controllers.get(file)?.abort();
                if (entry.id) this.remove(entry.id);
                this.entries.delete(file);
            }
        }
        for (const file of files) {
            if (!this.entries.has(file)) this.entries.set(file, { file, status: 'waiting', percent: 0 });
        }
        this.emit();
        this.pump();
    }

    retry() {
        for (const entry of this.entries.values()) {
            if (entry.status === 'failed') Object.assign(entry, { status: 'waiting', percent: 0, error: undefined });
        }
        this.emit();
        this.pump();
    }

    dispose() {
        this.disposed = true;
        for (const controller of this.controllers.values()) controller.abort();
    }

    private emit() {
        if (!this.disposed) this.changed(this.selected.flatMap(file => {
            const entry = this.entries.get(file);
            return entry ? [{ ...entry }] : [];
        }));
    }

    private pump() {
        if (this.disposed) return;
        for (const file of this.selected) {
            if (this.active >= this.concurrency) break;
            const entry = this.entries.get(file);
            if (!entry || entry.status !== 'waiting') continue;
            const controller = new AbortController();
            this.controllers.set(file, controller);
            entry.status = 'uploading';
            this.active++;
            this.emit();
            void this.upload(file, controller.signal, percent => {
                entry.percent = percent;
                this.emit();
            }).then(id => {
                if (this.disposed || this.entries.get(file) !== entry) {
                    this.remove(id);
                    return;
                }
                Object.assign(entry, { id, status: 'ready', percent: 100 });
            }).catch(error => {
                if (this.entries.get(file) === entry) {
                    entry.status = 'failed';
                    entry.error = error instanceof Error ? error.message : 'Tải ảnh thất bại.';
                }
            }).finally(() => {
                this.active--;
                if (this.controllers.get(file) === controller) this.controllers.delete(file);
                this.emit();
                this.pump();
            });
        }
    }
}

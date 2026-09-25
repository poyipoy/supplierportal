/**
 * ADASI Portal Supplier - File Upload Dropzone & Preview Component for Alpine.js
 * Supports both Single File and Multi-File / Bulk Upload with DataTransfer sync.
 */

export function adasiFileUploadComponent(paramA = '', paramB = false, paramC = {}) {
    let isMultiple = false;
    let maxFiles = 5;
    let maxSizeMb = 5;
    let initialExisting = [];

    if (typeof paramA === 'object' && paramA !== null && !Array.isArray(paramA)) {
        // Modern object signature: { multiple: bool, maxFiles: int, maxSizeMb: int, existingFiles: array }
        isMultiple = Boolean(paramA.multiple);
        maxFiles = Number(paramA.maxFiles) || 5;
        maxSizeMb = Number(paramA.maxSizeMb) || 5;
        if (Array.isArray(paramA.existingFiles)) {
            initialExisting = paramA.existingFiles.map((f, i) => ({
                id: f.id ?? null,
                name: f.name || f.filename || ('Berkas ' + (i + 1)),
                size: f.size || '',
                url: f.url || null,
            }));
        }
    } else {
        // Legacy signature: (initialName = '', isExisting = false, options = {})
        const initialName = String(paramA || '');
        const isExisting = Boolean(paramB);
        const options = (typeof paramC === 'object' && paramC !== null) ? paramC : {};
        isMultiple = Boolean(options.multiple);
        maxFiles = Number(options.maxFiles) || 5;
        maxSizeMb = Number(options.maxSizeMb) || 5;

        if (Array.isArray(options.existingFiles)) {
            initialExisting = options.existingFiles.map((f, i) => ({
                id: f.id ?? null,
                name: f.name || f.filename || ('Berkas ' + (i + 1)),
                size: f.size || '',
                url: f.url || null,
            }));
        } else if (initialName) {
            initialExisting = [{
                id: options.id ?? null,
                name: initialName,
                size: options.size || '',
                url: options.url || null,
            }];
        }
    }

    return {
        isMultiple,
        maxFiles,
        maxSizeMb,
        existingFiles: initialExisting,
        stagedFiles: [],
        isDragging: false,
        clientError: '',
        maxBytes: maxSizeMb * 1024 * 1024,
        _syncing: false,

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        get totalFilesCount() {
            return this.existingFiles.length + this.stagedFiles.length;
        },

        get hasFiles() {
            return this.totalFilesCount > 0;
        },

        get canAddMore() {
            return this.isMultiple ? (this.totalFilesCount < this.maxFiles) : (this.totalFilesCount === 0);
        },

        // Legacy getters for backward compatibility
        get fileName() {
            if (this.stagedFiles.length > 0) return this.stagedFiles[0].name;
            if (this.existingFiles.length > 0) return this.existingFiles[0].name;
            return '';
        },

        get fileSize() {
            if (this.stagedFiles.length > 0) return this.formatBytes(this.stagedFiles[0].size);
            if (this.existingFiles.length > 0) return this.existingFiles[0].size || '';
            return '';
        },

        get hasFile() {
            return this.hasFiles;
        },

        get isExisting() {
            return this.stagedFiles.length === 0 && this.existingFiles.length > 0;
        },

        syncInputFiles() {
            const input = this.$refs.fileInput;
            if (!input) return;
            try {
                const dt = new DataTransfer();
                for (const file of this.stagedFiles) {
                    dt.items.add(file);
                }
                this._syncing = true;
                input.files = dt.files;
            } catch (err) {
                console.warn('DataTransfer sync notice:', err);
            } finally {
                this._syncing = false;
            }
        },

        handleFiles(files) {
            if (!files || files.length === 0) return;
            this.clientError = '';
            const incoming = Array.from(files);

            if (!this.isMultiple) {
                const file = incoming[0];
                if (file.size > this.maxBytes) {
                    this.clientError = 'Ukuran berkas melebihi ' + this.maxSizeMb + ' MB (' + this.formatBytes(file.size) + '). Silakan pilih berkas yang lebih kecil.';
                    this.clearAll();
                    return;
                }
                this.existingFiles = [];
                this.stagedFiles = [file];
                this.syncInputFiles();
                return;
            }

            for (const file of incoming) {
                if (this.totalFilesCount >= this.maxFiles) {
                    this.clientError = 'Batas maksimal ' + this.maxFiles + ' berkas telah tercapai.';
                    break;
                }
                if (file.size > this.maxBytes) {
                    this.clientError = 'Berkas "' + file.name + '" melebihi batas ' + this.maxSizeMb + ' MB (' + this.formatBytes(file.size) + '). Berkas tersebut dilewati.';
                    continue;
                }
                const duplicate = this.stagedFiles.some(f => f.name === file.name && f.size === file.size);
                if (duplicate) {
                    continue;
                }
                this.stagedFiles.push(file);
            }
            this.syncInputFiles();
        },

        handleDrop(event) {
            this.isDragging = false;
            if (!this.canAddMore && this.isMultiple) return;
            const files = event.dataTransfer ? event.dataTransfer.files : null;
            if (files && files.length > 0) {
                this.handleFiles(files);
            }
        },

        removeStagedFile(index) {
            this.clientError = '';
            if (index >= 0 && index < this.stagedFiles.length) {
                this.stagedFiles.splice(index, 1);
                this.syncInputFiles();
            }
        },

        removeExistingFile(index) {
            this.clientError = '';
            if (index >= 0 && index < this.existingFiles.length) {
                this.existingFiles.splice(index, 1);
            }
        },

        clearAll() {
            this.stagedFiles = [];
            this.existingFiles = [];
            this.clientError = '';
            const input = this.$refs.fileInput;
            if (input) {
                input.value = '';
            }
        },

        // Legacy clearFile alias
        clearFile() {
            this.clearAll();
        },

        init() {
            if (this.$refs.fileInput) {
                this.$refs.fileInput.addEventListener('change', (e) => {
                    if (this._syncing) return;
                    if (!e.target.files || e.target.files.length === 0) {
                        if (!this.isMultiple && this.existingFiles.length === 0) {
                            this.stagedFiles = [];
                        }
                    } else {
                        this.handleFiles(e.target.files);
                    }
                });
            }
        }
    };
}

if (typeof window !== 'undefined') {
    window.adasiFileUploadComponent = adasiFileUploadComponent;
}

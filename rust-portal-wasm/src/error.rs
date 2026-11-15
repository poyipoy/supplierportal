use thiserror::Error;

#[derive(Debug, Clone, PartialEq, Eq, Error)]
pub enum PortalError {
    #[error("empty input buffer")]
    EmptyInput,

    #[error("input size ({actual} bytes) exceeds maximum allowed ({max} bytes)")]
    InputTooLarge { actual: usize, max: usize },

    #[error("invalid binary header: not a PDF document")]
    InvalidPdfHeader,

    #[error("corrupted or unreadable PDF object structure")]
    PdfParseFailed,

    #[error("password-encrypted PDF documents are not allowed")]
    PdfEncrypted,

    #[error("PDF object count ({count}) exceeds security cap ({max})")]
    PdfTooManyObjects { count: usize, max: usize },

    #[error("failed to serialize compressed PDF")]
    PdfWriteFailed,

    #[error("invalid existing hashes JSON payload")]
    InvalidHashesJson,

    #[error("ZIP container security check failed: {0}")]
    ZipBombDetected(String),

    #[error("corrupted or unsupported Excel workbook: {0}")]
    InvalidExcelWorkbook(String),

    #[error("Excel workbook contains no worksheets")]
    WorksheetMissing,

    #[error("failed to read primary Excel worksheet")]
    WorksheetReadFailed,

    #[error("Excel row count ({rows}) exceeds maximum allowed ({max})")]
    ExcelTooManyRows { rows: usize, max: usize },

    #[error("row {row}: {message}")]
    InvalidRowStructure { row: usize, message: String },

    #[error("row {row} ({column}): {detail}")]
    InvalidNumericCell {
        row: usize,
        column: &'static str,
        detail: String,
    },

    #[error("failed to serialize delta sync JSON")]
    SerializationFailed,
}

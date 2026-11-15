#![forbid(unsafe_code)]
#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

pub mod error;
pub mod excel;
pub mod hash;
pub mod pdf;

pub use error::PortalError;
pub use excel::{
    inspect_zip_container, normalize_decimal_cell, parse_and_filter_erp_excel_core,
    sanitize_spreadsheet_cell, ErpRow, SpreadsheetCell,
};
pub use hash::{build_canonical_payload, compute_canonical_xxh3_hex};
pub use pdf::sanitize_and_compress_pdf_core;

use wasm_bindgen::prelude::*;

#[wasm_bindgen]
pub fn compute_canonical_xxh3(
    doc_no: &str,
    item_code: &str,
    qty_norm: &str,
    price_norm: &str,
    status: &str,
) -> String {
    compute_canonical_xxh3_hex(doc_no, item_code, qty_norm, price_norm, status)
}

#[wasm_bindgen]
pub fn sanitize_and_compress_pdf(pdf_bytes: &[u8], max_dim: u32) -> Result<Vec<u8>, JsValue> {
    sanitize_and_compress_pdf_core(pdf_bytes, max_dim)
        .map_err(|err| JsValue::from_str(&err.to_string()))
}

#[wasm_bindgen]
pub fn parse_and_filter_erp_excel(
    excel_bytes: &[u8],
    existing_hashes_json: &str,
) -> Result<String, JsValue> {
    parse_and_filter_erp_excel_core(excel_bytes, existing_hashes_json)
        .map_err(|err| JsValue::from_str(&err.to_string()))
}

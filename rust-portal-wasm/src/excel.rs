use crate::error::PortalError;
use crate::hash::compute_canonical_xxh3_hex;
use quick_xml::events::Event;
use quick_xml::{Reader, XmlVersion};
use serde::{Deserialize, Serialize};
use std::collections::HashSet;
use std::fmt;
use std::io::{self, BufRead, BufReader, Cursor, Read};
use zip::result::ZipError;
use zip::ZipArchive;

pub const MAX_WASM_INPUT_BYTES: usize = 20 * 1024 * 1024;
pub const MAX_ZIP_UNCOMPRESSED_BYTES: u64 = 1024 * 1024 * 1024;
pub const MAX_ZIP_RATIO: u64 = 50;
pub const MAX_ZIP_ENTRIES: usize = 4096;
pub const MAX_EXCEL_ROWS: usize = 50_000;
const REQUIRED_ERP_COLUMNS: usize = 5;
const INFOR_PO_MIN_COLUMNS: usize = 13;
const INFOR_GR_MIN_COLUMNS: usize = 19;
const MAX_CAPTURED_COLUMNS: usize = 20;
const OLE2_XLS_MAGIC: [u8; 8] = [0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1];

#[derive(Debug, Clone, PartialEq)]
pub enum SpreadsheetCell {
    Empty,
    Number(f64),
    Int(i64),
    Text(String),
    Bool(bool),
}

impl fmt::Display for SpreadsheetCell {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Empty => Ok(()),
            Self::Number(n) => {
                if *n == 0.0 {
                    f.write_str("0")
                } else {
                    write!(f, "{n}")
                }
            }
            Self::Int(i) => write!(f, "{i}"),
            Self::Text(s) => f.write_str(s),
            Self::Bool(b) => write!(f, "{b}"),
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ErpRow {
    pub doc_no: String,
    pub item_code: String,
    pub qty_norm: String,
    pub price_norm: String,
    pub status: String,
    pub row_hash: String,
}

#[derive(Debug, Clone)]
struct ParsedRow {
    row_num: usize,
    col_count: usize,
    cells: [SpreadsheetCell; MAX_CAPTURED_COLUMNS],
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum RowLayout {
    Canonical5Col,
    InforPo,
    InforGr,
}

fn empty_captured_cells() -> [SpreadsheetCell; MAX_CAPTURED_COLUMNS] {
    std::array::from_fn(|_| SpreadsheetCell::Empty)
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum CellKind {
    Number,
    Shared,
    InlineStr,
    String,
    Bool,
}

fn is_dangerous_formula_prefix(ch: char) -> bool {
    matches!(ch, '=' | '+' | '-' | '@' | '\t' | '\r')
}

fn is_plain_negative_number(trimmed: &str) -> bool {
    let Some(rest) = trimmed.strip_prefix('-') else {
        return false;
    };
    if !rest.as_bytes().first().is_some_and(u8::is_ascii_digit) {
        return false;
    }
    trimmed.parse::<f64>().is_ok_and(f64::is_finite)
}

/// Neutralize CSV/Excel Formula Injection (CWE-1236) across both untrimmed and trimmed prefixes.
pub fn sanitize_spreadsheet_cell(raw: &str) -> String {
    let trimmed = raw.trim_matches(|c: char| c.is_whitespace() || c == '\0');
    if trimmed.is_empty() {
        return String::new();
    }

    let untrimmed_dangerous = raw.chars().next().is_some_and(is_dangerous_formula_prefix);
    let trimmed_dangerous = trimmed
        .chars()
        .next()
        .is_some_and(is_dangerous_formula_prefix);

    if !untrimmed_dangerous && !trimmed_dangerous {
        return trimmed.to_string();
    }

    if trimmed.starts_with('-')
        && !raw.starts_with(['\t', '\r'])
        && is_plain_negative_number(trimmed)
    {
        return trimmed.to_string();
    }

    format!("'{trimmed}")
}

pub fn normalize_decimal_cell(cell: &SpreadsheetCell, precision: usize) -> Result<String, String> {
    match cell {
        SpreadsheetCell::Number(f) if f.is_finite() && *f >= 0.0 => {
            let clean = if *f == 0.0 { 0.0 } else { *f };
            Ok(format!("{clean:.precision$}"))
        }
        SpreadsheetCell::Int(i) if *i >= 0 => {
            if precision == 0 {
                Ok(i.to_string())
            } else {
                Ok(format!("{i}.{:0>precision$}", 0))
            }
        }
        SpreadsheetCell::Text(s) => {
            let clean_str = s.trim().replace(',', "");
            let val: f64 = clean_str
                .parse()
                .map_err(|_| format!("invalid numeric value: {s}"))?;
            if !val.is_finite() || val < 0.0 {
                return Err(format!("numeric value out of range: {s}"));
            }
            let normalized = if val == 0.0 { 0.0 } else { val };
            Ok(format!("{normalized:.precision$}"))
        }
        _ => Err(String::from("empty or non-numeric cell")),
    }
}

fn max_zip_uncompressed_mb() -> u64 {
    MAX_ZIP_UNCOMPRESSED_BYTES / (1024 * 1024)
}

pub fn inspect_zip_container(bytes: &[u8]) -> Result<(), PortalError> {
    if !bytes.starts_with(b"PK\x03\x04") {
        return Ok(());
    }

    let mut archive = ZipArchive::new(Cursor::new(bytes))
        .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;

    if archive.len() > MAX_ZIP_ENTRIES {
        return Err(PortalError::ZipBombDetected(format!(
            "archive entry count ({}) exceeds limit ({MAX_ZIP_ENTRIES})",
            archive.len()
        )));
    }

    let max_ratio_bytes = (bytes.len() as u64).saturating_mul(MAX_ZIP_RATIO);
    let budget = MAX_ZIP_UNCOMPRESSED_BYTES.min(max_ratio_bytes);

    let mut declared_total: u64 = 0;
    for index in 0..archive.len() {
        let entry = archive
            .by_index_raw(index)
            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;

        if entry.compressed_size() == 0 && entry.size() > 0 {
            return Err(PortalError::ZipBombDetected(String::from(
                "forged zero compressed entry size",
            )));
        }

        declared_total = declared_total
            .checked_add(entry.size())
            .ok_or_else(|| PortalError::ZipBombDetected(String::from("entry size overflow")))?;

        if declared_total > MAX_ZIP_UNCOMPRESSED_BYTES {
            return Err(PortalError::ZipBombDetected(format!(
                "declared uncompressed size exceeds {} MB limit",
                max_zip_uncompressed_mb()
            )));
        }
        if declared_total > max_ratio_bytes {
            return Err(PortalError::ZipBombDetected(format!(
                "declared compression ratio exceeds {MAX_ZIP_RATIO}x limit"
            )));
        }
    }

    let mut actual_total: u64 = 0;
    for index in 0..archive.len() {
        let entry = archive
            .by_index(index)
            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;
        let remaining = budget.saturating_sub(actual_total);
        let mut bounded = entry.take(remaining.saturating_add(1));
        let copied = io::copy(&mut bounded, &mut io::sink())
            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;

        actual_total = actual_total.saturating_add(copied);
        if actual_total > MAX_ZIP_UNCOMPRESSED_BYTES {
            return Err(PortalError::ZipBombDetected(format!(
                "actual uncompressed payload exceeds {} MB limit",
                max_zip_uncompressed_mb()
            )));
        }
        if actual_total > max_ratio_bytes {
            return Err(PortalError::ZipBombDetected(format!(
                "actual compression ratio exceeds {MAX_ZIP_RATIO}x limit"
            )));
        }
    }

    Ok(())
}

fn read_zip_utf8_entry<R: Read + io::Seek>(
    archive: &mut ZipArchive<R>,
    name: &str,
) -> Result<Option<String>, PortalError> {
    let entry = match archive.by_name(name) {
        Ok(file) => file,
        Err(ZipError::FileNotFound) => return Ok(None),
        Err(err) => return Err(PortalError::InvalidExcelWorkbook(err.to_string())),
    };

    let mut raw = Vec::new();
    let mut bounded = entry.take(MAX_ZIP_UNCOMPRESSED_BYTES.saturating_add(1));
    bounded
        .read_to_end(&mut raw)
        .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;

    if (raw.len() as u64) > MAX_ZIP_UNCOMPRESSED_BYTES {
        return Err(PortalError::ZipBombDetected(format!(
            "XML entry exceeds {} MB uncompressed limit",
            max_zip_uncompressed_mb()
        )));
    }

    let text = String::from_utf8(raw)
        .map_err(|_| PortalError::InvalidExcelWorkbook(String::from("XML entry is not UTF-8")))?;
    Ok(Some(text))
}

fn append_xml_text_event(out: &mut String, event: &Event<'_>) -> Result<(), PortalError> {
    match event {
        Event::Text(e) => {
            out.push_str(&e.xml10_content());
        }
        Event::CData(e) => {
            out.push_str(&e.xml10_content());
        }
        Event::GeneralRef(r) => {
            if let Some(ch) = r
                .resolve_char_ref()
                .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?
            {
                out.push(ch);
            } else {
                match &**r {
                    "amp" => out.push('&'),
                    "lt" => out.push('<'),
                    "gt" => out.push('>'),
                    "quot" => out.push('"'),
                    "apos" => out.push('\''),
                    other => {
                        return Err(PortalError::InvalidExcelWorkbook(format!(
                            "unrecognized XML entity: &{other};"
                        )));
                    }
                }
            }
        }
        _ => {}
    }
    Ok(())
}

fn find_first_sheet_rel_id(workbook_xml: &str) -> Result<Option<String>, PortalError> {
    let mut reader = Reader::from_str(workbook_xml);
    let mut saw_sheet_container = false;

    loop {
        match reader.read_event() {
            Ok(Event::Start(e)) | Ok(Event::Empty(e)) => {
                let local = e.local_name();
                if local.as_ref() == "sheets" {
                    saw_sheet_container = true;
                } else if local.as_ref() == "sheet" {
                    for attr_res in e.attributes() {
                        let attr = attr_res
                            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;
                        if attr.key.local_name().as_ref() == "id" {
                            let val =
                                attr.normalized_value(XmlVersion::Implicit1_0)
                                    .map_err(|err| {
                                        PortalError::InvalidExcelWorkbook(err.to_string())
                                    })?;
                            return Ok(Some(val.into_owned()));
                        }
                    }
                    return Ok(None);
                }
            }
            Ok(Event::Eof) => break,
            Err(err) => return Err(PortalError::InvalidExcelWorkbook(err.to_string())),
            _ => {}
        }
    }

    if saw_sheet_container {
        return Err(PortalError::WorksheetMissing);
    }
    Ok(None)
}

fn normalize_ooxml_target(raw_target: &str) -> Result<String, PortalError> {
    let trimmed = raw_target.trim().trim_start_matches('/');
    if trimmed.is_empty() || trimmed.contains('\\') || trimmed.split('/').any(|seg| seg == "..") {
        return Err(PortalError::InvalidExcelWorkbook(String::from(
            "unsafe worksheet relationship target",
        )));
    }
    if trimmed.starts_with("xl/") {
        Ok(trimmed.to_string())
    } else {
        Ok(format!("xl/{trimmed}"))
    }
}

fn resolve_rel_target(rels_xml: &str, rel_id: &str) -> Result<Option<String>, PortalError> {
    let mut reader = Reader::from_str(rels_xml);
    loop {
        match reader.read_event() {
            Ok(Event::Start(e)) | Ok(Event::Empty(e)) => {
                if e.local_name().as_ref() == "Relationship" {
                    let mut id_match = false;
                    let mut target: Option<String> = None;
                    for attr_res in e.attributes() {
                        let attr = attr_res
                            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;
                        match attr.key.local_name().as_ref() {
                            "Id" => {
                                let v = attr.normalized_value(XmlVersion::Implicit1_0).map_err(
                                    |err| PortalError::InvalidExcelWorkbook(err.to_string()),
                                )?;
                                id_match = v == rel_id;
                            }
                            "Target" => {
                                let v = attr.normalized_value(XmlVersion::Implicit1_0).map_err(
                                    |err| PortalError::InvalidExcelWorkbook(err.to_string()),
                                )?;
                                target = Some(v.into_owned());
                            }
                            _ => {}
                        }
                    }
                    if id_match {
                        if let Some(t) = target {
                            return Ok(Some(normalize_ooxml_target(&t)?));
                        }
                    }
                }
            }
            Ok(Event::Eof) => break,
            Err(err) => return Err(PortalError::InvalidExcelWorkbook(err.to_string())),
            _ => {}
        }
    }
    Ok(None)
}

fn resolve_first_worksheet_path<R: Read + io::Seek>(
    archive: &mut ZipArchive<R>,
) -> Result<String, PortalError> {
    if let Some(workbook_xml) = read_zip_utf8_entry(archive, "xl/workbook.xml")? {
        if let Some(rel_id) = find_first_sheet_rel_id(&workbook_xml)? {
            if let Some(rels_xml) = read_zip_utf8_entry(archive, "xl/_rels/workbook.xml.rels")? {
                if let Some(path) = resolve_rel_target(&rels_xml, &rel_id)? {
                    return Ok(path);
                }
            }
        }
    }

    if archive.by_name("xl/worksheets/sheet1.xml").is_ok() {
        return Ok(String::from("xl/worksheets/sheet1.xml"));
    }

    Err(PortalError::WorksheetMissing)
}

fn parse_shared_strings<R: BufRead>(reader_input: R) -> Result<Vec<String>, PortalError> {
    let mut reader = Reader::from_reader(reader_input);
    let mut buf = Vec::with_capacity(4096);
    let mut strings = Vec::new();
    let mut in_si = false;
    let mut in_t = false;
    let mut phonetic_depth: usize = 0;
    let mut current = String::new();

    loop {
        buf.clear();
        let ev = reader
            .read_event_into(&mut buf)
            .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;
        if reader.buffer_position() > MAX_ZIP_UNCOMPRESSED_BYTES {
            return Err(PortalError::ZipBombDetected(format!(
                "XML entry exceeds {} MB uncompressed limit",
                max_zip_uncompressed_mb()
            )));
        }
        match ev {
            Event::Start(ref e) => match e.local_name().as_ref() {
                "si" => {
                    in_si = true;
                    in_t = false;
                    phonetic_depth = 0;
                    current.clear();
                }
                "rPh" if in_si => phonetic_depth = phonetic_depth.saturating_add(1),
                "t" if in_si && phonetic_depth == 0 => in_t = true,
                _ => {}
            },
            Event::Empty(ref e) => {
                if e.local_name().as_ref() == "si" {
                    strings.push(String::new());
                }
            }
            Event::Text(_) | Event::CData(_) | Event::GeneralRef(_) => {
                if in_si && in_t && phonetic_depth == 0 {
                    append_xml_text_event(&mut current, &ev)?;
                }
            }
            Event::End(ref e) => match e.local_name().as_ref() {
                "t" => in_t = false,
                "rPh" => phonetic_depth = phonetic_depth.saturating_sub(1),
                "si" if in_si => {
                    strings.push(std::mem::take(&mut current));
                    in_si = false;
                    in_t = false;
                    phonetic_depth = 0;
                }
                _ => {}
            },
            Event::Eof => break,
            _ => {}
        }
    }

    Ok(strings)
}

fn col_index_from_cell_ref(cell_ref: &str) -> Option<usize> {
    let mut col: usize = 0;
    let mut letters = 0usize;
    for byte in cell_ref.bytes() {
        if byte.is_ascii_alphabetic() {
            letters += 1;
            if letters > 3 {
                return None;
            }
            let digit = usize::from(byte.to_ascii_uppercase() - b'A') + 1;
            col = col.checked_mul(26)?.checked_add(digit)?;
        } else {
            break;
        }
    }
    if letters == 0 {
        None
    } else {
        col.checked_sub(1)
    }
}

fn cell_kind_from_attr(raw: &str) -> CellKind {
    match raw {
        "s" => CellKind::Shared,
        "inlineStr" => CellKind::InlineStr,
        "str" | "e" => CellKind::String,
        "b" => CellKind::Bool,
        _ => CellKind::Number,
    }
}

fn build_xlsx_cell(
    kind: CellKind,
    raw_value: String,
    shared_strings: &[String],
) -> Result<SpreadsheetCell, PortalError> {
    match kind {
        CellKind::Shared => {
            if raw_value.is_empty() {
                return Ok(SpreadsheetCell::Empty);
            }
            let idx: usize = raw_value.trim().parse().map_err(|_| {
                PortalError::InvalidExcelWorkbook(format!(
                    "invalid shared string index: {raw_value}"
                ))
            })?;
            let resolved = shared_strings.get(idx).ok_or_else(|| {
                PortalError::InvalidExcelWorkbook(format!(
                    "shared string index out of bounds: {idx}"
                ))
            })?;
            Ok(SpreadsheetCell::Text(resolved.clone()))
        }
        CellKind::InlineStr | CellKind::String => Ok(SpreadsheetCell::Text(raw_value)),
        CellKind::Bool => Ok(SpreadsheetCell::Bool(
            raw_value == "1" || raw_value.eq_ignore_ascii_case("true"),
        )),
        CellKind::Number => {
            let trimmed = raw_value.trim();
            if trimmed.is_empty() {
                return Ok(SpreadsheetCell::Empty);
            }
            if !trimmed.contains(['.', 'e', 'E']) {
                if let Ok(int_val) = trimmed.parse::<i64>() {
                    return Ok(SpreadsheetCell::Int(int_val));
                }
            }
            if let Ok(float_val) = trimmed.parse::<f64>() {
                return Ok(SpreadsheetCell::Number(float_val));
            }
            Ok(SpreadsheetCell::Text(raw_value))
        }
    }
}

fn parse_worksheet_rows<R: BufRead>(
    reader_input: R,
    shared_strings: &[String],
) -> Result<Vec<ParsedRow>, PortalError> {
    let mut reader = Reader::from_reader(reader_input);
    let mut buf = Vec::with_capacity(8192);
    let mut rows = Vec::new();
    let mut in_row = false;
    let mut in_cell = false;
    let mut in_v = false;
    let mut in_is = false;
    let mut in_t = false;
    let mut current_row_num: usize = 0;
    let mut next_col: usize = 0;
    let mut row_width: usize = 0;
    let mut cur_col: usize = 0;
    let mut cur_kind = CellKind::Number;
    let mut val_buf = String::new();
    let mut cells = empty_captured_cells();

    loop {
        buf.clear();
        let ev = reader
            .read_event_into(&mut buf)
            .map_err(|_| PortalError::WorksheetReadFailed)?;
        if reader.buffer_position() > MAX_ZIP_UNCOMPRESSED_BYTES {
            return Err(PortalError::ZipBombDetected(format!(
                "XML entry exceeds {} MB uncompressed limit",
                max_zip_uncompressed_mb()
            )));
        }
        match ev {
            Event::Start(ref e) | Event::Empty(ref e) => {
                let is_empty_tag = matches!(ev, Event::Empty(_));
                match e.local_name().as_ref() {
                    "row" => {
                        current_row_num = rows.len() + 1;
                        for attr_res in e.attributes() {
                            let attr = attr_res.map_err(|_| PortalError::WorksheetReadFailed)?;
                            if attr.key.local_name().as_ref() == "r" {
                                if let Ok(v) = attr.normalized_value(XmlVersion::Implicit1_0) {
                                    if let Ok(parsed_num) = v.trim().parse::<usize>() {
                                        current_row_num = parsed_num;
                                    }
                                }
                            }
                        }
                        if is_empty_tag {
                            continue;
                        }
                        in_row = true;
                        next_col = 0;
                        row_width = 0;
                        cells = empty_captured_cells();
                    }
                    "c" if in_row => {
                        let mut parsed_col = None;
                        cur_kind = CellKind::Number;
                        for attr_res in e.attributes() {
                            let attr = attr_res.map_err(|_| PortalError::WorksheetReadFailed)?;
                            match attr.key.local_name().as_ref() {
                                "r" => {
                                    if let Ok(v) = attr.normalized_value(XmlVersion::Implicit1_0) {
                                        parsed_col = col_index_from_cell_ref(&v);
                                    }
                                }
                                "t" => {
                                    if let Ok(v) = attr.normalized_value(XmlVersion::Implicit1_0) {
                                        cur_kind = cell_kind_from_attr(&v);
                                    }
                                }
                                _ => {}
                            }
                        }
                        cur_col = parsed_col.unwrap_or(next_col);
                        next_col = cur_col.saturating_add(1);
                        row_width = row_width.max(next_col);
                        if !is_empty_tag {
                            in_cell = true;
                            in_v = false;
                            in_is = false;
                            in_t = false;
                            val_buf.clear();
                        }
                    }
                    "v" if in_cell && !is_empty_tag => in_v = true,
                    "is" if in_cell && !is_empty_tag => in_is = true,
                    "t" if in_cell && in_is && !is_empty_tag => in_t = true,
                    _ => {}
                }
            }
            Event::Text(_) | Event::CData(_) | Event::GeneralRef(_) => {
                if in_cell && (in_v || (in_is && in_t)) {
                    append_xml_text_event(&mut val_buf, &ev)?;
                }
            }
            Event::End(ref e) => match e.local_name().as_ref() {
                "v" => in_v = false,
                "is" => {
                    in_is = false;
                    in_t = false;
                }
                "t" => in_t = false,
                "c" if in_cell => {
                    if cur_col < MAX_CAPTURED_COLUMNS {
                        cells[cur_col] = build_xlsx_cell(
                            cur_kind,
                            std::mem::take(&mut val_buf),
                            shared_strings,
                        )?;
                    } else {
                        val_buf.clear();
                    }
                    in_cell = false;
                    in_v = false;
                    in_is = false;
                    in_t = false;
                }
                "row" if in_row => {
                    in_row = false;
                    let has_non_empty = cells.iter().any(|c| !matches!(c, SpreadsheetCell::Empty));
                    if rows.is_empty() || has_non_empty {
                        if rows.len() >= MAX_EXCEL_ROWS {
                            return Err(PortalError::ExcelTooManyRows {
                                rows: rows.len() + 1,
                                max: MAX_EXCEL_ROWS,
                            });
                        }
                        rows.push(ParsedRow {
                            row_num: current_row_num,
                            col_count: row_width,
                            cells: std::mem::replace(&mut cells, empty_captured_cells()),
                        });
                    }
                }
                _ => {}
            },
            Event::Eof => break,
            _ => {}
        }
    }

    Ok(rows)
}

fn parse_xlsx_rows(excel_bytes: &[u8]) -> Result<Vec<ParsedRow>, PortalError> {
    let mut archive = ZipArchive::new(Cursor::new(excel_bytes))
        .map_err(|err| PortalError::InvalidExcelWorkbook(err.to_string()))?;

    let worksheet_path = resolve_first_worksheet_path(&mut archive)?;
    let shared_strings = match archive.by_name("xl/sharedStrings.xml") {
        Ok(entry) => {
            let bounded = entry.take(MAX_ZIP_UNCOMPRESSED_BYTES.saturating_add(1));
            parse_shared_strings(BufReader::with_capacity(64 * 1024, bounded))?
        }
        Err(ZipError::FileNotFound) => Vec::new(),
        Err(err) => return Err(PortalError::InvalidExcelWorkbook(err.to_string())),
    };

    let sheet_entry = match archive.by_name(&worksheet_path) {
        Ok(entry) => entry,
        Err(ZipError::FileNotFound) => return Err(PortalError::WorksheetMissing),
        Err(err) => return Err(PortalError::InvalidExcelWorkbook(err.to_string())),
    };
    let bounded_sheet = sheet_entry.take(MAX_ZIP_UNCOMPRESSED_BYTES.saturating_add(1));
    parse_worksheet_rows(
        BufReader::with_capacity(64 * 1024, bounded_sheet),
        &shared_strings,
    )
}

fn push_csv_row(rows: &mut Vec<ParsedRow>, fields: Vec<String>) -> Result<(), PortalError> {
    if rows.is_empty() || fields.iter().any(|f| !f.trim().is_empty()) {
        if rows.len() >= MAX_EXCEL_ROWS {
            return Err(PortalError::ExcelTooManyRows {
                rows: rows.len() + 1,
                max: MAX_EXCEL_ROWS,
            });
        }
        let col_count = fields.len();
        let mut cells = empty_captured_cells();
        for (idx, val) in fields.into_iter().take(MAX_CAPTURED_COLUMNS).enumerate() {
            cells[idx] = if val.is_empty() {
                SpreadsheetCell::Empty
            } else {
                SpreadsheetCell::Text(val)
            };
        }
        rows.push(ParsedRow {
            row_num: rows.len() + 1,
            col_count,
            cells,
        });
    }
    Ok(())
}

fn parse_csv_rows(csv_bytes: &[u8]) -> Result<Vec<ParsedRow>, PortalError> {
    if csv_bytes.starts_with(&OLE2_XLS_MAGIC) {
        return Err(PortalError::InvalidExcelWorkbook(String::from(
            "legacy .xls format is not supported; please upload .xlsx or .csv",
        )));
    }

    let raw_text = std::str::from_utf8(csv_bytes).map_err(|_| {
        PortalError::InvalidExcelWorkbook(String::from(
            "invalid spreadsheet binary or non-UTF-8 CSV input",
        ))
    })?;
    let text = raw_text.strip_prefix('\u{FEFF}').unwrap_or(raw_text);

    let mut rows = Vec::new();
    let mut fields = Vec::new();
    let mut field = String::new();
    let mut in_quotes = false;
    let mut chars = text.chars().peekable();

    while let Some(ch) = chars.next() {
        if in_quotes {
            if ch == '"' {
                if chars.peek() == Some(&'"') {
                    let _ = chars.next();
                    field.push('"');
                } else {
                    in_quotes = false;
                }
            } else {
                field.push(ch);
            }
            continue;
        }

        match ch {
            '"' if field.is_empty() => in_quotes = true,
            ',' => fields.push(std::mem::take(&mut field)),
            '\r' => {
                if chars.peek() == Some(&'\n') {
                    let _ = chars.next();
                }
                fields.push(std::mem::take(&mut field));
                push_csv_row(&mut rows, std::mem::take(&mut fields))?;
            }
            '\n' => {
                fields.push(std::mem::take(&mut field));
                push_csv_row(&mut rows, std::mem::take(&mut fields))?;
            }
            other => field.push(other),
        }
    }

    if in_quotes {
        return Err(PortalError::InvalidExcelWorkbook(String::from(
            "unterminated quoted field in CSV input",
        )));
    }

    if !field.is_empty() || !fields.is_empty() {
        fields.push(field);
        push_csv_row(&mut rows, fields)?;
    }

    if rows.is_empty() {
        return Err(PortalError::WorksheetMissing);
    }

    Ok(rows)
}

fn normalized_header_cell(row: &ParsedRow, idx: usize) -> String {
    row.cells
        .get(idx)
        .map(|cell| cell.to_string().trim().to_ascii_lowercase())
        .unwrap_or_default()
}

fn detect_row_layout(header: Option<&ParsedRow>) -> RowLayout {
    let Some(row) = header else {
        return RowLayout::Canonical5Col;
    };

    if row.col_count >= INFOR_PO_MIN_COLUMNS
        && normalized_header_cell(row, 4) == "order"
        && normalized_header_cell(row, 10) == "order amount"
        && normalized_header_cell(row, 12) == "status"
    {
        return RowLayout::InforPo;
    }

    if row.col_count >= INFOR_GR_MIN_COLUMNS
        && normalized_header_cell(row, 9) == "receipt"
        && normalized_header_cell(row, 11) == "order line"
        && normalized_header_cell(row, 15) == "received quantity"
        && normalized_header_cell(row, 18) == "line status"
    {
        return RowLayout::InforGr;
    }

    RowLayout::Canonical5Col
}

fn cap_chars(value: String, max_chars: usize) -> String {
    if value.chars().count() <= max_chars {
        return value;
    }
    value.chars().take(max_chars).collect()
}

fn parse_decimal_column(
    cell: &SpreadsheetCell,
    precision: usize,
    row: usize,
    column: &'static str,
) -> Result<String, PortalError> {
    normalize_decimal_cell(cell, precision).map_err(|detail| PortalError::InvalidNumericCell {
        row,
        column,
        detail,
    })
}

fn extract_fallback_status(cell: &SpreadsheetCell, default: &str) -> String {
    let raw = sanitize_spreadsheet_cell(&cell.to_string());
    if raw.is_empty() {
        String::from(default)
    } else {
        cap_chars(raw, 64)
    }
}

fn non_empty_doc_and_item(
    row: usize,
    doc_no: String,
    item_code: String,
) -> Result<(String, String), PortalError> {
    if doc_no.is_empty() || item_code.is_empty() {
        return Err(PortalError::InvalidRowStructure {
            row,
            message: String::from("doc_no or item_code is empty"),
        });
    }
    Ok((doc_no, item_code))
}

fn extract_canonical_row(
    parsed: &ParsedRow,
) -> Result<(String, String, String, String, String), PortalError> {
    if parsed.col_count < REQUIRED_ERP_COLUMNS {
        return Err(PortalError::InvalidRowStructure {
            row: parsed.row_num,
            message: String::from("fewer than 5 required columns"),
        });
    }
    let (doc_no, item_code) = non_empty_doc_and_item(
        parsed.row_num,
        cap_chars(sanitize_spreadsheet_cell(&parsed.cells[0].to_string()), 100),
        cap_chars(sanitize_spreadsheet_cell(&parsed.cells[1].to_string()), 100),
    )?;
    let qty_norm = parse_decimal_column(&parsed.cells[2], 4, parsed.row_num, "Qty")?;
    let price_norm = parse_decimal_column(&parsed.cells[3], 2, parsed.row_num, "Price")?;
    let status = cap_chars(sanitize_spreadsheet_cell(&parsed.cells[4].to_string()), 64);
    Ok((doc_no, item_code, qty_norm, price_norm, status))
}

fn extract_infor_po_row(
    parsed: &ParsedRow,
) -> Result<(String, String, String, String, String), PortalError> {
    if parsed.col_count < INFOR_PO_MIN_COLUMNS {
        return Err(PortalError::InvalidRowStructure {
            row: parsed.row_num,
            message: String::from("fewer than 13 Infor PO columns"),
        });
    }
    let supplier_name = sanitize_spreadsheet_cell(&parsed.cells[6].to_string());
    let bp_code = sanitize_spreadsheet_cell(&parsed.cells[5].to_string());
    let raw_item = [supplier_name, bp_code]
        .into_iter()
        .find(|s| !s.is_empty())
        .unwrap_or_else(|| String::from("PO-HEADER"));
    let (doc_no, item_code) = non_empty_doc_and_item(
        parsed.row_num,
        cap_chars(sanitize_spreadsheet_cell(&parsed.cells[4].to_string()), 100),
        cap_chars(raw_item, 100),
    )?;
    let price_norm = parse_decimal_column(&parsed.cells[10], 2, parsed.row_num, "Price")?;
    let status = extract_fallback_status(&parsed.cells[12], "OPEN");
    Ok((
        doc_no,
        item_code,
        String::from("1.0000"),
        price_norm,
        status,
    ))
}

fn extract_infor_gr_row(
    parsed: &ParsedRow,
) -> Result<(String, String, String, String, String), PortalError> {
    if parsed.col_count < INFOR_GR_MIN_COLUMNS {
        return Err(PortalError::InvalidRowStructure {
            row: parsed.row_num,
            message: String::from("fewer than 19 Infor GR columns"),
        });
    }
    let receipt = sanitize_spreadsheet_cell(&parsed.cells[9].to_string());
    let item = sanitize_spreadsheet_cell(&parsed.cells[7].to_string());
    let combined_item = match (receipt.is_empty(), item.is_empty()) {
        (false, false) => format!("{receipt}|{item}"),
        (false, true) => receipt,
        (true, false) => item,
        (true, true) => String::new(),
    };
    let (doc_no, item_code) = non_empty_doc_and_item(
        parsed.row_num,
        cap_chars(
            sanitize_spreadsheet_cell(&parsed.cells[11].to_string()),
            100,
        ),
        cap_chars(sanitize_spreadsheet_cell(&combined_item), 100),
    )?;
    let qty_norm = parse_decimal_column(&parsed.cells[15], 4, parsed.row_num, "Qty")?;
    let status = extract_fallback_status(&parsed.cells[18], "CONFIRMED");
    Ok((doc_no, item_code, qty_norm, String::from("0.00"), status))
}

fn extract_erp_row_fields(
    parsed: &ParsedRow,
    layout: RowLayout,
) -> Result<(String, String, String, String, String), PortalError> {
    match layout {
        RowLayout::Canonical5Col => extract_canonical_row(parsed),
        RowLayout::InforPo => extract_infor_po_row(parsed),
        RowLayout::InforGr => extract_infor_gr_row(parsed),
    }
}

pub fn parse_and_filter_erp_excel_core(
    excel_bytes: &[u8],
    existing_hashes_json: &str,
) -> Result<String, PortalError> {
    if excel_bytes.is_empty() {
        return Err(PortalError::EmptyInput);
    }
    if excel_bytes.len() > MAX_WASM_INPUT_BYTES {
        return Err(PortalError::InputTooLarge {
            actual: excel_bytes.len(),
            max: MAX_WASM_INPUT_BYTES,
        });
    }

    let known_hashes: HashSet<String> =
        serde_json::from_str(existing_hashes_json).map_err(|_| PortalError::InvalidHashesJson)?;

    inspect_zip_container(excel_bytes)?;

    let parsed_rows = if excel_bytes.starts_with(b"PK\x03\x04") {
        parse_xlsx_rows(excel_bytes)?
    } else {
        parse_csv_rows(excel_bytes)?
    };

    let layout = detect_row_layout(parsed_rows.first());
    let mut delta_rows = Vec::new();

    for parsed in parsed_rows.into_iter().skip(1) {
        let (doc_no, item_code, qty_norm, price_norm, status) =
            extract_erp_row_fields(&parsed, layout)?;

        let row_hash =
            compute_canonical_xxh3_hex(&doc_no, &item_code, &qty_norm, &price_norm, &status);

        if !known_hashes.contains(&row_hash) {
            delta_rows.push(ErpRow {
                doc_no,
                item_code,
                qty_norm,
                price_norm,
                status,
                row_hash,
            });
        }
    }

    serde_json::to_string(&delta_rows).map_err(|_| PortalError::SerializationFailed)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::hash::compute_canonical_xxh3_hex;
    use proptest::prelude::*;
    use rstest::rstest;
    use std::io::{Cursor, Write};
    use zip::write::SimpleFileOptions;
    use zip::{CompressionMethod, ZipWriter};

    fn xml_escape(input: &str) -> String {
        input
            .replace('&', "&amp;")
            .replace('<', "&lt;")
            .replace('>', "&gt;")
            .replace('"', "&quot;")
            .replace('\'', "&apos;")
    }

    fn build_minimal_xlsx(
        rows: &[Vec<(&str, bool)>],
    ) -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let mut buffer = Vec::new();
        {
            let mut zip = ZipWriter::new(Cursor::new(&mut buffer));
            let opts = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);

            zip.start_file("[Content_Types].xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>"#,
            )?;

            zip.start_file("_rels/.rels", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>"#,
            )?;

            zip.start_file("xl/workbook.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>"#,
            )?;

            zip.start_file("xl/_rels/workbook.xml.rels", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>"#,
            )?;

            let mut sheet_xml = String::from(
                r#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>"#,
            );
            for (r_idx, row) in rows.iter().enumerate() {
                let r_num = r_idx + 1;
                sheet_xml.push_str(&format!("<row r=\"{r_num}\">"));
                for (c_idx, (val, is_num)) in row.iter().enumerate() {
                    let col = (b'A' + (c_idx as u8)) as char;
                    if *is_num {
                        sheet_xml.push_str(&format!(
                            "<c r=\"{col}{r_num}\"><v>{}</v></c>",
                            xml_escape(val)
                        ));
                    } else {
                        sheet_xml.push_str(&format!(
                            "<c r=\"{col}{r_num}\" t=\"inlineStr\"><is><t>{}</t></is></c>",
                            xml_escape(val)
                        ));
                    }
                }
                sheet_xml.push_str("</row>");
            }
            sheet_xml.push_str("</sheetData></worksheet>");

            zip.start_file("xl/worksheets/sheet1.xml", opts)?;
            zip.write_all(sheet_xml.as_bytes())?;
            zip.finish()?;
        }
        Ok(buffer)
    }

    fn build_shared_strings_xlsx() -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let mut buffer = Vec::new();
        {
            let mut zip = ZipWriter::new(Cursor::new(&mut buffer));
            let opts = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);

            zip.start_file("xl/workbook.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Data" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>"#,
            )?;

            zip.start_file("xl/_rels/workbook.xml.rels", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet2.xml"/>
</Relationships>"#,
            )?;

            zip.start_file("xl/sharedStrings.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">
  <si><t>PO-A&amp;B</t><rPh sb="0" eb="2"><t>PHONETIC</t></rPh></si>
  <si><r><t>MAT</t></r><r><t xml:space="preserve">-01</t></r></si>
  <si><t>OPEN</t></si>
  <si><t> =PO-INJ</t></si>
  <si><t>MAT&#x27;99</t></si>
</sst>"#,
            )?;

            zip.start_file("xl/worksheets/sheet2.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>doc_no</t></is></c>
      <c r="B1" t="inlineStr"><is><t>item_code</t></is></c>
      <c r="C1" t="inlineStr"><is><t>qty</t></is></c>
      <c r="D1" t="inlineStr"><is><t>price</t></is></c>
      <c r="E1" t="inlineStr"><is><t>status</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="s"><v>0</v></c>
      <c r="B2" t="s"><v>1</v></c>
      <c r="C2"><f>5+5</f><v>10.25</v></c>
      <c r="D2"><v>200.5</v></c>
      <c r="E2" t="s"><v>2</v></c>
      <c r="AA2" t="inlineStr"><is><t>ignored-far-col</t></is></c>
    </row>
    <row r="3">
      <c r="A3" t="s"><v>3</v></c>
      <c r="B3" t="s"><v>4</v></c>
      <c r="C3"><v>3</v></c>
      <c r="D3"><v>4.5</v></c>
      <c r="E3" t="str"><v>ACTIVE</v></c>
    </row>
  </sheetData>
</worksheet>"#,
            )?;
            zip.finish()?;
        }
        Ok(buffer)
    }

    fn build_zip_bomb(uncompressed_size: usize) -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let mut buffer = Vec::new();
        {
            let mut zip = ZipWriter::new(Cursor::new(&mut buffer));
            let opts = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);
            zip.start_file("bomb.xml", opts)?;
            let chunk = vec![b'A'; 65_536];
            let mut remaining = uncompressed_size;
            while remaining > 0 {
                let n = remaining.min(chunk.len());
                zip.write_all(&chunk[..n])?;
                remaining -= n;
            }
            zip.finish()?;
        }
        Ok(buffer)
    }

    #[rstest]
    #[case("=1+1", "'=1+1")]
    #[case(" =1+1", "'=1+1")]
    #[case("\t=1+1", "'=1+1")]
    #[case("\r=1+1", "'=1+1")]
    #[case(" \t\r=CMD|' /C calc'!A0", "'=CMD|' /C calc'!A0")]
    #[case("\x00=1+1", "'=1+1")]
    #[case("+SUM(A1:A2)", "'+SUM(A1:A2)")]
    #[case(" +42", "'+42")]
    #[case("@SUM(A1)", "'@SUM(A1)")]
    #[case(" @IMPORT", "'@IMPORT")]
    #[case("-CMD|calc", "'-CMD|calc")]
    #[case("-1+1", "'-1+1")]
    #[case("-inf", "'-inf")]
    #[case("-NaN", "'-NaN")]
    #[case("-+1", "'-+1")]
    #[case("\t-42", "'-42")]
    #[case("\tABC", "'ABC")]
    #[case("\rABC", "'ABC")]
    #[case("-42.50", "-42.50")]
    #[case(" -0.001 ", "-0.001")]
    #[case("-0", "-0")]
    #[case("  PO-001  ", "PO-001")]
    #[case("   ", "")]
    #[case("'=already", "'=already")]
    fn sanitize_spreadsheet_cell_cases(#[case] raw: &str, #[case] expected: &str) {
        assert_eq!(sanitize_spreadsheet_cell(raw), expected);
    }

    proptest! {
        #[test]
        fn sanitized_cell_never_starts_with_unquoted_formula_trigger(
            prefix in "[ \t\r\n]{0,3}",
            trigger in prop_oneof![Just("="), Just("+"), Just("@"), Just("\t"), Just("\r"), Just("-A")],
            tail in "[A-Za-z0-9()+*/!| ]{0,20}",
        ) {
            let raw = format!("{prefix}{trigger}{tail}");
            let out = sanitize_spreadsheet_cell(&raw);
            if !out.is_empty() {
                let first = out.chars().next();
                prop_assert!(
                    !matches!(first, Some('=' | '+' | '@' | '\t' | '\r')),
                    "unsafe start in sanitized cell: {out:?} from {raw:?}"
                );
            }
        }
    }

    #[test]
    fn normalize_decimal_cell_formats_and_validates() {
        assert_eq!(
            normalize_decimal_cell(&SpreadsheetCell::Number(10.5), 4),
            Ok(String::from("10.5000"))
        );
        assert_eq!(
            normalize_decimal_cell(&SpreadsheetCell::Number(-0.0), 4),
            Ok(String::from("0.0000"))
        );
        assert_eq!(
            normalize_decimal_cell(&SpreadsheetCell::Int(42), 2),
            Ok(String::from("42.00"))
        );
        assert_eq!(
            normalize_decimal_cell(&SpreadsheetCell::Text(String::from(" 1,234.5 ")), 2),
            Ok(String::from("1234.50"))
        );
        assert!(normalize_decimal_cell(&SpreadsheetCell::Number(-1.0), 2).is_err());
        assert!(normalize_decimal_cell(&SpreadsheetCell::Number(f64::NAN), 2).is_err());
        assert!(normalize_decimal_cell(&SpreadsheetCell::Number(f64::INFINITY), 2).is_err());
        assert!(normalize_decimal_cell(&SpreadsheetCell::Empty, 2).is_err());
    }

    #[test]
    fn rejects_high_ratio_zip_bomb_before_parsing() -> Result<(), Box<dyn std::error::Error>> {
        let bomb = build_zip_bomb(512 * 1024)?;
        let res = parse_and_filter_erp_excel_core(&bomb, "[]");
        assert!(matches!(res, Err(PortalError::ZipBombDetected(_))));
        Ok(())
    }

    #[test]
    fn rejects_uncompressed_zip_bomb() -> Result<(), Box<dyn std::error::Error>> {
        let bomb = build_zip_bomb(33 * 1024 * 1024)?;
        let res = inspect_zip_container(&bomb);
        assert!(matches!(res, Err(PortalError::ZipBombDetected(_))));
        Ok(())
    }

    #[test]
    fn parses_xlsx_with_over_32mb_uncompressed_xml_within_ratio_and_row_limits(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let mut buffer = Vec::new();
        {
            let mut zip = ZipWriter::new(Cursor::new(&mut buffer));
            let opts = SimpleFileOptions::default().compression_method(CompressionMethod::Deflated);

            zip.start_file("xl/workbook.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>"#,
            )?;

            zip.start_file("xl/_rels/workbook.xml.rels", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>"#,
            )?;

            zip.start_file("xl/worksheets/sheet1.xml", opts)?;
            zip.write_all(
                br#"<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><!-- "#,
            )?;
            let mut rng: u64 = 0x9E37_79B9_7F4A_7C15;
            let mut noise = vec![b'A'; 2 * 1024 * 1024];
            for byte in &mut noise {
                rng = rng.wrapping_mul(6364136223846793005).wrapping_add(1);
                *byte = b'A' + ((rng >> 56) as u8 % 26);
            }
            zip.write_all(&noise)?;
            let pad = vec![b'A'; 65_536];
            for _ in 0..(35 * 16) {
                zip.write_all(&pad)?;
            }
            zip.write_all(
                br#" --><sheetData>
  <row r="1">
    <c r="A1" t="inlineStr"><is><t>doc_no</t></is></c>
    <c r="B1" t="inlineStr"><is><t>item_code</t></is></c>
    <c r="C1" t="inlineStr"><is><t>qty</t></is></c>
    <c r="D1" t="inlineStr"><is><t>price</t></is></c>
    <c r="E1" t="inlineStr"><is><t>status</t></is></c>
  </row>
  <row r="2">
    <c r="A2" t="inlineStr"><is><t>PO-BIG-XML</t></is></c>
    <c r="B2" t="inlineStr"><is><t>MAT-99</t></is></c>
    <c r="C2"><v>5</v></c>
    <c r="D2"><v>1200</v></c>
    <c r="E2" t="inlineStr"><is><t>OPEN</t></is></c>
  </row>
</sheetData></worksheet>"#,
            )?;
            zip.finish()?;
        }

        assert!(buffer.len() <= MAX_WASM_INPUT_BYTES);
        let out_json = parse_and_filter_erp_excel_core(&buffer, "[]")?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].doc_no, "PO-BIG-XML");
        assert_eq!(rows[0].item_code, "MAT-99");
        Ok(())
    }

    #[test]
    fn rejects_excel_over_20mb() {
        let huge = vec![b'P'; MAX_WASM_INPUT_BYTES + 1];
        let res = parse_and_filter_erp_excel_core(&huge, "[]");
        assert!(matches!(res, Err(PortalError::InputTooLarge { .. })));
    }

    #[test]
    fn rejects_legacy_ole2_xls_and_invalid_binary() {
        let ole2 = b"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1\x00\x00\x00";
        assert!(matches!(
            parse_and_filter_erp_excel_core(ole2, "[]"),
            Err(PortalError::InvalidExcelWorkbook(_))
        ));

        let invalid_utf8 = b"\xFF\xFE\x00\x01binary-garbage";
        assert!(matches!(
            parse_and_filter_erp_excel_core(invalid_utf8, "[]"),
            Err(PortalError::InvalidExcelWorkbook(_))
        ));
    }

    #[test]
    fn parses_filters_known_hashes_and_sanitizes_formulas() -> Result<(), Box<dyn std::error::Error>>
    {
        let known_hash =
            compute_canonical_xxh3_hex("PO-001", "MAT-01", "10.0000", "1500.50", "ACTIVE");
        let existing_json = serde_json::to_string(&vec![known_hash])?;

        let xlsx = build_minimal_xlsx(&[
            vec![
                ("doc_no", false),
                ("item_code", false),
                ("qty", false),
                ("price", false),
                ("status", false),
            ],
            vec![
                ("PO-001", false),
                ("MAT-01", false),
                ("10", true),
                ("1500.5", true),
                ("ACTIVE", false),
            ],
            vec![
                (" \t=PO-002", false),
                (" +MAT-02 ", false),
                ("25.125", true),
                ("99.9", true),
                ("\t=CMD|calc", false),
            ],
        ])?;

        let out_json = parse_and_filter_erp_excel_core(&xlsx, &existing_json)?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].doc_no, "'=PO-002");
        assert_eq!(rows[0].item_code, "'+MAT-02");
        assert_eq!(rows[0].qty_norm, "25.1250");
        assert_eq!(rows[0].price_norm, "99.90");
        assert_eq!(rows[0].status, "'=CMD|calc");
        assert_eq!(
            rows[0].row_hash,
            compute_canonical_xxh3_hex("'=PO-002", "'+MAT-02", "25.1250", "99.90", "'=CMD|calc")
        );
        Ok(())
    }

    #[test]
    fn parses_xlsx_with_shared_strings_rich_text_entities_rels_and_formulas(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let known_hash =
            compute_canonical_xxh3_hex("PO-A&B", "MAT-01", "10.2500", "200.50", "OPEN");
        let existing_json = serde_json::to_string(&vec![known_hash])?;

        let xlsx = build_shared_strings_xlsx()?;
        let out_json = parse_and_filter_erp_excel_core(&xlsx, &existing_json)?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].doc_no, "'=PO-INJ");
        assert_eq!(rows[0].item_code, "MAT'99");
        assert_eq!(rows[0].qty_norm, "3.0000");
        assert_eq!(rows[0].price_norm, "4.50");
        assert_eq!(rows[0].status, "ACTIVE");
        Ok(())
    }

    #[test]
    fn parses_rfc4180_csv_with_bom_quoted_fields_and_formula_neutralization(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let known_hash =
            compute_canonical_xxh3_hex("PO-100", "ITEM-A", "2.0000", "1234.50", "ACTIVE");
        let existing_json = serde_json::to_string(&vec![known_hash])?;

        let csv_payload = concat!(
            "\u{FEFF}doc_no,item_code,qty,price,status\r\n",
            "PO-100,ITEM-A,2,\"1,234.50\",ACTIVE\r\n",
            "\"=CMD|calc\",\"+MAT\"\"2\",7.5,19.99,\"\t@HOLD\"\n"
        );
        let out_json = parse_and_filter_erp_excel_core(csv_payload.as_bytes(), &existing_json)?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].doc_no, "'=CMD|calc");
        assert_eq!(rows[0].item_code, "'+MAT\"2");
        assert_eq!(rows[0].qty_norm, "7.5000");
        assert_eq!(rows[0].price_norm, "19.99");
        assert_eq!(rows[0].status, "'@HOLD");
        Ok(())
    }

    #[test]
    fn rejects_excessive_rows_over_50000() -> Result<(), Box<dyn std::error::Error>> {
        let mut rows = Vec::with_capacity(MAX_EXCEL_ROWS + 2);
        rows.push(vec![
            ("doc", false),
            ("item", false),
            ("qty", false),
            ("price", false),
            ("status", false),
        ]);
        for _ in 0..=MAX_EXCEL_ROWS {
            rows.push(vec![
                ("P", false),
                ("I", false),
                ("1", true),
                ("1", true),
                ("OK", false),
            ]);
        }
        let xlsx = build_minimal_xlsx(&rows)?;
        let res = parse_and_filter_erp_excel_core(&xlsx, "[]");
        assert!(matches!(res, Err(PortalError::ExcelTooManyRows { .. })));

        let mut csv = String::from("doc,item,qty,price,status\n");
        for _ in 0..=MAX_EXCEL_ROWS {
            csv.push_str("P,I,1,1,OK\n");
        }
        let csv_res = parse_and_filter_erp_excel_core(csv.as_bytes(), "[]");
        assert!(matches!(csv_res, Err(PortalError::ExcelTooManyRows { .. })));
        Ok(())
    }

    #[test]
    fn auto_detects_infor_po_layout_and_preserves_totals_and_cwe1236_guard(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let csv_payload = concat!(
            "Import Status,Import Code,Import Message,Company,Order,Buy-from Business Partner,,Buyer,,Order Date,Order Amount,,Status\n",
            ",,,999,SYNPO1001,BP001,PT SINTESIS NUSANTARA,B001,SYN BUYER,2026-09-16,1250000,IDR,Free\n",
            ",,,999,=SYNPO1002,BP002,+PT CONTOH,B002,SYN BUYER,2026-09-17,2500000.5,IDR,\t=Closed\n"
        );

        let out_json = parse_and_filter_erp_excel_core(csv_payload.as_bytes(), "[]")?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 2);

        assert_eq!(rows[0].doc_no, "SYNPO1001");
        assert_eq!(rows[0].item_code, "PT SINTESIS NUSANTARA");
        assert_eq!(rows[0].qty_norm, "1.0000");
        assert_eq!(rows[0].price_norm, "1250000.00");
        assert_eq!(rows[0].status, "Free");

        assert_eq!(rows[1].doc_no, "'=SYNPO1002");
        assert_eq!(rows[1].item_code, "'+PT CONTOH");
        assert_eq!(rows[1].qty_norm, "1.0000");
        assert_eq!(rows[1].price_norm, "2500000.50");
        assert_eq!(rows[1].status, "'=Closed");
        Ok(())
    }

    #[test]
    fn auto_detects_infor_gr_xlsx_layout_across_columns_beyond_5(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let header: Vec<(&str, bool)> = vec![
            ("Import Status", false),
            ("Import Code", false),
            ("Import Message", false),
            ("Company", false),
            ("Warehouse", false),
            ("", false),
            ("Item", false),
            ("", false),
            ("", false),
            ("Receipt", false),
            ("Line", false),
            ("Order Line", false),
            ("", false),
            ("", false),
            ("", false),
            ("Received Quantity", false),
            ("", false),
            ("Final Receipt", false),
            ("Line Status", false),
        ];
        let row1: Vec<(&str, bool)> = vec![
            ("", false),
            ("", false),
            ("", false),
            ("999", true),
            ("SYNWH", false),
            ("SYN-LOC", false),
            ("", false),
            ("SYN-ITEM-01", false),
            ("Part 01", false),
            ("SYNGR0001", false),
            ("1", true),
            ("SYNPO1001", false),
            ("10", true),
            ("0", true),
            ("0", true),
            ("3.5", true),
            ("pcs", false),
            ("Yes", false),
            ("Confirmed", false),
        ];

        let xlsx = build_minimal_xlsx(&[header, row1])?;
        let out_json = parse_and_filter_erp_excel_core(&xlsx, "[]")?;
        let rows: Vec<ErpRow> = serde_json::from_str(&out_json)?;
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].doc_no, "SYNPO1001");
        assert_eq!(rows[0].item_code, "SYNGR0001|SYN-ITEM-01");
        assert_eq!(rows[0].qty_norm, "3.5000");
        assert_eq!(rows[0].price_norm, "0.00");
        assert_eq!(rows[0].status, "Confirmed");
        Ok(())
    }
}

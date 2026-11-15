use crate::error::PortalError;
use image::codecs::jpeg::JpegEncoder;
use image::imageops::FilterType;
use image::{ColorType, DynamicImage, ImageFormat, ImageReader, Limits};
use lopdf::{Document, Object, Stream};
use std::io::Cursor;

pub const MAX_WASM_INPUT_BYTES: usize = 20 * 1024 * 1024;
pub const MAX_PDF_OBJECTS: usize = 25_000;
pub const MIN_RECOMPRESS_STREAM_BYTES: usize = 120_000;
pub const MAX_DECODE_IMAGE_DIM: u32 = 12_000;
pub const MAX_DECODE_ALLOC_BYTES: u64 = 64 * 1024 * 1024;
const JPEG_RECOMPRESS_QUALITY: u8 = 80;

fn expected_color_type(stream: &Stream) -> Option<ColorType> {
    let is_image = stream
        .dict
        .get(b"Subtype")
        .and_then(Object::as_name_str)
        .is_ok_and(|name| name == "Image");
    let is_dct = stream
        .dict
        .get(b"Filter")
        .and_then(Object::as_name_str)
        .is_ok_and(|name| name == "DCTDecode");
    let has_mask_or_decode =
        stream.dict.has(b"SMask") || stream.dict.has(b"Mask") || stream.dict.has(b"Decode");

    if !is_image || !is_dct || has_mask_or_decode {
        return None;
    }

    match stream
        .dict
        .get(b"ColorSpace")
        .and_then(Object::as_name_str)
        .ok()
    {
        Some("DeviceRGB") => Some(ColorType::Rgb8),
        Some("DeviceGray") => Some(ColorType::L8),
        _ => None,
    }
}

fn decode_bounded_jpeg(bytes: &[u8]) -> Option<DynamicImage> {
    let mut reader = ImageReader::with_format(Cursor::new(bytes), ImageFormat::Jpeg);
    let mut limits = Limits::default();
    limits.max_image_width = Some(MAX_DECODE_IMAGE_DIM);
    limits.max_image_height = Some(MAX_DECODE_IMAGE_DIM);
    limits.max_alloc = Some(MAX_DECODE_ALLOC_BYTES);
    reader.limits(limits);
    reader.decode().ok()
}

fn encode_jpeg_preserving_colorspace(img: &DynamicImage, expected: ColorType) -> Option<Vec<u8>> {
    if img.color() != expected {
        return None;
    }

    let mut out_buf = Vec::new();
    let mut cursor = Cursor::new(&mut out_buf);
    let encoder = JpegEncoder::new_with_quality(&mut cursor, JPEG_RECOMPRESS_QUALITY);

    let write_res = match (img, expected) {
        (DynamicImage::ImageRgb8(buf), ColorType::Rgb8) => buf.write_with_encoder(encoder),
        (DynamicImage::ImageLuma8(buf), ColorType::L8) => buf.write_with_encoder(encoder),
        _ => return None,
    };

    if write_res.is_ok() {
        Some(out_buf)
    } else {
        None
    }
}

fn try_recompress_image_stream(stream: &mut Stream, safe_dim: u32) {
    let Some(expected_color) = expected_color_type(stream) else {
        return;
    };
    if stream.content.len() <= MIN_RECOMPRESS_STREAM_BYTES {
        return;
    }

    let Some(img) = decode_bounded_jpeg(&stream.content) else {
        return;
    };
    if img.color() != expected_color {
        return;
    }

    let resized = if img.width() > safe_dim || img.height() > safe_dim {
        img.resize(safe_dim, safe_dim, FilterType::Triangle)
    } else {
        img
    };

    let Some(out_buf) = encode_jpeg_preserving_colorspace(&resized, expected_color) else {
        return;
    };

    if out_buf.len() < stream.content.len() {
        stream.dict.set("Width", i64::from(resized.width()));
        stream.dict.set("Height", i64::from(resized.height()));
        stream.dict.set("BitsPerComponent", 8_i64);
        stream.set_plain_content(out_buf);
        stream
            .dict
            .set("Filter", Object::Name(b"DCTDecode".to_vec()));
    }
}

pub fn sanitize_and_compress_pdf_core(
    pdf_bytes: &[u8],
    max_dim: u32,
) -> Result<Vec<u8>, PortalError> {
    if pdf_bytes.is_empty() {
        return Err(PortalError::EmptyInput);
    }
    if pdf_bytes.len() > MAX_WASM_INPUT_BYTES {
        return Err(PortalError::InputTooLarge {
            actual: pdf_bytes.len(),
            max: MAX_WASM_INPUT_BYTES,
        });
    }
    if !pdf_bytes.starts_with(b"%PDF-") {
        return Err(PortalError::InvalidPdfHeader);
    }

    let mut doc = Document::load_mem(pdf_bytes).map_err(|_| PortalError::PdfParseFailed)?;

    if doc.is_encrypted() || doc.trailer.has(b"Encrypt") {
        return Err(PortalError::PdfEncrypted);
    }
    if doc.objects.len() > MAX_PDF_OBJECTS {
        return Err(PortalError::PdfTooManyObjects {
            count: doc.objects.len(),
            max: MAX_PDF_OBJECTS,
        });
    }

    let safe_dim = max_dim.clamp(800, 2400);

    for object in doc.objects.values_mut() {
        if let Object::Stream(ref mut stream) = object {
            try_recompress_image_stream(stream, safe_dim);
        }
    }

    doc.compress();
    let mut out_pdf = Vec::with_capacity(pdf_bytes.len());
    doc.save_to(&mut out_pdf)
        .map_err(|_| PortalError::PdfWriteFailed)?;

    if out_pdf.starts_with(b"%PDF-") && out_pdf.len() < pdf_bytes.len() {
        Ok(out_pdf)
    } else {
        Ok(pdf_bytes.to_vec())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use image::codecs::jpeg::JpegEncoder;
    use image::{ColorType, ImageBuffer, Luma, Rgb};
    use lopdf::{dictionary, Document, Object, Stream};
    use std::io::Cursor;

    fn make_noisy_rgb_jpeg(
        width: u32,
        height: u32,
        quality: u8,
    ) -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let img: ImageBuffer<Rgb<u8>, Vec<u8>> = ImageBuffer::from_fn(width, height, |x, y| {
            let r = ((x.wrapping_mul(37) ^ y.wrapping_mul(17)) & 0xFF) as u8;
            let g = ((x.wrapping_mul(13) + y.wrapping_mul(53)) & 0xFF) as u8;
            let b = ((x.wrapping_mul(71) ^ y.wrapping_mul(29)) & 0xFF) as u8;
            Rgb([r, g, b])
        });
        let mut out = Vec::new();
        let mut cursor = Cursor::new(&mut out);
        let encoder = JpegEncoder::new_with_quality(&mut cursor, quality);
        img.write_with_encoder(encoder)?;
        Ok(out)
    }

    fn make_noisy_gray_jpeg(
        width: u32,
        height: u32,
        quality: u8,
    ) -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let img: ImageBuffer<Luma<u8>, Vec<u8>> = ImageBuffer::from_fn(width, height, |x, y| {
            let v = ((x.wrapping_mul(41) ^ y.wrapping_mul(23)) & 0xFF) as u8;
            Luma([v])
        });
        let mut out = Vec::new();
        let mut cursor = Cursor::new(&mut out);
        let encoder = JpegEncoder::new_with_quality(&mut cursor, quality);
        img.write_with_encoder(encoder)?;
        Ok(out)
    }

    fn build_pdf_with_image(
        image_bytes: Vec<u8>,
        width: i64,
        height: i64,
        color_space: &str,
        extra_key: Option<&str>,
    ) -> Result<Vec<u8>, Box<dyn std::error::Error>> {
        let mut doc = Document::with_version("1.5");
        let pages_id = doc.new_object_id();
        let mut img_dict = dictionary! {
            "Type" => "XObject",
            "Subtype" => "Image",
            "Width" => width,
            "Height" => height,
            "ColorSpace" => Object::Name(color_space.as_bytes().to_vec()),
            "BitsPerComponent" => 8,
            "Filter" => "DCTDecode",
            "Length" => image_bytes.len() as i64,
        };
        if let Some(key) = extra_key {
            let mask_id = doc.add_object(Stream::new(dictionary! {}, vec![0xFF; 16]));
            img_dict.set(key, mask_id);
        }
        let img_id = doc.add_object(Stream::new(img_dict, image_bytes));
        let resources_id = doc.add_object(dictionary! {
            "XObject" => dictionary! {
                "Im0" => img_id,
            }
        });
        let content_id = doc.add_object(Stream::new(
            dictionary! {},
            b"q 100 0 0 100 0 0 cm /Im0 Do Q".to_vec(),
        ));
        let page_id = doc.add_object(dictionary! {
            "Type" => "Page",
            "Parent" => pages_id,
            "Resources" => resources_id,
            "MediaBox" => vec![0.into(), 0.into(), 595.into(), 842.into()],
            "Contents" => content_id,
        });
        doc.objects.insert(
            pages_id,
            Object::Dictionary(dictionary! {
                "Type" => "Pages",
                "Kids" => vec![page_id.into()],
                "Count" => 1,
            }),
        );
        let catalog_id = doc.add_object(dictionary! {
            "Type" => "Catalog",
            "Pages" => pages_id,
        });
        doc.trailer.set("Root", catalog_id);

        let mut pdf = Vec::new();
        doc.save_to(&mut pdf)?;
        Ok(pdf)
    }

    #[test]
    fn rejects_empty_non_pdf_corrupt_oversized_and_encrypted_pdfs(
    ) -> Result<(), Box<dyn std::error::Error>> {
        assert!(matches!(
            sanitize_and_compress_pdf_core(b"", 1600),
            Err(PortalError::EmptyInput)
        ));
        assert!(matches!(
            sanitize_and_compress_pdf_core(b"<html>fake</html>", 1600),
            Err(PortalError::InvalidPdfHeader)
        ));

        let mut oversized = b"%PDF-1.4\n".to_vec();
        oversized.resize(MAX_WASM_INPUT_BYTES + 1, b'0');
        assert!(matches!(
            sanitize_and_compress_pdf_core(&oversized, 1600),
            Err(PortalError::InputTooLarge { .. })
        ));

        assert!(matches!(
            sanitize_and_compress_pdf_core(b"%PDF-1.4\ncorrupt-payload%%%EOF", 1600),
            Err(PortalError::PdfParseFailed)
        ));

        // Encrypted PDF (trailer contains /Encrypt reference)
        let mut doc = Document::with_version("1.5");
        let pages_id = doc.new_object_id();
        doc.objects.insert(
            pages_id,
            Object::Dictionary(
                dictionary! { "Type" => "Pages", "Kids" => Vec::<Object>::new(), "Count" => 0 },
            ),
        );
        let catalog_id = doc.add_object(dictionary! { "Type" => "Catalog", "Pages" => pages_id });
        let enc_id = doc.add_object(
            dictionary! { "Filter" => "Standard", "V" => 2, "R" => 3, "Length" => 128 },
        );
        doc.trailer.set("Root", catalog_id);
        doc.trailer.set("Encrypt", enc_id);
        let mut enc_pdf = Vec::new();
        doc.save_to(&mut enc_pdf)?;
        assert!(matches!(
            sanitize_and_compress_pdf_core(&enc_pdf, 1600),
            Err(PortalError::PdfEncrypted)
        ));
        Ok(())
    }

    #[test]
    fn rejects_pdf_with_more_than_25000_objects() -> Result<(), Box<dyn std::error::Error>> {
        let mut doc = Document::with_version("1.5");
        let pages_id = doc.new_object_id();
        doc.objects.insert(
            pages_id,
            Object::Dictionary(
                dictionary! { "Type" => "Pages", "Kids" => Vec::<Object>::new(), "Count" => 0 },
            ),
        );
        let catalog_id = doc.add_object(dictionary! { "Type" => "Catalog", "Pages" => pages_id });
        doc.trailer.set("Root", catalog_id);
        for i in 0..=MAX_PDF_OBJECTS {
            doc.add_object(Object::Integer(i as i64));
        }
        let mut pdf = Vec::new();
        doc.save_to(&mut pdf)?;
        assert!(matches!(
            sanitize_and_compress_pdf_core(&pdf, 1600),
            Err(PortalError::PdfTooManyObjects { .. })
        ));
        Ok(())
    }

    #[test]
    fn preserves_cmyk_smask_and_mask_streams_untouched() -> Result<(), Box<dyn std::error::Error>> {
        // Synthetic >120 KB payload marked /DeviceCMYK (must NOT be decoded/recompressed)
        let cmyk_payload = vec![0xAB; 130_000];
        let cmyk_pdf = build_pdf_with_image(cmyk_payload.clone(), 2000, 2000, "DeviceCMYK", None)?;
        let out_cmyk = sanitize_and_compress_pdf_core(&cmyk_pdf, 1200)?;
        let reloaded = Document::load_mem(&out_cmyk)?;
        let has_exact_cmyk = reloaded.objects.values().any(|obj| {
            if let Object::Stream(s) = obj {
                s.dict.get(b"ColorSpace").and_then(Object::as_name_str).ok() == Some("DeviceCMYK")
                    && s.content == cmyk_payload
            } else {
                false
            }
        });
        assert!(has_exact_cmyk, "CMYK stream must be preserved intact");

        // Large RGB JPEG with /SMask must NOT be recompressed
        let rgb_jpeg = make_noisy_rgb_jpeg(600, 600, 95)?;
        assert!(rgb_jpeg.len() > MIN_RECOMPRESS_STREAM_BYTES);
        let smask_pdf =
            build_pdf_with_image(rgb_jpeg.clone(), 600, 600, "DeviceRGB", Some("SMask"))?;
        let out_smask = sanitize_and_compress_pdf_core(&smask_pdf, 800)?;
        let reloaded_smask = Document::load_mem(&out_smask)?;
        let has_exact_smask = reloaded_smask.objects.values().any(|obj| {
            if let Object::Stream(s) = obj {
                s.dict.has(b"SMask") && s.content == rgb_jpeg
            } else {
                false
            }
        });
        assert!(
            has_exact_smask,
            "/SMask image stream must be preserved intact"
        );

        // Large RGB JPEG with /Mask must NOT be recompressed
        let mask_pdf = build_pdf_with_image(rgb_jpeg.clone(), 600, 600, "DeviceRGB", Some("Mask"))?;
        let out_mask = sanitize_and_compress_pdf_core(&mask_pdf, 800)?;
        let reloaded_mask = Document::load_mem(&out_mask)?;
        let has_exact_mask = reloaded_mask.objects.values().any(|obj| {
            if let Object::Stream(s) = obj {
                s.dict.has(b"Mask") && s.content == rgb_jpeg
            } else {
                false
            }
        });
        assert!(
            has_exact_mask,
            "/Mask image stream must be preserved intact"
        );
        Ok(())
    }

    #[test]
    fn recompresses_large_rgb_and_gray_jpeg_streams_cleanly(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let large_rgb = make_noisy_rgb_jpeg(2200, 2200, 95)?;
        assert!(large_rgb.len() > MIN_RECOMPRESS_STREAM_BYTES);
        let rgb_pdf = build_pdf_with_image(large_rgb.clone(), 2200, 2200, "DeviceRGB", None)?;

        let compressed_rgb_pdf = sanitize_and_compress_pdf_core(&rgb_pdf, 1000)?;
        assert!(compressed_rgb_pdf.starts_with(b"%PDF-"));
        assert!(compressed_rgb_pdf.len() < rgb_pdf.len());

        let reloaded_rgb = Document::load_mem(&compressed_rgb_pdf)?;
        let mut found_rgb = false;
        for obj in reloaded_rgb.objects.values() {
            if let Object::Stream(s) = obj {
                if s.dict.get(b"Subtype").and_then(Object::as_name_str).ok() == Some("Image") {
                    found_rgb = true;
                    assert_eq!(
                        s.dict.get(b"Filter").and_then(Object::as_name_str).ok(),
                        Some("DCTDecode")
                    );
                    assert_eq!(
                        s.dict.get(b"ColorSpace").and_then(Object::as_name_str).ok(),
                        Some("DeviceRGB")
                    );
                    let w = s.dict.get(b"Width").and_then(Object::as_i64)?;
                    let h = s.dict.get(b"Height").and_then(Object::as_i64)?;
                    assert!(w <= 1000 && h <= 1000);
                    let decoded =
                        image::load_from_memory_with_format(&s.content, image::ImageFormat::Jpeg)?;
                    assert_eq!(decoded.color(), ColorType::Rgb8);
                }
            }
        }
        assert!(found_rgb);

        let large_gray = make_noisy_gray_jpeg(2200, 2200, 95)?;
        assert!(large_gray.len() > MIN_RECOMPRESS_STREAM_BYTES);
        let gray_pdf = build_pdf_with_image(large_gray.clone(), 2200, 2200, "DeviceGray", None)?;

        let compressed_gray_pdf = sanitize_and_compress_pdf_core(&gray_pdf, 1000)?;
        assert!(compressed_gray_pdf.len() < gray_pdf.len());
        let reloaded_gray = Document::load_mem(&compressed_gray_pdf)?;
        let mut found_gray = false;
        for obj in reloaded_gray.objects.values() {
            if let Object::Stream(s) = obj {
                if s.dict.get(b"Subtype").and_then(Object::as_name_str).ok() == Some("Image") {
                    found_gray = true;
                    assert_eq!(
                        s.dict.get(b"ColorSpace").and_then(Object::as_name_str).ok(),
                        Some("DeviceGray")
                    );
                    let decoded =
                        image::load_from_memory_with_format(&s.content, image::ImageFormat::Jpeg)?;
                    assert_eq!(decoded.color(), ColorType::L8);
                }
            }
        }
        assert!(found_gray);
        Ok(())
    }
}

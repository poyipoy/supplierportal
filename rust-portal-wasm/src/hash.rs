use xxhash_rust::xxh3::xxh3_64;

fn push_trimmed_upper(out: &mut String, input: &str) {
    for ch in input.trim().chars() {
        for upper in ch.to_uppercase() {
            out.push(upper);
        }
    }
}

/// Canonicalization contract matching Laravel `CanonicalRowHasher`:
/// `UPPER(TRIM(doc_no))|UPPER(TRIM(item_code))|TRIM(qty_norm)|TRIM(price_norm)|UPPER(TRIM(status))`
pub fn build_canonical_payload(
    doc_no: &str,
    item_code: &str,
    qty_norm: &str,
    price_norm: &str,
    status: &str,
) -> String {
    let qty = qty_norm.trim();
    let price = price_norm.trim();
    let cap = doc_no.len() + item_code.len() + qty.len() + price.len() + status.len() + 4;
    let mut out = String::with_capacity(cap);

    push_trimmed_upper(&mut out, doc_no);
    out.push('|');
    push_trimmed_upper(&mut out, item_code);
    out.push('|');
    out.push_str(qty);
    out.push('|');
    out.push_str(price);
    out.push('|');
    push_trimmed_upper(&mut out, status);
    out
}

pub fn compute_canonical_xxh3_hex(
    doc_no: &str,
    item_code: &str,
    qty_norm: &str,
    price_norm: &str,
    status: &str,
) -> String {
    let canonical = build_canonical_payload(doc_no, item_code, qty_norm, price_norm, status);
    format!("{:016x}", xxh3_64(canonical.as_bytes()))
}

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;
    use rstest::rstest;

    #[rstest]
    #[case(
        "PO/05/2025/001",
        "mat-001",
        "10.0000",
        "1500.50",
        "active",
        "PO/05/2025/001|MAT-001|10.0000|1500.50|ACTIVE",
        "5cd229126f3d88b4"
    )]
    #[case(
        "  po-001 \t\n",
        "  mat-01  ",
        " 0.0000 ",
        "\r25.00 ",
        "  draft ",
        "PO-001|MAT-01|0.0000|25.00|DRAFT",
        "28bcf663890cb0c4"
    )]
    #[case(
        "PO-DEC",
        "ITEM-9",
        "12345.6789",
        "0.01",
        "PENDING",
        "PO-DEC|ITEM-9|12345.6789|0.01|PENDING",
        "f2befcb3982dcd91"
    )]
    #[case(
        "PO#2026/A&B+1",
        "MAT_X-99/1",
        "100.2500",
        "999999.99",
        "ON-HOLD",
        "PO#2026/A&B+1|MAT_X-99/1|100.2500|999999.99|ON-HOLD",
        "3ec524af74202815"
    )]
    #[case(
        "PO-PT. Baja — 日本語",
        "mat-é-ß-€",
        "5.5000",
        "42.10",
        "approved",
        "PO-PT. BAJA — 日本語|MAT-É-SS-€|5.5000|42.10|APPROVED",
        "11955466a98c0560"
    )]
    #[case(
        "PO-001",
        "MAT-01",
        "10.0000",
        "1500.50",
        "ACTIVE",
        "PO-001|MAT-01|10.0000|1500.50|ACTIVE",
        "34956c47a415bb9e"
    )]
    fn parity_vectors_match_php_xxh3(
        #[case] doc_no: &str,
        #[case] item_code: &str,
        #[case] qty_norm: &str,
        #[case] price_norm: &str,
        #[case] status: &str,
        #[case] expected_canonical: &str,
        #[case] expected_hex: &str,
    ) {
        let canonical = build_canonical_payload(doc_no, item_code, qty_norm, price_norm, status);
        assert_eq!(canonical, expected_canonical);

        let hex = compute_canonical_xxh3_hex(doc_no, item_code, qty_norm, price_norm, status);
        assert_eq!(hex, expected_hex);
        assert_eq!(hex.len(), 16);
        assert!(hex.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f')));
    }

    proptest! {
        #[test]
        fn canonical_hash_is_deterministic_and_16_char_lowercase_hex(
            doc_no in ".*",
            item_code in ".*",
            qty in 0_u64..1_000_000_u64,
            price in 0_u64..1_000_000_u64,
            status in ".*",
        ) {
            let qty_norm = format!("{}.{:04}", qty / 10000, qty % 10000);
            let price_norm = format!("{}.{:02}", price / 100, price % 100);

            let h1 = compute_canonical_xxh3_hex(&doc_no, &item_code, &qty_norm, &price_norm, &status);
            let h2 = compute_canonical_xxh3_hex(&doc_no, &item_code, &qty_norm, &price_norm, &status);

            prop_assert_eq!(&h1, &h2);
            prop_assert_eq!(h1.len(), 16);
            prop_assert!(h1.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f')));
        }

        #[test]
        fn surrounding_ascii_whitespace_and_case_do_not_change_hash(
            doc_no in "[A-Za-z0-9/\\-_]{1,20}",
            item_code in "[A-Za-z0-9/\\-_]{1,20}",
            status in "[A-Za-z_]{1,12}",
            pad_left in "[ \t\n\r]{0,4}",
            pad_right in "[ \t\n\r]{0,4}",
        ) {
            let h_clean = compute_canonical_xxh3_hex(
                &doc_no.to_ascii_uppercase(),
                &item_code.to_ascii_uppercase(),
                "12.3456",
                "78.90",
                &status.to_ascii_uppercase(),
            );
            let padded_doc = format!("{pad_left}{}{pad_right}", doc_no.to_ascii_lowercase());
            let padded_item = format!("{pad_left}{}{pad_right}", item_code.to_ascii_lowercase());
            let padded_status = format!("{pad_left}{}{pad_right}", status.to_ascii_lowercase());

            let h_padded = compute_canonical_xxh3_hex(
                &padded_doc,
                &padded_item,
                " 12.3456\t",
                "\n78.90 ",
                &padded_status,
            );
            prop_assert_eq!(h_clean, h_padded);
        }
    }
}

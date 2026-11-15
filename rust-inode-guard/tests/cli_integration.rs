#![forbid(unsafe_code)]
#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

use std::fs;
use std::process::Command;
use tempfile::TempDir;

#[test]
fn cli_exits_with_code_2_on_missing_or_bad_args() -> Result<(), Box<dyn std::error::Error>> {
    let bin = env!("CARGO_BIN_EXE_inode-guard");
    let output = Command::new(bin).output()?;
    assert_eq!(output.status.code(), Some(2));
    assert!(String::from_utf8_lossy(&output.stderr).contains("Usage: inode-guard"));
    Ok(())
}

#[test]
fn cli_dry_run_succeeds_without_deleting_files() -> Result<(), Box<dyn std::error::Error>> {
    let bin = env!("CARGO_BIN_EXE_inode-guard");
    let root = TempDir::new()?;
    let cache_dir = root.path().join("storage/framework/cache/data");
    fs::create_dir_all(&cache_dir)?;
    let fresh_file = cache_dir.join("fresh.bin");
    fs::write(&fresh_file, b"keep-me")?;

    let output = Command::new(bin)
        .arg(root.path())
        .arg("0")
        .arg("--dry-run")
        .output()?;

    assert!(output.status.success());
    assert!(fresh_file.exists());
    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(stdout.contains("dry_run=true"));
    assert!(stdout.contains("min_age_h=1"));
    Ok(())
}

#![forbid(unsafe_code)]
#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

use std::fs;
use std::io::{self, Write};
use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime};
use thiserror::Error;

pub const MIN_AGE_HOURS: u64 = 1;
pub const MAX_SWEEP_DEPTH: usize = 32;

pub const ALLOWED_SUBDIRS: [&str; 6] = [
    "storage/app/private/imports/reader-cache",
    "storage/app/private/native-security/xml",
    "storage/framework/cache/data",
    "storage/framework/cache/import-previews",
    "storage/framework/cache/laravel-excel",
    "storage/app/private/po-documents/staging",
];

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CliConfig {
    pub root: PathBuf,
    pub max_age_hours: u64,
    pub dry_run: bool,
}

#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct SweepReport {
    pub effective_age_hours: u64,
    pub deleted_files: u64,
    pub freed_bytes: u64,
    pub skipped_symlinks: u64,
    pub skipped_dotfiles: u64,
    pub skipped_outside_whitelist: u64,
}

#[derive(Debug, Error, PartialEq, Eq)]
pub enum GuardError {
    #[error("invalid CLI arguments: {0}")]
    InvalidArgs(String),

    #[error("invalid application root path: {0}")]
    InvalidRoot(String),
}

pub fn parse_cli_args(args: &[String]) -> Result<CliConfig, GuardError> {
    if args.len() < 3 {
        return Err(GuardError::InvalidArgs(String::from(
            "expected <app_root_path> <max_age_hours> [--dry-run]",
        )));
    }

    let root_raw = args[1].trim();
    if root_raw.is_empty() {
        return Err(GuardError::InvalidArgs(String::from(
            "app_root_path cannot be empty",
        )));
    }

    let requested_hours = args[2].parse::<u64>().map_err(|_| {
        GuardError::InvalidArgs(String::from("max_age_hours must be a non-negative integer"))
    })?;

    let mut dry_run = false;
    for flag in &args[3..] {
        if flag == "--dry-run" {
            dry_run = true;
        } else {
            return Err(GuardError::InvalidArgs(format!("unknown argument: {flag}")));
        }
    }

    Ok(CliConfig {
        root: PathBuf::from(root_raw),
        max_age_hours: requested_hours.max(MIN_AGE_HOURS),
        dry_run,
    })
}

pub fn is_safe_regular_file(base_canon: &Path, entry_path: &Path) -> bool {
    let Ok(meta) = fs::symlink_metadata(entry_path) else {
        return false;
    };
    if meta.file_type().is_symlink() || !meta.is_file() {
        return false;
    }
    let Ok(canon) = fs::canonicalize(entry_path) else {
        return false;
    };
    canon.starts_with(base_canon)
}

pub fn is_whitelisted_subdir(root_canon: &Path, candidate: &Path) -> bool {
    if !candidate.starts_with(root_canon) {
        return false;
    }

    for sub in ALLOWED_SUBDIRS {
        let mut cur = root_canon.to_path_buf();
        let mut symlink_free = true;
        for part in Path::new(sub).components() {
            cur.push(part);
            match fs::symlink_metadata(&cur) {
                Ok(meta) if meta.file_type().is_symlink() => {
                    symlink_free = false;
                    break;
                }
                Ok(_) => {}
                Err(_) => {
                    symlink_free = false;
                    break;
                }
            }
        }
        if !symlink_free {
            continue;
        }
        if let Ok(sub_canon) = fs::canonicalize(&cur) {
            if sub_canon.starts_with(root_canon) && candidate.starts_with(&sub_canon) {
                return true;
            }
        }
    }

    false
}

fn resolve_safe_subdir(
    root: &Path,
    root_canon: &Path,
    subdir: &str,
    report: &mut SweepReport,
    err: &mut dyn Write,
) -> Option<PathBuf> {
    let mut cur = root.to_path_buf();

    for component in Path::new(subdir).components() {
        cur.push(component);
        let meta = match fs::symlink_metadata(&cur) {
            Ok(meta) => meta,
            Err(e) if e.kind() == io::ErrorKind::NotFound => return None,
            Err(_) => return None,
        };

        if meta.file_type().is_symlink() {
            report.skipped_symlinks += 1;
            let _ = writeln!(
                err,
                "[SECURITY-SKIP] Symlink detected along whitelist path: {}",
                cur.display()
            );
            return None;
        }
    }

    let Ok(meta) = fs::symlink_metadata(&cur) else {
        return None;
    };
    if !meta.is_dir() {
        return None;
    }

    let Ok(base_canon) = fs::canonicalize(&cur) else {
        return None;
    };
    if !base_canon.starts_with(root_canon) {
        report.skipped_outside_whitelist += 1;
        let _ = writeln!(
            err,
            "[SECURITY-SKIP] Canonicalized path escaped root: {}",
            base_canon.display()
        );
        return None;
    }

    Some(base_canon)
}

struct SweepContext<'a> {
    base_canon: &'a Path,
    max_age: Duration,
    dry_run: bool,
    now: SystemTime,
    report: &'a mut SweepReport,
    out: &'a mut dyn Write,
    err: &'a mut dyn Write,
}

fn sweep_dir(ctx: &mut SweepContext<'_>, current: &Path, depth: usize) {
    if depth >= MAX_SWEEP_DEPTH {
        ctx.report.skipped_outside_whitelist += 1;
        let _ = writeln!(
            ctx.err,
            "[SECURITY-SKIP] Maximum sweep depth ({MAX_SWEEP_DEPTH}) reached at: {}",
            current.display()
        );
        return;
    }

    let Ok(entries) = fs::read_dir(current) else {
        return;
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let Ok(meta) = fs::symlink_metadata(&path) else {
            continue;
        };

        if meta.file_type().is_symlink() {
            ctx.report.skipped_symlinks += 1;
            let _ = writeln!(
                ctx.err,
                "[SECURITY-SKIP] Symlink detected and ignored: {}",
                path.display()
            );
            continue;
        }

        let Some(file_name) = path.file_name().and_then(|n| n.to_str()) else {
            continue;
        };
        if file_name.starts_with('.') {
            ctx.report.skipped_dotfiles += 1;
            continue;
        }

        if meta.is_dir() {
            let Ok(dir_canon) = fs::canonicalize(&path) else {
                continue;
            };
            if !dir_canon.starts_with(ctx.base_canon) {
                ctx.report.skipped_outside_whitelist += 1;
                let _ = writeln!(
                    ctx.err,
                    "[SECURITY-SKIP] Subdirectory escaped whitelist: {}",
                    path.display()
                );
                continue;
            }
            sweep_dir(ctx, &dir_canon, depth + 1);
            continue;
        }

        if !is_safe_regular_file(ctx.base_canon, &path) {
            ctx.report.skipped_outside_whitelist += 1;
            continue;
        }

        let Ok(modified) = meta.modified() else {
            continue;
        };
        let Ok(age) = ctx.now.duration_since(modified) else {
            continue;
        };

        if age >= ctx.max_age {
            let len = meta.len();
            if ctx.dry_run {
                let _ = writeln!(
                    ctx.out,
                    "[DRY-RUN] Would delete: {} ({len} bytes)",
                    path.display()
                );
                ctx.report.deleted_files += 1;
                ctx.report.freed_bytes = ctx.report.freed_bytes.saturating_add(len);
            } else {
                match fs::remove_file(&path) {
                    Ok(()) => {
                        ctx.report.deleted_files += 1;
                        ctx.report.freed_bytes = ctx.report.freed_bytes.saturating_add(len);
                    }
                    Err(remove_err) => {
                        let _ = writeln!(
                            ctx.err,
                            "[WARN] Failed to remove {}: {remove_err}",
                            path.display()
                        );
                    }
                }
            }
        }
    }
}

pub fn run_cleanup_at(
    root: &Path,
    max_age_hours: u64,
    dry_run: bool,
    now: SystemTime,
    out: &mut dyn Write,
    err: &mut dyn Write,
) -> Result<SweepReport, GuardError> {
    let root_canon = fs::canonicalize(root)
        .map_err(|e| GuardError::InvalidRoot(format!("{}: {e}", root.display())))?;
    if !root_canon.is_dir() {
        return Err(GuardError::InvalidRoot(format!(
            "{} is not a directory",
            root.display()
        )));
    }

    let effective_age_hours = max_age_hours.max(MIN_AGE_HOURS);
    let max_age = Duration::from_secs(effective_age_hours.saturating_mul(3600));

    let mut report = SweepReport {
        effective_age_hours,
        ..SweepReport::default()
    };

    for sub in ALLOWED_SUBDIRS {
        if let Some(base_canon) = resolve_safe_subdir(root, &root_canon, sub, &mut report, err) {
            let mut ctx = SweepContext {
                base_canon: &base_canon,
                max_age,
                dry_run,
                now,
                report: &mut report,
                out,
                err,
            };
            sweep_dir(&mut ctx, &base_canon, 0);
        }
    }

    Ok(report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;
    use rstest::rstest;
    use std::fs;
    use std::path::PathBuf;
    use std::time::Duration;
    use tempfile::TempDir;

    #[cfg(unix)]
    fn create_symlink_file(original: &Path, link: &Path) -> std::io::Result<()> {
        std::os::unix::fs::symlink(original, link)
    }

    #[cfg(windows)]
    fn create_symlink_file(original: &Path, link: &Path) -> std::io::Result<()> {
        std::os::windows::fs::symlink_file(original, link)
    }

    #[cfg(unix)]
    fn create_symlink_dir(original: &Path, link: &Path) -> std::io::Result<()> {
        std::os::unix::fs::symlink(original, link)
    }

    #[cfg(windows)]
    fn create_symlink_dir(original: &Path, link: &Path) -> std::io::Result<()> {
        std::os::windows::fs::symlink_dir(original, link)
    }

    fn setup_root_with_subdirs() -> Result<TempDir, Box<dyn std::error::Error>> {
        let dir = TempDir::new()?;
        for sub in ALLOWED_SUBDIRS {
            fs::create_dir_all(dir.path().join(sub))?;
        }
        Ok(dir)
    }

    #[test]
    fn allowed_subdirs_match_verified_laravel_paths_and_exclude_protected_dirs() {
        assert!(ALLOWED_SUBDIRS.contains(&"storage/app/private/imports/reader-cache"));
        assert!(ALLOWED_SUBDIRS.contains(&"storage/app/private/native-security/xml"));
        assert!(ALLOWED_SUBDIRS.contains(&"storage/framework/cache/data"));
        assert!(ALLOWED_SUBDIRS.contains(&"storage/framework/cache/import-previews"));
        assert!(ALLOWED_SUBDIRS.contains(&"storage/framework/cache/laravel-excel"));
        assert!(ALLOWED_SUBDIRS.contains(&"storage/app/private/po-documents/staging"));
        assert!(!ALLOWED_SUBDIRS.contains(&"storage/app/private/po-documents"));
        assert!(!ALLOWED_SUBDIRS.contains(&"storage/app/private/exports"));
        assert!(!ALLOWED_SUBDIRS.contains(&"storage/app/private/imports/uploads"));
    }

    #[rstest]
    #[case(&["inode-guard", "/var/www/app", "0"], "/var/www/app", 1, false)]
    #[case(&["inode-guard", "/var/www/app", "24", "--dry-run"], "/var/www/app", 24, true)]
    #[case(&["inode-guard", "/var/www/app", "2"], "/var/www/app", 2, false)]
    fn parse_cli_args_enforces_min_1_hour_even_when_zero(
        #[case] raw: &[&str],
        #[case] expected_root: &str,
        #[case] expected_hours: u64,
        #[case] expected_dry_run: bool,
    ) -> Result<(), Box<dyn std::error::Error>> {
        let args: Vec<String> = raw.iter().map(|s| (*s).to_string()).collect();
        let cfg = parse_cli_args(&args)?;
        assert_eq!(cfg.root, PathBuf::from(expected_root));
        assert_eq!(cfg.max_age_hours, expected_hours);
        assert_eq!(cfg.dry_run, expected_dry_run);
        Ok(())
    }

    #[test]
    fn parse_cli_args_rejects_missing_invalid_or_unknown_flags() {
        assert!(parse_cli_args(&[String::from("inode-guard")]).is_err());
        assert!(parse_cli_args(&[
            String::from("inode-guard"),
            String::from("/tmp"),
            String::from("abc"),
        ])
        .is_err());
        assert!(parse_cli_args(&[
            String::from("inode-guard"),
            String::from("/tmp"),
            String::from("2"),
            String::from("--unknown-flag"),
        ])
        .is_err());
    }

    #[test]
    fn enforces_minimum_1_hour_age_even_when_arg_is_zero() -> Result<(), Box<dyn std::error::Error>>
    {
        let root = setup_root_with_subdirs()?;
        let cache_file = root
            .path()
            .join("storage/framework/cache/data/recent_session.bin");
        fs::write(&cache_file, b"active-cache")?;

        let mut out = Vec::new();
        let mut err = Vec::new();
        let at_30m = SystemTime::now() + Duration::from_secs(1800);
        let report_early = run_cleanup_at(root.path(), 0, false, at_30m, &mut out, &mut err)?;

        assert_eq!(report_early.effective_age_hours, 1);
        assert_eq!(report_early.deleted_files, 0);
        assert!(
            cache_file.exists(),
            "file younger than 1 hour must not be deleted even when max_age_hours=0"
        );

        let at_61m = SystemTime::now() + Duration::from_secs(3660);
        let report_old = run_cleanup_at(root.path(), 0, false, at_61m, &mut out, &mut err)?;
        assert_eq!(report_old.deleted_files, 1);
        assert!(!cache_file.exists(), "file >= 1 hour old should be deleted");
        Ok(())
    }

    #[test]
    fn dry_run_is_strictly_non_destructive() -> Result<(), Box<dyn std::error::Error>> {
        let root = setup_root_with_subdirs()?;
        let stale_file = root
            .path()
            .join("storage/app/private/imports/reader-cache/stale.cache");
        fs::write(&stale_file, b"stale-payload-123")?;

        let mut out = Vec::new();
        let mut err = Vec::new();
        let future = SystemTime::now() + Duration::from_secs(7200);
        let report = run_cleanup_at(root.path(), 1, true, future, &mut out, &mut err)?;

        assert_eq!(report.deleted_files, 1);
        assert_eq!(report.freed_bytes, 17);
        assert!(stale_file.exists(), "--dry-run must never unlink files");
        let stdout_text = String::from_utf8_lossy(&out);
        assert!(stdout_text.contains("[DRY-RUN]"));
        Ok(())
    }

    #[test]
    fn ignores_dotfiles_and_never_deletes_outside_whitelist(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let root = setup_root_with_subdirs()?;
        let gitignore = root.path().join("storage/framework/cache/data/.gitignore");
        let htaccess = root
            .path()
            .join("storage/app/private/po-documents/staging/.htaccess");
        let env_file = root.path().join(".env");
        let po_lock = root
            .path()
            .join("storage/app/private/po-documents/admission.lock");
        let export_file = root
            .path()
            .join("storage/app/private/exports/active_export.xlsx");

        fs::create_dir_all(root.path().join("storage/app/private/exports"))?;
        fs::write(&gitignore, b"*\n!.gitignore\n")?;
        fs::write(&htaccess, b"Deny from all\n")?;
        fs::write(&env_file, b"APP_KEY=secret\n")?;
        fs::write(&po_lock, b"LOCK")?;
        fs::write(&export_file, b"XLSX")?;

        let mut out = Vec::new();
        let mut err = Vec::new();
        let future = SystemTime::now() + Duration::from_secs(86_400);
        let report = run_cleanup_at(root.path(), 1, false, future, &mut out, &mut err)?;

        assert_eq!(report.deleted_files, 0);
        assert!(report.skipped_dotfiles >= 2);
        assert!(gitignore.exists());
        assert!(htaccess.exists());
        assert!(env_file.exists());
        assert!(po_lock.exists());
        assert!(export_file.exists());

        let root_canon = fs::canonicalize(root.path())?;
        let env_canon = fs::canonicalize(&env_file)?;
        assert!(!is_whitelisted_subdir(&root_canon, &env_canon));
        let staging_canon =
            fs::canonicalize(root.path().join("storage/app/private/po-documents/staging"))?;
        assert!(is_whitelisted_subdir(&root_canon, &staging_canon));
        Ok(())
    }

    #[test]
    fn rejects_out_of_whitelist_traversal_and_ignores_symlinks_when_permitted(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let root = setup_root_with_subdirs()?;
        let outside = TempDir::new()?;
        let outside_secret = outside.path().join("secret.env");
        fs::write(&outside_secret, b"TOP_SECRET")?;

        let root_canon = fs::canonicalize(root.path())?;
        let cache_canon = fs::canonicalize(root.path().join("storage/framework/cache/data"))?;
        assert!(!is_safe_regular_file(&cache_canon, &outside_secret));
        assert!(!is_whitelisted_subdir(&root_canon, outside.path()));

        // Symlink tests on Windows may require Developer Mode / elevation; skip gracefully if OS denies symlink creation
        let link_file = root
            .path()
            .join("storage/framework/cache/data/evil_link.txt");
        if create_symlink_file(&outside_secret, &link_file).is_ok() {
            assert!(!is_safe_regular_file(&cache_canon, &link_file));
            let mut out = Vec::new();
            let mut err = Vec::new();
            let future = SystemTime::now() + Duration::from_secs(86_400);
            let report = run_cleanup_at(root.path(), 1, false, future, &mut out, &mut err)?;
            assert_eq!(report.deleted_files, 0);
            assert!(report.skipped_symlinks >= 1);
            assert!(
                outside_secret.exists(),
                "symlink target outside whitelist must remain intact"
            );
            assert!(String::from_utf8_lossy(&err).contains("[SECURITY-SKIP]"));
        }

        // Symlinked directory inside whitelist must NEVER be descended
        let link_dir = root.path().join("storage/framework/cache/data/evil_dir");
        if create_symlink_dir(outside.path(), &link_dir).is_ok() {
            let mut out = Vec::new();
            let mut err = Vec::new();
            let future = SystemTime::now() + Duration::from_secs(86_400);
            let report = run_cleanup_at(root.path(), 1, false, future, &mut out, &mut err)?;
            assert_eq!(report.deleted_files, 0);
            assert!(
                outside_secret.exists(),
                "files behind symlinked dir must remain intact"
            );
        }
        Ok(())
    }

    #[test]
    fn enforces_max_sweep_depth_bound_without_recursing_forever(
    ) -> Result<(), Box<dyn std::error::Error>> {
        let root = setup_root_with_subdirs()?;
        let mut nested = root.path().join("storage/framework/cache/data");
        for level in 0..(MAX_SWEEP_DEPTH + 4) {
            nested = nested.join(format!("d{level}"));
        }
        fs::create_dir_all(&nested)?;
        let deep_file = nested.join("too_deep.tmp");
        fs::write(&deep_file, b"keep-me-past-max-depth")?;

        let mut out = Vec::new();
        let mut err = Vec::new();
        let future = SystemTime::now() + Duration::from_secs(7200);
        let report = run_cleanup_at(root.path(), 1, false, future, &mut out, &mut err)?;

        assert_eq!(report.deleted_files, 0);
        assert!(
            deep_file.exists(),
            "file beyond MAX_SWEEP_DEPTH must not be touched"
        );
        let stderr_text = String::from_utf8_lossy(&err);
        assert!(stderr_text.contains("[SECURITY-SKIP]") || stderr_text.contains("max depth"));
        Ok(())
    }

    proptest! {
        #[test]
        fn effective_age_is_always_at_least_one_hour(requested_hours in 0_u64..10_000_u64) {
            let args = vec![
                String::from("inode-guard"),
                String::from("."),
                requested_hours.to_string(),
            ];
            let cfg = parse_cli_args(&args);
            prop_assert!(cfg.is_ok());
            if let Ok(parsed) = cfg {
                prop_assert!(parsed.max_age_hours >= 1);
                prop_assert_eq!(parsed.max_age_hours, requested_hours.max(1));
            }
        }
    }
}

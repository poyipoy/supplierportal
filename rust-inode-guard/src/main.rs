#![forbid(unsafe_code)]
#![deny(clippy::unwrap_used, clippy::expect_used, clippy::panic)]

use rust_inode_guard::{parse_cli_args, run_cleanup_at};
use std::env;
use std::io;
use std::process::ExitCode;
use std::time::SystemTime;

fn main() -> ExitCode {
    let args: Vec<String> = env::args().collect();
    let config = match parse_cli_args(&args) {
        Ok(cfg) => cfg,
        Err(err) => {
            eprintln!("Error: {err}");
            eprintln!("Usage: inode-guard <app_root_path> <max_age_hours> [--dry-run]");
            return ExitCode::from(2);
        }
    };

    let mut stdout = io::stdout().lock();
    let mut stderr = io::stderr().lock();

    match run_cleanup_at(
        &config.root,
        config.max_age_hours,
        config.dry_run,
        SystemTime::now(),
        &mut stdout,
        &mut stderr,
    ) {
        Ok(report) => {
            println!(
                "inode-guard done (dry_run={}, min_age_h={}): {} files cleaned ({} KB), {} symlinks skipped.",
                config.dry_run,
                report.effective_age_hours,
                report.deleted_files,
                report.freed_bytes / 1024,
                report.skipped_symlinks,
            );
            ExitCode::SUCCESS
        }
        Err(err) => {
            eprintln!("Error: {err}");
            ExitCode::from(2)
        }
    }
}

import boto3
from collections import Counter
from pathlib import Path

from botocore.exceptions import ClientError

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

BUCKET = "dipayan-transfer-bucket-269870119780-ca-central-1-an"
S3_TRANSFER_ROOT = "transfer"
PROJECT_ROOT = Path(__file__).resolve().parents[1]
LOCAL_DATA_ROOT = PROJECT_ROOT


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _as_path(p: str) -> Path:
    """Convert a user-supplied string path to a Path, normalizing backslashes first.
    Ensures Windows-style paths work correctly on Mac/Linux and vice versa."""
    return Path(p.replace("\\", "/"))


def _prefix(s3_dir: str, s3_root: str = S3_TRANSFER_ROOT) -> str:
    """Build the S3 key prefix: <s3_root>/<s3_dir>/"""
    p = _as_path(s3_root).as_posix().strip("/") + "/"
    if s3_dir:
        p += _as_path(s3_dir).as_posix().strip("/") + "/"
    return p


def _fmt_size(num_bytes: int) -> str:
    """Format a byte count as B / KB / MB / GB."""
    if num_bytes >= 1_000_000_000:
        return f"{num_bytes / 1_000_000_000:.2f} GB"
    if num_bytes >= 1_000_000:
        return f"{num_bytes / 1_000_000:.2f} MB"
    if num_bytes >= 1_000:
        return f"{num_bytes / 1_000:.1f} KB"
    return f"{num_bytes} B"


def _batch_delete(s3, bucket: str, keys: list[str]) -> None:
    """Delete S3 keys in batches of 1000 (S3 API limit per request)."""
    for i in range(0, len(keys), 1000):
        batch = [{"Key": k} for k in keys[i : i + 1000]]
        s3.delete_objects(Bucket=bucket, Delete={"Objects": batch})


def _list_objects(s3, bucket: str, prefix: str) -> list:
    """Return all S3 object metadata under prefix."""
    objects = []
    paginator = s3.get_paginator("list_objects_v2")
    for page in paginator.paginate(Bucket=bucket, Prefix=prefix):
        objects.extend(page.get("Contents", []))
    return objects


# ---------------------------------------------------------------------------
# Operations: list, upload, collect, download, delete
# ---------------------------------------------------------------------------

def list_folder(
    s3_dir: str = "",
    s3_root: str = S3_TRANSFER_ROOT,
    bucket: str = BUCKET,
    mode: str = "summary",
) -> None:
    """List objects under s3://<bucket>/<s3_root>/<s3_dir>/.

    mode='summary' (default): hierarchical folder tree with file counts and sizes.
    mode='detail'            : one line per file with size and last-modified date.
    """
    s3 = boto3.client("s3")
    prefix = _prefix(s3_dir, s3_root)
    objects = _list_objects(s3, bucket, prefix)

    if not objects:
        print(f"No objects found under s3://{bucket}/{prefix}")
        return

    total_bytes = sum(o["Size"] for o in objects)
    print(f"s3://{bucket}/{prefix}  ({len(objects)} files, {_fmt_size(total_bytes)} total)\n")

    if mode == "detail":
        for obj in sorted(objects, key=lambda o: o["Key"]):
            rel = obj["Key"][len(prefix):]
            print(f"  {rel:<60}  {_fmt_size(obj['Size']):>12}  {obj['LastModified'].strftime('%Y-%m-%d %H:%M')}")
        return

    # summary: build a nested dict tree with cumulative byte counts per node
    tree: dict = {}
    for obj in objects:
        rel = obj["Key"][len(prefix):]
        parts = rel.split("/")
        node = tree
        tree["_bytes"] = tree.get("_bytes", 0) + obj["Size"]
        for part in parts[:-1]:
            node = node.setdefault(part, {"_files": [], "_bytes": 0})
            node["_bytes"] += obj["Size"]
        node.setdefault("_files", []).append((parts[-1], obj["Size"]))

    def _has_files(node: dict) -> bool:
        return bool(node.get("_files")) or any(
            _has_files(v) for k, v in node.items() if not k.startswith("_")
        )

    def _print_tree(node: dict, indent: int = 0) -> None:
        pad = "  " * indent
        files = node.get("_files", [])
        if files:
            ext_counts = Counter(
                Path(name).suffix.lower() or "(no ext)" for name, _ in files
            )
            summary = ",  ".join(
                f"{c} {ext} file{'s' if c != 1 else ''}"
                for ext, c in sorted(ext_counts.items())
            )
            print(f"{pad}[ {summary} ]")
        for name, child in sorted((k, v) for k, v in node.items() if not k.startswith("_")):
            if not _has_files(child):
                continue
            print(f"{pad}{name}/  ({_fmt_size(child.get('_bytes', 0))})")
            _print_tree(child, indent + 1)

    _print_tree(tree)


def upload_folder(
    local_path: str,
    s3_dir: str | None = None,
    s3_root: str = S3_TRANSFER_ROOT,
    bucket: str = BUCKET,
    overwrite: bool = False,
) -> None:
    """Upload all files under <data>/<local_path> to s3://<bucket>/<s3_root>/<s3_dir>/.

    local_path: path relative to the local data root (data/).
    s3_dir    : destination under transfer/; defaults to local_path if None.
    overwrite=False (default): skip files that already exist in S3.
    overwrite=True            : upload and overwrite regardless.
    """
    s3 = boto3.client("s3")
    local_folder = LOCAL_DATA_ROOT / _as_path(local_path)
    prefix = _prefix(s3_dir if s3_dir is not None else local_path, s3_root)
    skipped = 0

    for file_path in sorted(local_folder.rglob("*")):
        if not file_path.is_file():
            continue
        if any(part.startswith(".") for part in file_path.parts):
            continue
        relative = file_path.relative_to(local_folder)
        s3_key = prefix + relative.as_posix()
        if not overwrite:
            try:
                s3.head_object(Bucket=bucket, Key=s3_key)
                skipped += 1
                continue
            except ClientError:
                pass
        print(f"Uploading {relative} -> s3://{bucket}/{s3_key}")
        s3.upload_file(str(file_path), bucket, s3_key)

    if skipped:
        print(f"Skipped {skipped} already-existing file(s). Use overwrite=True to replace them.")
    print("Upload complete.")


def collect_folder(
    s3_dir: str,
    local_path: str | None = None,
    s3_root: str = S3_TRANSFER_ROOT,
    bucket: str = BUCKET,
    overwrite: bool = False,
) -> None:
    """Download only new files from S3, then delete them from S3 after a successful download.

    s3_dir    : source folder under transfer/.
    local_path: destination relative to data/; defaults to s3_dir if None.
    overwrite=False (default): skip files that already exist locally.
    overwrite=True            : overwrite existing local files.
    Files that already exist locally (and overwrite=False) are left untouched in S3.
    """
    s3 = boto3.client("s3")
    prefix = _prefix(s3_dir, s3_root)
    local_dest = LOCAL_DATA_ROOT / _as_path(local_path if local_path is not None else s3_dir)
    objects = _list_objects(s3, bucket, prefix)

    downloaded_keys = []
    skipped = 0

    for obj in objects:
        s3_key = obj["Key"]
        relative = s3_key[len(prefix):]
        if not relative:
            continue
        dest_path = local_dest / _as_path(relative)
        if not overwrite and dest_path.exists():
            skipped += 1
            continue
        dest_path.parent.mkdir(parents=True, exist_ok=True)
        print(f"Downloading s3://{bucket}/{s3_key} -> {dest_path}")
        s3.download_file(bucket, s3_key, str(dest_path))
        downloaded_keys.append(s3_key)

    if skipped:
        print(f"Skipped {skipped} already-existing local file(s) (left in S3).")
    if not downloaded_keys:
        print("No new files to collect.")
        return

    print(f"\nDeleting {len(downloaded_keys)} downloaded file(s) from S3...")
    _batch_delete(s3, bucket, downloaded_keys)
    print(f"Collect complete. {len(downloaded_keys)} file(s) downloaded and removed from S3.")

    print("\nRemaining objects in S3:")
    list_folder()


def download_folder(
    s3_dir: str,
    local_path: str | None = None,
    s3_root: str = S3_TRANSFER_ROOT,
    bucket: str = BUCKET,
    overwrite: bool = False,
) -> None:
    """Download all objects under s3://<bucket>/<s3_root>/<s3_dir>/ to <data>/<local_path>/.

    s3_dir    : source folder under transfer/.
    local_path: destination relative to data/; defaults to s3_dir if None.
    overwrite=False (default): skip files that already exist locally.
    overwrite=True            : download and overwrite regardless.
    """
    s3 = boto3.client("s3")
    prefix = _prefix(s3_dir, s3_root)
    local_dest = LOCAL_DATA_ROOT / _as_path(local_path if local_path is not None else s3_dir)
    objects = _list_objects(s3, bucket, prefix)
    skipped = 0

    for obj in objects:
        s3_key = obj["Key"]
        relative = s3_key[len(prefix):]
        if not relative:
            continue
        dest_path = local_dest / _as_path(relative)
        if not overwrite and dest_path.exists():
            skipped += 1
            continue
        dest_path.parent.mkdir(parents=True, exist_ok=True)
        print(f"Downloading s3://{bucket}/{s3_key} -> {dest_path}")
        s3.download_file(bucket, s3_key, str(dest_path))

    if skipped:
        print(f"Skipped {skipped} already-existing file(s). Use overwrite=True to replace them.")
    print("Download complete.")


def delete_folder(
    s3_dir: str = "",
    s3_root: str = S3_TRANSFER_ROOT,
    bucket: str = BUCKET,
) -> None:
    """Delete all objects under s3://<bucket>/<s3_root>/<s3_dir>/ after confirmation."""
    s3 = boto3.client("s3")
    prefix = _prefix(s3_dir, s3_root)
    keys = [obj["Key"] for obj in _list_objects(s3, bucket, prefix)]

    if not keys:
        print(f"No objects found under s3://{bucket}/{prefix}")
        return

    print(f"About to delete {len(keys)} object(s) under s3://{bucket}/{prefix}")
    if input("Are you sure? Type 'yes' to confirm: ").strip().lower() != "yes":
        print("Deletion cancelled.")
        return

    _batch_delete(s3, bucket, keys)
    print(f"Deleted {len(keys)} object(s).")


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    # Set operation to one of: "list", "upload", "collect", "download", "delete"
    operation = "collect"

    # local_path: relative to data/  |  s3_dir: relative to transfer/ in S3
    # For upload : provide local_path; s3_dir defaults to local_path if omitted.
    # For download/collect: provide s3_dir; local_path defaults to s3_dir if omitted.
    local_path = "resources"    # relative to project root
    s3_dir = "resources"        # relative to transfer/

    if operation == "list":
        list_folder(s3_dir=s3_dir)
    elif operation == "upload":
        upload_folder(local_path, s3_dir=s3_dir, overwrite=False)
    elif operation == "collect":
        collect_folder(s3_dir=s3_dir, overwrite=False)
    elif operation == "download":
        download_folder(s3_dir=s3_dir, overwrite=False)
    elif operation == "delete":
        delete_folder(s3_dir=s3_dir)
    else:
        raise ValueError(f"Unknown operation '{operation}'. Must be one of: list, upload, collect, download, delete.")


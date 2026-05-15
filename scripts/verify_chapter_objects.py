#!/usr/bin/env python3
"""
verify_chapter_objects.py

Validate one chapter's object artifacts and render annotated slide images.

Checks:
- detection_data.json structure and bbox validity
- metadata.json selected/unselected integrity
- crop file existence and size consistency vs bbox-derived crop extents
- overlap/missing references between detections and metadata

Outputs:
- Annotated slide images in resources/temp/.../annotated_slides/
- report.json and report.txt in the same output root

Usage:
  python scripts/verify_chapter_objects.py \
      --chapter-dir resources/i12394/v9265/chapter6

If --chapter-dir is omitted, defaults to resources/i12394/v9265/chapter6.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

try:
    from PIL import Image, ImageDraw
    PIL_AVAILABLE = True
except Exception:
    PIL_AVAILABLE = False


PROJECT_ROOT = Path(__file__).resolve().parents[1]

# Hardcoded single-chapter configuration
CONFIG_INSTRUCTOR_ID = 12394
CONFIG_VIDEO_ID = 9265
CONFIG_CHAPTER_NUM = 6

# Optional: set to a custom absolute or project-relative output directory.
# Keep empty to write into resources/temp/i.../v.../chapter.../verify_YYYYMMDD_HHMMSS
CONFIG_OUTPUT_DIR = ""


@dataclass
class CropCheck:
    filename: str
    exists: bool
    expected_size: tuple[int, int]
    actual_size: tuple[int, int] | None
    width_diff: int | None
    height_diff: int | None


@dataclass
class SlideSummary:
    slide_name: str
    slide_exists: bool
    slide_size: tuple[int, int] | None
    coord_space: tuple[int, int]
    detection_count: int
    missing_crop_count: int
    mismatched_crop_count: int
    notes: list[str]


def load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def safe_int(value: Any, default: int = 0) -> int:
    try:
        return int(value)
    except Exception:
        return default


def parse_ids(chapter_dir: Path) -> tuple[str, str, str]:
    chapter = chapter_dir.name
    video = chapter_dir.parent.name
    instructor = chapter_dir.parent.parent.name
    return instructor, video, chapter


def chapter_output_root(chapter_dir: Path) -> Path:
    instructor, video, chapter = parse_ids(chapter_dir)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    return PROJECT_ROOT / "resources" / "temp" / instructor / video / chapter / f"verify_{stamp}"


def chapter_dir_from_config() -> Path:
    return (
        PROJECT_ROOT
        / "resources"
        / f"i{CONFIG_INSTRUCTOR_ID}"
        / f"v{CONFIG_VIDEO_ID}"
        / f"chapter{CONFIG_CHAPTER_NUM}"
    )


def list_image_files(folder: Path) -> list[str]:
    if not folder.is_dir():
        return []
    allowed = {".jpg", ".jpeg", ".png", ".webp"}
    return sorted(p.name for p in folder.iterdir() if p.is_file() and p.suffix.lower() in allowed)


def choose_coord_space(entry: dict[str, Any], slide_size: tuple[int, int] | None) -> tuple[int, int, list[str]]:
    notes: list[str] = []
    infer_w = safe_int(entry.get("inference_width"), 1280)
    infer_h = safe_int(entry.get("inference_height"), 720)

    detections = entry.get("detections") or []
    max_x2 = 0
    max_y2 = 0
    for det in detections:
        bbox = det.get("bbox_xyxy") or []
        if isinstance(bbox, list) and len(bbox) >= 4:
            x2 = safe_int(bbox[2])
            y2 = safe_int(bbox[3])
            max_x2 = max(max_x2, x2)
            max_y2 = max(max_y2, y2)

    coord_w, coord_h = infer_w, infer_h
    if slide_size is not None:
        sw, sh = slide_size
        if (max_x2 > infer_w or max_y2 > infer_h) and max_x2 <= sw and max_y2 <= sh:
            coord_w, coord_h = sw, sh
            notes.append(
                "bbox exceeds inference size; using slide size as coordinate space"
            )

    return coord_w, coord_h, notes


def map_bbox_to_slide_pixels(
    bbox: list[Any],
    coord_space: tuple[int, int],
    slide_size: tuple[int, int],
) -> tuple[int, int, int, int]:
    coord_w, coord_h = coord_space
    sw, sh = slide_size

    x1 = max(0, min(coord_w, safe_int(bbox[0])))
    y1 = max(0, min(coord_h, safe_int(bbox[1])))
    x2 = max(0, min(coord_w, safe_int(bbox[2])))
    y2 = max(0, min(coord_h, safe_int(bbox[3])))

    if x2 < x1:
        x1, x2 = x2, x1
    if y2 < y1:
        y1, y2 = y2, y1

    ax1 = max(0, min(sw, round((x1 / coord_w) * sw))) if coord_w > 0 else 0
    ay1 = max(0, min(sh, round((y1 / coord_h) * sh))) if coord_h > 0 else 0
    ax2 = max(0, min(sw, round((x2 / coord_w) * sw))) if coord_w > 0 else 0
    ay2 = max(0, min(sh, round((y2 / coord_h) * sh))) if coord_h > 0 else 0

    if ax2 < ax1:
        ax1, ax2 = ax2, ax1
    if ay2 < ay1:
        ay1, ay2 = ay2, ay1

    return ax1, ay1, ax2, ay2


def inspect_chapter(chapter_dir: Path, output_root: Path) -> dict[str, Any]:
    metadata_path = chapter_dir / "metadata.json"
    detection_path = chapter_dir / "detection_data.json"
    slides_dir = chapter_dir / "slides"
    visual_dir = chapter_dir / "visual_objects"

    report: dict[str, Any] = {
        "chapter_dir": str(chapter_dir),
        "timestamp": datetime.now().isoformat(timespec="seconds"),
        "paths": {
            "metadata": str(metadata_path),
            "detection_data": str(detection_path),
            "slides_dir": str(slides_dir),
            "visual_objects_dir": str(visual_dir),
            "output_root": str(output_root),
        },
        "errors": [],
        "warnings": [],
        "metadata_checks": {},
        "slides": [],
        "summary": {},
    }

    if not metadata_path.exists():
        report["errors"].append("metadata.json missing")
        return report
    if not detection_path.exists():
        report["errors"].append("detection_data.json missing")
        return report
    if not slides_dir.is_dir():
        report["errors"].append("slides directory missing")
        return report

    metadata = load_json(metadata_path)
    detection_data = load_json(detection_path)

    meta_slides = metadata.get("slides") or []
    if not isinstance(meta_slides, list):
        report["warnings"].append("metadata.slides is not a list; using detection keys")
        meta_slides = []

    slide_names: list[str] = []
    seen: set[str] = set()
    for name in list(meta_slides) + list(detection_data.keys()):
        if isinstance(name, str) and name not in seen:
            seen.add(name)
            slide_names.append(name)

    visual_meta = metadata.get("visual_objects") or {}
    selected = visual_meta.get("selected") or []
    unselected = visual_meta.get("unselected") or []

    if not isinstance(selected, list):
        selected = []
        report["warnings"].append("metadata.visual_objects.selected is not a list")
    if not isinstance(unselected, list):
        unselected = []
        report["warnings"].append("metadata.visual_objects.unselected is not a list")

    selected_set = set(x for x in selected if isinstance(x, str))
    unselected_set = set(x for x in unselected if isinstance(x, str))
    overlap = sorted(selected_set & unselected_set)

    disk_crops = list_image_files(visual_dir)
    disk_crop_set = set(disk_crops)

    missing_selected = sorted([f for f in selected_set if f not in disk_crop_set])
    missing_unselected = sorted([f for f in unselected_set if f not in disk_crop_set])

    report["metadata_checks"] = {
        "selected_count": len(selected_set),
        "unselected_count": len(unselected_set),
        "selected_unselected_overlap": overlap,
        "missing_selected_files": missing_selected,
        "missing_unselected_files": missing_unselected,
    }

    annotated_dir = output_root / "annotated_slides"
    annotated_dir.mkdir(parents=True, exist_ok=True)

    all_detection_output_files: set[str] = set()
    total_detections = 0
    total_missing_crops = 0
    total_mismatch_crops = 0

    for slide_name in slide_names:
        entry = detection_data.get(slide_name) or {}
        detections = entry.get("detections") or []
        if not isinstance(detections, list):
            detections = []

        slide_path = slides_dir / slide_name
        slide_exists = slide_path.exists()
        slide_size: tuple[int, int] | None = None
        slide_image = None
        draw = None

        notes: list[str] = []
        if slide_exists and PIL_AVAILABLE:
            try:
                slide_image = Image.open(slide_path).convert("RGB")
                slide_size = (slide_image.width, slide_image.height)
                draw = ImageDraw.Draw(slide_image)
            except Exception as exc:
                notes.append(f"failed to open slide image: {exc}")
                slide_image = None
                draw = None
        elif slide_exists and not PIL_AVAILABLE:
            notes.append("Pillow not available; cannot render annotations")
        else:
            notes.append("slide file missing")

        coord_w, coord_h, space_notes = choose_coord_space(entry, slide_size)
        notes.extend(space_notes)

        detection_rows: list[dict[str, Any]] = []
        missing_crops_for_slide = 0
        mismatched_crops_for_slide = 0

        for idx, det in enumerate(detections, start=1):
            total_detections += 1
            bbox = det.get("bbox_xyxy") or []
            out_name = str(det.get("output_filename") or "")
            if out_name:
                all_detection_output_files.add(out_name)

            det_row: dict[str, Any] = {
                "index": idx,
                "output_filename": out_name,
                "bbox_xyxy": bbox,
                "confidence": det.get("confidence"),
                "valid_bbox": isinstance(bbox, list) and len(bbox) >= 4,
                "mapped_bbox_slide_px": None,
                "crop_check": None,
            }

            if not (isinstance(bbox, list) and len(bbox) >= 4 and slide_size is not None):
                detection_rows.append(det_row)
                continue

            ax1, ay1, ax2, ay2 = map_bbox_to_slide_pixels(bbox, (coord_w, coord_h), slide_size)
            mapped = [ax1, ay1, ax2, ay2]
            det_row["mapped_bbox_slide_px"] = mapped

            expected_w = max(0, ax2 - ax1)
            expected_h = max(0, ay2 - ay1)

            crop_exists = out_name in disk_crop_set
            crop_actual_size: tuple[int, int] | None = None
            wdiff: int | None = None
            hdiff: int | None = None

            if crop_exists and PIL_AVAILABLE:
                try:
                    with Image.open(visual_dir / out_name) as crop_img:
                        crop_actual_size = (crop_img.width, crop_img.height)
                        wdiff = crop_img.width - expected_w
                        hdiff = crop_img.height - expected_h
                except Exception as exc:
                    notes.append(f"failed to open crop {out_name}: {exc}")
                    crop_exists = False

            if not crop_exists:
                missing_crops_for_slide += 1
                total_missing_crops += 1
            elif wdiff is not None and hdiff is not None and (abs(wdiff) > 2 or abs(hdiff) > 2):
                mismatched_crops_for_slide += 1
                total_mismatch_crops += 1

            crop_check = CropCheck(
                filename=out_name,
                exists=crop_exists,
                expected_size=(expected_w, expected_h),
                actual_size=crop_actual_size,
                width_diff=wdiff,
                height_diff=hdiff,
            )
            det_row["crop_check"] = {
                "filename": crop_check.filename,
                "exists": crop_check.exists,
                "expected_size": list(crop_check.expected_size),
                "actual_size": list(crop_check.actual_size) if crop_check.actual_size else None,
                "width_diff": crop_check.width_diff,
                "height_diff": crop_check.height_diff,
            }

            if draw is not None:
                color = "#16a34a"
                if not crop_exists:
                    color = "#dc2626"
                elif wdiff is not None and hdiff is not None and (abs(wdiff) > 2 or abs(hdiff) > 2):
                    color = "#d97706"

                draw.rectangle([(ax1, ay1), (ax2, ay2)], outline=color, width=3)
                label = f"{idx}:{out_name or 'no_name'}"
                draw.text((ax1 + 4, max(0, ay1 - 14)), label, fill=color)

            detection_rows.append(det_row)

        if slide_image is not None:
            out_img = annotated_dir / slide_name
            try:
                slide_image.save(out_img)
            except Exception as exc:
                notes.append(f"failed to save annotated image: {exc}")

        slide_summary = SlideSummary(
            slide_name=slide_name,
            slide_exists=slide_exists,
            slide_size=slide_size,
            coord_space=(coord_w, coord_h),
            detection_count=len(detections),
            missing_crop_count=missing_crops_for_slide,
            mismatched_crop_count=mismatched_crops_for_slide,
            notes=notes,
        )

        report["slides"].append({
            "slide_name": slide_summary.slide_name,
            "slide_exists": slide_summary.slide_exists,
            "slide_size": list(slide_summary.slide_size) if slide_summary.slide_size else None,
            "coord_space": list(slide_summary.coord_space),
            "detection_count": slide_summary.detection_count,
            "missing_crop_count": slide_summary.missing_crop_count,
            "mismatched_crop_count": slide_summary.mismatched_crop_count,
            "notes": slide_summary.notes,
            "detections": detection_rows,
        })

    referenced_set = selected_set | unselected_set
    missing_in_metadata = sorted([f for f in all_detection_output_files if f not in referenced_set])
    extra_on_disk = sorted([f for f in disk_crop_set if f not in referenced_set])

    report["summary"] = {
        "slide_count": len(slide_names),
        "total_detections": total_detections,
        "total_disk_crops": len(disk_crop_set),
        "missing_crops_from_detections": total_missing_crops,
        "mismatched_crop_sizes": total_mismatch_crops,
        "detection_files_not_in_metadata": missing_in_metadata,
        "disk_files_not_in_metadata": extra_on_disk,
        "annotated_output_dir": str(annotated_dir),
        "pillow_available": PIL_AVAILABLE,
    }

    return report


def write_text_report(report: dict[str, Any], out_path: Path) -> None:
    lines: list[str] = []
    lines.append("Chapter Verification Report")
    lines.append("=" * 28)
    lines.append(f"Chapter: {report.get('chapter_dir')}")
    lines.append(f"Generated: {report.get('timestamp')}")
    lines.append("")

    if report.get("errors"):
        lines.append("Errors:")
        for err in report["errors"]:
            lines.append(f"- {err}")
        lines.append("")

    if report.get("warnings"):
        lines.append("Warnings:")
        for warn in report["warnings"]:
            lines.append(f"- {warn}")
        lines.append("")

    summary = report.get("summary", {})
    if summary:
        lines.append("Summary:")
        lines.append(f"- Slides: {summary.get('slide_count', 0)}")
        lines.append(f"- Detections: {summary.get('total_detections', 0)}")
        lines.append(f"- Disk crop files: {summary.get('total_disk_crops', 0)}")
        lines.append(f"- Missing crops: {summary.get('missing_crops_from_detections', 0)}")
        lines.append(f"- Mismatched crop sizes: {summary.get('mismatched_crop_sizes', 0)}")
        lines.append(f"- Annotated slides: {summary.get('annotated_output_dir', '')}")
        lines.append("")

    meta = report.get("metadata_checks", {})
    if meta:
        lines.append("Metadata checks:")
        lines.append(f"- selected_count: {meta.get('selected_count', 0)}")
        lines.append(f"- unselected_count: {meta.get('unselected_count', 0)}")
        overlap = meta.get("selected_unselected_overlap", [])
        lines.append(f"- selected/unselected overlap: {len(overlap)}")
        if overlap:
            lines.extend([f"  * {x}" for x in overlap[:20]])
        lines.append("")

    for slide in report.get("slides", []):
        lines.append(f"Slide {slide.get('slide_name')}")
        lines.append(f"- exists: {slide.get('slide_exists')}")
        lines.append(f"- size: {slide.get('slide_size')}")
        lines.append(f"- coord_space: {slide.get('coord_space')}")
        lines.append(f"- detections: {slide.get('detection_count')} | missing_crops: {slide.get('missing_crop_count')} | mismatched: {slide.get('mismatched_crop_count')}")
        for note in slide.get("notes", []):
            lines.append(f"  note: {note}")
        lines.append("")

    out_path.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    chapter_dir = chapter_dir_from_config().resolve()

    if not chapter_dir.is_dir():
        raise SystemExit(f"Chapter directory not found: {chapter_dir}")

    output_root = (
        Path(CONFIG_OUTPUT_DIR).resolve()
        if CONFIG_OUTPUT_DIR
        else chapter_output_root(chapter_dir)
    )
    output_root.mkdir(parents=True, exist_ok=True)

    report = inspect_chapter(chapter_dir, output_root)

    report_json = output_root / "report.json"
    report_txt = output_root / "report.txt"
    report_json.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    write_text_report(report, report_txt)

    print(f"Report JSON : {report_json}")
    print(f"Report Text : {report_txt}")
    print(f"Annotated   : {output_root / 'annotated_slides'}")

    if not PIL_AVAILABLE:
        print("Pillow is not installed, so annotated images were not rendered.")
        print("Install with: pip install pillow")


if __name__ == "__main__":
    main()

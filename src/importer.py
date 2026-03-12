"""CSV importer for Pumpergy - handles IVT Anywhere II export format."""

import csv
from pathlib import Path
from typing import Optional

from .models import get_connection, init_db, DB_COLUMNS, DB_PATH

DATA_DIR = Path(__file__).parent.parent / "data"


def parse_value(val: str) -> Optional[float]:
    """Parse a CSV value, returning None for missing data."""
    if val in ('-', '', None):
        return None
    try:
        return float(val)
    except ValueError:
        return None


def import_csv(csv_path: Path, db_path: Optional[Path] = None) -> dict:
    """
    Import data from IVT Anywhere II CSV export.
    
    The CSV has a complex multi-row header structure:
    - Row 0: Empty, Empty, then category groups (ProducedEnergy, ConsumedEnergy, Sensors)
    - Row 1: Empty, Empty, then subcategories (Total, CentralHeating, etc.)
    - Row 2: category, timestamp, then measurement types (heatPump(kWh), etc.)
    - Row 3+: Data rows
    
    Returns dict with import statistics.
    """
    db_path = db_path or DB_PATH
    init_db(db_path)
    
    with open(csv_path, 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        rows = list(reader)
    
    if len(rows) < 4:
        raise ValueError("CSV file too short - expected at least 4 rows (3 header + 1 data)")
    
    # Parse multi-level headers
    header_row0 = rows[0]  # Category groups
    header_row1 = rows[1]  # Subcategories 
    header_row2 = rows[2]  # Measurement types
    
    # Build column mapping: csv_index -> db_column
    column_map = {}
    
    # First two columns are always category and timestamp
    column_map[0] = 'category'
    column_map[1] = 'timestamp'
    
    # Track current group from row 0
    current_group = ''
    current_subcat = ''
    
    for i in range(2, len(header_row2)):
        # Update group if specified in row 0
        if i < len(header_row0) and header_row0[i]:
            current_group = header_row0[i]
        
        # Update subcategory if specified in row 1
        if i < len(header_row1) and header_row1[i]:
            current_subcat = header_row1[i]
        
        measurement = header_row2[i] if i < len(header_row2) else ''
        
        # Map to database column
        # For sensors, subcategory is empty in our mapping
        if current_group == 'Sensors':
            key = (current_group, '', measurement)
        else:
            key = (current_group, current_subcat, measurement)
        
        # Look up in our predefined mapping
        if key in CSV_COLUMN_MAP:
            column_map[i] = CSV_COLUMN_MAP[key]
    
    # Import data rows
    conn = get_connection(db_path)
    cursor = conn.cursor()
    
    stats = {'inserted': 0, 'updated': 0, 'skipped': 0}
    
    for row_idx, row in enumerate(rows[3:], start=4):
        if len(row) < 2:
            stats['skipped'] += 1
            continue
            
        category = row[0]
        timestamp = row[1]
        
        if not category or not timestamp:
            stats['skipped'] += 1
            continue
        
        # Check if all data values are missing
        data_values = [parse_value(row[i]) for i in range(2, len(row)) if i in column_map]
        if all(v is None for v in data_values):
            stats['skipped'] += 1
            continue
        
        # Build record
        record = {col: None for col in DB_COLUMNS}
        record['category'] = category
        record['timestamp'] = timestamp
        
        for csv_idx, db_col in column_map.items():
            if csv_idx >= 2 and csv_idx < len(row):
                record[db_col] = parse_value(row[csv_idx])
        
        # Check if record exists and compare values
        cursor.execute(
            f"SELECT {', '.join(DB_COLUMNS[2:])} FROM energy_readings WHERE category = ? AND timestamp = ?",
            (category, timestamp)
        )
        existing = cursor.fetchone()
        
        if existing:
            # Compare existing values with new values
            # Normalize both to handle int/float differences (1 vs 1.0)
            new_values = tuple(record[col] for col in DB_COLUMNS[2:])
            existing_values = tuple(existing)
            
            def normalize(v):
                if v is None:
                    return None
                return float(v)
            
            if tuple(normalize(v) for v in existing_values) == tuple(normalize(v) for v in new_values):
                # No change, skip update
                stats['skipped'] += 1
                continue
            
            # Update existing record (values differ)
            set_clause = ', '.join(f"{col} = ?" for col in DB_COLUMNS[2:])
            values = [record[col] for col in DB_COLUMNS[2:]] + [category, timestamp]
            cursor.execute(
                f"UPDATE energy_readings SET {set_clause} WHERE category = ? AND timestamp = ?",
                values
            )
            stats['updated'] += 1
        else:
            # Insert new record
            placeholders = ', '.join(['?'] * len(DB_COLUMNS))
            columns = ', '.join(DB_COLUMNS)
            values = [record[col] for col in DB_COLUMNS]
            cursor.execute(
                f"INSERT INTO energy_readings ({columns}) VALUES ({placeholders})",
                values
            )
            stats['inserted'] += 1
    
    conn.commit()
    conn.close()
    
    return stats


# Import CSV_COLUMN_MAP here to avoid circular import issues
from .models import CSV_COLUMN_MAP


def import_all_csvs(data_dir: Path = None, db_path: Optional[Path] = None) -> dict:
    """
    Import all CSV files from the data directory.

    Successfully imported files are deleted afterwards.
    Returns aggregated stats plus per-file details.
    """
    data_dir = data_dir or DATA_DIR
    if not data_dir.is_dir():
        return {'inserted': 0, 'updated': 0, 'skipped': 0, 'files': []}

    csv_files = sorted(data_dir.glob("*.csv"))
    if not csv_files:
        return {'inserted': 0, 'updated': 0, 'skipped': 0, 'files': []}

    totals = {'inserted': 0, 'updated': 0, 'skipped': 0, 'files': []}

    for csv_path in csv_files:
        try:
            stats = import_csv(csv_path, db_path)
            totals['inserted'] += stats['inserted']
            totals['updated'] += stats['updated']
            totals['skipped'] += stats['skipped']
            totals['files'].append({'name': csv_path.name, 'status': 'ok', **stats})
            csv_path.unlink()
            print(f"Imported and deleted {csv_path.name}", flush=True)
        except Exception as e:
            print(f"Failed to import {csv_path.name}: {e}", flush=True)
            totals['files'].append({'name': csv_path.name, 'status': 'error', 'error': str(e)})

    return totals


def main():
    """CLI entry point for importing CSV."""
    import sys
    
    if len(sys.argv) < 2:
        csv_path = Path(__file__).parent.parent / "data.csv"
    else:
        csv_path = Path(sys.argv[1])
    
    if not csv_path.exists():
        print(f"Error: CSV file not found: {csv_path}")
        sys.exit(1)
    
    print(f"Importing from {csv_path}...")
    stats = import_csv(csv_path)
    print(f"Done! Inserted: {stats['inserted']}, Updated: {stats['updated']}, Skipped: {stats['skipped']}")


if __name__ == "__main__":
    main()

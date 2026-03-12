"""Database models and schema for Pumpergy."""

import sqlite3
from pathlib import Path
from typing import Optional

DB_PATH = Path(__file__).parent.parent / "pumpergy.db"

SCHEMA = """
CREATE TABLE IF NOT EXISTS energy_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,           -- 'hour', 'day', 'month'
    timestamp TEXT NOT NULL,          -- ISO format
    
    -- ProducedEnergy heatPump (kWh)
    prod_total_hp REAL,
    prod_ch_hp REAL,          -- Central Heating
    prod_cooling_hp REAL,
    prod_hw_hp REAL,          -- Hot Water
    
    -- ProducedEnergy environment (kWh)
    prod_total_env REAL,
    prod_ch_env REAL,
    prod_cooling_env REAL,
    prod_hw_env REAL,
    
    -- ConsumedEnergy heatPump (kWh)
    cons_total_hp REAL,
    cons_ch_hp REAL,
    cons_cooling_hp REAL,
    cons_hw_hp REAL,
    
    -- ConsumedEnergy auxiliaryHeater (kWh)
    cons_total_aux REAL,
    cons_ch_aux REAL,
    cons_hw_aux REAL,
    
    -- Sensors
    outdoor_temp REAL,        -- °C
    flow_temp REAL,           -- °C
    room_temp REAL,           -- °C
    hw_temp REAL,             -- Hot water °C
    
    UNIQUE(category, timestamp)
);

CREATE INDEX IF NOT EXISTS idx_category ON energy_readings(category);
CREATE INDEX IF NOT EXISTS idx_timestamp ON energy_readings(timestamp);
CREATE INDEX IF NOT EXISTS idx_category_timestamp ON energy_readings(category, timestamp);

CREATE TABLE IF NOT EXISTS annotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp TEXT NOT NULL,          -- Start time (ISO format)
    duration_hours REAL DEFAULT 0,    -- Duration in hours (0 = point in time)
    icon TEXT NOT NULL,               -- Icon identifier
    text TEXT NOT NULL,               -- User's annotation text
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_annotations_timestamp ON annotations(timestamp);

CREATE TABLE IF NOT EXISTS handled_aux_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    event_start TEXT NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    handled_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(category, event_start)
);

CREATE INDEX IF NOT EXISTS idx_handled_aux_events ON handled_aux_events(category, event_start);
"""

# Available annotation icons
ANNOTATION_ICONS = {
    'fuse': ('⚡', 'Fuse/Electrical issue'),
    'cold': ('🥶', 'Very cold day'),
    'hot': ('🥵', 'Very hot day'),
    'shower': ('🚿', 'Extra hot water usage'),
    'manual': ('🔧', 'Manual intervention'),
    'maintenance': ('🛠️', 'Maintenance'),
    'vacation': ('🏖️', 'Away/Vacation'),
    'guests': ('👥', 'Extra guests'),
    'error': ('❌', 'Error/Malfunction'),
    'note': ('📝', 'General note'),
    'question': ('❓', 'Investigate'),
}


def get_connection(db_path: Optional[Path] = None) -> sqlite3.Connection:
    """Get a database connection."""
    path = db_path or DB_PATH
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    return conn


def init_db(db_path: Optional[Path] = None) -> None:
    """Initialize the database with schema."""
    conn = get_connection(db_path)
    conn.executescript(SCHEMA)
    conn.commit()
    conn.close()


# Column mapping from CSV to database
CSV_COLUMN_MAP = {
    # ProducedEnergy heatPump
    ('ProducedEnergy', 'Total', 'heatPump(kWh)'): 'prod_total_hp',
    ('ProducedEnergy', 'CentralHeating', 'heatPump(kWh)'): 'prod_ch_hp',
    ('ProducedEnergy', 'Cooling', 'heatPump(kWh)'): 'prod_cooling_hp',
    ('ProducedEnergy', 'HotWater', 'heatPump(kWh)'): 'prod_hw_hp',
    
    # ProducedEnergy environment
    ('ProducedEnergy', 'Total', 'environment(kWh)'): 'prod_total_env',
    ('ProducedEnergy', 'CentralHeating', 'environment(kWh)'): 'prod_ch_env',
    ('ProducedEnergy', 'Cooling', 'environment(kWh)'): 'prod_cooling_env',
    ('ProducedEnergy', 'HotWater', 'environment(kWh)'): 'prod_hw_env',
    
    # ConsumedEnergy heatPump
    ('ConsumedEnergy', 'Total', 'heatPump(kWh)'): 'cons_total_hp',
    ('ConsumedEnergy', 'CentralHeating', 'heatPump(kWh)'): 'cons_ch_hp',
    ('ConsumedEnergy', 'Cooling', 'heatPump(kWh)'): 'cons_cooling_hp',
    ('ConsumedEnergy', 'HotWater', 'heatPump(kWh)'): 'cons_hw_hp',
    
    # ConsumedEnergy auxiliaryHeater
    ('ConsumedEnergy', 'Total', 'auxiliaryHeater(kWh)'): 'cons_total_aux',
    ('ConsumedEnergy', 'CentralHeating', 'auxiliaryHeater(kWh)'): 'cons_ch_aux',
    ('ConsumedEnergy', 'HotWater', 'auxiliaryHeater(kWh)'): 'cons_hw_aux',
    
    # Sensors
    ('Sensors', '', 'outdoorTemperature(C)'): 'outdoor_temp',
    ('Sensors', '', 'flowTemperature(C)'): 'flow_temp',
    ('Sensors', '', 'roomTemperature(C)'): 'room_temp',
    ('Sensors', '', 'hotWaterTemperature(C)'): 'hw_temp',
}

DB_COLUMNS = [
    'category', 'timestamp',
    'prod_total_hp', 'prod_ch_hp', 'prod_cooling_hp', 'prod_hw_hp',
    'prod_total_env', 'prod_ch_env', 'prod_cooling_env', 'prod_hw_env',
    'cons_total_hp', 'cons_ch_hp', 'cons_cooling_hp', 'cons_hw_hp',
    'cons_total_aux', 'cons_ch_aux', 'cons_hw_aux',
    'outdoor_temp', 'flow_temp', 'room_temp', 'hw_temp'
]


# Annotation CRUD functions
def add_annotation(timestamp: str, icon: str, text: str, duration_hours: float = 0, db_path: Optional[Path] = None) -> int:
    """Add a new annotation. Returns the new annotation ID."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    cursor.execute(
        "INSERT INTO annotations (timestamp, icon, text, duration_hours) VALUES (?, ?, ?, ?)",
        (timestamp, icon, text, duration_hours)
    )
    conn.commit()
    annotation_id = cursor.lastrowid
    conn.close()
    return annotation_id


def update_annotation(annotation_id: int, timestamp: str, icon: str, text: str, duration_hours: float = 0, db_path: Optional[Path] = None) -> None:
    """Update an existing annotation."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    cursor.execute(
        "UPDATE annotations SET timestamp = ?, icon = ?, text = ?, duration_hours = ? WHERE id = ?",
        (timestamp, icon, text, duration_hours, annotation_id)
    )
    conn.commit()
    conn.close()


def delete_annotation(annotation_id: int, db_path: Optional[Path] = None) -> None:
    """Delete an annotation."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    cursor.execute("DELETE FROM annotations WHERE id = ?", (annotation_id,))
    conn.commit()
    conn.close()


def get_annotations(start_date: str = None, end_date: str = None, db_path: Optional[Path] = None) -> list:
    """Get annotations, optionally filtered by date range."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    
    query = "SELECT * FROM annotations WHERE 1=1"
    params = []
    
    if start_date:
        query += " AND timestamp >= ?"
        params.append(start_date)
    
    if end_date:
        query += " AND timestamp <= ?"
        params.append(end_date + 'Z')
    
    query += " ORDER BY timestamp"
    
    cursor.execute(query, params)
    rows = cursor.fetchall()
    conn.close()
    
    return [dict(row) for row in rows]


def mark_aux_event_handled(category: str, event_start: str, note: str, db_path: Optional[Path] = None) -> int:
    """Mark an aux heater event as handled. Returns the record ID."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    cursor.execute(
        "INSERT OR REPLACE INTO handled_aux_events (category, event_start, note) VALUES (?, ?, ?)",
        (category, event_start, note)
    )
    conn.commit()
    record_id = cursor.lastrowid
    conn.close()
    return record_id


def unmark_aux_event_handled(handled_id: int, db_path: Optional[Path] = None) -> None:
    """Remove the handled mark from an aux heater event."""
    conn = get_connection(db_path)
    cursor = conn.cursor()
    cursor.execute("DELETE FROM handled_aux_events WHERE id = ?", (handled_id,))
    conn.commit()
    conn.close()


def get_handled_aux_events(category: str = None, db_path: Optional[Path] = None) -> list:
    """Get handled aux heater events, optionally filtered by category."""
    conn = get_connection(db_path)
    cursor = conn.cursor()

    if category:
        cursor.execute(
            "SELECT * FROM handled_aux_events WHERE category = ? ORDER BY event_start",
            (category,)
        )
    else:
        cursor.execute("SELECT * FROM handled_aux_events ORDER BY event_start")

    rows = cursor.fetchall()
    conn.close()
    return [dict(row) for row in rows]

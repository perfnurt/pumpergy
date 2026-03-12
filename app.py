"""Pumpergy - Heat Pump Energy Dashboard."""

import sqlite3
from datetime import datetime, timedelta

import pandas as pd
import plotly.express as px
import plotly.graph_objects as go
from plotly.subplots import make_subplots
import streamlit as st

from src.models import (
    get_connection, init_db, DB_PATH,
    add_annotation, update_annotation, delete_annotation, get_annotations, ANNOTATION_ICONS,
    mark_aux_event_handled, unmark_aux_event_handled, get_handled_aux_events
)
from src.importer import import_all_csvs, DATA_DIR

# Page config
st.set_page_config(
    page_title="Pumpergy - Heat Pump Dashboard",
    page_icon="🔥",
    layout="wide"
)

# Initialize database (creates tables if needed)
init_db()

st.title("🔥 Pumpergy - Heat Pump Energy Dashboard")


def load_data(category: str = None, start_date: str = None, end_date: str = None) -> pd.DataFrame:
    """Load data from database with optional filters."""
    conn = get_connection()
    
    query = "SELECT * FROM energy_readings WHERE 1=1"
    params = []
    
    if category:
        query += " AND category = ?"
        params.append(category)
    
    if start_date:
        if category == 'month':
            # For monthly data, compare year-month only (timestamps are like '2026-02')
            query += " AND timestamp >= ?"
            params.append(start_date[:7])  # Just YYYY-MM
        else:
            query += " AND timestamp >= ?"
            params.append(start_date)
    
    if end_date:
        if category == 'month':
            # For monthly data, compare year-month only
            query += " AND timestamp <= ?"
            params.append(end_date[:7])  # Just YYYY-MM
        else:
            query += " AND timestamp <= ?"
            params.append(end_date + 'Z')  # Include full day
    
    query += " ORDER BY timestamp"
    
    df = pd.read_sql_query(query, conn, params=params)
    conn.close()
    
    if not df.empty:
        df['timestamp'] = pd.to_datetime(df['timestamp'])
    
    return df


def check_db_exists():
    """Check if database exists and has data."""
    if not DB_PATH.exists():
        return False
    conn = get_connection()
    cursor = conn.cursor()
    cursor.execute("SELECT COUNT(*) FROM energy_readings")
    count = cursor.fetchone()[0]
    conn.close()
    return count > 0


# Auto-import CSV files from data/ folder at startup
if 'import_done' not in st.session_state:
    st.session_state.import_done = True
    if DATA_DIR.is_dir() and any(DATA_DIR.glob("*.csv")):
        with st.spinner("Importing CSV files..."):
            stats = import_all_csvs()
        if stats['inserted'] or stats['updated']:
            msg = f"✅ Imported {len([f for f in stats['files'] if f['status'] == 'ok'])} file(s): {stats['inserted']} inserted, {stats['updated']} updated"
            st.toast(msg, icon="📥")
        errors = [f for f in stats['files'] if f['status'] == 'error']
        for f in errors:
            st.toast(f"❌ Failed: {f['name']}: {f['error']}", icon="⚠️")

# Sidebar - Filters
with st.sidebar:
    st.header("🔍 Filters")
    
    category = st.selectbox(
        "Time Resolution",
        options=["hour", "day", "month"],
        index=0,  # Default to 'hour'
        format_func=lambda x: {"hour": "Hourly", "day": "Daily", "month": "Monthly"}[x]
    )
    
    # Date range
    col1, col2 = st.columns(2)
    with col1:
        start_date = st.date_input("Start Date", value=datetime(2026, 2, 1))
    with col2:
        end_date = st.date_input("End Date", value=datetime(2026, 3, 31))
    
    st.divider()
    st.header("🦠 Legionella Schedule")
    st.caption("Hot water is heated extra to prevent legionella bacteria growth")
    
    WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
    legionella_day = st.selectbox(
        "Scheduled Day",
        options=list(range(7)),
        index=1,  # Tuesday
        format_func=lambda x: WEEKDAYS[x]
    )
    legionella_hour = st.selectbox(
        "Scheduled Hour",
        options=list(range(24)),
        index=2,  # 2 AM
        format_func=lambda x: f"{x:02d}:00"
    )
    
    st.divider()
    st.header("📝 Annotations")
    
    # Add new annotation
    with st.expander("➕ Add Annotation", expanded=False):
        ann_date = st.date_input("Date", value=datetime.now(), key="ann_date")
        ann_time = st.time_input("Time", value=datetime.now().time(), key="ann_time")
        
        icon_options = list(ANNOTATION_ICONS.keys())
        ann_icon = st.selectbox(
            "Icon",
            options=icon_options,
            format_func=lambda x: f"{ANNOTATION_ICONS[x][0]} {ANNOTATION_ICONS[x][1]}"
        )
        
        ann_duration = st.number_input("Duration (hours)", min_value=0.0, max_value=168.0, value=0.0, step=1.0,
                                       help="0 = point in time, otherwise duration in hours")
        ann_text = st.text_area("Note (optional)", placeholder="What happened?")
        
        if st.button("Add Annotation", type="primary"):
            timestamp = datetime.combine(ann_date, ann_time).isoformat()
            add_annotation(timestamp, ann_icon, ann_text.strip(), ann_duration)
            st.success("✅ Annotation added!")
            st.rerun()
    
    # List existing annotations
    all_annotations = get_annotations(start_date.isoformat(), end_date.isoformat())
    if all_annotations:
        st.caption(f"{len(all_annotations)} annotation(s) in selected period")
        for ann in all_annotations:
            icon_emoji = ANNOTATION_ICONS.get(ann['icon'], ('📝', 'Note'))[0]
            ann_ts = datetime.fromisoformat(ann['timestamp'])
            duration_str = f" ({ann['duration_hours']}h)" if ann['duration_hours'] > 0 else ""
            
            col1, col2, col3 = st.columns([4, 1, 1])
            with col1:
                st.markdown(f"{icon_emoji} **{ann_ts.strftime('%Y-%m-%d %H:%M')}**{duration_str}: {ann['text']}")
            with col2:
                if st.button("✏️", key=f"edit_{ann['id']}", help="Edit annotation"):
                    st.session_state[f"editing_{ann['id']}"] = True
            with col3:
                if st.button("🗑️", key=f"del_{ann['id']}", help="Delete annotation"):
                    delete_annotation(ann['id'])
                    st.rerun()
            
            # Edit form (shown when editing)
            if st.session_state.get(f"editing_{ann['id']}", False):
                with st.container():
                    edit_date = st.date_input("Date", value=ann_ts.date(), key=f"edit_date_{ann['id']}")
                    edit_time = st.time_input("Time", value=ann_ts.time(), key=f"edit_time_{ann['id']}")
                    
                    icon_options = list(ANNOTATION_ICONS.keys())
                    current_icon_idx = icon_options.index(ann['icon']) if ann['icon'] in icon_options else 0
                    edit_icon = st.selectbox(
                        "Icon",
                        options=icon_options,
                        index=current_icon_idx,
                        format_func=lambda x: f"{ANNOTATION_ICONS[x][0]} {ANNOTATION_ICONS[x][1]}",
                        key=f"edit_icon_{ann['id']}"
                    )
                    
                    edit_duration = st.number_input("Duration (hours)", min_value=0.0, max_value=168.0, 
                                                    value=float(ann['duration_hours']), step=1.0,
                                                    key=f"edit_duration_{ann['id']}")
                    edit_text = st.text_area("Note (optional)", value=ann['text'], key=f"edit_text_{ann['id']}")
                    
                    col_save, col_cancel = st.columns(2)
                    with col_save:
                        if st.button("💾 Save", key=f"save_{ann['id']}", type="primary"):
                            new_timestamp = datetime.combine(edit_date, edit_time).isoformat()
                            update_annotation(ann['id'], new_timestamp, edit_icon, edit_text.strip(), edit_duration)
                            st.session_state[f"editing_{ann['id']}"] = False
                            st.rerun()
                    with col_cancel:
                        if st.button("Cancel", key=f"cancel_{ann['id']}"):
                            st.session_state[f"editing_{ann['id']}"] = False
                            st.rerun()
                    st.divider()


# Main content
if not check_db_exists():
    st.warning("⚠️ No data in database. Place CSV export files in the `data/` folder and restart.")
    st.stop()

# Load filtered data
df = load_data(
    category=category,
    start_date=start_date.isoformat(),
    end_date=end_date.isoformat()
)

if df.empty:
    st.info("No data available for the selected filters.")
    st.stop()

# Detect gaps in data
def find_gaps(df, category):
    """Find time gaps in the data that exceed the expected interval."""
    if len(df) < 2:
        return []
    expected_delta = {
        'hour': timedelta(hours=1),
        'day': timedelta(days=1),
        'month': timedelta(days=28),  # Approximate; checked loosely
    }[category]
    # Allow some tolerance
    threshold = expected_delta * 2
    gaps = []
    timestamps = df['timestamp'].sort_values()
    for i in range(1, len(timestamps)):
        delta = timestamps.iloc[i] - timestamps.iloc[i - 1]
        if delta > threshold:
            gaps.append((timestamps.iloc[i - 1], timestamps.iloc[i]))
    return gaps

data_gaps = find_gaps(df, category)

# Metrics row
st.subheader("📊 Summary Metrics")
col1, col2 = st.columns(2)

with col1:
    total_consumed = df['cons_total_hp'].sum() + df['cons_total_aux'].sum()
    st.metric("Total Consumed", f"{total_consumed:.0f} kWh")

with col2:
    aux_total = df['cons_total_aux'].sum()
    st.metric("Auxiliary Heater", f"{aux_total:.0f} kWh")

# Charts
st.divider()

# Energy Consumption Chart
st.subheader("⚡ Energy Consumption Over Time")

fig_consumption = make_subplots(specs=[[{"secondary_y": True}]])

# Prepare temperature line series.
temperature_columns = [
    ("outdoor_temp", "Outdoor Temp", "#2A9D8F"),
    ("flow_temp", "Flow Temp", "#264653"),
    ("hw_temp", "Hot Water Temp", "#F4A261"),
]
temperature_series = []
for col_name, label, color in temperature_columns:
    if col_name in df.columns and df[col_name].notna().any():
        series = df[col_name]
        series_label = label
        temperature_series.append((series, series_label, color))

fig_consumption.add_trace(go.Bar(
    x=df['timestamp'],
    y=df['cons_total_hp'],
    name='Heat Pump',
    marker_color='#2E86AB'
), secondary_y=False)

fig_consumption.add_trace(go.Bar(
    x=df['timestamp'],
    y=df['cons_total_aux'],
    name='Auxiliary Heater',
    marker_color='#E94F37'
), secondary_y=False)

for series, label, color in temperature_series:
    fig_consumption.add_trace(go.Scatter(
        x=df['timestamp'],
        y=series,
        name=label,
        mode='lines+markers',
        line=dict(color=color, width=2),
        marker=dict(size=4, color=color),
        opacity=0.9
    ), secondary_y=True)

# Add legionella schedule markers for hourly/daily views
if category in ['hour', 'day']:
    # Find timestamps matching the legionella schedule
    legionella_times = []
    for ts in df['timestamp']:
        if category == 'hour':
            # Match exact day and hour
            if ts.weekday() == legionella_day and ts.hour == legionella_hour:
                legionella_times.append(ts.to_pydatetime())
        elif category == 'day':
            # Match the day of week
            if ts.weekday() == legionella_day:
                legionella_times.append(ts.to_pydatetime())
    
    # Add vertical lines for each legionella schedule time
    for ts in legionella_times:
        fig_consumption.add_shape(
            type="line",
            x0=ts, x1=ts,
            y0=0, y1=1,
            yref="paper",
            line=dict(color="#9B59B6", width=2, dash="dash")
        )
        # Add annotation separately
        fig_consumption.add_annotation(
            x=ts,
            y=1,
            yref="paper",
            text="🦠",
            showarrow=False,
            font=dict(size=14),
            yshift=10
        )
    
    if legionella_times:
        # Add invisible trace for legend entry
        fig_consumption.add_trace(go.Scatter(
            x=[None], y=[None],
            mode='lines',
            name=f'Legionella ({WEEKDAYS[legionella_day][:3]} {legionella_hour:02d}:00)',
            line=dict(color='#9B59B6', dash='dash', width=2),
            showlegend=True
        ))

# Add user annotations to chart
for ann in all_annotations:
    ann_ts = datetime.fromisoformat(ann['timestamp'])
    icon_emoji = ANNOTATION_ICONS.get(ann['icon'], ('📝', 'Note'))[0]
    icon_desc = ANNOTATION_ICONS.get(ann['icon'], ('📝', 'Note'))[1]
    
    # Add vertical line at annotation time
    fig_consumption.add_shape(
        type="line",
        x0=ann_ts, x1=ann_ts,
        y0=0, y1=1,
        yref="paper",
        line=dict(color="#E67E22", width=2, dash="dot")
    )
    
    # If there's duration, add a shaded region
    if ann['duration_hours'] > 0:
        end_ts = ann_ts + timedelta(hours=ann['duration_hours'])
        fig_consumption.add_vrect(
            x0=ann_ts, x1=end_ts,
            fillcolor="#E67E22",
            opacity=0.1,
            line_width=0
        )
    
    # Add annotation marker with hover text
    fig_consumption.add_annotation(
        x=ann_ts,
        y=1,
        yref="paper",
        text=icon_emoji,
        showarrow=False,
        font=dict(size=16),
        yshift=25,
        hovertext=f"{icon_desc}: {ann['text']}"
    )

# Add legend entry for user annotations if any exist
if all_annotations:
    fig_consumption.add_trace(go.Scatter(
        x=[None], y=[None],
        mode='lines',
        name='User Annotations',
        line=dict(color='#E67E22', dash='dot', width=2),
        showlegend=True
    ))

# Shade data gaps
for gap_start, gap_end in data_gaps:
    fig_consumption.add_vrect(
        x0=gap_start, x1=gap_end,
        fillcolor="gray", opacity=0.15, line_width=0,
        annotation_text="no data" if (gap_end - gap_start) > timedelta(hours=6) else "",
        annotation_position="top",
        annotation_font_color="gray",
    )

if data_gaps:
    fig_consumption.add_trace(go.Scatter(
        x=[None], y=[None], mode='lines',
        name='Data Gap', line=dict(color='gray', width=8), opacity=0.3,
        showlegend=True
    ))

fig_consumption.update_layout(
    barmode='stack',
    xaxis_title='Time',
    yaxis_title='Energy (kWh)',
    legend=dict(orientation='h', yanchor='bottom', y=1.02),
    height=450,
    margin=dict(t=60)  # Extra top margin for annotation icons
)

fig_consumption.update_yaxes(title_text='Energy (kWh)', secondary_y=False)
fig_consumption.update_yaxes(title_text='Temperature (°C)', secondary_y=True)

st.plotly_chart(fig_consumption, width='stretch')

# Two column layout for additional charts
col_left, col_right = st.columns(2)

with col_left:
    st.subheader("🌡️ Temperature vs Consumption")
    
    # Filter out rows with no temperature data
    temp_df = df[df['outdoor_temp'].notna()].copy()
    
    if not temp_df.empty:
        fig_temp = px.scatter(
            temp_df,
            x='outdoor_temp',
            y='cons_total_hp',
            color='cons_total_aux',
            color_continuous_scale='Reds',
            labels={
                'outdoor_temp': 'Outdoor Temperature (°C)',
                'cons_total_hp': 'Heat Pump Consumption (kWh)',
                'cons_total_aux': 'Aux Heater (kWh)'
            },
            height=350
        )
        fig_temp.update_traces(marker=dict(size=10))
        st.plotly_chart(fig_temp, width='stretch')
    else:
        st.info("No temperature data available")

with col_right:
    st.subheader("🔥 Energy Breakdown by Type")
    
    breakdown_data = {
        'Type': ['Central Heating (HP)', 'Hot Water (HP)', 'Central Heating (Aux)', 'Hot Water (Aux)'],
        'Energy': [
            df['cons_ch_hp'].sum(),
            df['cons_hw_hp'].sum(),
            df['cons_ch_aux'].sum(),
            df['cons_hw_aux'].sum()
        ]
    }
    breakdown_df = pd.DataFrame(breakdown_data)
    breakdown_df = breakdown_df[breakdown_df['Energy'] > 0]
    
    if not breakdown_df.empty:
        fig_breakdown = px.pie(
            breakdown_df,
            values='Energy',
            names='Type',
            color_discrete_sequence=px.colors.qualitative.Set2,
            height=350
        )
        st.plotly_chart(fig_breakdown, width='stretch')
    else:
        st.info("No breakdown data available")

# Auxiliary Heater Analysis
st.divider()
st.subheader("⚠️ Auxiliary Heater Analysis")

# Display expected schedule info
WEEKDAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"]
st.info(f"""**Expected Schedule:** The auxiliary heater is scheduled to run on **{WEEKDAYS[legionella_day]}s around {legionella_hour:02d}:00** 
for legionella prevention (extra hot water heating to 60°C+). Usage outside this window may indicate:
- Very cold outdoor temperatures requiring backup heating
- Heat pump malfunction or insufficient capacity
- Manual override or other scheduled heating""")

# Find all times when auxiliary heater was active
aux_active = df[df['cons_total_aux'] > 0].copy()

if aux_active.empty:
    st.success("✅ No auxiliary heater usage in selected period!")
else:
    # Check for unexpected usage (outside scheduled window)
    aux_active['hour'] = aux_active['timestamp'].dt.hour
    aux_active['weekday'] = aux_active['timestamp'].dt.weekday  # 0=Monday, 1=Tuesday, etc.
    
    # Expected logic depends on time resolution
    hour_min = max(0, legionella_hour - 1)
    hour_max = min(23, legionella_hour + 2)
    
    if category == 'hour':
        # For hourly: must match day AND be within hour window
        aux_active['expected'] = (aux_active['weekday'] == legionella_day) & (aux_active['hour'].between(hour_min, hour_max))
    else:
        # For daily/monthly: just match the day of week (can't check hour precision)
        aux_active['expected'] = (aux_active['weekday'] == legionella_day)
    
    # Group consecutive timestamps into events
    aux_active = aux_active.sort_values('timestamp').reset_index(drop=True)
    
    events = []
    current_event = None
    
    for idx, row in aux_active.iterrows():
        ts = row['timestamp'].to_pydatetime()
        
        # Check if this should be part of current event
        should_group = False
        if current_event is not None:
            if category == 'hour':
                # For hourly data, consecutive means within ~1 hour
                should_group = (ts - current_event['end']) <= timedelta(hours=1, minutes=30)
            elif category == 'day':
                # For daily data, don't group days - each day is its own event
                should_group = False
            else:
                # For monthly data, consecutive means next month
                should_group = (ts - current_event['end']) <= timedelta(days=45)
        
        if current_event is None:
            # Start new event
            current_event = {
                'start': ts,
                'end': ts,
                'total_kwh': row['cons_total_aux'] or 0,
                'ch_kwh': row['cons_ch_aux'] or 0,
                'hw_kwh': row['cons_hw_aux'] or 0,
                'outdoor_temps': [row['outdoor_temp']] if pd.notna(row['outdoor_temp']) else [],
                'expected': row['expected'],
            }
        elif should_group:
            # Continue current event
            current_event['end'] = ts
            current_event['total_kwh'] += row['cons_total_aux'] or 0
            current_event['ch_kwh'] += row['cons_ch_aux'] or 0
            current_event['hw_kwh'] += row['cons_hw_aux'] or 0
            if pd.notna(row['outdoor_temp']):
                current_event['outdoor_temps'].append(row['outdoor_temp'])
            # Event is only expected if ALL periods are expected
            current_event['expected'] = current_event['expected'] and row['expected']
        else:
            # Save current event and start new one
            events.append(current_event)
            current_event = {
                'start': ts,
                'end': ts,
                'total_kwh': row['cons_total_aux'] or 0,
                'ch_kwh': row['cons_ch_aux'] or 0,
                'hw_kwh': row['cons_hw_aux'] or 0,
                'outdoor_temps': [row['outdoor_temp']] if pd.notna(row['outdoor_temp']) else [],
                'expected': row['expected'],
            }
    
    # Don't forget the last event
    if current_event:
        events.append(current_event)
    
    # Load which events were marked as handled and attach status to each event.
    handled_map = {
        row['event_start']: {'id': row['id'], 'note': row['note']}
        for row in get_handled_aux_events(category)
    }
    for e in events:
        event_key = e['start'].isoformat()
        if event_key in handled_map:
            e['handled'] = True
            e['handled_id'] = handled_map[event_key]['id']
            e['handled_note'] = handled_map[event_key]['note']
        else:
            e['handled'] = False
            e['handled_id'] = None
            e['handled_note'] = ''

    # Count expected vs unexpected events.
    expected_events = [e for e in events if e['expected']]
    unexpected_events = [e for e in events if not e['expected']]
    unhandled_unexpected = [e for e in unexpected_events if not e['handled']]

    st.warning(f"⚠️ Auxiliary heater was active in {len(events)} event(s)")

    col1, col2 = st.columns(2)
    with col1:
        st.metric(f"Expected ({WEEKDAYS[legionella_day]} {legionella_hour:02d}:00)", f"{len(expected_events)} event(s)")
    with col2:
        if len(unhandled_unexpected) > 0:
            st.metric("⚠️ Unexpected Usage", f"{len(unhandled_unexpected)} event(s)", delta="Investigate", delta_color="inverse")
        elif len(unexpected_events) > 0:
            st.metric("Unexpected Usage", f"All {len(unexpected_events)} handled")
        else:
            st.metric("Unexpected Usage", "0 events")

    if events:
        # Helper function to get hourly range for a day
        def get_hourly_range_for_day(day_date):
            """Query hourly data to get the actual hour range when aux heater was active."""
            conn = get_connection()
            query = """
                SELECT timestamp FROM energy_readings
                WHERE category = 'hour'
                AND timestamp >= ? AND timestamp < ?
                AND (cons_total_aux > 0 OR cons_ch_aux > 0 OR cons_hw_aux > 0)
                ORDER BY timestamp
            """
            day_str = day_date.strftime('%Y-%m-%d')
            next_day = day_date + timedelta(days=1)
            next_day_str = next_day.strftime('%Y-%m-%d')
            hourly_df = pd.read_sql_query(query, conn, params=[day_str, next_day_str])
            conn.close()
            if hourly_df.empty:
                return None, None
            hourly_df['timestamp'] = pd.to_datetime(hourly_df['timestamp'])
            first_hour = hourly_df['timestamp'].min().hour
            last_hour = hourly_df['timestamp'].max().hour
            return first_hour, last_hour

        def format_event_time(event):
            if category == 'hour':
                if event['start'] == event['end']:
                    return event['start'].strftime('%Y-%m-%d %H:%M')
                if event['start'].date() == event['end'].date():
                    return f"{event['start'].strftime('%Y-%m-%d %H:%M')} → {event['end'].strftime('%H:%M')}"
                return f"{event['start'].strftime('%Y-%m-%d %H:%M')} → {event['end'].strftime('%Y-%m-%d %H:%M')}"

            if category == 'day':
                first_hour, last_hour = get_hourly_range_for_day(event['start'])
                if first_hour is not None and last_hour is not None:
                    end_hour = (last_hour + 1) % 24
                    return f"{event['start'].strftime('%Y-%m-%d')} {first_hour:02d}:00 → {end_hour:02d}:00"
                return event['start'].strftime('%Y-%m-%d')

            if event['start'] == event['end']:
                return event['start'].strftime('%Y-%m')
            return f"{event['start'].strftime('%Y-%m')} → {event['end'].strftime('%Y-%m')}"

        if expected_events:
            st.write("**Expected Usage (Legionella schedule):**")
            expected_rows = []
            for e in expected_events:
                avg_temp = sum(e['outdoor_temps']) / len(e['outdoor_temps']) if e['outdoor_temps'] else None
                expected_rows.append({
                    'Time': format_event_time(e),
                    'Total (kWh)': e['total_kwh'],
                    'Central Heating': e['ch_kwh'],
                    'Hot Water': e['hw_kwh'],
                    'Avg Outdoor Temp': f"{avg_temp:.1f}°C" if avg_temp is not None else "-",
                })
            st.dataframe(pd.DataFrame(expected_rows), width='stretch', hide_index=True)

        if unhandled_unexpected:
            st.write("**Unexpected Usage - needs investigation:**")
            for e in unhandled_unexpected:
                event_key = e['start'].isoformat()
                key_suffix = event_key.replace(':', '-').replace('+', '_')
                avg_temp = sum(e['outdoor_temps']) / len(e['outdoor_temps']) if e['outdoor_temps'] else None
                avg_temp_str = f"{avg_temp:.1f}°C" if avg_temp is not None else "-"

                with st.container(border=True):
                    col_info, col_btn = st.columns([5, 2])
                    with col_info:
                        st.markdown(f"**{format_event_time(e)}**")
                        st.caption(
                            f"Total: {e['total_kwh']:.2f} kWh | "
                            f"CH: {e['ch_kwh']:.2f} kWh | HW: {e['hw_kwh']:.2f} kWh | "
                            f"Outdoor: {avg_temp_str}"
                        )
                    with col_btn:
                        if st.button("Mark as Handled", key=f"btn_mark_{key_suffix}"):
                            st.session_state[f"marking_event_{key_suffix}"] = True

                    if st.session_state.get(f"marking_event_{key_suffix}", False):
                        note = st.text_input(
                            "Note (optional)",
                            key=f"note_event_{key_suffix}",
                            placeholder="What caused this?"
                        )
                        col_save, col_cancel = st.columns(2)
                        with col_save:
                            if st.button("Save", key=f"save_event_{key_suffix}", type="primary"):
                                mark_aux_event_handled(category, event_key, note.strip())
                                st.session_state.pop(f"marking_event_{key_suffix}", None)
                                st.rerun()
                        with col_cancel:
                            if st.button("Cancel", key=f"cancel_event_{key_suffix}"):
                                st.session_state.pop(f"marking_event_{key_suffix}", None)
                                st.rerun()
        elif unexpected_events:
            st.success(f"All {len(unexpected_events)} unexpected event(s) have been handled.")

        handled_unexpected = [e for e in unexpected_events if e['handled']]
        if handled_unexpected:
            with st.expander(f"Handled unexpected events ({len(handled_unexpected)})", expanded=False):
                for e in handled_unexpected:
                    handled_key = e['start'].isoformat().replace(':', '-').replace('+', '_')
                    avg_temp = sum(e['outdoor_temps']) / len(e['outdoor_temps']) if e['outdoor_temps'] else None
                    avg_temp_str = f"{avg_temp:.1f}°C" if avg_temp is not None else "-"

                    col_info, col_btn = st.columns([5, 1])
                    with col_info:
                        st.markdown(f"**{format_event_time(e)}** - {e['total_kwh']:.2f} kWh | Outdoor: {avg_temp_str}")
                        if e['handled_note']:
                            st.caption(f"Note: {e['handled_note']}")
                    with col_btn:
                        if st.button("Unmark", key=f"unmark_{handled_key}", help="Remove handled mark"):
                            unmark_aux_event_handled(e['handled_id'])
                            st.rerun()
                    st.divider()

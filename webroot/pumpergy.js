let annotationsCache = [];
let currentLegionellaSchedule = { day: 1, hour: 2 };
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function byId(id) {
  return document.getElementById(id);
}

function fmtNumber(value, decimals = 2) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) {
    return '-';
  }
  return Number(value).toLocaleString(undefined, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

function toDateInputValue(ts) {
  const date = new Date(ts);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  const ss = String(date.getSeconds()).padStart(2, '0');
  return `${y}-${m}-${d} ${hh}:${mm}:${ss}`;
}

function normalizeIsoDateInput(value) {
  if (typeof value !== 'string') {
    return '';
  }

  const trimmed = value.trim();
  const match = trimmed.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
  if (!match) {
    return trimmed;
  }

  const [, year, month, day] = match;
  const mm = String(month).padStart(2, '0');
  const dd = String(day).padStart(2, '0');
  return `${year}-${mm}-${dd}`;
}

function normalizeTimestampInput(value) {
  if (typeof value !== 'string') {
    return '';
  }

  const trimmed = value.trim();
  const match = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!match) {
    return trimmed;
  }

  const [, year, month, day, hour, minute, second = '00'] = match;
  return `${year}-${month}-${day} ${hour}:${minute}:${second}`;
}

function formatLocalDateTime(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  const ss = String(date.getSeconds()).padStart(2, '0');
  return `${y}-${m}-${d} ${hh}:${mm}:${ss}`;
}

function toDisplayBucketTs(date, resolution, hourOverride = null) {
  const dt = new Date(date.getTime());
  if (resolution === 'hour') {
    dt.setMinutes(0, 0, 0);
    if (Number.isFinite(hourOverride)) {
      dt.setHours(Number(hourOverride), 0, 0, 0);
    }
  } else if (resolution === 'month') {
    dt.setDate(15);
    dt.setHours(12, 0, 0, 0);
  } else {
    dt.setHours(12, 0, 0, 0);
  }

  return formatLocalDateTime(dt);
}

function stateFromForm() {
  const start = byId('start');
  const end = byId('end');

  if (start) {
    start.value = normalizeIsoDateInput(start.value);
  }
  if (end) {
    end.value = normalizeIsoDateInput(end.value);
  }

  return {
    resolution: 'day',
    start: start ? start.value : '',
    end: end ? end.value : '',
  };
}

function legionellaStateFromForm() {
  return {
    legionella_day: byId('legionella-day').value,
    legionella_hour: byId('legionella-hour').value,
  };
}

function toQuery(params) {
  const query = new URLSearchParams(params);
  return query.toString();
}

function setStatus(message) {
  const el = byId('status');
  if (el) {
    el.textContent = message;
  }
}

function normalizeSeries(series) {
  if (!Array.isArray(series) || series.length === 0) {
    return [];
  }

  const first = series[0] || {};
  if (!Object.prototype.hasOwnProperty.call(first, 'metric_name')) {
    return series;
  }

  const byTs = new Map();
  for (const row of series) {
    const ts = row.ts;
    if (!ts) {
      continue;
    }

    if (!byTs.has(ts)) {
      byTs.set(ts, { ts });
    }

    const target = byTs.get(ts);
    const key = row.metric_name;
    if (!key) {
      continue;
    }

    const num = row.metric_value === null || row.metric_value === undefined ? null : Number(row.metric_value);
    target[key] = Number.isFinite(num) ? num : null;
  }

  return Array.from(byTs.values()).sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
}

function collapseSeriesForDisplay(series, resolution) {
  if (!Array.isArray(series) || series.length === 0) {
    return [];
  }

  if (resolution !== 'day' && resolution !== 'month') {
    return series;
  }

  const buckets = new Map();
  for (const row of series) {
    const rawTs = String(row?.ts || '');
    if (!rawTs) {
      continue;
    }

    const key = resolution === 'day' ? rawTs.slice(0, 10) : rawTs.slice(0, 7);
    const canonicalTs = resolution === 'day' ? `${key} 12:00:00` : `${key}-15 12:00:00`;

    if (!buckets.has(key)) {
      buckets.set(key, { ...row, ts: canonicalTs });
      continue;
    }

    const target = buckets.get(key);
    target.ts = canonicalTs;

    // Keep the most recent non-null values inside each day/month bucket.
    for (const [field, value] of Object.entries(row)) {
      if (field === 'ts' || value === null || value === undefined) {
        continue;
      }
      target[field] = value;
    }
  }

  return Array.from(buckets.values()).sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
}

async function loadReadings(state) {
  const res = await fetch(`readings.php?${toQuery(state)}`);
  if (!res.ok) {
    throw new Error(`Readings request failed (${res.status})`);
  }
  return res.json();
}

async function loadAnnotations(state) {
  const res = await fetch(`annotations.php?${toQuery(state)}`);
  if (!res.ok) {
    throw new Error(`Annotations request failed (${res.status})`);
  }
  return res.json();
}

async function saveLegionellaSettings(state) {
  const res = await fetch('settings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      legionella_day: Number(state.legionella_day),
      legionella_hour: Number(state.legionella_hour),
    }),
  });
  if (!res.ok) {
    throw new Error(`Settings request failed (${res.status})`);
  }
  return res.json();
}

function formatSyncStatus(data) {
  if (!data || typeof data !== 'object') {
    return 'Import status unavailable.';
  }

  const nextDueSuffix = (() => {
    const secondsUntilDue = Number(data.seconds_until_due ?? NaN);
    if (!Number.isFinite(secondsUntilDue) || secondsUntilDue <= 0) {
      return '';
    }

    const minutes = Math.ceil(secondsUntilDue / 60);
    const minuteLabel = minutes === 1 ? 'minute' : 'minutes';
    return ` Next automatic scan in about ${minutes} ${minuteLabel}.`;
  })();

  const nextDueReadySuffix = (() => {
    if (data.ready_now === true) {
      return ' Automatic import can run now.';
    }
    return '';
  })();

  const forceSuffix = ' Use Force import to scan immediately.';

  if (data.status === 'ok') {
    const importedFiles = Number(data.imported_files || 0);
    const importedRows = Number(data.imported_rows || 0);

    if (importedFiles > 0) {
      const fileLabel = importedFiles === 1 ? 'file' : 'files';
      const rowLabel = importedRows === 1 ? 'row' : 'rows';
      return `Imported ${importedFiles} ${fileLabel} and ${importedRows} ${rowLabel}.${nextDueSuffix || nextDueReadySuffix}`;
    }

    return `No new CSV files were found to import.${nextDueSuffix || nextDueReadySuffix}`;
  }

  if (data.status === 'skipped' && data.reason === 'sync_not_due') {
    return `Import scan skipped because a recent sync check already ran.${nextDueSuffix}${forceSuffix}`;
  }

  if (data.status === 'error') {
    return data.error ? `Import scan failed: ${data.error}` : 'Import scan failed.';
  }

  return 'Sync completed.';
}

function syncLegionellaControls() {
  byId('legionella-day').value = String(currentLegionellaSchedule.day);
  byId('legionella-hour').value = String(currentLegionellaSchedule.hour);
}

function schedulePlotResize() {
  const host = byId('chart-consumption');
  if (!host || typeof Plotly === 'undefined') {
    return;
  }

  requestAnimationFrame(() => {
    Plotly.Plots.resize(host);
  });
}

function renderConsumptionChart(series) {
  const host = byId('chart-consumption');
  if (!host || typeof Plotly === 'undefined') {
    return;
  }

  const width = Math.max(host.clientWidth || 0, 360);
  const state = stateFromForm();
  const legionellaDay = Number(currentLegionellaSchedule.day);
  const legionellaHour = Number(currentLegionellaSchedule.hour);
  const resolution = state.resolution;
  const annotationRangeStart = state.start ? new Date(`${state.start}T00:00:00`) : null;
  const annotationRangeEnd = state.end ? new Date(`${state.end}T23:59:59`) : null;

  const x = series.map((row) => row.ts);
  const hp = series.map((row) => Number(row.cons_total_hp || 0));
  const aux = series.map((row) => Number(row.cons_total_aux || 0));
  const outdoor = series.map((row) => (row.outdoor_temp === null ? null : Number(row.outdoor_temp)));
  const hotWater = series.map((row) => (row.hw_temp === null ? null : Number(row.hw_temp)));

  const legionellaTimes = [];
  const seenLegionellaKeys = new Set();

  const toDate = (value) => {
    if (!value) {
      return null;
    }
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const startDate = toDate(state.start) || toDate(series[0]?.ts);
  const endDate = toDate(state.end) || toDate(series[series.length - 1]?.ts);

  if (startDate && endDate) {
    const cursor = new Date(startDate);
    cursor.setHours(0, 0, 0, 0);
    const last = new Date(endDate);
    last.setHours(0, 0, 0, 0);

    while (cursor <= last) {
      const weekdayMonZero = (cursor.getDay() + 6) % 7;
      if (weekdayMonZero === legionellaDay) {
        const canonicalKey = toDisplayBucketTs(cursor, resolution, legionellaHour);
        if (!seenLegionellaKeys.has(canonicalKey)) {
          seenLegionellaKeys.add(canonicalKey);
          legionellaTimes.push(canonicalKey);
        }
      }
      cursor.setDate(cursor.getDate() + 1);
    }
  }

  const barWidthMs = resolution === 'hour' ? (60 * 60 * 1000 * 0.8) : null;

  const traces = [
    {
      type: 'bar',
      x,
      y: hp,
      name: 'Heat Pump',
      marker: { color: '#669966' },
      ...(barWidthMs !== null ? { width: barWidthMs } : {}),
      yaxis: 'y',
    },
    {
      type: 'bar',
      x,
      y: aux,
      name: 'Auxiliary Heater',
      marker: { color: '#ff6666' },
      ...(barWidthMs !== null ? { width: barWidthMs } : {}),
      yaxis: 'y',
    },
    {
      type: 'scatter',
      mode: 'lines',
      x,
      y: outdoor,
      name: 'Outdoor Temp',
      yaxis: 'y2',
      line: { color: '#000000', width: 2 },
      marker: { color: '#000000', size: 0 },
      opacity: 0.9,
      connectgaps: true,
    },
    {
      type: 'scatter',
      mode: 'lines',
      x,
      y: hotWater,
      name: 'Water Temp',
      yaxis: 'y2',
      line: { color: '#6666ff', width: 2 },
      marker: { color: '#6666ff', size: 0 },
      opacity: 0.9,
      connectgaps: true,
    },
  ];

  const uniqueLegionellaTimes = [...new Set(legionellaTimes)];
  const shapes = [];
  const annotations = [];
  for (const ts of uniqueLegionellaTimes) {
    shapes.push({
      type: 'line',
      x0: ts,
      x1: ts,
      y0: 0,
      y1: 1,
      yref: 'paper',
      line: { color: '#9B59B6', width: 2, dash: 'dash' },
    });

    annotations.push({
      x: ts,
      y: 1,
      yref: 'paper',
      text: '🦠',
      showarrow: false,
      font: { size: 14 },
      yshift: 10,
    });
  }

  const annotationIconMap = {
    note: '📝',
    fuse: '⚡',
    cold: '🥶',
    hot: '🥵',
    shower: '🚿',
    manual: '🔧',
    maintenance: '🛠️',
    vacation: '🏖️',
    guests: '👥',
    error: '❌',
    question: '❓',
  };

  for (const ann of annotationsCache) {
    if (!ann || !ann.ts) {
      continue;
    }

    const startDate = new Date(String(ann.ts).replace(' ', 'T'));
    if (Number.isNaN(startDate.getTime())) {
      continue;
    }

    const startMs = startDate.getTime();
    const durationHours = Number(ann.duration_hours || 0);
    const endMs = Number.isFinite(durationHours) && durationHours > 0 ? startMs + (durationHours * 60 * 60 * 1000) : startMs;

    if (annotationRangeStart && endMs < annotationRangeStart.getTime()) {
      continue;
    }
    if (annotationRangeEnd && startMs > annotationRangeEnd.getTime()) {
      continue;
    }

    const symbol = annotationIconMap[ann.icon || 'note'] || '📝';
    const label = `${symbol} ${ann.note || ''}`.trim();
    const startBucketTs = toDisplayBucketTs(startDate, resolution);
    const endBucketTs = toDisplayBucketTs(new Date(endMs), resolution);
    const isBucketedResolution = resolution !== 'hour';

    if (durationHours > 0 && !isBucketedResolution) {
      shapes.push({
        type: 'rect',
        x0: startBucketTs,
        x1: endBucketTs,
        y0: 0,
        y1: 1,
        yref: 'paper',
        fillcolor: 'rgba(92, 124, 220, 0.14)',
        line: { color: '#5C7CDC', width: 1 },
      });
      annotations.push({
        x: formatLocalDateTime(new Date((startMs + endMs) / 2)),
        y: 1,
        yref: 'paper',
        text: symbol,
        showarrow: false,
        font: { size: 14, color: '#2f3f75' },
        yshift: 12,
        hovertext: label,
      });
      continue;
    }

    shapes.push({
      type: 'line',
      x0: startBucketTs,
      x1: startBucketTs,
      y0: 0,
      y1: 1,
      yref: 'paper',
      line: { color: '#5C7CDC', width: 2 },
    });

    annotations.push({
      x: startBucketTs,
      y: 1,
      yref: 'paper',
      text: symbol,
      showarrow: false,
      font: { size: 14, color: '#2f3f75' },
      yshift: 10,
      hovertext: label,
    });
  }

  const weekday = WEEKDAYS[legionellaDay] || WEEKDAYS[1];
  const hourStr = String(legionellaHour).padStart(2, '0');
  if (legionellaTimes.length > 0) {
    traces.push({
      type: 'scatter',
      x: [null],
      y: [null],
      mode: 'lines',
      name: `Legionella (${weekday.slice(0, 3)} ${hourStr}:00)`,
      line: { color: '#9B59B6', dash: 'dash', width: 2 },
      showlegend: false,
    });
  }

  const dayTickMs = 24 * 60 * 60 * 1000;
  const tick0 = x.length > 0 ? x[0] : undefined;

  const layout = {
    width,
    autosize: true,
    barmode: 'stack',
    hovermode: 'x unified',
    paper_bgcolor: 'rgba(0,0,0,0)',
    plot_bgcolor: 'rgba(0,0,0,0)',
    xaxis: {
      title: 'Time',
      type: 'date',
      automargin: true,
      tickfont: { size: 11 },
      showgrid: false,
      zeroline: false,
      tickformat: '%Y-%m-%d',
      ...(tick0 ? { tick0, dtick: dayTickMs } : {}),
    },
    yaxis: {
      title: 'Energy (kWh)',
      gridcolor: 'rgba(35, 40, 33, 0.08)',
      zeroline: false,
      tickfont: { size: 11 },
    },
    yaxis2: {
      title: 'Temperature (°C)',
      overlaying: 'y',
      side: 'right',
      gridcolor: 'rgba(35, 40, 33, 0.08)',
      zeroline: false,
      tickfont: { size: 11 },
    },
    legend: {
      orientation: 'h',
      x: 0,
      y: 1.15,
      xanchor: 'left',
      yanchor: 'bottom',
      bgcolor: 'rgba(255,255,255,0.45)',
      bordercolor: 'rgba(35, 40, 33, 0.12)',
      borderwidth: 1,
    },
    height: 420,
    margin: { t: 38, r: 58, b: 58, l: 58 },
    shapes,
    annotations,
  };

  Plotly.react(host, traces, layout, {
    responsive: true,
    displayModeBar: false,
  });

  schedulePlotResize();
}

function renderSampleTable(series) {
  const target = byId('sample-body');
  if (!target) {
    return;
  }

  const rows = series.slice(-24).reverse();
  target.innerHTML = rows
    .map((row) => {
      return `<tr>
        <td>${row.ts ?? ''}</td>
        <td>${fmtNumber(row.cons_total_hp)}</td>
        <td>${fmtNumber(row.cons_total_aux)}</td>
        <td>${fmtNumber(row.outdoor_temp, 1)}</td>
        <td>${fmtNumber(row.flow_temp, 1)}</td>
      </tr>`;
    })
    .join('');
}

const ANNOTATION_ICON_LABELS = {
  note: '📝 General note',
  fuse: '⚡ Fuse / electrical issue',
  cold: '🥶 Very cold day',
  hot: '🥵 Very hot day',
  shower: '🚿 Extra hot water usage',
  manual: '🔧 Manual intervention',
  maintenance: '🛠️ Maintenance',
  vacation: '🏖️ Away / vacation',
  guests: '👥 Extra guests',
  error: '❌ Error / malfunction',
  question: '❓ Investigate',
};

function resetAnnotationForm() {
  byId('annotation-id').value = '';
  byId('annotation-ts').value = '';
  byId('annotation-icon').value = 'note';
  byId('annotation-duration').value = '0';
  byId('annotation-note').value = '';
  byId('annotation-submit').textContent = 'Save annotation';
}

function renderAnnotationList() {
  const host = byId('annotation-list');
  if (!host) {
    return;
  }

  if (annotationsCache.length === 0) {
    host.innerHTML = '<p>No annotations in selected range.</p>';
    return;
  }

  host.innerHTML = annotationsCache
    .map((ann) => {
      const id = Number(ann.id);
      const duration = Number(ann.duration_hours || 0);
      const note = (ann.note || '').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
      const iconKey = ann.icon || 'note';
      const iconLabel = ANNOTATION_ICON_LABELS[iconKey] || ANNOTATION_ICON_LABELS.note;
      return `<div class="annotation-item" data-id="${id}">
        <div class="annotation-item-head">
          <strong>${ann.ts}</strong>
          <div class="annotation-item-actions">
            <button class="annotation-mini-btn" type="button" data-edit="${id}">Edit</button>
            <button class="annotation-mini-btn" type="button" data-delete="${id}">Delete</button>
          </div>
        </div>
        <div>${iconLabel} · ${duration}h</div>
        <div>${note}</div>
      </div>`;
    })
    .join('');

  host.querySelectorAll('[data-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const id = Number(button.getAttribute('data-edit'));
      const selected = annotationsCache.find((ann) => Number(ann.id) === id);
      if (!selected) {
        return;
      }
      byId('annotation-id').value = String(selected.id);
      byId('annotation-ts').value = toDateInputValue(selected.ts);
      byId('annotation-icon').value = selected.icon || 'note';
      byId('annotation-duration').value = String(selected.duration_hours || 0);
      byId('annotation-note').value = selected.note || '';
      byId('annotation-submit').textContent = 'Update annotation';
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    });
  });

  host.querySelectorAll('[data-delete]').forEach((button) => {
    button.addEventListener('click', async () => {
      const id = Number(button.getAttribute('data-delete'));
      if (!Number.isFinite(id) || !confirm('Delete this annotation?')) {
        return;
      }
      await fetch('annotations.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      await refreshDashboard();
    });
  });
}

async function triggerSync(force = false) {
  const out = byId('sync-status');
  if (!out) {
    return null;
  }

  out.textContent = 'sync in progress...';
  try {
    const url = force ? 'sync.php?force=1' : 'sync.php';
    const res = await fetch(url);
    const data = await res.json();
    out.textContent = formatSyncStatus(data);
    return data;
  } catch (err) {
    out.textContent = `sync error: ${String(err)}`;
    return null;
  }
}

async function refreshDashboard() {
  const state = stateFromForm();
  if (!state.start || !state.end) {
    setStatus('Select start and end dates first.');
    return;
  }

  setStatus('Loading readings and annotations...');

  const [readings, annotationData] = await Promise.all([
    loadReadings(state),
    loadAnnotations(state),
  ]);

  const normalizedSeries = normalizeSeries(Array.isArray(readings.series) ? readings.series : []);
  const series = collapseSeriesForDisplay(normalizedSeries, state.resolution);

  // Keep chart overlays in sync with the latest fetched annotations.
  annotationsCache = Array.isArray(annotationData.annotations) ? annotationData.annotations : [];

  renderConsumptionChart(series);
  renderSampleTable(series);

  renderAnnotationList();

  const query = toQuery(state);
  history.replaceState(null, '', `?${query}`);
  setStatus(``);
}

function wireEvents() {
  byId('filter-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await refreshDashboard();
    } catch (err) {
      setStatus(`Failed to refresh dashboard: ${String(err)}`);
    }
  });

  byId('btn-force-import').addEventListener('click', async () => {
    try {
      await triggerSync(true);
      await refreshDashboard();
    } catch (err) {
      setStatus(`Failed to force import: ${String(err)}`);
    }
  });

  byId('legionella-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const saved = await saveLegionellaSettings(legionellaStateFromForm());
      currentLegionellaSchedule = {
        day: Number(saved.day),
        hour: Number(saved.hour),
      };
      syncLegionellaControls();
      await refreshDashboard();
    } catch (err) {
      setStatus(`Failed to save Legionella schedule: ${String(err)}`);
    }
  });

  byId('annotation-form').addEventListener('submit', async (event) => {
    event.preventDefault();

    const id = byId('annotation-id').value.trim();
    const tsInput = normalizeTimestampInput(byId('annotation-ts').value);
    const icon = byId('annotation-icon').value.trim() || 'note';
    const durationHours = Number(byId('annotation-duration').value || 0);
    const note = byId('annotation-note').value.trim();

    if (!tsInput) {
      setStatus('Annotation timestamp is required.');
      return;
    }

    const ts = tsInput;
    const payload = {
      ts,
      icon,
      duration_hours: Number.isFinite(durationHours) ? durationHours : 0,
      note,
    };

    const options = {
      method: id ? 'PATCH' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(id ? { ...payload, id: Number(id) } : payload),
    };

    const res = await fetch('annotations.php', options);
    if (!res.ok) {
      const detail = await res.text();
      throw new Error(`Failed saving annotation: ${detail}`);
    }

    resetAnnotationForm();
    await refreshDashboard();
  });

  byId('annotation-reset').addEventListener('click', () => {
    resetAnnotationForm();
  });
}

window.addEventListener('DOMContentLoaded', async () => {
  const boot = window.PUMPERGY_BOOT || {};
  const forceSync = new URLSearchParams(window.location.search).get('force') === '1';
  const resolutionInput = byId('resolution');
  if (resolutionInput) {
    resolutionInput.value = 'day';
  }
  if (boot.start) byId('start').value = boot.start;
  if (boot.end) byId('end').value = boot.end;
  if (boot.legionella_day !== undefined) currentLegionellaSchedule.day = Number(boot.legionella_day);
  if (boot.legionella_hour !== undefined) currentLegionellaSchedule.hour = Number(boot.legionella_hour);
  syncLegionellaControls();

  wireEvents();
  resetAnnotationForm();

  try {
    await triggerSync(forceSync);
    await refreshDashboard();
  } catch (err) {
    setStatus(`Initial load failed: ${String(err)}`);
  }
});

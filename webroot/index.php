<?php

// Renders the main Pumpergy dashboard page.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_loader.php';

if (!empty($container['boot_error'])) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pumpergy setup required</title>
  <link rel="stylesheet" href="pumpergy.css">
</head>
<body>
  <h1>Pumpergy setup required</h1>
  <div class="panel">
    <p>Backend bootstrap loaded, but database setup is incomplete.</p>
    <pre><?= htmlspecialchars((string)$container['boot_error']) ?></pre>
  </div>
</body>
</html>
<?php
    exit;
}

try {
    [$resolution, $start, $end] = DateRange::fromQuery($_GET);
    $startInput = date('Y-m-d', strtotime($start));
    $endInput = date('Y-m-d', strtotime($end));
    $schedule = $container['services']['settings']->getLegionellaSchedule();
    $legionellaDay = (int)($schedule['day'] ?? 1);
    $legionellaHour = (int)($schedule['hour'] ?? 2);
    $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $annotationIcons = [
        'note' => '📝 General note',
        'fuse' => '⚡ Fuse / electrical issue',
        'cold' => '🥶 Very cold day',
        'hot' => '🥵 Very hot day',
        'shower' => '🚿 Extra hot water usage',
        'manual' => '🔧 Manual intervention',
        'maintenance' => '🛠️ Maintenance',
        'vacation' => '🏖️ Away / vacation',
        'guests' => '👥 Extra guests',
        'error' => '❌ Error / malfunction',
        'question' => '❓ Investigate',
    ];
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pumpergy setup required</title>
  <link rel="stylesheet" href="pumpergy.css">
</head>
<body>
  <h1>Pumpergy setup required</h1>
  <div class="panel">
    <p>Backend loaded, but the database is not ready yet.</p>
    <pre><?= htmlspecialchars($e->getMessage()) ?></pre>
  </div>
</body>
</html>
<?php
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pumpergy</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="pumpergy.css">
</head>
<body>
  <div class="page-bg" aria-hidden="true"></div>
  <main class="layout">
    <header class="hero">
      <h1>Pumpergy - Heat pump energy dashboard</h1>
    </header>

    <section class="panel filters-panel">
      <form id="filter-form" class="filters" novalidate>
        <input id="resolution" type="hidden" name="resolution" value="day">
        <label>
          Start
          <input id="start" type="text" name="start" value="<?= htmlspecialchars($startInput) ?>" inputmode="numeric" pattern="\d{4}-\d{2}-\d{2}" placeholder="yyyy-mm-dd" autocomplete="off">
        </label>
        <label>
          End
          <input id="end" type="text" name="end" value="<?= htmlspecialchars($endInput) ?>" inputmode="numeric" pattern="\d{4}-\d{2}-\d{2}" placeholder="yyyy-mm-dd" autocomplete="off">
        </label>
        <button class="btn btn-primary" type="submit">Apply</button>
      </form>
      <p id="status" class="status">Loading data...</p>
    </section>

    <section class="panel chart-panel">
      <div class="panel-title-row">
        <h2>Energy consumption and temperatures over time</h2>
      </div>
      <div class="chart-wrap">
        <div id="chart-consumption"></div>
      </div>
    </section>

    <section class="panel">
      <h2>Annotations</h2>
      <div class="annotation-layout">
        <form id="annotation-form" class="annotation-form" novalidate>
          <input type="hidden" id="annotation-id" value="">
          <label class="annotation-field annotation-field-ts">
            Timestamp
            <input id="annotation-ts" type="text" required inputmode="numeric" pattern="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}" placeholder="yyyy-mm-dd hh:mm:ss" autocomplete="off">
          </label>
          <label class="annotation-field annotation-field-icon">
            Icon key
            <select id="annotation-icon" required>
              <?php foreach ($annotationIcons as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $key === 'note' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="annotation-field annotation-field-narrow">
            Duration hours
            <input id="annotation-duration" type="number" value="0" min="0" step="0.5">
          </label>
          <label class="annotation-field annotation-field-note">
            Note
            <textarea id="annotation-note" rows="3" placeholder="Optional note"></textarea>
          </label>
          <div class="annotation-actions annotation-field-wide">
            <button class="btn btn-primary" type="submit" id="annotation-submit">Save annotation</button>
            <button class="btn" type="button" id="annotation-reset">Clear</button>
          </div>
        </form>
        <div id="annotation-list" class="annotation-list"></div>
      </div>
    </section>

    <section class="panel">
      <div class="settings-panel-head">
        <h2>Import</h2>
        <button class="btn" type="button" id="btn-force-import">Force import</button>
      </div>
      <pre id="sync-status">waiting...</pre>
    </section>

    <section class="panel">
      <h2>Data sample</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>HP (kWh)</th>
              <th>AUX (kWh)</th>
              <th>Outdoor C</th>
              <th>Flow C</th>
            </tr>
          </thead>
          <tbody id="sample-body"></tbody>
        </table>
      </div>
    </section>

    <section class="panel settings-panel">
      <div class="settings-panel-head">
        <h2>Legionella schedule</h2>
        <p>This rarely changes and is stored in the database.</p>
      </div>
      <form id="legionella-form" class="filters settings-form" novalidate>
        <label>
          Legionella day
          <select id="legionella-day" name="legionella_day">
            <?php foreach ($weekdays as $idx => $dayName): ?>
              <option value="<?= $idx ?>" <?= $idx === $legionellaDay ? 'selected' : '' ?>><?= htmlspecialchars($dayName) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          Legionella hour
          <select id="legionella-hour" name="legionella_hour">
            <?php for ($hour = 0; $hour < 24; $hour++): ?>
              <option value="<?= $hour ?>" <?= $hour === $legionellaHour ? 'selected' : '' ?>><?= str_pad((string)$hour, 2, '0', STR_PAD_LEFT) ?>:00</option>
            <?php endfor; ?>
          </select>
        </label>
        <button class="btn" type="submit">Save schedule</button>
      </form>
    </section>
  </main>

  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
  <script>
    window.PUMPERGY_BOOT = {
      resolution: <?= json_encode($resolution, JSON_UNESCAPED_SLASHES) ?>,
      start: <?= json_encode($startInput, JSON_UNESCAPED_SLASHES) ?>,
      end: <?= json_encode($endInput, JSON_UNESCAPED_SLASHES) ?>,
      legionella_day: <?= json_encode($legionellaDay, JSON_UNESCAPED_SLASHES) ?>,
      legionella_hour: <?= json_encode($legionellaHour, JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="pumpergy.js"></script>
</body>
</html>

<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

/* ===== NO-CACHE: prevent back-button access after logout ===== */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* ===== SESSION PROTECTION ===== */
if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require 'vendor/autoload.php';
require 'db.php';   // PDO connection → $pdo
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* ===== LOAD DATA FROM DB ===== */

// Load all events
$eventsRaw = $pdo->query("SELECT * FROM events ORDER BY date ASC, id ASC")->fetchAll();
$events = [];
foreach ($eventsRaw as $row) {
    $events[] = [
        'id'              => $row['id'],
        'name'            => $row['name'],
        'venue'           => $row['venue'],
        'organising_team' => $row['organising_team'] ?? '',
        'multiday'        => (bool)$row['multiday'],
        'date'            => $row['date'],
        'end_date'        => $row['end_date'],
        'time'            => $row['time'],
        'days'            => $row['days'] ? json_decode($row['days'], true) : null,
    ];
}

// Load history
$historyRaw = $pdo->query("SELECT * FROM segregation_history ORDER BY segregated_on DESC")->fetchAll();
$history = [];
foreach ($historyRaw as $row) {
    $history[] = [
        'run_date_range' => $row['run_date_range'],
        'date_from'      => $row['date_from'],
        'date_to'        => $row['date_to'],
        'segregated_on'  => $row['segregated_on'],
        'events'         => $row['events'] ? json_decode($row['events'], true) : [],
        'zips'           => $row['zips']   ? json_decode($row['zips'],   true) : [],
    ];
}

// Load school codes from DB
$schoolsRaw  = $pdo->query("SELECT school_name, codes FROM schools ORDER BY school_name ASC")->fetchAll();
$schoolCodes = [];
foreach ($schoolsRaw as $row) {
    $schoolCodes[$row['school_name']] = json_decode($row['codes'], true);
}

/* ================= EVENT REGISTRATION ================= */
if (isset($_POST['register_event'])) {

    $isMultiday = isset($_POST['is_multiday']) && $_POST['is_multiday'] == '1';

    if ($isMultiday) {
        $days = [];
        foreach ($_POST['day_date'] as $i => $dayDate) {
            if (empty($dayDate)) continue;
            $from = $_POST['day_from_hour'][$i].":".$_POST['day_from_minute'][$i]." ".$_POST['day_from_ampm'][$i];
            $to   = $_POST['day_to_hour'][$i].":".$_POST['day_to_minute'][$i]." ".$_POST['day_to_ampm'][$i];
            $days[] = ["date" => $dayDate, "time" => $from." - ".$to];
        }
        usort($days, fn($a,$b) => strcmp($a['date'], $b['date']));

        $stmt = $pdo->prepare("INSERT INTO events
            (name, venue, organising_team, multiday, date, end_date, days)
            VALUES (?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([
            $_POST['event_name'],
            $_POST['event_venue'],
            $_POST['organising_team'] ?? '',
            $days[0]['date'],
            end($days)['date'],
            json_encode($days)
        ]);
    } else {
        $time = $_POST['from_hour'].":".$_POST['from_minute']." ".$_POST['from_ampm']
              . " - "
              . $_POST['to_hour'].":".$_POST['to_minute']." ".$_POST['to_ampm'];

        $stmt = $pdo->prepare("INSERT INTO events
            (name, venue, organising_team, multiday, date, time)
            VALUES (?, ?, ?, 0, ?, ?)");
        $stmt->execute([
            $_POST['event_name'],
            $_POST['event_venue'],
            $_POST['organising_team'] ?? '',
            $_POST['event_date'],
            $time
        ]);
    }

    header("Location: register_event.php");
    exit();
}

/* ================= SEGREGATION ================= */
if (isset($_POST['segregate_all'])) {

    if (!file_exists("downloads")) mkdir("downloads", 0777, true);

    // schoolCodes already loaded from DB above

    $createdFiles      = [];
    $allZipFiles       = [];
    $schoolEventData   = [];   // [eventIndex][school][date][code][] = regNo
    $eventMeta         = [];   // [eventIndex] = [name, venue, days...]

    foreach ($_POST['selected_event'] as $index => $selectedValue) {

        if (empty($selectedValue)) continue;

        // Verify file was actually uploaded for this index
        $tmpFile = $_FILES['excel_file']['tmp_name'][$index] ?? '';
        if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;

        // selectedValue uses "||" as delimiter to avoid clash with time strings
        $parts     = explode("||", $selectedValue);
        $eventName = trim($parts[0] ?? '');
        $venue     = trim($parts[1] ?? '');
        $isMulti   = isset($parts[2]) && trim($parts[2]) === 'MULTIDAY';

        // Find the matching event object from events.json
        $evObj = null;
        foreach ($events as $ev) {
            if ($ev['name'] === $eventName) { $evObj = $ev; break; }
        }
        if (!$evObj) continue;

        // Normalise: ensure every event has a 'days' array
        if (empty($evObj['days'])) {
            $evObj['days'] = [[
                "date" => $evObj['date'] ?? '',
                "time" => $evObj['time'] ?? ''
            ]];
        }

        if ($isMulti || !empty($evObj['multiday'])) {

            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $venue,
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => true,
                "days"            => $evObj['days']
            ];

            // Multiday Excel: row 1 = date headers, each column = one day
            $spreadsheet = IOFactory::load($tmpFile);
            $sheet       = $spreadsheet->getActiveSheet();
            // Use column-letter keys (4th param = true)
            $rawData     = $sheet->toArray(null, true, true, true);

            $colDateMap = [];
            $firstRow   = array_shift($rawData);
            if (is_array($firstRow)) {
                foreach ($firstRow as $colLetter => $cellVal) {
                    if ($cellVal === null || $cellVal === '' || is_array($cellVal)) continue;
                    $cellStr    = trim((string)$cellVal);
                    $normalized = null;

                    $ts = strtotime($cellStr);
                    if ($ts !== false && $ts > 86400) { // sanity: > 1970-01-02
                        $normalized = date("Y-m-d", $ts);
                    } else {
                        $cleaned = str_replace(['/', '.'], '-', $cellStr);
                        $p2 = explode('-', $cleaned);
                        if (count($p2) === 3 && strlen($p2[2]) === 4
                            && (int)$p2[0] <= 31 && (int)$p2[1] <= 12) {
                            $normalized = date("Y-m-d",
                                mktime(0, 0, 0, (int)$p2[1], (int)$p2[0], (int)$p2[2]));
                        }
                    }
                    if ($normalized) $colDateMap[$colLetter] = $normalized;
                }
            }

            foreach ($rawData as $row) {
                if (!is_array($row)) continue;
                foreach ($colDateMap as $colLetter => $dateVal) {
                    $cell  = $row[$colLetter] ?? null;
                    if ($cell === null || is_array($cell)) continue;
                    $regNo = trim((string)$cell);
                    if (strlen($regNo) !== 9) continue;
                    $code  = substr($regNo, 2, 3);
                    foreach ($schoolCodes as $school => $codes) {
                        if (in_array($code, $codes)) {
                            $schoolEventData[$index][$school][$dateVal][$code][] = $regNo;
                        }
                    }
                }
            }

        } else {
            // Single-day event
            $date = $evObj['date'] ?? '';
            $time = $evObj['time'] ?? '';

            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $venue,
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => false,
                "days"            => [["date" => $date, "time" => $time]]
            ];

            $spreadsheet = IOFactory::load($tmpFile);
            // Simple 2D array, no column-letter keys needed
            $allRows     = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            foreach ($allRows as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $cell) {
                    if ($cell === null || is_array($cell)) continue;
                    $regNo = trim((string)$cell);
                    if (strlen($regNo) !== 9) continue;
                    $code  = substr($regNo, 2, 3);
                    foreach ($schoolCodes as $school => $codes) {
                        if (in_array($code, $codes)) {
                            $schoolEventData[$index][$school][$date][$code][] = $regNo;
                        }
                    }
                }
            }
        }
    }

    /* ===== BUILD ONE EXCEL PER SCHOOL (all events + all dates inside) ===== */
    // Collect all unique dates from actual collected data (not meta, which may have empty dates)
    $allDates = [];
    foreach ($schoolEventData as $index => $schoolArr) {
        foreach ($schoolArr as $school => $dateArr) {
            foreach (array_keys($dateArr) as $d) {
                if (!empty($d)) $allDates[$d] = $d;
            }
        }
    }
    ksort($allDates);

    $zipFilesByDate = []; // kept for compat — not used for zip anymore
    $allSchoolFiles = []; // all xlsx paths for single ZIP

    foreach ($schoolCodes as $school => $codes) {

        // Check if this school has any data at all
        $hasData = false;
        foreach ($eventMeta as $index => $meta) {
            if (!empty($schoolEventData[$index][$school])) { $hasData = true; break; }
        }
        if (!$hasData) continue;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $currentRow  = 1;

        // For each event
        foreach ($eventMeta as $index => $meta) {

            if (empty($schoolEventData[$index][$school])) continue;

            // Event header
            $sheet->setCellValue('A'.$currentRow, $meta['name']." | ".$meta['venue']);
            $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true)->setSize(13);
            $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
            $currentRow++;

            // Build a date->time lookup from meta days (for display)
            $dateTimeMap = [];
            foreach (($meta['days'] ?? []) as $day) {
                if (!empty($day['date'])) {
                    $dateTimeMap[$day['date']] = $day['time'];
                }
            }

            // Use ACTUAL collected dates for this school+event (not meta days, which may have empty dates)
            $collectedDates = array_keys($schoolEventData[$index][$school]);
            sort($collectedDates);

            foreach ($collectedDates as $dateKey) {

                if (empty($schoolEventData[$index][$school][$dateKey])) continue;

                $dateCodes = $schoolEventData[$index][$school][$dateKey];
                $dateStr   = date("d-m-Y", strtotime($dateKey));
                $timeStr   = $dateTimeMap[$dateKey] ?? $meta['days'][0]['time'] ?? '';

                // Date + time row
                $sheet->setCellValue('A'.$currentRow, $dateStr.($timeStr ? " | ".$timeStr : ""));
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9E1F2');
                $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
                $currentRow++;

                // Code headers + reg numbers side by side
                $startColumn = 1;
                $headerRow   = $currentRow;
                $maxRows     = 0;

                foreach ($dateCodes as $code => $regList) {
                    $colLetter = Coordinate::stringFromColumnIndex($startColumn);
                    $sheet->setCellValue($colLetter.$headerRow, $code);
                    $sheet->getStyle($colLetter.$headerRow)->getFont()->setBold(true);
                    $sheet->getStyle($colLetter.$headerRow)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FFF2CC');

                    $rowPointer = $headerRow + 1;
                    foreach ($regList as $reg) {
                        $sheet->setCellValue($colLetter.$rowPointer, $reg);
                        $rowPointer++;
                    }
                    $maxRows = max($maxRows, count($regList));
                    $startColumn++;
                }

                $currentRow = $headerRow + $maxRows + 2;
            }

            $currentRow += 1; // gap between events
        }

        // Auto-size columns
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $highestCol; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // Determine dates for this school's file (use actual collected dates, not meta days)
        $schoolDates = [];
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;
            foreach (array_keys($schoolEventData[$index][$school]) as $d) {
                $schoolDates[$d] = $d;
            }
        }
        ksort($schoolDates);
        $dateRange  = count($schoolDates) > 1
            ? array_key_first($schoolDates)."_to_".array_key_last($schoolDates)
            : array_key_first($schoolDates);

        $fileName = $school."_".$dateRange.".xlsx";
        $filePath = "downloads/".$fileName;

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $createdFiles[] = $fileName;
        $allSchoolFiles[] = $filePath;
    }

    /* ===== CREATE ONE ZIP FOR ALL SCHOOL FILES ===== */
    $createdZips = [];
    if (!empty($allSchoolFiles)) {
        // Build a date-range label for the zip filename
        $allCollectedDates = [];
        foreach ($schoolEventData as $schoolArr) {
            foreach ($schoolArr as $dateArr) {
                foreach (array_keys($dateArr) as $d) {
                    if (!empty($d)) $allCollectedDates[$d] = $d;
                }
            }
        }
        ksort($allCollectedDates);
        $zipDateFrom = !empty($allCollectedDates) ? array_key_first($allCollectedDates) : date("Y-m-d");
        $zipDateTo   = !empty($allCollectedDates) ? array_key_last($allCollectedDates)  : $zipDateFrom;
        $zipLabel    = ($zipDateFrom === $zipDateTo) ? $zipDateFrom : $zipDateFrom."_to_".$zipDateTo;

        $zipFileName = "downloads/all_schools_".$zipLabel."_".date("His").".zip";
        $zip = new ZipArchive();
        if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach (array_unique($allSchoolFiles) as $fp) {
                $zip->addFile($fp, basename($fp));
            }
            $zip->close();
        }
        $createdFiles[] = basename($zipFileName);
        $createdZips[]  = basename($zipFileName);
    }

    /* ===== UPDATE HISTORY — one record per segregation run ===== */
    $runDates = [];
    foreach ($schoolEventData as $schoolArr) {
        foreach ($schoolArr as $dateArr) {
            foreach (array_keys($dateArr) as $d) {
                if (!empty($d)) $runDates[$d] = $d;
            }
        }
    }
    ksort($runDates);

    $runEventSummary = [];
    foreach ($eventMeta as $meta) {
        $dayStrings = array_map(
            fn($d) => ($d['date'] ?? '')." (".($d['time'] ?? '').")",
            $meta['days'] ?? []
        );
        $runEventSummary[] = [
            "name"            => $meta['name'],
            "venue"           => $meta['venue'],
            "organising_team" => $meta['organising_team'] ?? '',
            "multiday"        => $meta['multiday'],
            "days"            => $dayStrings
        ];
    }

    $runZips     = array_values($createdZips);
    $runDateFrom = !empty($runDates) ? array_key_first($runDates) : date("Y-m-d");
    $runDateTo   = !empty($runDates) ? array_key_last($runDates)  : $runDateFrom;
    $dateLabel   = ($runDateFrom === $runDateTo) ? $runDateFrom : $runDateFrom." to ".$runDateTo;

    $stmt = $pdo->prepare("INSERT INTO segregation_history
        (run_date_range, date_from, date_to, segregated_on, events, zips)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $dateLabel,
        $runDateFrom,
        $runDateTo,
        date("Y-m-d H:i:s"),
        json_encode($runEventSummary),
        json_encode($runZips)
    ]);

    $_SESSION['files'] = $createdFiles;
    header("Location: register_event.php?segregation=done");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VIT Attendance Segregator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ---- General ---- */
        .event-box { border:1px solid #ccc; padding:15px; margin-bottom:15px; border-radius:10px; background:#f9f9f9; }
        .event-box h3 { margin-top:0; color:black; }
        .download-links a { color:navy; text-decoration:underline; display:block; margin-bottom:5px; }
        table { border-collapse:collapse; width:100%; margin-bottom:20px; }
        th,td { border:1px solid #ccc; padding:7px 10px; text-align:left; }
        th { background:#eee; }
        .page { display:none; }
        .page.active { display:block; }

        /* ---- Buttons ---- */
        .nav button, .submit-btn, .modal-content button, .logout-btn {
            background:rgb(27,0,93) !important; color:white !important; border:none !important;
        }
        .nav button:hover, .submit-btn:hover { background:rgb(45,0,140) !important; }

        /* ---- Multiday ---- */
        .toggle-row { display:flex; align-items:center; gap:10px; margin-bottom:15px; }
        .day-slot { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:10px; background:#fff; }
        .day-slot-header { font-weight:bold; margin-bottom:8px; color:rgb(27,0,93); }
        #day_slots_container .remove-day { background:#c0392b !important; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; float:right; }

        /* ---- Admin panel ---- */
        .admin-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:15px; }
        .admin-controls input[type=text], .admin-controls input[type=date], .admin-controls select {
            padding:7px; border-radius:5px; border:1px solid #ccc;
        }
        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; }
        .pagination button { padding:5px 12px; border:1px solid #ccc; border-radius:4px; cursor:pointer; background:#f0f0f0; }
        .pagination button.active-page { background:rgb(27,0,93); color:white; border-color:rgb(27,0,93); }
        .history-table td, .history-table th { font-size:13px; }
        .badge-multi { background:rgb(27,0,93); color:white; font-size:11px; padding:2px 7px; border-radius:10px; }
        .no-results { color:#888; font-style:italic; padding:10px 0; }
    </style>

    <!-- Disable right-click inspect and devtools shortcuts -->
    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            if (e.key === 'F12') e.preventDefault();
            if (e.ctrlKey && e.shiftKey && ['I','J','C'].includes(e.key)) e.preventDefault();
            if (e.ctrlKey && e.key === 'U') e.preventDefault();
        });
    </script>
</head>
<body>

<div class="top-bar">
    <div class="user-menu" onclick="toggleMenu()">
        <img src="vit-logo.png" class="mini-profile">
        <?php echo htmlspecialchars($_SESSION['user']); ?> ▼
    </div>
    <div class="dropdown" id="dropdownMenu">
        <div class="dropdown-profile"><img src="vit-logo.png"></div>
        <a href="index.php?logout=1" class="logout-btn" onclick="return confirmLogout()">Sign out</a>
    </div>
</div>

<div class="main-header">
    <div class="logo-row">
        <img src="vit-logo.png" class="logo">
        <img src="iic-logo.png" class="logo">
    </div>
    <div class="header-text">
        <h2>Office of Innovation, Startup and Technology Transfer (VIT-IST)</h2>
        <h1>SMART ATTENDANCE SEGREGATOR</h1>
    </div>
</div>

<div class="container">
    <div class="nav">
        <button onclick="showPage('register')">Event Registration</button>
        <button onclick="showPage('segregation')">Excel Segregation</button>
        <button onclick="showPage('admin')">Admin Panel</button>
    </div>

    <!-- ==================== EVENT REGISTRATION ==================== -->
    <div id="register" class="page active">
        <h2>Register New Event</h2>
        <form action="" method="POST" autocomplete="off">
            <div class="form-row">
                <label>Event Name</label>
                <input type="text" name="event_name" required>
            </div>
            <div class="form-row">
                <label>Event Venue</label>
                <input type="text" name="event_venue" required>
            </div>
            <div class="form-row">
                <label>Organising Team</label>
                <input type="text" name="organising_team" required>
            </div>

            <!-- Multiday toggle -->
            <div class="toggle-row">
                <label><strong>Multi-day Event?</strong></label>
                <input type="checkbox" id="multiday_toggle" name="is_multiday" value="1" onchange="toggleMultiday()">
            </div>

            <!-- Single-day fields -->
            <div id="singleday_fields">
                <div class="form-row">
                    <label>Event Date</label>
                    <input type="date" name="event_date">
                </div>
                <div class="form-row">
                    <label>Event Timing</label>
                    <div class="time-group">
                        <div>
                            <span>From</span><br>
                            <select name="from_hour"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="from_minute"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="from_ampm"><option>AM</option><option>PM</option></select>
                        </div>
                        <div>
                            <span>To</span><br>
                            <select name="to_hour"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="to_minute"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="to_ampm"><option>AM</option><option>PM</option></select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Multi-day fields -->
            <div id="multiday_fields" style="display:none;">
                <div id="day_slots_container"></div>
                <button type="button" onclick="addDaySlot()" style="margin-bottom:12px;padding:7px 18px;background:rgb(27,0,93);color:white;border:none;border-radius:5px;cursor:pointer;">+ Add Day</button>
            </div>

            <button type="submit" name="register_event" class="submit-btn">Add Event</button>
        </form>
    </div>

    <!-- ==================== EXCEL SEGREGATION ==================== -->
    <div id="segregation" class="page">
        <h2>Excel Segregation</h2>

        <!-- Step 1: choose event type -->
        <div class="form-row" style="margin-bottom:18px;">
            <label><strong>Step 1 — Select Event Type</strong></label>
            <div style="display:flex;gap:12px;margin-top:8px;">
                <button type="button" id="btn_oneday"   onclick="setEventTypeFilter('oneday')"
                    style="padding:10px 28px;border-radius:6px;border:2px solid rgb(27,0,93);background:white;color:rgb(27,0,93);font-weight:bold;cursor:pointer;">
                    📅 One Day Event
                </button>
                <button type="button" id="btn_multiday" onclick="setEventTypeFilter('multiday')"
                    style="padding:10px 28px;border-radius:6px;border:2px solid rgb(27,0,93);background:white;color:rgb(27,0,93);font-weight:bold;cursor:pointer;">
                    📆 Multi-Day Event
                </button>
            </div>
        </div>

        <!-- Step 2: date range (shown after type selection) -->
        <div id="date_range_row" style="display:none;">
            <div class="form-row">
                <label><strong>Step 2 — Select Date Range</strong></label>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;">
                    <input type="date" id="filter_date_from" placeholder="From">
                    <span>to</span>
                    <input type="date" id="filter_date_to" placeholder="To (optional for one-day)">
                    <button type="button" onclick="filterEventsByRange()"
                        style="padding:8px 16px;background:rgb(27,0,93);color:white;border:none;border-radius:5px;cursor:pointer;">
                        Find Events
                    </button>
                </div>
            </div>

            <div class="form-row" style="margin-top:12px;">
                <label><strong>Step 3 — Number of Events to Segregate</strong></label>
                <select id="num_events" disabled style="margin-top:6px;">
                    <option value="">-- Select Number of Events --</option>
                </select>
            </div>
        </div>

        <form id="segregate_all_form" method="POST" enctype="multipart/form-data">
            <div id="event_toggles"></div>
            <div id="segregate_btn_wrap" style="display:none;">
                <button type="submit" name="segregate_all" class="submit-btn">Segregate All Events</button>
            </div>
        </form>

        <div id="segregation_results" class="download-links">
            <?php
            if (isset($_SESSION['files'])) {
                echo "<hr><h3>✅ Segregation Completed Successfully</h3>";

                $zipFiles    = array_filter($_SESSION['files'], fn($f) => str_ends_with($f, '.zip'));
                $schoolFiles = array_filter($_SESSION['files'], fn($f) => !str_ends_with($f, '.zip'));

                // Individual school files first
                if (!empty($schoolFiles)) {
                    echo "<p><strong>📄 Individual School Files:</strong></p>";
                    foreach ($schoolFiles as $file) {
                        $sf = htmlspecialchars($file);
                        echo "<p style='margin:4px 0 4px 12px;'>⬇ <a href='downloads/$sf' target='_blank'>$sf</a></p>";
                    }
                }

                // ZIP at the bottom
                foreach ($zipFiles as $file) {
                    $sf = htmlspecialchars($file);
                    echo "<p style='margin-top:12px;'><strong>📦 Download All (ZIP):</strong> <a href='downloads/$sf' target='_blank'>$sf</a></p>";
                }

                unset($_SESSION['files']);
            }
            ?>
        </div>
    </div>

    <!-- ==================== ADMIN PANEL ==================== -->
    <div id="admin" class="page">
        <h2>Admin Panel – Segregation History</h2>

        <?php
        // History already loaded from DB in clean format — just normalize for JS
        $adminHistory = [];
        foreach ($history as $record) {
            $eventSummaries   = [];
            $teamsList        = [];
            foreach (($record['events'] ?? []) as $ev) {
                if (!is_array($ev)) continue;
                $team  = $ev['organising_team'] ?? '';
                $days  = isset($ev['days']) ? implode('; ', (array)$ev['days']) : '';
                $eventSummaries[] = $ev['name']." | ".($ev['venue'] ?? '').($days ? " | ".$days : "");
                if ($team) $teamsList[] = $team;
            }
            $adminHistory[] = [
                "date_range"       => $record['run_date_range'] ?? '',
                "date_from"        => $record['date_from']      ?? '',
                "date_to"          => $record['date_to']        ?? '',
                "segregated_on"    => $record['segregated_on']  ?? '',
                "events_text"      => implode("\n", $eventSummaries),
                "event_count"      => count($record['events'] ?? []),
                "organising_teams" => implode(", ", array_unique($teamsList)),
                "zips"             => $record['zips'] ?? []
            ];
        }
        ?>

        <div class="admin-controls">
            <input type="text"  id="admin_search"    placeholder="Search event name / venue..." oninput="renderAdminTable()">
            <input type="date"  id="admin_date_from" onchange="renderAdminTable()">
            <span>to</span>
            <input type="date"  id="admin_date_to"   onchange="renderAdminTable()">
            <select id="admin_sort" onchange="renderAdminTable()">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="event_date_asc">Event Date ↑</option>
                <option value="event_date_desc">Event Date ↓</option>
            </select>
            <button onclick="clearAdminFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear Filters</button>
        </div>

        <div id="admin_table_container"></div>
        <div class="pagination" id="admin_pagination"></div>
    </div>

    <!-- ==================== MODAL ==================== -->
    <div id="rulesModal" class="modal">
        <div class="modal-content">
            <h2>WELCOME TO SMART ATTENDANCE SEGREGATOR</h2>
            <h2>Please read this page!</h2>
            <ul>
                <li>Event Name must be unique.</li>
                <li>Event details must be accurate.</li>
                <li>For multi-day events, add one slot per day with its own date and time.</li>
                <li>Upload attendance Excel with date headers (row 1) for multi-day events.</li>
            </ul>
            <button id="closeModal">I Understand</button>
        </div>
    </div>
</div>

<script>
/* ==================== HELPERS ==================== */
function showPage(pageId) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.getElementById(pageId).classList.add('active');
}
function toggleMenu() { document.getElementById("dropdownMenu").classList.toggle("show"); }
window.addEventListener("click", e => {
    if (!e.target.closest('.user-menu')) document.getElementById("dropdownMenu").classList.remove("show");
});
function confirmLogout() {
    return confirm("Are you sure you want to sign out?");
}

/* ==================== MODAL ==================== */
const modal     = document.getElementById('rulesModal');
const closeModal = document.getElementById('closeModal');
closeModal.addEventListener('click', () => { modal.style.display = 'none'; });

/* ==================== MULTI-DAY REGISTRATION ==================== */
let dayCount = 0;

function makeTimeSelects(prefix, idx) {
    let h = `<select name="${prefix}_hour[${idx}]">`, m = `<select name="${prefix}_minute[${idx}]">`, ap = `<select name="${prefix}_ampm[${idx}]">`;
    for (let i = 1; i <= 12; i++) h += `<option>${i}</option>`;
    h += '</select>';
    for (let i = 0; i <= 59; i++) { let mm = String(i).padStart(2,'0'); m += `<option>${mm}</option>`; }
    m += '</select>';
    ap += '<option>AM</option><option>PM</option></select>';
    return h + ' ' + m + ' ' + ap;
}

function addDaySlot() {
    const container = document.getElementById('day_slots_container');
    const idx = dayCount++;
    const div = document.createElement('div');
    div.classList.add('day-slot');
    div.id = 'day_slot_' + idx;
    div.innerHTML = `
        <div class="day-slot-header">Day ${idx + 1} <button type="button" class="remove-day" onclick="removeDaySlot(${idx})">✕ Remove</button></div>
        <div class="form-row">
            <label>Date</label>
            <input type="date" name="day_date[${idx}]" required>
        </div>
        <div class="form-row">
            <label>Timing</label>
            <div class="time-group">
                <div><span>From</span><br>${makeTimeSelects('day_from', idx)}</div>
                <div><span>To</span><br>${makeTimeSelects('day_to', idx)}</div>
            </div>
        </div>`;
    container.appendChild(div);
}

function removeDaySlot(idx) {
    const el = document.getElementById('day_slot_' + idx);
    if (el) el.remove();
}

function toggleMultiday() {
    const checked = document.getElementById('multiday_toggle').checked;
    document.getElementById('singleday_fields').style.display = checked ? 'none' : 'block';
    document.getElementById('multiday_fields').style.display  = checked ? 'block' : 'none';
    // Manage required attributes
    document.querySelectorAll('#singleday_fields input, #singleday_fields select').forEach(el => {
        if (el.type === 'date') el.required = !checked;
    });
    if (checked && dayCount === 0) addDaySlot();
}

/* ==================== SEGREGATION ==================== */
const eventsData            = <?php echo json_encode($events); ?>;
const filterDateFrom        = document.getElementById('filter_date_from');
const filterDateTo          = document.getElementById('filter_date_to');
const numEventsSelect       = document.getElementById('num_events');
const eventTogglesContainer = document.getElementById('event_toggles');
const segregatedContainer   = document.getElementById('segregation_results');

let availableEvents  = [];
let currentTypeFilter = null; // 'oneday' or 'multiday'

function setEventTypeFilter(type) {
    currentTypeFilter = type;

    // Update button styles
    const btnOne   = document.getElementById('btn_oneday');
    const btnMulti = document.getElementById('btn_multiday');
    const activeStyle   = 'padding:10px 28px;border-radius:6px;border:2px solid rgb(27,0,93);background:rgb(27,0,93);color:white;font-weight:bold;cursor:pointer;';
    const inactiveStyle = 'padding:10px 28px;border-radius:6px;border:2px solid rgb(27,0,93);background:white;color:rgb(27,0,93);font-weight:bold;cursor:pointer;';
    btnOne.style.cssText   = (type === 'oneday')   ? activeStyle : inactiveStyle;
    btnMulti.style.cssText = (type === 'multiday') ? activeStyle : inactiveStyle;

    // Show date range section
    document.getElementById('date_range_row').style.display = 'block';

    // Reset downstream
    eventTogglesContainer.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    numEventsSelect.innerHTML = '<option value="">-- Select Number of Events --</option>';
    numEventsSelect.disabled  = true;
    filterDateFrom.value = '';
    filterDateTo.value   = '';
    segregatedContainer.innerHTML = '';

    // Adjust "To" date label
    document.querySelector('#date_range_row label').innerHTML =
        '<strong>Step 2 — Select Date' + (type === 'multiday' ? ' Range' : '') + '</strong>';
}

function filterEventsByRange() {
    const from = filterDateFrom.value;
    const to   = filterDateTo.value || from;

    if (!from) { segregatedContainer.innerHTML = '<p>Please select a date.</p>'; return; }

    // Filter by type AND date range
    availableEvents = eventsData.filter(ev => {
        const evStart  = ev.date;
        const evEnd    = ev.end_date || ev.date;
        const inRange  = evStart <= to && evEnd >= from;
        if (currentTypeFilter === 'oneday') {
            // One-day: not multiday
            return inRange && !ev.multiday;
        } else {
            // Multi-day: show both (multiday events AND single-day events in range)
            return inRange;
        }
    });

    eventTogglesContainer.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    segregatedContainer.innerHTML   = '';

    if (availableEvents.length === 0) {
        segregatedContainer.innerHTML = '<p>No events found for this selection.</p>';
        numEventsSelect.innerHTML = '<option value="">-- No Events --</option>';
        numEventsSelect.disabled  = true;
        return;
    }

    segregatedContainer.innerHTML = `<p>${availableEvents.length} event(s) found.</p>`;
    let opts = '';
    for (let i = 1; i <= availableEvents.length; i++) opts += `<option value="${i}">${i}</option>`;
    numEventsSelect.innerHTML = opts;
    numEventsSelect.disabled  = false;
    numEventsSelect.value     = "1";
    numEventsSelect.dispatchEvent(new Event('change'));
}

function updateDropdownOptions() {
    const selected = Array.from(document.querySelectorAll('.event-name-select')).map(s => s.value).filter(Boolean);
    document.querySelectorAll('.event-name-select').forEach(sel => {
        const cur = sel.value;
        Array.from(sel.options).forEach(opt => {
            if (!opt.value) return;
            opt.disabled = selected.includes(opt.value) && opt.value !== cur;
        });
    });
}

numEventsSelect.addEventListener('change', function () {
    const count = parseInt(this.value);
    eventTogglesContainer.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    if (!count || availableEvents.length === 0) return;

    for (let i = 0; i < count; i++) {
        const div = document.createElement('div');
        div.classList.add('event-box');

        let options = availableEvents.map(ev => {
            let val, label;
            if (ev.multiday) {
                const dStart = ev.date ? new Date(ev.date).toLocaleDateString('en-IN') : '';
                const dEnd   = ev.end_date ? new Date(ev.end_date).toLocaleDateString('en-IN') : '';
                val   = `${ev.name}||${ev.venue}||MULTIDAY`;
                label = `${ev.name} (Multi-day: ${dStart} – ${dEnd} | ${ev.venue})`;
            } else {
                val   = `${ev.name}||${ev.venue}||${ev.date}||${ev.time}`;
                label = `${ev.name} (${ev.date} | ${ev.time} | ${ev.venue})`;
            }
            return `<option value="${val}">${label}</option>`;
        }).join('');

        div.innerHTML = `
            <h3>Event ${i + 1}</h3>
            <div class="form-row">
                <label>Event Name</label>
                <select name="selected_event[]" class="event-name-select">
                    <option value="">-- Select Event --</option>
                    ${options}
                </select>
            </div>
            <div id="event_meta_${i}" style="margin-top:8px;color:#555;font-size:13px;"></div>
            <div class="form-row" style="margin-top:10px;">
                <label>Upload Attendance Excel</label>
                <input type="file" name="excel_file[]" accept=".xlsx,.xls" required>
            </div>`;

        eventTogglesContainer.appendChild(div);
    }

    document.getElementById('segregate_btn_wrap').style.display = 'block';

    document.querySelectorAll('.event-name-select').forEach((sel, idx) => {
        sel.addEventListener('change', function () {
            updateDropdownOptions();
            const val  = this.value;
            const meta = document.getElementById('event_meta_' + idx);
            if (!val) { meta.innerHTML = ''; return; }
            const parts = val.split('||');
            if (parts[2] === 'MULTIDAY') {
                const evObj = availableEvents.find(e => e.name === parts[0]);
                if (evObj && evObj.days) {
                    meta.innerHTML = '<strong>Days:</strong> ' +
                        evObj.days.map(d => `${d.date} (${d.time})`).join(', ') +
                        '<br><small style="color:red;">⚠ Excel must have date headers in row 1 (one column per day).</small>';
                }
            } else {
                meta.innerHTML = `<strong>Date:</strong> ${parts[2] || ''} &nbsp; <strong>Time:</strong> ${parts[3] || ''}`;
            }
        });
    });
});

/* ==================== ADMIN PANEL ==================== */
const allHistoryData = <?php echo json_encode($adminHistory); ?>;
const PAGE_SIZE      = 10;
let adminCurrentPage = 1;

function getFilteredHistory() {
    const search   = document.getElementById('admin_search').value.toLowerCase();
    const dateFrom = document.getElementById('admin_date_from').value;
    const dateTo   = document.getElementById('admin_date_to').value;
    const sort     = document.getElementById('admin_sort').value;

    let data = allHistoryData.filter(r => {
        const matchText = !search ||
            r.events_text.toLowerCase().includes(search) ||
            r.date_range.toLowerCase().includes(search) ||
            (r.organising_teams || '').toLowerCase().includes(search);
        const matchFrom = !dateFrom || r.date_to   >= dateFrom;
        const matchTo   = !dateTo   || r.date_from <= dateTo;
        return matchText && matchFrom && matchTo;
    });

    data.sort((a, b) => {
        if (sort === 'newest')          return b.segregated_on.localeCompare(a.segregated_on);
        if (sort === 'oldest')          return a.segregated_on.localeCompare(b.segregated_on);
        if (sort === 'event_date_asc')  return a.date_from.localeCompare(b.date_from);
        if (sort === 'event_date_desc') return b.date_from.localeCompare(a.date_from);
        return 0;
    });

    return data;
}

function formatDate(ymd) {
    if (!ymd) return '';
    const parts = ymd.split('-');
    if (parts.length !== 3) return ymd;
    return parts[2]+'-'+parts[1]+'-'+parts[0];
}

function renderAdminTable() {
    adminCurrentPage = 1;
    renderAdminPage();
}

function renderAdminPage() {
    const data    = getFilteredHistory();
    const total   = data.length;
    const pages   = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start   = (adminCurrentPage - 1) * PAGE_SIZE;
    const slice   = data.slice(start, start + PAGE_SIZE);

    const container  = document.getElementById('admin_table_container');
    const pagination = document.getElementById('admin_pagination');

    if (total === 0) {
        container.innerHTML  = '<p class="no-results">No records match your filters.</p>';
        pagination.innerHTML = '';
        return;
    }

    let html = `<p style="font-size:13px;color:#555;">Showing ${start+1}–${Math.min(start+PAGE_SIZE,total)} of ${total} records</p>`;
    html += `<table class="history-table">
        <tr>
            <th>#</th>
            <th>Event Date(s)</th>
            <th>Events Segregated</th>
            <th>Organising Team</th>
            <th>No. of Events</th>
            <th>Segregated On</th>
            <th>Download</th>
        </tr>`;

    slice.forEach((r, i) => {
        const drFrom = r.date_from ? formatDate(r.date_from) : '';
        const drTo   = r.date_to   ? formatDate(r.date_to)   : '';
        const dateDisplay = (drFrom && drTo && drFrom !== drTo) ? drFrom + ' – ' + drTo : drFrom;

        const evLines = r.events_text.split('\n').map(line =>
            `<div style="margin-bottom:3px;">• ${line}</div>`
        ).join('');

        // Admin panel: only show ZIP
        const zipLinks = (r.zips || []).filter(z => z.endsWith('.zip')).map(z =>
            `<a href="downloads/${z}" target="_blank" style="display:block;margin-bottom:3px;">📦 ${z}</a>`
        ).join('') || '–';

        html += `<tr>
            <td>${start + i + 1}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;line-height:1.6;">${evLines}</td>
            <td style="font-size:12px;">${r.organising_teams || '–'}</td>
            <td style="text-align:center;">${r.event_count}</td>
            <td style="white-space:nowrap;">${r.segregated_on}</td>
            <td style="font-size:12px;">${zipLinks}</td>
        </tr>`;
    });

    html += '</table>';
    container.innerHTML = html;

    // Pagination buttons
    let pHtml = '';
    for (let p = 1; p <= pages; p++) {
        pHtml += `<button class="${p === adminCurrentPage ? 'active-page' : ''}" onclick="goAdminPage(${p})">${p}</button>`;
    }
    pagination.innerHTML = pHtml;
}

function goAdminPage(p) {
    adminCurrentPage = p;
    renderAdminPage();
}

function clearAdminFilters() {
    document.getElementById('admin_search').value    = '';
    document.getElementById('admin_date_from').value = '';
    document.getElementById('admin_date_to').value   = '';
    document.getElementById('admin_sort').value      = 'newest';
    renderAdminTable();
}

/* ==================== PAGE LOAD ==================== */
window.addEventListener("load", function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has("segregation")) {
        showPage("segregation");
    } else {
        modal.style.display = 'block';
    }
    renderAdminTable();
});
</script>
</body>
</html>

<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user']) || empty($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require 'vendor/autoload.php';
require 'db.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* ===== LOAD DATA FROM DB ===== */
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

$historyRaw = $pdo->query("SELECT * FROM segregation_history ORDER BY segregated_on DESC")->fetchAll();
$history = [];
foreach ($historyRaw as $row) {
    $history[] = [
        'id'             => $row['id'],
        'run_date_range' => $row['run_date_range'],
        'date_from'      => $row['date_from'],
        'date_to'        => $row['date_to'],
        'segregated_on'  => $row['segregated_on'],
        'events'         => $row['events'] ? json_decode($row['events'], true) : [],
        'zips'           => $row['zips']   ? json_decode($row['zips'],   true) : [],
    ];
}

$schoolsRaw  = $pdo->query("SELECT school_name, codes FROM schools ORDER BY school_name ASC")->fetchAll();
$schoolCodes = [];
foreach ($schoolsRaw as $row) {
    $schoolCodes[$row['school_name']] = json_decode($row['codes'], true);
}

/* ===== ANALYTICS DATA ===== */

// --- Events: monthly breakdown & type split ---
$eventsMonthly   = array_fill(1, 12, 0); // month 1..12
$totalSingleDay  = 0;
$totalMultiDay   = 0;
$eventYears      = [];
foreach ($eventsRaw as $ev) {
    $m = (int)date('n', strtotime($ev['date']));
    $y = (int)date('Y', strtotime($ev['date']));
    $eventsMonthly[$m]++;
    $eventYears[$y] = true;
    if ($ev['multiday']) $totalMultiDay++; else $totalSingleDay++;
}

// --- Segregation: monthly breakdown & run stats ---
$segregMonthly   = array_fill(1, 12, 0);
$runEventCounts  = []; // events per run
$lastSegOn       = null;
$lastEventOn     = null;

foreach ($historyRaw as $h) {
    $m = (int)date('n', strtotime($h['segregated_on']));
    $segregMonthly[$m]++;
    $evArr = $h['events'] ? json_decode($h['events'], true) : [];
    $runEventCounts[] = count($evArr);
    if (!$lastSegOn) $lastSegOn = $h['segregated_on'];
}
if (!empty($eventsRaw)) {
    $lastEventRow = end($eventsRaw);
    $lastEventOn  = $lastEventRow['date'].' ('.$lastEventRow['name'].')';
}

$totalEventsRegistered  = count($eventsRaw);
$totalSegregationRuns   = count($historyRaw);
$avgEventsPerRun        = $totalSegregationRuns > 0 ? round(array_sum($runEventCounts) / $totalSegregationRuns, 1) : 0;
$maxEventsInRun         = !empty($runEventCounts) ? max($runEventCounts) : 0;
$minEventsInRun         = !empty($runEventCounts) ? min($runEventCounts) : 0;

// Most active month for events
$peakEvMonth    = array_search(max($eventsMonthly), $eventsMonthly);
$peakSegMonth   = array_search(max($segregMonthly), $segregMonthly);
$monthNames     = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Smart insights
$smartInsights = [];
if ($totalEventsRegistered > 0) {
    $smartInsights[] = "📅 <strong>".$monthNames[$peakEvMonth]."</strong> was the most active month for event registrations (".($eventsMonthly[$peakEvMonth])." events).";
}
if ($totalSegregationRuns > 0) {
    $smartInsights[] = "⚡ System has processed <strong>$totalSegregationRuns</strong> segregation run(s) with an average of <strong>$avgEventsPerRun</strong> events per run.";
}
if ($totalMultiDay > 0) {
    $pct = round($totalMultiDay / max($totalEventsRegistered,1) * 100);
    $smartInsights[] = "📆 <strong>$pct%</strong> of registered events are multi-day events ($totalMultiDay out of $totalEventsRegistered).";
}
if ($lastSegOn) {
    $smartInsights[] = "🕓 Last segregation was done on <strong>".date('d M Y, h:i A', strtotime($lastSegOn))."</strong>.";
}
if ($lastEventOn) {
    $smartInsights[] = "📋 Most recently registered event: <strong>$lastEventOn</strong>.";
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

        $stmt = $pdo->prepare("INSERT INTO events (name, venue, organising_team, multiday, date, end_date, days) VALUES (?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([
            $_POST['event_name'], $_POST['event_venue'], $_POST['organising_team'] ?? '',
            $days[0]['date'], end($days)['date'], json_encode($days)
        ]);
    } else {
        $time = $_POST['from_hour'].":".$_POST['from_minute']." ".$_POST['from_ampm']
              . " - "
              . $_POST['to_hour'].":".$_POST['to_minute']." ".$_POST['to_ampm'];

        $stmt = $pdo->prepare("INSERT INTO events (name, venue, organising_team, multiday, date, time) VALUES (?, ?, ?, 0, ?, ?)");
        $stmt->execute([
            $_POST['event_name'], $_POST['event_venue'], $_POST['organising_team'] ?? '',
            $_POST['event_date'], $time
        ]);
    }

    header("Location: register_event.php");
    exit();
}

/* ================= DELETE EVENT ================= */
if (isset($_POST['delete_event'])) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
    $stmt->execute([(int)$_POST['event_id']]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ================= DELETE HISTORY RECORD ================= */
if (isset($_POST['delete_history'])) {
    $stmt = $pdo->prepare("DELETE FROM segregation_history WHERE id = ?");
    $stmt->execute([(int)$_POST['history_id']]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ================= SEGREGATION ================= */
if (isset($_POST['segregate_all'])) {

    if (!file_exists("downloads")) mkdir("downloads", 0777, true);

    $createdFiles    = [];
    $schoolEventData = [];
    $eventMeta       = [];

    foreach ($_POST['selected_event'] as $index => $selectedValue) {
        if (empty($selectedValue)) continue;

        $parts     = explode("||", $selectedValue);
        $eventName = trim($parts[0] ?? '');
        $isMulti   = isset($parts[2]) && trim($parts[2]) === 'MULTIDAY';

        $evObj = null;
        foreach ($events as $ev) {
            if ($ev['name'] === $eventName) { $evObj = $ev; break; }
        }
        if (!$evObj) continue;

        if (empty($evObj['days'])) {
            $evObj['days'] = [["date" => $evObj['date'] ?? '', "time" => $evObj['time'] ?? '']];
        }

        if ($isMulti || !empty($evObj['multiday'])) {
            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $evObj['venue'] ?? '',
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => true,
                "days"            => $evObj['days']
            ];

            foreach ($evObj['days'] as $dayIdx => $day) {
                $fileKey = "excel_file_{$index}_{$dayIdx}";
                $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
                if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;

                $dateVal     = $day['date'];
                $spreadsheet = IOFactory::load($tmpFile);
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
                                $schoolEventData[$index][$school][$dateVal][$code][] = $regNo;
                            }
                        }
                    }
                }
            }

        } else {
            $date = $evObj['date'] ?? '';
            $time = $evObj['time'] ?? '';

            $eventMeta[$index] = [
                "name"            => $eventName,
                "venue"           => $evObj['venue'] ?? '',
                "organising_team" => $evObj['organising_team'] ?? '',
                "multiday"        => false,
                "days"            => [["date" => $date, "time" => $time]]
            ];

            $fileKey = "excel_file_{$index}_0";
            $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
            if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;

            $spreadsheet = IOFactory::load($tmpFile);
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

    /* ===== BUILD ONE EXCEL PER SCHOOL ===== */
    $allSchoolFiles = [];

    foreach ($schoolCodes as $school => $codes) {
        $hasData = false;
        foreach ($eventMeta as $index => $meta) {
            if (!empty($schoolEventData[$index][$school])) { $hasData = true; break; }
        }
        if (!$hasData) continue;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $currentRow  = 1;

        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;

            $sheet->setCellValue('A'.$currentRow, $meta['name']." | ".$meta['venue']);
            $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true)->setSize(13);
            $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
            $currentRow++;

            $dateTimeMap = [];
            foreach (($meta['days'] ?? []) as $day) {
                if (!empty($day['date'])) $dateTimeMap[$day['date']] = $day['time'];
            }

            $collectedDates = array_keys($schoolEventData[$index][$school]);
            sort($collectedDates);

            foreach ($collectedDates as $dateKey) {
                if (empty($schoolEventData[$index][$school][$dateKey])) continue;

                $dateCodes = $schoolEventData[$index][$school][$dateKey];
                $dateStr   = date("d-m-Y", strtotime($dateKey));
                $timeStr   = $dateTimeMap[$dateKey] ?? $meta['days'][0]['time'] ?? '';

                $sheet->setCellValue('A'.$currentRow, $dateStr.($timeStr ? " | ".$timeStr : ""));
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('D9E1F2');
                $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
                $currentRow++;

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

            $currentRow += 1;
        }

        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $highestCol; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $schoolDates = [];
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;
            foreach (array_keys($schoolEventData[$index][$school]) as $d) {
                $schoolDates[$d] = $d;
            }
        }
        ksort($schoolDates);
        $dateRange = count($schoolDates) > 1
            ? array_key_first($schoolDates)."_to_".array_key_last($schoolDates)
            : (array_key_first($schoolDates) ?? date("Y-m-d"));

        $fileName = $school."_".$dateRange.".xlsx";
        $filePath = "downloads/".$fileName;

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $createdFiles[]   = $fileName;
        $allSchoolFiles[] = $filePath;
    }

    /* ===== CREATE ONE ZIP ===== */
    $createdZips = [];
    if (!empty($allSchoolFiles)) {
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

    /* ===== UPDATE HISTORY ===== */
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

    $runDateFrom = !empty($runDates) ? array_key_first($runDates) : date("Y-m-d");
    $runDateTo   = !empty($runDates) ? array_key_last($runDates)  : $runDateFrom;
    $dateLabel   = ($runDateFrom === $runDateTo) ? $runDateFrom : $runDateFrom." to ".$runDateTo;

    $stmt = $pdo->prepare("INSERT INTO segregation_history (run_date_range, date_from, date_to, segregated_on, events, zips) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $dateLabel, $runDateFrom, $runDateTo, date("Y-m-d H:i:s"),
        json_encode($runEventSummary), json_encode($createdZips)
    ]);

    $_SESSION['files'] = $createdFiles;
    header("Location: register_event.php?tab=segregation&segregation=done");
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
        .event-box { border:1px solid #d0d0d0; padding:18px; margin-bottom:14px; border-radius:10px; background:#f9f9f9; }
        .event-box h3 { margin:0 0 12px 0; color:rgb(27,0,93); font-size:16px; }
        .download-links a { color:navy; text-decoration:underline; display:block; margin-bottom:5px; }
        .page { display:none; }
        .page.active { display:block; }

        table { border-collapse:collapse; width:100%; margin-bottom:20px; font-size:13px; }
        th,td { border:1px solid #ddd; padding:8px 10px; text-align:left; }
        th { background:#f0eeff; color:rgb(27,0,93); font-weight:700; }
        tr:hover { background:#fafafa; }

        .nav button, .submit-btn, .modal-content button, .logout-btn {
            background:rgb(27,0,93) !important; color:white !important; border:none !important;
        }
        .nav button:hover, .submit-btn:hover { background:rgb(45,0,140) !important; }
        .btn-delete { background:#c0392b !important; color:white; border:none; padding:5px 12px; border-radius:5px; cursor:pointer; font-size:12px; font-weight:bold; }
        .btn-delete:hover { background:#a93226 !important; }

        .toggle-row { display:flex; align-items:center; gap:10px; margin-bottom:15px; }
        .day-slot { border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:10px; background:#fff; }
        .day-slot-header { font-weight:bold; margin-bottom:8px; color:rgb(27,0,93); }
        .remove-day { background:#c0392b !important; color:white; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; float:right; }

        .step-label { font-weight:700; color:rgb(27,0,93); font-size:14px; margin-bottom:6px; display:block; }
        .date-filter-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
        .date-filter-row input[type=date] { padding:8px; border-radius:5px; border:1px solid #ccc; }
        .find-btn { padding:8px 18px; background:rgb(27,0,93); color:white; border:none; border-radius:5px; cursor:pointer; font-weight:bold; }
        .find-btn:hover { background:rgb(45,0,140); }

        .day-upload-slot { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:12px 14px; margin-bottom:8px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .day-upload-slot .day-label { font-weight:700; color:rgb(27,0,93); min-width:130px; font-size:13px; }
        .day-upload-slot .day-time  { color:#555; font-size:12px; min-width:160px; }
        .day-upload-slot input[type=file] { flex:1; min-width:200px; }

.admin-tabs {
    display:flex;
    flex-wrap:wrap; /* ADD THIS */
    gap:4px;        /* small spacing */
    margin-bottom:20px;
    border-bottom:2px solid rgb(27,0,93);
}        .admin-tab  { padding:9px 24px; cursor:pointer; font-weight:700; border:none; background:#f0eeff; color:rgb(27,0,93); border-radius:6px 6px 0 0; margin-right:4px; font-size:14px; }
        .admin-tab.active { background:rgb(27,0,93); color:white; }

        .admin-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
        .admin-controls input[type=text], .admin-controls input[type=date], .admin-controls select { padding:7px; border-radius:5px; border:1px solid #ccc; }
        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; }
        .pagination button { padding:5px 12px; border:1px solid #ccc; border-radius:4px; cursor:pointer; background:#f0f0f0; }
        .pagination button.active-page { background:rgb(27,0,93); color:white; border-color:rgb(27,0,93); }
        .badge-multi { background:rgb(27,0,93); color:white; font-size:11px; padding:2px 7px; border-radius:10px; }
        .no-results { color:#888; font-style:italic; padding:10px 0; }
        .section-count { font-size:13px; color:#555; margin-bottom:8px; }
        .dropdown.show { display:block; }
        /* ---- Analytics ---- */
        .kpi-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:22px; }
        .kpi-card { flex:1; min-width:140px; background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px 14px; text-align:center; box-shadow:0 2px 8px rgba(27,0,93,0.07); }
        .kpi-icon  { font-size:26px; margin-bottom:6px; }
        .kpi-value { font-size:32px; font-weight:800; color:rgb(27,0,93); line-height:1; }
        .kpi-label { font-size:12px; color:#777; margin-top:5px; font-weight:600; letter-spacing:0.5px; }
        .insight-box { background:linear-gradient(135deg,rgb(27,0,93) 0%,rgb(60,0,160) 100%); color:white; border-radius:12px; padding:20px 24px; margin-bottom:22px; }
        .insight-title { font-size:15px; font-weight:800; margin-bottom:12px; letter-spacing:1px; }
        .insight-line  { font-size:13px; margin-bottom:8px; line-height:1.6; opacity:0.95; }
        .insight-line strong { color:#ffe066; }
        .charts-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
        .chart-card { flex:1; min-width:280px; background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px; box-shadow:0 2px 8px rgba(27,0,93,0.07); }
        .chart-card-small { flex:0 0 320px; }
        .chart-title { font-size:13px; font-weight:700; color:rgb(27,0,93); margin-bottom:12px; }
        .analytics-section { background:white; border:1px solid #e0e0e0; border-radius:12px; padding:18px; margin-bottom:18px; box-shadow:0 2px 8px rgba(27,0,93,0.07); }

    </style>

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
                <label>Organising Tem</label>
                <input type="text" name="organising_team" required>
            </div>

            <div class="toggle-row">
                <label><strong>Multi-day Event?</strong></label>
                <input type="checkbox" id="multiday_toggle" name="is_multiday" value="1" onchange="toggleMultiday()">
            </div>

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

        <span class="step-label">Step 1 — Select Date Range</span>
        <div class="date-filter-row">
            <input type="date" id="filter_date_from">
            <span>to</span>
            <input type="date" id="filter_date_to">
            <button class="find-btn" onclick="filterEventsByRange()">🔍 Find Events</button>
        </div>

        <div id="num_events_row" style="display:none; margin-bottom:18px;">
            <span class="step-label">Step 2 — Number of Events to Segregate</span>
            <select id="num_events" style="padding:8px;border-radius:5px;border:1px solid #ccc;min-width:220px;">
                <option value="">-- Select --</option>
            </select>
        </div>

        <form id="segregate_all_form" method="POST" enctype="multipart/form-data">
            <div id="event_toggles"></div>
            <div id="segregate_btn_wrap" style="display:none;">
                <button type="submit" name="segregate_all" class="submit-btn">⚡ Segregate All Events</button>
            </div>
        </form>

        <div id="segregation_results" class="download-links" style="margin-top:16px;">
            <?php
            if (isset($_SESSION['files'])) {
                echo "<hr><h3>✅ Segregation Completed Successfully</h3>";
                $zipFiles    = array_filter($_SESSION['files'], fn($f) => str_ends_with($f, '.zip'));
                $schoolFiles = array_filter($_SESSION['files'], fn($f) => !str_ends_with($f, '.zip'));
                if (!empty($schoolFiles)) {
                    echo "<p><strong>📄 Individual School Files:</strong></p>";
                    foreach ($schoolFiles as $file) {
                        $sf = htmlspecialchars($file);
                        echo "<p style='margin:4px 0 4px 12px;'>⬇ <a href='downloads/$sf' target='_blank'>$sf</a></p>";
                    }
                }
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
        <h2>Admin Panel</h2>

        <div class="admin-tabs">
            <button class="admin-tab active" onclick="switchAdminTab('events')">📋 Events</button>
            <button class="admin-tab" onclick="switchAdminTab('history')">🕓 Segregation History</button>
            <button class="admin-tab" onclick="switchAdminTab('analytics')">📊 Analytics</button>
        </div>

        <div id="admin_events_tab">
            <div class="admin-controls">
                <input type="text" id="ev_search" placeholder="Search event name / venue..." oninput="renderEventsTable()">
                <input type="date" id="ev_date_from" onchange="renderEventsTable()">
                <span>to</span>
                <input type="date" id="ev_date_to" onchange="renderEventsTable()">
                <button onclick="clearEvFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="events_table_container"></div>
            <div class="pagination" id="events_pagination"></div>
        </div>

        <div id="admin_history_tab" style="display:none;">
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
                <button onclick="clearAdminFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="admin_table_container"></div>
            <div class="pagination" id="admin_pagination"></div>
        </div>

        <!-- ANALYTICS SUB-TAB -->
        <div id="admin_analytics_tab" style="display:none;">

            <!-- KPI Cards -->
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-icon">📋</div>
                    <div class="kpi-value"><?= $totalEventsRegistered ?></div>
                    <div class="kpi-label">Events Registered</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">⚡</div>
                    <div class="kpi-value"><?= $totalSegregationRuns ?></div>
                    <div class="kpi-label">Segregation Runs</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">📅</div>
                    <div class="kpi-value"><?= $totalSingleDay ?></div>
                    <div class="kpi-label">Single-Day Events</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">📆</div>
                    <div class="kpi-value"><?= $totalMultiDay ?></div>
                    <div class="kpi-label">Multi-Day Events</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon">🔁</div>
                    <div class="kpi-value"><?= $avgEventsPerRun ?></div>
                    <div class="kpi-label">Avg Events / Run</div>
                </div>
            </div>

            <!-- Smart Insight Box -->
            <?php if (!empty($smartInsights)): ?>
            <div class="insight-box">
                <div class="insight-title">🧠 Smart Insights</div>
                <?php foreach ($smartInsights as $ins): ?>
                    <div class="insight-line">→ <?= $ins ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Charts Row 1: Bar charts -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-title">📅 Events Registered — Monthly</div>
                    <canvas id="chartEventsMonthly" height="220"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title">⚡ Segregation Runs — Monthly</div>
                    <canvas id="chartSegregMonthly" height="220"></canvas>
                </div>
            </div>

            <!-- Charts Row 2: Line + Donut -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-title">📈 Registered vs Segregated (Monthly Trend)</div>
                    <canvas id="chartComparison" height="220"></canvas>
                </div>
                <div class="chart-card chart-card-small">
                    <div class="chart-title">🍩 Event Type Split</div>
                    <canvas id="chartTypeSplit" height="220"></canvas>
                </div>
            </div>

            <!-- Run Stats Table -->
            <div class="analytics-section">
                <div class="chart-title" style="margin-bottom:12px;">📊 Segregation Run Stats</div>
                <table>
                    <tr><th>Metric</th><th>Value</th></tr>
                    <tr><td>Total Segregation Runs</td><td><strong><?= $totalSegregationRuns ?></strong></td></tr>
                    <tr><td>Max Events in a Single Run</td><td><strong><?= $maxEventsInRun ?: '–' ?></strong></td></tr>
                    <tr><td>Min Events in a Single Run</td><td><strong><?= $minEventsInRun ?: '–' ?></strong></td></tr>
                    <tr><td>Average Events per Run</td><td><strong><?= $avgEventsPerRun ?: '–' ?></strong></td></tr>
                    <tr><td>Last Segregation Done</td><td><strong><?= $lastSegOn ? date('d M Y, h:i A', strtotime($lastSegOn)) : '–' ?></strong></td></tr>
                    <tr><td>Last Event Registered</td><td><strong><?= htmlspecialchars($lastEventOn ?? '–') ?></strong></td></tr>
                </table>
            </div>

        </div>

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
                <li>For multi-day segregation, each day gets its own Excel upload box.</li>
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
function confirmLogout() { return confirm("Are you sure you want to sign out?"); }

/* ==================== MODAL ==================== */
const modal = document.getElementById('rulesModal');
document.getElementById('closeModal').addEventListener('click', () => { modal.style.display = 'none'; });

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
        <div class="day-slot-header">Day ${idx + 1}
            <button type="button" class="remove-day" onclick="removeDaySlot(${idx})">✕ Remove</button>
        </div>
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
    document.querySelectorAll('#singleday_fields input[type=date]').forEach(el => { el.required = !checked; });
    if (checked && dayCount === 0) addDaySlot();
}

/* ==================== SEGREGATION ==================== */
const eventsData      = <?php echo json_encode($events); ?>;
const filterDateFrom  = document.getElementById('filter_date_from');
const filterDateTo    = document.getElementById('filter_date_to');
const numEventsSelect = document.getElementById('num_events');
const eventToggles    = document.getElementById('event_toggles');
const segregResults   = document.getElementById('segregation_results');

let availableEvents = [];

function filterEventsByRange() {
    const from = filterDateFrom.value;
    const to   = filterDateTo.value || from;

    if (!from) { alert('Please select a start date.'); return; }

    availableEvents = eventsData.filter(ev => {
        const evStart = ev.date;
        const evEnd   = ev.end_date || ev.date;
        return evStart <= to && evEnd >= from;
    });

    eventToggles.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    segregResults.innerHTML = '';

    if (availableEvents.length === 0) {
        segregResults.innerHTML = '<p style="color:#c0392b;">⚠ No events found in this date range.</p>';
        document.getElementById('num_events_row').style.display = 'none';
        return;
    }

    segregResults.innerHTML = `<p style="color:green;">✅ ${availableEvents.length} event(s) found in range.</p>`;
    let opts = '';
    for (let i = 1; i <= availableEvents.length; i++) opts += `<option value="${i}">${i}</option>`;
    numEventsSelect.innerHTML = '<option value="">-- Select --</option>' + opts;
    numEventsSelect.value     = '';
    document.getElementById('num_events_row').style.display = 'block';
}

function buildFileUploadSlots(evObj, eventIdx) {
    if (evObj.multiday && evObj.days && evObj.days.length > 0) {
        let html = `<div style="margin-top:10px;"><strong style="color:rgb(27,0,93);">📅 Upload Attendance Excel — One per Day:</strong></div>`;
        evObj.days.forEach((day, dayIdx) => {
            const dateFormatted = day.date
                ? new Date(day.date + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'})
                : '';
            html += `<div class="day-upload-slot">
                <span class="day-label">📆 Day ${dayIdx+1}: ${dateFormatted}</span>
                <span class="day-time">${day.time || ''}</span>
                <input type="file" name="excel_file_${eventIdx}_${dayIdx}" accept=".xlsx,.xls" required>
            </div>`;
        });
        return html;
    } else {
        return `<div class="form-row" style="margin-top:10px;">
            <label>📄 Upload Attendance Excel</label>
            <input type="file" name="excel_file_${eventIdx}_0" accept=".xlsx,.xls" required>
        </div>`;
    }
}

numEventsSelect.addEventListener('change', function () {
    const count = parseInt(this.value);
    eventToggles.innerHTML = '';
    document.getElementById('segregate_btn_wrap').style.display = 'none';
    if (!count || availableEvents.length === 0) return;

    for (let i = 0; i < count; i++) {
        const div = document.createElement('div');
        div.classList.add('event-box');

        let options = '<option value="">-- Select Event --</option>';
        availableEvents.forEach(ev => {
            let val, label;
            if (ev.multiday) {
                const dStart = ev.date     ? new Date(ev.date+'T00:00:00').toLocaleDateString('en-IN') : '';
                const dEnd   = ev.end_date ? new Date(ev.end_date+'T00:00:00').toLocaleDateString('en-IN') : '';
                val   = `${ev.name}||${ev.venue}||MULTIDAY`;
                label = `📆 ${ev.name} (Multi-day: ${dStart} – ${dEnd} | ${ev.venue})`;
            } else {
                val   = `${ev.name}||${ev.venue}||${ev.date}||${ev.time}`;
                label = `📅 ${ev.name} (${ev.date} | ${ev.time} | ${ev.venue})`;
            }
            options += `<option value="${val}">${label}</option>`;
        });

        div.innerHTML = `
            <h3>Event ${i + 1}</h3>
            <div class="form-row">
                <label>Select Event</label>
                <select name="selected_event[]" class="event-name-select" data-idx="${i}">
                    ${options}
                </select>
            </div>
            <div id="event_upload_${i}"></div>`;

        eventToggles.appendChild(div);
    }

    document.getElementById('segregate_btn_wrap').style.display = 'block';

    document.querySelectorAll('.event-name-select').forEach(sel => {
        sel.addEventListener('change', function () {
            const idx = parseInt(this.dataset.idx);
            const val = this.value;
            const uploadContainer = document.getElementById('event_upload_' + idx);
            uploadContainer.innerHTML = '';
            if (!val) return;
            const evName = val.split('||')[0];
            const evObj  = availableEvents.find(e => e.name === evName);
            if (!evObj) return;
            uploadContainer.innerHTML = buildFileUploadSlots(evObj, idx);
        });
    });
});

/* ==================== ADMIN PANEL ==================== */
const allHistoryData = <?php
    $adminHistory = [];
    foreach ($history as $record) {
        $eventSummaries = [];
        $teamsList      = [];
        foreach (($record['events'] ?? []) as $ev) {
            if (!is_array($ev)) continue;
            $team = $ev['organising_team'] ?? '';
            $days = isset($ev['days']) ? implode('; ', (array)$ev['days']) : '';
            $eventSummaries[] = ($ev['name'] ?? '')." | ".($ev['venue'] ?? '').($days ? " | ".$days : "");
            if ($team) $teamsList[] = $team;
        }
        $adminHistory[] = [
            "id"               => $record['id'],
            "date_range"       => $record['run_date_range'] ?? '',
            "date_from"        => $record['date_from'] ?? '',
            "date_to"          => $record['date_to'] ?? '',
            "segregated_on"    => $record['segregated_on'] ?? '',
            "events_text"      => implode("\n", $eventSummaries),
            "event_count"      => count($record['events'] ?? []),
            "organising_teams" => implode(", ", array_unique($teamsList)),
            "zips"             => $record['zips'] ?? []
        ];
    }
    echo json_encode($adminHistory);
?>;

const allEventsData = <?php echo json_encode($events); ?>;
const PAGE_SIZE = 10;
let adminCurrentPage  = 1;
let eventsCurrentPage = 1;

function switchAdminTab(tab) {
    document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('admin_events_tab').style.display     = tab === 'events'    ? 'block' : 'none';
    document.getElementById('admin_history_tab').style.display    = tab === 'history'   ? 'block' : 'none';
    document.getElementById('admin_analytics_tab').style.display  = tab === 'analytics' ? 'block' : 'none';
    document.querySelectorAll('.admin-tab')[0].classList.toggle('active', tab === 'events');
    document.querySelectorAll('.admin-tab')[1].classList.toggle('active', tab === 'history');
    document.querySelectorAll('.admin-tab')[2].classList.toggle('active', tab === 'analytics');
    if (tab === 'analytics') setTimeout(initCharts, 50);
}

function getFilteredEvents() {
    const search   = document.getElementById('ev_search').value.toLowerCase();
    const dateFrom = document.getElementById('ev_date_from').value;
    const dateTo   = document.getElementById('ev_date_to').value;
    return allEventsData.filter(ev => {
        const matchText = !search || ev.name.toLowerCase().includes(search) || ev.venue.toLowerCase().includes(search);
        const evEnd     = ev.end_date || ev.date;
        const matchFrom = !dateFrom || evEnd >= dateFrom;
        const matchTo   = !dateTo   || ev.date <= dateTo;
        return matchText && matchFrom && matchTo;
    });
}

function formatDate(ymd) {
    if (!ymd) return '';
    const p = ymd.split('-');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : ymd;
}

function formatDateTime(dt) {
    if (!dt) return '–';
    // dt format: "2025-03-05 14:32:00"
    const parts = dt.split(' ');
    const datePart = formatDate(parts[0]);
    if (!parts[1]) return datePart;
    // Convert HH:MM:SS to h:MM AM/PM
    const t = parts[1].split(':');
    let h = parseInt(t[0]), m = t[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${datePart} ${h}:${m} ${ampm}`;
}

function renderEventsTable() { eventsCurrentPage = 1; renderEventsPage(); }

function renderEventsPage() {
    const data  = getFilteredEvents();
    const total = data.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start = (eventsCurrentPage - 1) * PAGE_SIZE;
    const slice = data.slice(start, start + PAGE_SIZE);

    const container  = document.getElementById('events_table_container');
    const pagination = document.getElementById('events_pagination');

    if (total === 0) {
        container.innerHTML  = '<p class="no-results">No events match your filters.</p>';
        pagination.innerHTML = '';
        return;
    }

    let html = `<p class="section-count">Showing ${start+1}–${Math.min(start+PAGE_SIZE,total)} of ${total} events</p>`;
    html += `<table><tr>
        <th>#</th><th>Event Name</th><th>Venue</th><th>Organising Team</th>
        <th>Type</th><th>Date(s)</th><th>Time</th><th>Action</th>
    </tr>`;

    slice.forEach((ev, i) => {
        const typeLabel   = ev.multiday ? '<span class="badge-multi">Multi-day</span>' : 'Single Day';
        const dateDisplay = ev.multiday
            ? `${formatDate(ev.date)} – ${formatDate(ev.end_date || ev.date)}`
            : formatDate(ev.date);
        const timeDisplay = ev.multiday
            ? (ev.days ? ev.days.map(d => `${formatDate(d.date)}: ${d.time}`).join('<br>') : '–')
            : (ev.time || '–');
        const safeName = ev.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");

        html += `<tr>
            <td>${start+i+1}</td>
            <td>${ev.name}</td>
            <td>${ev.venue}</td>
            <td>${ev.organising_team || '–'}</td>
            <td>${typeLabel}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;">${timeDisplay}</td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete event \\'${safeName}\\'. This cannot be undone.');">
                    <input type="hidden" name="event_id" value="${ev.id}">
                    <button type="submit" name="delete_event" class="btn-delete">🗑 Delete</button>
                </form>
            </td>
        </tr>`;
    });

    html += '</table>';
    container.innerHTML = html;

    let pHtml = '';
    for (let p = 1; p <= pages; p++) {
        pHtml += `<button class="${p === eventsCurrentPage ? 'active-page' : ''}" onclick="goEventsPage(${p})">${p}</button>`;
    }
    pagination.innerHTML = pHtml;
}

function goEventsPage(p) { eventsCurrentPage = p; renderEventsPage(); }
function clearEvFilters() {
    document.getElementById('ev_search').value    = '';
    document.getElementById('ev_date_from').value = '';
    document.getElementById('ev_date_to').value   = '';
    renderEventsTable();
}

function getFilteredHistory() {
    const search   = document.getElementById('admin_search').value.toLowerCase();
    const dateFrom = document.getElementById('admin_date_from').value;
    const dateTo   = document.getElementById('admin_date_to').value;
    const sort     = document.getElementById('admin_sort').value;

    let data = allHistoryData.filter(r => {
        const matchText = !search ||
            r.events_text.toLowerCase().includes(search) ||
            r.date_range.toLowerCase().includes(search) ||
            (r.organising_teams||'').toLowerCase().includes(search);
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

function renderAdminTable() { adminCurrentPage = 1; renderAdminPage(); }

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

    let html = `<p class="section-count">Showing ${start+1}–${Math.min(start+PAGE_SIZE,total)} of ${total} records</p>`;
    html += `<table class="history-table"><tr>
        <th>#</th><th>Event Date(s)</th><th>Events Segregated</th>
        <th>Organising Team</th><th>Count</th><th>Segregated On</th><th>Download</th><th>Action</th>
    </tr>`;

    slice.forEach((r, i) => {
        const drFrom = r.date_from ? formatDate(r.date_from) : '';
        const drTo   = r.date_to   ? formatDate(r.date_to)   : '';
        const dateDisplay = (drFrom && drTo && drFrom !== drTo) ? drFrom + ' – ' + drTo : drFrom;

        const evLines = r.events_text.split('\n').map(line =>
            `<div style="margin-bottom:3px;">• ${line}</div>`
        ).join('');

        const zipLinks = (r.zips||[]).filter(z => z.endsWith('.zip')).map(z =>
            `<a href="downloads/${z}" target="_blank" style="display:block;margin-bottom:3px;">📦 ${z}</a>`
        ).join('') || '–';

        html += `<tr>
            <td>${start+i+1}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;line-height:1.6;">${evLines}</td>
            <td style="font-size:12px;">${r.organising_teams||'–'}</td>
            <td style="text-align:center;">${r.event_count}</td>
            <td style="white-space:nowrap;">${formatDateTime(r.segregated_on)}</td>
            <td style="font-size:12px;">${zipLinks}</td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete this history record? This cannot be undone.');">
                    <input type="hidden" name="history_id" value="${r.id}">
                    <button type="submit" name="delete_history" class="btn-delete">🗑 Delete</button>
                </form>
            </td>
        </tr>`;
    });

    html += '</table>';
    container.innerHTML = html;

    let pHtml = '';
    for (let p = 1; p <= pages; p++) {
        pHtml += `<button class="${p === adminCurrentPage ? 'active-page' : ''}" onclick="goAdminPage(${p})">${p}</button>`;
    }
    pagination.innerHTML = pHtml;
}

function goAdminPage(p) { adminCurrentPage = p; renderAdminPage(); }
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
    const tab = urlParams.get('tab');
    if (tab === 'segregation' || urlParams.has('segregation')) {
        showPage('segregation');
    } else if (tab === 'admin') {
        showPage('admin');
    } else {
        modal.style.display = 'block';
    }
    renderEventsTable();
    renderAdminTable();
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
/* ==================== ANALYTICS CHARTS ==================== */
const monthLabels     = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const eventsMonthly   = <?php echo json_encode(array_values($eventsMonthly)); ?>;
const segregMonthly   = <?php echo json_encode(array_values($segregMonthly)); ?>;
const totalSingleDay  = <?= $totalSingleDay ?>;
const totalMultiDay   = <?= $totalMultiDay ?>;

const baseColor    = 'rgb(27,0,93)';
const accentColor  = 'rgb(90,0,200)';
const lightColor   = 'rgba(27,0,93,0.15)';
const accentLight  = 'rgba(90,0,200,0.15)';
const goldColor    = 'rgb(255,193,7)';

let chartsInitialized = false;

function initCharts() {
    if (chartsInitialized) return;
    chartsInitialized = true;

    // 1. Bar: Events Registered Monthly
    new Chart(document.getElementById('chartEventsMonthly'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Events Registered',
                data: eventsMonthly,
                backgroundColor: monthLabels.map((_, i) =>
                    eventsMonthly[i] === Math.max(...eventsMonthly) ? goldColor : baseColor),
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            },
            responsive: true
        }
    });

    // 2. Bar: Segregation Runs Monthly
    new Chart(document.getElementById('chartSegregMonthly'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [{
                label: 'Segregation Runs',
                data: segregMonthly,
                backgroundColor: monthLabels.map((_, i) =>
                    segregMonthly[i] === Math.max(...segregMonthly) ? goldColor : accentColor),
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            },
            responsive: true
        }
    });

    // 3. Line: Registered vs Segregated monthly trend
    new Chart(document.getElementById('chartComparison'), {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: 'Events Registered',
                    data: eventsMonthly,
                    borderColor: baseColor,
                    backgroundColor: lightColor,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: baseColor,
                    pointRadius: 4,
                },
                {
                    label: 'Segregation Runs',
                    data: segregMonthly,
                    borderColor: goldColor,
                    backgroundColor: 'rgba(255,193,7,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: goldColor,
                    pointRadius: 4,
                }
            ]
        },
        options: {
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 } } } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } },
                x: { grid: { display: false } }
            },
            responsive: true
        }
    });

    // 4. Donut: Event type split
    new Chart(document.getElementById('chartTypeSplit'), {
        type: 'doughnut',
        data: {
            labels: ['Single-Day', 'Multi-Day'],
            datasets: [{
                data: [totalSingleDay, totalMultiDay],
                backgroundColor: [baseColor, goldColor],
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 14, font: { size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = totalSingleDay + totalMultiDay;
                            const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                            return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                        }
                    }
                }
            },
            responsive: true
        }
    });
}

// Init charts when analytics tab is opened

</script>

</body>
</html>

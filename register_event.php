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
        'faculty_coordinator' => $row['faculty_coordinator'] ?? '',
        'school'          => $row['school'] ?? '',
        'phone_number'    => $row['phone_number'] ?? '',
        'event_type'      => $row['event_type'] ?? '',
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

/* ===== STATS FOR KPI INITIAL VALUES ===== */
$dayNames        = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$totalSingleDay  = 0;
$totalMultiDay   = 0;
$dowCounts       = array_fill(0, 7, 0);
foreach ($eventsRaw as $ev) {
    if ($ev['multiday']) $totalMultiDay++; else $totalSingleDay++;
    $ts = strtotime($ev['date']);
    $dowCounts[(int)date('w', $ts)]++;
}

$schoolStudentCounts = [];
try {
    $ssRows = $pdo->query("SELECT school_name, SUM(student_count) AS total FROM segregation_stats GROUP BY school_name ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ssRows as $r) { if ((int)$r['total'] > 0) $schoolStudentCounts[$r['school_name']] = (int)$r['total']; }
} catch (\Exception $e) { $schoolStudentCounts = []; }

$segregatedEventNames = [];
$lastSegOn = null;
$runEventCounts = [];
foreach ($historyRaw as $h) {
    if (!$lastSegOn) $lastSegOn = $h['segregated_on'];
    $evArr = $h['events'] ? json_decode($h['events'], true) : [];
    foreach ($evArr as $he) { if (!empty($he['name'])) $segregatedEventNames[$he['name']] = true; }
    $runEventCounts[] = count($evArr);
}

$totalEventsRegistered = count($eventsRaw);
$totalSegregationRuns  = count($historyRaw);
$totalStudentsAllRuns  = array_sum($schoolStudentCounts);
$avgStudentsPerRun     = $totalSegregationRuns > 0 ? round($totalStudentsAllRuns / $totalSegregationRuns) : 0;
$avgEventsPerRun       = $totalSegregationRuns > 0 && !empty($runEventCounts) ? round(array_sum($runEventCounts) / $totalSegregationRuns, 1) : 0;

$lastEventOn = null;
if (!empty($eventsRaw)) {
    $lastEventRow = end($eventsRaw);
    $lastEventOn  = $lastEventRow['date'].' ('.$lastEventRow['name'].')';
}

$pendingEvents = array_filter($eventsRaw, fn($ev) => !isset($segregatedEventNames[$ev['name']]));
$totalPending  = count($pendingEvents);

/* ===== EVENT REGISTRATION ===== */
if (isset($_POST['register_event'])) {
    $inputName   = trim(substr($_POST['event_name']      ?? '', 0, 255));
    $inputVenue  = trim(substr($_POST['event_venue']     ?? '', 0, 255));
    $inputTeam   = trim(substr($_POST['faculty_coordinator'] ?? '', 0, 100));
    $inputSchool = trim(substr($_POST['school']          ?? '', 0, 100));
    $inputPhone  = trim(substr($_POST['phone_number']    ?? '', 0, 15));
    $inputType   = trim($_POST['event_type'] ?? '');
    $validTypes  = ['Expert Talk','Mentoring Session','Workshop','Seminar','Boot Camp','Expo','Demo Day / Competition','Tech Fest / Hackathon / Ideathon'];
    if (!in_array($inputType, $validTypes, true)) $inputType = '';
    $isMultiday  = isset($_POST['is_multiday']) && $_POST['is_multiday'] == '1';
    if (empty($inputName) || (!$isMultiday && empty($inputVenue))) { header("Location: register_event.php?error=invalid"); exit(); }

    if ($isMultiday) {
        $days = [];
        foreach ($_POST['day_date'] as $i => $dayDate) {
            if (empty($dayDate)) continue;
            $from     = $_POST['day_from_hour'][$i].":".$_POST['day_from_minute'][$i]." ".$_POST['day_from_ampm'][$i];
            $to       = $_POST['day_to_hour'][$i].":".$_POST['day_to_minute'][$i]." ".$_POST['day_to_ampm'][$i];
            $dayVenue = trim(substr($_POST['day_venue'][$i] ?? '', 0, 255));
            $days[]   = ["date" => $dayDate, "time" => $from." - ".$to, "venue" => $dayVenue];
        }
        usort($days, fn($a,$b) => strcmp($a['date'], $b['date']));
        $primaryVenue = !empty($days[0]['venue']) ? $days[0]['venue'] : '';
        $stmt = $pdo->prepare("INSERT INTO events (name, venue, faculty_coordinator, school, phone_number, event_type, multiday, date, end_date, days) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
        $stmt->execute([$inputName, $primaryVenue, $inputTeam, $inputSchool, $inputPhone, $inputType, $days[0]['date'], end($days)['date'], json_encode($days)]);
    } else {
        $time = $_POST['from_hour'].":".$_POST['from_minute']." ".$_POST['from_ampm']." - ".$_POST['to_hour'].":".$_POST['to_minute']." ".$_POST['to_ampm'];
        $stmt = $pdo->prepare("INSERT INTO events (name, venue, faculty_coordinator, school, phone_number, event_type, multiday, date, time) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmt->execute([$inputName, $inputVenue, $inputTeam, $inputSchool, $inputPhone, $inputType, $_POST['event_date'], $time]);
    }
    header("Location: register_event.php?event_added=1");
    exit();
}

/* ===== DELETE EVENT ===== */
if (isset($_POST['delete_event'])) {
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([(int)$_POST['event_id']]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ===== DELETE HISTORY RECORD ===== */
if (isset($_POST['delete_history'])) {
    $hid = (int)$_POST['history_id'];
    $pdo->prepare("DELETE FROM segregation_stats WHERE history_id = ?")->execute([$hid]);
    $pdo->prepare("DELETE FROM segregation_history WHERE id = ?")->execute([$hid]);
    header("Location: register_event.php?tab=admin");
    exit();
}

/* ===== SEGREGATION ===== */
if (isset($_POST['segregate_all'])) {

    if (!file_exists("downloads")) mkdir("downloads", 0777, true);

    $createdFiles    = [];
    $schoolEventData = [];
    $eventMeta       = [];

    foreach ($_POST['selected_event'] as $index => $selectedValue) {
        if (empty($selectedValue)) continue;
        $parts   = explode("||", $selectedValue);
        $idPart  = trim($parts[0] ?? '');
        $isMulti = isset($parts[1]) && trim($parts[1]) === 'MULTIDAY';
        $evObj   = null;
        if (preg_match('/^ID:(\d+)$/', $idPart, $m)) {
            $evId = (int)$m[1];
            foreach ($events as $ev) { if ((int)$ev['id'] === $evId) { $evObj = $ev; break; } }
        } else {
            foreach ($events as $ev) { if ($ev['name'] === $idPart) { $evObj = $ev; break; } }
        }
        if (!$evObj) continue;
        if (empty($evObj['days'])) {
            $evObj['days'] = [["date" => $evObj['date'] ?? '', "time" => $evObj['time'] ?? '', "venue" => $evObj['venue'] ?? '']];
        }
        $evName = $evObj['name'];

        if ($isMulti || !empty($evObj['multiday'])) {
            $eventMeta[$index] = ["name" => $evName, "venue" => $evObj['venue'] ?? '', "faculty_coordinator" => $evObj['faculty_coordinator'] ?? '', "multiday" => true, "days" => $evObj['days']];
            foreach ($evObj['days'] as $dayIdx => $day) {
                $fileKey = "excel_file_{$index}_{$dayIdx}";
                $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
                if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;
                $dateVal     = $day['date'];
                $spreadsheet = IOFactory::load($tmpFile);
                foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                    $allRows = $worksheet->toArray(null, true, true, false);
                    foreach ($allRows as $row) {
                        if (!is_array($row)) continue;
                        foreach ($row as $cell) {
                            if ($cell === null || is_array($cell)) continue;
                            $regNo = strtoupper(trim((string)$cell));
                            if (strlen($regNo) !== 9) continue;
                            $code    = substr($regNo, 2, 3);
                            $matched = false;
                            foreach ($schoolCodes as $school => $codes) {
                                if (in_array($code, $codes)) {
                                    $schoolEventData[$index][$school][$dateVal][$code][] = $regNo;
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                $schoolEventData[$index]['OTHERS'][$dateVal]['UNKNOWN'][] = $regNo;
                            }
                        }
                    }
                }
            }
        } else {
            $date = $evObj['date'] ?? '';
            $time = $evObj['time'] ?? '';
            $eventMeta[$index] = ["name" => $evName, "venue" => $evObj['venue'] ?? '', "faculty_coordinator" => $evObj['faculty_coordinator'] ?? '', "multiday" => false, "days" => [["date" => $date, "time" => $time, "venue" => $evObj['venue'] ?? '']]];
            $fileKey = "excel_file_{$index}_0";
            $tmpFile = $_FILES[$fileKey]['tmp_name'] ?? '';
            if (empty($tmpFile) || !is_uploaded_file($tmpFile)) continue;
            $spreadsheet = IOFactory::load($tmpFile);
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $allRows = $worksheet->toArray(null, true, true, false);
                foreach ($allRows as $row) {
                    if (!is_array($row)) continue;
                    foreach ($row as $cell) {
                        if ($cell === null || is_array($cell)) continue;
                        $regNo = strtoupper(trim((string)$cell));
                        if (strlen($regNo) !== 9) continue;
                        $code    = substr($regNo, 2, 3);
                        $matched = false;
                        foreach ($schoolCodes as $school => $codes) {
                            if (in_array($code, $codes)) {
                                $schoolEventData[$index][$school][$date][$code][] = $regNo;
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) {
                            $schoolEventData[$index]['OTHERS'][$date]['UNKNOWN'][] = $regNo;
                        }
                    }
                }
            }
        }
    }

    /* BUILD ONE EXCEL PER SCHOOL */
    $allSchoolFiles = [];
    foreach ($schoolCodes as $school => $codes) {
        $hasData = false;
        foreach ($eventMeta as $index => $meta) { if (!empty($schoolEventData[$index][$school])) { $hasData = true; break; } }
        if (!$hasData) continue;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $currentRow  = 1;

        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;
            $sheet->setCellValue('A'.$currentRow, $meta['name']);
            $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true)->setSize(13);
            $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
            $currentRow++;
            $dateTimeMap = []; $dateVenueMap = [];
            foreach (($meta['days'] ?? []) as $day) {
                if (!empty($day['date'])) { $dateTimeMap[$day['date']] = $day['time'] ?? ''; $dateVenueMap[$day['date']] = $day['venue'] ?? $meta['venue']; }
            }
            $collectedDates = array_keys($schoolEventData[$index][$school]);
            sort($collectedDates);
            foreach ($collectedDates as $dateKey) {
                if (empty($schoolEventData[$index][$school][$dateKey])) continue;
                $dateCodes   = $schoolEventData[$index][$school][$dateKey];
                $dateStr     = date("d-m-Y", strtotime($dateKey));
                $timeStr     = $dateTimeMap[$dateKey]  ?? ($meta['days'][0]['time']  ?? '');
                $dayVenueStr = $dateVenueMap[$dateKey] ?? ($meta['days'][0]['venue'] ?? $meta['venue']);
                $cellVal = $dateStr.($dayVenueStr ? " | ".$dayVenueStr : '').($timeStr ? " | ".$timeStr : '');
                $sheet->setCellValue('A'.$currentRow, $cellVal);
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
                $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
                $currentRow++;
                $startColumn = 1; $headerRow = $currentRow; $maxRows = 0;
                foreach ($dateCodes as $code => $regList) {
                    $colLetter = Coordinate::stringFromColumnIndex($startColumn);
                    $sheet->setCellValue($colLetter.$headerRow, $code);
                    $sheet->getStyle($colLetter.$headerRow)->getFont()->setBold(true);
                    $sheet->getStyle($colLetter.$headerRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
                    $rowPointer = $headerRow + 1;
                    foreach ($regList as $reg) { $sheet->setCellValue($colLetter.$rowPointer, $reg); $rowPointer++; }
                    $maxRows = max($maxRows, count($regList));
                    $startColumn++;
                }
                $currentRow = $headerRow + $maxRows + 2;
            }
            $currentRow += 1;
        }

        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $highestCol; $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);

        $schoolDates = [];
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index][$school])) continue;
            foreach (array_keys($schoolEventData[$index][$school]) as $d) $schoolDates[$d] = $d;
        }
        ksort($schoolDates);
        $dateRange = count($schoolDates) > 1 ? array_key_first($schoolDates)."_to_".array_key_last($schoolDates) : (array_key_first($schoolDates) ?? date("Y-m-d"));
        $fileName  = $school."_".$dateRange.".xlsx";
        $filePath  = "downloads/".$fileName;
        (new Xlsx($spreadsheet))->save($filePath);
        $createdFiles[]   = $fileName;
        $allSchoolFiles[] = $filePath;
    }

    /* BUILD OTHERS FILE (unmatched reg numbers) */
    $hasOthers = false;
    foreach ($eventMeta as $index => $meta) {
        if (!empty($schoolEventData[$index]['OTHERS'])) { $hasOthers = true; break; }
    }
    if ($hasOthers) {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $currentRow  = 1;
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index]['OTHERS'])) continue;
            $sheet->setCellValue('A'.$currentRow, $meta['name']);
            $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true)->setSize(13);
            $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
            $currentRow++;
            $collectedDates = array_keys($schoolEventData[$index]['OTHERS']);
            sort($collectedDates);
            foreach ($collectedDates as $dateKey) {
                if (empty($schoolEventData[$index]['OTHERS'][$dateKey]['UNKNOWN'])) continue;
                $dateStr = date("d-m-Y", strtotime($dateKey));
                $sheet->setCellValue('A'.$currentRow, $dateStr.' | Unmatched / Unknown School');
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFD9D9');
                $sheet->mergeCells('A'.$currentRow.':H'.$currentRow);
                $currentRow++;
                $sheet->setCellValue('A'.$currentRow, 'REG NO');
                $sheet->getStyle('A'.$currentRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$currentRow)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
                $currentRow++;
                foreach ($schoolEventData[$index]['OTHERS'][$dateKey]['UNKNOWN'] as $reg) {
                    $sheet->setCellValue('A'.$currentRow, $reg);
                    $currentRow++;
                }
                $currentRow += 2;
            }
            $currentRow += 1;
        }
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $highestCol; $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        $othersDates = [];
        foreach ($eventMeta as $index => $meta) {
            if (empty($schoolEventData[$index]['OTHERS'])) continue;
            foreach (array_keys($schoolEventData[$index]['OTHERS']) as $d) $othersDates[$d] = $d;
        }
        ksort($othersDates);
        $dateRange    = count($othersDates) > 1 ? array_key_first($othersDates)."_to_".array_key_last($othersDates) : (array_key_first($othersDates) ?? date("Y-m-d"));
        $fileName     = "OTHERS_".$dateRange.".xlsx";
        $filePath     = "downloads/".$fileName;
        (new Xlsx($spreadsheet))->save($filePath);
        $createdFiles[]   = $fileName;
        $allSchoolFiles[] = $filePath;
    }

    /* TXT SUMMARY */
    $schoolTotals = [];
    foreach ($schoolEventData as $idx => $schoolArr) {
        foreach ($schoolArr as $school => $dateArr) {
            foreach ($dateArr as $code => $regList) {
                if (is_array($regList)) {
                    foreach ($regList as $regs) { $schoolTotals[$school] = ($schoolTotals[$school] ?? 0) + (is_array($regs) ? count($regs) : 0); }
                }
            }
        }
    }
    arsort($schoolTotals);
    $runTotalStudents = array_sum($schoolTotals);
    $_earlyRunDates = [];
    foreach ($schoolEventData as $_esArr) { foreach ($_esArr as $_edArr) { foreach (array_keys($_edArr) as $_ed) { if (!empty($_ed)) $_earlyRunDates[$_ed] = $_ed; } } }
    ksort($_earlyRunDates);
    $runDateFrom = !empty($_earlyRunDates) ? array_key_first($_earlyRunDates) : date("Y-m-d");
    $runDateTo   = !empty($_earlyRunDates) ? array_key_last($_earlyRunDates)  : $runDateFrom;
    $zipLabel    = ($runDateFrom === $runDateTo) ? $runDateFrom : $runDateFrom."_to_".$runDateTo;
    $runOnDate   = date('d M Y, h:i A');
    $runDateDisp = ($runDateFrom === $runDateTo) ? date('d M Y', strtotime($runDateFrom)) : date('d M Y', strtotime($runDateFrom)).' - '.date('d M Y', strtotime($runDateTo));

    $sep  = "=======================================================";
    $dash = "-------------------------------------------------------";
    $txtLines = [];
    $txtLines[] = $sep;
    $txtLines[] = "  VIT SMART ATTENDANCE SEGREGATOR - Segregation Summary";
    $txtLines[] = "  Generated : $runOnDate";
    $txtLines[] = "  Date Range: $runDateDisp";
    $txtLines[] = $sep;
    $txtLines[] = "";
    $txtLines[] = "--- EVENTS SEGREGATED (".count($eventMeta).") ---";
    foreach ($eventMeta as $meta) {
        $typeTag = $meta['multiday'] ? " [Multi-day]" : " [Single-day]";
        $txtLines[] = "  * ".$meta['name'].$typeTag;
        $txtLines[] = "    Venue: ".$meta['venue'].(!empty($meta['faculty_coordinator']) ? " | Faculty: ".$meta['faculty_coordinator'] : "");
        foreach (($meta['days'] ?? []) as $d) {
            $dstr = !empty($d['date']) ? date('d M Y', strtotime($d['date'])) : '';
            $tstr = !empty($d['time']) ? " | ".$d['time'] : '';
            $txtLines[] = "    Day: $dstr$tstr";
        }
        $txtLines[] = "";
    }
    $txtLines[] = $dash;
    $txtLines[] = "--- SCHOOL-WISE STUDENT COUNT (".count($schoolTotals)." schools) ---";
    $idx2 = 1;
    foreach ($schoolTotals as $school => $cnt) { $txtLines[] = "  ".str_pad($idx2.".", 4).str_pad($school, 12)." : ".number_format($cnt)." students"; $idx2++; }
    $txtLines[] = $dash;
    $txtLines[] = "  TOTAL STUDENTS PROCESSED : ".number_format($runTotalStudents);
    $txtLines[] = $sep;
    $txtLines[] = "  VIT-IST | Office of Innovation, Startup & Technology Transfer";
    $txtLines[] = $sep;

    $_SESSION['summary_lines']    = $txtLines;
    $_SESSION['summary_filename'] = "Segregation_Summary_".$zipLabel.".pdf";

    $summaryTxtName = "Segregation_Summary_".$zipLabel.".txt";
    $summaryTxtPath = "downloads/".$summaryTxtName;
    file_put_contents($summaryTxtPath, implode("\n", $txtLines));

    /* ZIP */
    $createdZips = [];
    if (!empty($allSchoolFiles)) {
        $allCollectedDates = [];
        foreach ($schoolEventData as $schoolArr) { foreach ($schoolArr as $dateArr) { foreach (array_keys($dateArr) as $d) { if (!empty($d)) $allCollectedDates[$d] = $d; } } }
        ksort($allCollectedDates);
        $zipDateFrom = !empty($allCollectedDates) ? array_key_first($allCollectedDates) : date("Y-m-d");
        $zipDateTo   = !empty($allCollectedDates) ? array_key_last($allCollectedDates)  : $zipDateFrom;
        $zipLabel    = ($zipDateFrom === $zipDateTo) ? $zipDateFrom : $zipDateFrom."_to_".$zipDateTo;
        $zipFileName = "downloads/all_schools_".$zipLabel."_".date("His").".zip";
        $zip = new ZipArchive();
        if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach (array_unique($allSchoolFiles) as $fp) $zip->addFile($fp, basename($fp));
            $zip->addFile($summaryTxtPath, $summaryTxtName);
            $zip->close();
        }
        $createdFiles[] = basename($zipFileName);
        $createdZips[]  = basename($zipFileName);
    }

    /* HISTORY */
    $runEventSummary = [];
    foreach ($eventMeta as $meta) {
        $dayStrings = array_map(fn($d) => ($d['date'] ?? '')." (".($d['time'] ?? '').")", $meta['days'] ?? []);
        $runEventSummary[] = ["name" => $meta['name'], "venue" => $meta['venue'], "faculty_coordinator" => $meta['faculty_coordinator'] ?? '', "multiday" => $meta['multiday'], "days" => $dayStrings];
    }
    $dateLabel    = ($runDateFrom === $runDateTo) ? $runDateFrom : $runDateFrom." to ".$runDateTo;
    $segregatedOn = date("Y-m-d H:i:s");
    $stmt = $pdo->prepare("INSERT INTO segregation_history (run_date_range, date_from, date_to, segregated_on, events, zips) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$dateLabel, $runDateFrom, $runDateTo, $segregatedOn, json_encode($runEventSummary), json_encode($createdZips)]);
    $historyId = (int)$pdo->lastInsertId();

    $eventSchoolCounts = [];
    foreach ($schoolEventData as $idx => $schoolArr) {
        foreach ($schoolArr as $school => $dateArr) {
            $cnt = 0;
            foreach ($dateArr as $codeArr) { foreach ($codeArr as $regList) { $cnt += is_array($regList) ? count($regList) : 0; } }
            if ($cnt > 0) $eventSchoolCounts[$idx][$school] = $cnt;
        }
    }
    $statsStmt = $pdo->prepare("INSERT INTO segregation_stats (history_id, school_name, event_name, student_count, segregated_on) VALUES (?, ?, ?, ?, ?)");
    foreach ($eventMeta as $idx => $meta) {
        $evName = substr(trim($meta['name']), 0, 255);
        foreach (($eventSchoolCounts[$idx] ?? []) as $school => $cnt) $statsStmt->execute([$historyId, $school, $evName, $cnt, $segregatedOn]);
    }

    $_SESSION['files'] = $createdFiles;
    header("Location: register_event.php?tab=segregation&segregation=done");
    exit();
}

/* ===== BUILD JS DATA FOR ADMIN TABLE ===== */
$eventLookup = [];
foreach ($eventsRaw as $ev) {
    $eventLookup[$ev['name']] = ['school' => $ev['school'] ?? '', 'phone_number' => $ev['phone_number'] ?? ''];
}
$adminHistory = [];
foreach ($history as $record) {
    $eventSummaries = []; $teamsList = [];
    foreach (($record['events'] ?? []) as $ev) {
        if (!is_array($ev)) continue;
        $team  = $ev['faculty_coordinator'] ?? '';
        $ename = $ev['name'] ?? '';
        $days  = isset($ev['days']) ? implode('; ', (array)$ev['days']) : '';
        $eventSummaries[] = $ename." | ".($ev['venue'] ?? '').($days ? " | ".$days : "");
        $extra = '';
        if (isset($eventLookup[$ename])) {
            $sc = $eventLookup[$ename]['school']; $ph = $eventLookup[$ename]['phone_number'];
            $parts = array_filter([$sc, $ph ? '📞 '.$ph : '']);
            if ($parts) $extra = ' ('.implode(' | ', $parts).')';
        }
        if ($team || $extra) $teamsList[] = ($team ?: '–').$extra;
    }
    $adminHistory[] = [
        "id"               => $record['id'],
        "date_range"       => $record['run_date_range'] ?? '',
        "date_from"        => $record['date_from'] ?? '',
        "date_to"          => $record['date_to'] ?? '',
        "segregated_on"    => $record['segregated_on'] ?? '',
        "events_text"      => implode("\n", $eventSummaries),
        "event_count"      => count($record['events'] ?? []),
        "organising_teams" => implode("<br>", array_unique($teamsList)),
        "zips"             => $record['zips'] ?? []
    ];
}

$pendingForJS = [];
foreach ($pendingEvents as $pev) {
    $pendingForJS[] = ['name' => $pev['name'], 'venue' => $pev['venue'], 'date' => $pev['date'], 'end_date' => $pev['end_date'] ?? $pev['date'], 'multiday' => (bool)$pev['multiday'], 'event_type' => $pev['event_type'] ?? ''];
}

$segHistoryForJS = [];
foreach ($historyRaw as $h) {
    $evNames = [];
    $evArr2  = $h['events'] ? json_decode($h['events'], true) : [];
    foreach ($evArr2 as $he) { if (!empty($he['name'])) $evNames[] = $he['name']; }
    $segHistoryForJS[] = ['segregated_on' => $h['segregated_on'], 'date_from' => $h['date_from'], 'date_to' => $h['date_to'], 'event_names' => $evNames];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SMART_ATT</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="register_event.css">
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
        <div class="avatar-circle">
            <img src="logout-logo.png" class="avatar-vit-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <span class="avatar-initials" style="display:none;">VIT</span>
        </div>
        <span class="user-name-label"><?php echo htmlspecialchars($_SESSION['user']); ?></span>
        <span class="chevron-arrow">▼</span>
    </div>
    <div class="dropdown" id="dropdownMenu">
        <div class="dropdown-profile">
            <div class="dropdown-avatar">
                <img src="logout-logo.png" class="dropdown-vit-img" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <span class="dropdown-initials" style="display:none;">VIT</span>
            </div>
            <div class="dropdown-username"><?php echo htmlspecialchars($_SESSION['user']); ?></div>
            <div class="dropdown-role">VIT-IST Admin</div>
        </div>
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
        <form action="register_event.php" method="POST" autocomplete="off">
            <div class="form-row">
                <label>Event Name</label>
                <input type="text" name="event_name" required>
            </div>
            <div class="form-row">
                <label>Faculty Coordinator</label>
                <input type="text" name="faculty_coordinator" required>
            </div>
            <div class="form-row">
                <label>School</label>
                <input type="text" name="school" required>
            </div>
            <div class="form-row">
                <label>Phone Number <span style="color:#999;font-size:12px;">(optional)</span></label>
                <input type="tel" name="phone_number" pattern="[0-9]{10}" maxlength="10" placeholder="10-digit mobile number (optional)">
            </div>
            <div class="form-row">
                <label>Event Type</label>
                <select name="event_type" required>
                    <option value="">-- Select Event Type --</option>
                    <option value="Expert Talk">1. Expert Talk</option>
                    <option value="Mentoring Session">2. Mentoring Session</option>
                    <option value="Workshop">3. Workshop</option>
                    <option value="Seminar">4. Seminar</option>
                    <option value="Boot Camp">5. Boot Camp</option>
                    <option value="Expo">6. Expo</option>
                    <option value="Demo Day / Competition">7. Demo Day / Competition</option>
                    <option value="Tech Fest / Hackathon / Ideathon">8. Tech Fest / Hackathon / Ideathon</option>
                </select>
            </div>

            <div class="toggle-row">
                <label><strong>Multi-day Event?</strong></label>
                <input type="checkbox" id="multiday_toggle" name="is_multiday" value="1" onchange="toggleMultiday()">
            </div>

            <div id="singleday_fields">
                <div class="form-row">
                    <label>Event Date</label>
                    <input type="date" name="event_date" required>
                </div>
                <div class="form-row">
                    <label>Event Timing</label>
                    <div class="time-group">
                        <div>
                            <span>From</span><br>
                            <select name="from_hour"   onchange="validateEventTiming()"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="from_minute" onchange="validateEventTiming()"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="from_ampm"   onchange="validateEventTiming()"><option>AM</option><option>PM</option></select>
                        </div>
                        <div>
                            <span>To</span><br>
                            <select name="to_hour"     onchange="validateEventTiming()"><?php for($i=1;$i<=12;$i++) echo "<option>$i</option>"; ?></select>
                            <select name="to_minute"   onchange="validateEventTiming()"><?php for($i=0;$i<=59;$i++){$m=str_pad($i,2,'0',STR_PAD_LEFT);echo "<option>$m</option>";}?></select>
                            <select name="to_ampm"     onchange="validateEventTiming()"><option>AM</option><option>PM</option></select>
                        </div>
                    </div>
                    <div id="time_warn" style="display:none;margin-top:6px;color:#e74c3c;font-size:12px;font-weight:700;">&#9888; End time must be later than start time.</div>
                </div>
                <div class="form-row">
                    <label>Event Venue</label>
                    <input type="text" name="event_venue" id="main_venue_input" required placeholder="Enter venue">
                </div>
            </div>

            <div id="multiday_fields" style="display:none;">
                <div id="day_slots_container"></div>
                <button type="button" onclick="addDaySlot()" style="margin-bottom:12px;padding:7px 18px;background:rgb(27,0,93);color:white;border:none;border-radius:5px;cursor:pointer;">+ Add Day</button>
            </div>

            <button type="submit" name="register_event" class="submit-btn" onclick="return confirmAddEvent()">Add Event</button>
        </form>
    </div>

    <!-- ==================== EXCEL SEGREGATION ==================== -->
    <div id="segregation" class="page">
        <h2>Excel Segregation</h2>
        <span class="step-label">Step 1 — Select Date Range</span>
        <div class="date-filter-row">
            <input type="date" id="filter_date_from" required onchange="updateSegToMin()">
            <span>to</span>
            <input type="date" id="filter_date_to" required onchange="updateSegToMin()">
            <button class="find-btn" onclick="filterEventsByRange()">🔍 Find Events</button>
        </div>
        <div id="num_events_row" style="display:none; margin-bottom:18px;">
            <span class="step-label">Step 2 — Number of Events to Segregate</span>
            <select id="num_events" style="padding:8px;border-radius:5px;border:1px solid #ccc;min-width:220px;">
                <option value="">-- Select --</option>
            </select>
        </div>
            <form id="segregate_all_form" method="POST" action="register_event.php" enctype="multipart/form-data">
            <div id="event_toggles"></div>
            <div id="segregate_btn_wrap" style="display:none;">
                <button type="submit" name="segregate_all" class="submit-btn">⚡ Segregate All Events</button>
            </div>
        </form>
        <div id="segregation_results" class="download-links" style="margin-top:16px;">
            <?php
            if (isset($_SESSION['files'])) {
                echo "<hr><h3 style='color:green;margin-bottom:14px;'>✅ Segregation Completed Successfully</h3>";
                $zipFiles    = array_filter($_SESSION['files'], fn($f) => str_ends_with($f, '.zip'));
                $schoolFiles = array_filter($_SESSION['files'], fn($f) => !str_ends_with($f, '.zip'));
                if (!empty($_SESSION['summary_lines'])) {
                    $jsLines  = json_encode($_SESSION['summary_lines']);
                    $jsFname  = json_encode($_SESSION['summary_filename'] ?? 'Segregation_Summary.pdf');
                    echo "
                    <div style='background:#f0eeff;border:2px solid rgb(27,0,93);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;'>
                        <div>
                            <div style='font-weight:700;color:rgb(27,0,93);font-size:15px;'>📄 Segregation Summary Report</div>
                            <div style='font-size:12px;color:#666;margin-top:3px;'>PDF summary of this segregation run</div>
                        </div>
                        <button onclick='downloadSegSummaryPDF($jsLines,$jsFname)'
                            style='padding:10px 22px;background:rgb(27,0,93);color:white;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;'>
                            ⬇ Download Summary
                        </button>
                    </div>";
                    unset($_SESSION['summary_lines'], $_SESSION['summary_filename']);
                }
                foreach ($zipFiles as $file) {
                    $sf = htmlspecialchars($file);
                    echo "<div style='margin-bottom:14px;'><strong>📦 Download All Schools (ZIP):</strong><br><a href='downloads/$sf' target='_blank' style='color:navy;'>⬇ $sf</a></div>";
                }
                if (!empty($schoolFiles)) {
                    echo "<div style='margin-top:8px;'><strong style='color:rgb(27,0,93);'>📁 Individual School Files (" . count($schoolFiles) . ")</strong></div>";
                    foreach ($schoolFiles as $file) {
                        $sf = htmlspecialchars($file);
                        echo "<p style='margin:4px 0 4px 12px;'>⬇ <a href='downloads/$sf' target='_blank'>$sf</a></p>";
                    }
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
                <input type="date" id="ev_date_from" onchange="document.getElementById('ev_date_to').min=this.value; if(document.getElementById('ev_date_to').value&&document.getElementById('ev_date_to').value<this.value)document.getElementById('ev_date_to').value=''; renderEventsTable()">
                <span>to</span>
                <input type="date" id="ev_date_to" onchange="renderEventsTable()">
                <select id="ev_type_filter" onchange="renderEventsTable()">
                    <option value="">All Event Types</option>
                    <option>Expert Talk</option><option>Mentoring Session</option><option>Workshop</option>
                    <option>Seminar</option><option>Boot Camp</option><option>Expo</option>
                    <option>Demo Day / Competition</option><option>Tech Fest / Hackathon / Ideathon</option>
                </select>
                <select id="ev_sort" onchange="renderEventsTable()">
                    <option value="newest">Newest First</option><option value="oldest">Oldest First</option>
                    <option value="name_asc">Name A–Z</option><option value="name_desc">Name Z–A</option>
                </select>
                <button onclick="clearEvFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="events_table_container"></div>
            <div class="pagination" id="events_pagination"></div>
        </div>

        <div id="admin_history_tab" style="display:none;">
            <div class="admin-controls">
                <input type="text"  id="admin_search"    placeholder="Search event name / venue..." oninput="renderAdminTable()">
                <input type="date"  id="admin_date_from" onchange="document.getElementById('admin_date_to').min=this.value; if(document.getElementById('admin_date_to').value&&document.getElementById('admin_date_to').value<this.value)document.getElementById('admin_date_to').value=''; renderAdminTable()">
                <span>to</span>
                <input type="date"  id="admin_date_to"   onchange="renderAdminTable()">
                <select id="admin_sort" onchange="renderAdminTable()">
                    <option value="newest">Newest First</option><option value="oldest">Oldest First</option>
                    <option value="event_date_asc">Event Date ↑</option><option value="event_date_desc">Event Date ↓</option>
                </select>
                <button onclick="clearAdminFilters()" style="padding:7px 14px;background:#888;color:white;border:none;border-radius:5px;cursor:pointer;">Clear</button>
            </div>
            <div id="admin_table_container"></div>
            <div class="pagination" id="admin_pagination"></div>
        </div>

        <!-- ANALYTICS SUB-TAB -->
        <div id="admin_analytics_tab" style="display:none;">
            <div id="analytics_loading_msg" style="display:none;text-align:center;padding:40px;color:rgb(27,0,93);">
                <div style="font-size:32px;margin-bottom:12px;">⏳</div>
                <div style="font-weight:700;font-size:15px;">Loading analytics data…</div>
            </div>

            <div style="background:#fff;border:1px solid #e8e8e8;border-radius:14px;padding:16px 20px;margin-bottom:18px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;" id="time_pill_row">
                        <button class="a-pill active" id="pill_alltime" onclick="setTimePill('alltime')">🗓️ All Time</button>
                        <button class="a-pill" id="pill_year"    onclick="setTimePill('year')">📅 Year</button>
                        <button class="a-pill" id="pill_mrange"  onclick="setTimePill('mrange')">📆 Month Range</button>
                        <button class="a-pill" id="pill_custom"  onclick="setTimePill('custom')">🔎 Custom Range</button>
                    </div>
                    <div style="background:#f0eeff;color:rgb(27,0,93);font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;" id="showing_label">Showing: All Time</div>
                </div>
                <div id="time_inputs_year" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="font-weight:600;font-size:13px;color:#444;">Year:</label>
                    <select id="filter_year" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                </div>
                <?php $mn=['January','February','March','April','May','June','July','August','September','October','November','December']; ?>
                <div id="time_inputs_mrange" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="font-weight:600;font-size:13px;color:#444;">From:</label>
                    <select id="filter_mrange_from_month" onchange="enforceMrangeOrder(); applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php foreach($mn as $mi=>$ml){$v=str_pad($mi+1,2,'0',STR_PAD_LEFT);echo "<option value='$v'>$ml</option>";}?>
                    </select>
                    <select id="filter_mrange_from_year" onchange="enforceMrangeOrder(); applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                    <label style="font-weight:600;font-size:13px;color:#444;margin-left:8px;">To:</label>
                    <select id="filter_mrange_to_month" onchange="enforceMrangeOrder(); applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php foreach($mn as $mi=>$ml){$v=str_pad($mi+1,2,'0',STR_PAD_LEFT);$sel=($mi+1==(int)date('n'))?'selected':'';echo "<option value='$v' $sel>$ml</option>";}?>
                    </select>
                    <select id="filter_mrange_to_year" onchange="enforceMrangeOrder(); applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                        <?php for($y=date('Y');$y>=2020;$y--) echo "<option value='$y'>$y</option>"; ?>
                    </select>
                </div>
                <div id="time_inputs_custom" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                    <label style="font-weight:600;font-size:13px;color:#444;">From:</label>
                    <input type="date" id="filter_custom_from" onchange="enforceCustomRange('from'); applyAnalyticsFilter();" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                    <label style="font-weight:600;font-size:13px;color:#444;margin-left:8px;">To:</label>
                    <input type="date" id="filter_custom_to" onchange="enforceCustomRange('to'); applyAnalyticsFilter();" style="padding:7px;border-radius:6px;border:1px solid #ccc;">
                </div>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0;">
                    <label style="font-weight:600;font-size:13px;color:rgb(27,0,93);">Event Type:</label>
                    <select id="analytics_type_filter" onchange="applyAnalyticsFilter()" style="padding:7px;border-radius:6px;border:1px solid #ccc;min-width:210px;">
                        <option value="all">All Event Types</option>
                        <option>Expert Talk</option><option>Mentoring Session</option><option>Workshop</option>
                        <option>Seminar</option><option>Boot Camp</option><option>Expo</option>
                        <option>Demo Day / Competition</option><option>Tech Fest / Hackathon / Ideathon</option>
                    </select>
                    <button onclick="downloadAnalyticsTXT()" style="margin-left:auto;padding:8px 18px;background:rgb(27,0,93);color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">⬇ Download Analytics Report</button>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:12px;margin-bottom:18px;" id="kpi_grid">
                <div class="kpi-card2"><div class="kpi2-icon">📋</div><div class="kpi2-val" id="kpi_total_events"><?= $totalEventsRegistered ?></div><div class="kpi2-label">EVENTS REGISTERED</div></div>
                <div class="kpi-card2" title="When filtering by event type, counts runs that contained at least one event of that type."><div class="kpi2-icon">⚡</div><div class="kpi2-val" id="kpi_seg_runs"><?= $totalSegregationRuns ?></div><div class="kpi2-label">SEGREGATIONS DONE</div><div id="kpi_seg_note" style="display:none;font-size:10px;color:#888;margin-top:3px;line-height:1.3;">runs with this type</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">🎓</div><div class="kpi2-val" id="kpi_students"><?= number_format($totalStudentsAllRuns) ?></div><div class="kpi2-label">STUDENTS PROCESSED</div></div>
                <div class="kpi-card2" style="border-color:#e74c3c;"><div class="kpi2-icon">⚠️</div><div class="kpi2-val" id="kpi_pending" style="color:#e74c3c;"><?= $totalPending ?></div><div class="kpi2-label" style="color:#e74c3c;">PENDING</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">📅</div><div class="kpi2-val" id="kpi_single"><?= $totalSingleDay ?></div><div class="kpi2-label">SINGLE-DAY EVENTS</div></div>
                <div class="kpi-card2"><div class="kpi2-icon">🗓️</div><div class="kpi2-val" id="kpi_multi"><?= $totalMultiDay ?></div><div class="kpi2-label">MULTI-DAY EVENTS</div></div>
            </div>

            <div class="insight-box" id="insight_box_dynamic">
                <div class="insight-title">🧠 Smart Insights</div>
                <div id="insight_lines_dynamic"></div>
            </div>

            <div class="charts-row" style="align-items:stretch;">
                <div class="chart-card" style="flex:1.2;min-width:300px;">
                    <div class="chart-title" style="color:#e74c3c;">⚠️ Pending Events (Registered but Never Segregated)</div>
                    <div id="pending_events_list"></div>
                </div>
                <div class="chart-card" style="flex:1;min-width:260px;">
                    <div class="chart-title">📊 Segregation Stats</div>
                    <table style="margin-top:8px;" id="seg_stats_table">
                        <tr><th>Metric</th><th>Value</th></tr>
                        <tr><td>Segregations Done</td><td><strong id="ss_runs"><?= $totalSegregationRuns ?></strong></td></tr>
                        <tr><td>Total Students</td><td><strong id="ss_students"><?= number_format($totalStudentsAllRuns) ?></strong></td></tr>
                        <tr><td>Avg Students / Segregation</td><td><strong id="ss_avg_students"><?= number_format($avgStudentsPerRun) ?></strong></td></tr>
                        <tr><td>Avg Events / Segregation</td><td><strong id="ss_avg_events"><?= $avgEventsPerRun ?></strong></td></tr>
                        <tr><td>Busiest Day of Week</td><td><strong id="ss_dow"><?= !empty($dowCounts) && max($dowCounts) > 0 ? $dayNames[array_search(max($dowCounts),$dowCounts)] : '–' ?></strong></td></tr>
                        <tr><td>Last Segregation</td><td><strong><?= $lastSegOn ? date('d-m-Y g:i A', strtotime($lastSegOn)) : '–' ?></strong></td></tr>
                        <tr><td>Last Event Registered</td><td><strong><?= htmlspecialchars($lastEventOn ?? '–') ?></strong></td></tr>
                    </table>
                </div>
            </div>

            <div class="charts-row">
                <div class="chart-card"><div class="chart-title">📅 Events Registered — Monthly</div><canvas id="chartEventsMonthly" height="220"></canvas></div>
                <div class="chart-card"><div class="chart-title">⚡ Segregations Done — Monthly</div><canvas id="chartSegregMonthly" height="220"></canvas></div>
            </div>
            <div class="charts-row">
                <div class="chart-card"><div class="chart-title">📈 Registered vs Segregated (Monthly Trend)</div><canvas id="chartComparison" height="220"></canvas></div>
                <div class="chart-card chart-card-small"><div class="chart-title">🔵 Event Type Split</div><canvas id="chartTypeSplit" height="220"></canvas></div>
            </div>
            <div class="charts-row" style="align-items:stretch;">
                <div class="chart-card" style="flex:0 0 320px;"><div class="chart-title">📅 Busiest Days of the Week</div><canvas id="chartDOW" height="280"></canvas></div>
                <div class="chart-card" style="flex:1;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <div class="chart-title" style="margin-bottom:0;">📅 Event Density Heatmap — <span id="heatmapYearLabel"><?= date('Y') ?></span></div>
                        <div style="display:flex;gap:6px;">
                            <button onclick="heatmapPrevYear()" style="padding:3px 10px;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#f5f5f5;">◀</button>
                            <button onclick="heatmapNextYear()" style="padding:3px 10px;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#f5f5f5;">▶</button>
                        </div>
                    </div>
                    <div id="heatmapContainer" style="overflow-x:auto;"></div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:12px;color:#888;">
                        <span>Less</span>
                        <div style="width:12px;height:12px;border-radius:2px;background:#edf2ff;border:1px solid #ddd;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#9b9fd4;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#6366c1;"></div>
                        <div style="width:12px;height:12px;border-radius:2px;background:#1b005d;"></div>
                        <span>More</span>
                    </div>
                </div>
            </div>
            <div class="chart-card" style="margin-bottom:18px;">
                <div class="chart-title">🏫 School-wise Attendance Distribution</div>
                <div id="schoolAttendanceContainer"><p style="color:#888;font-style:italic;padding:14px 0;">Loading…</p></div>
            </div>
            <div class="charts-row">
                <div class="chart-card chart-card-small"><div class="chart-title">🏷️ Events by Type</div><canvas id="chartEventTypeSplit" height="260"></canvas></div>
                <div class="chart-card"><div class="chart-title">📊 Event Type Breakdown</div><div id="event_type_table_container"></div></div>
            </div>
            <div class="charts-row" style="align-items:stretch;">
                <div class="chart-card" style="flex:1;"><div class="chart-title">🏛️ Venue Utilisation (Top 10)</div><div id="venueLeaderboardDynamic" style="margin-top:10px;"></div></div>
                <div class="chart-card" style="flex:1;"><div class="chart-title">👥 Faculty Coordinator Leaderboard (Top 10)</div><div id="teamLeaderboardDynamic" style="margin-top:10px;"></div></div>
            </div>
        </div><!-- /admin_analytics_tab -->
    </div><!-- /admin page -->

    <!-- MODAL -->
    <div id="rulesModal" class="modal">
        <div class="modal-content">
            <h2>WELCOME TO SMART ATTENDANCE SEGREGATOR</h2>
            <h2>Please read this page!</h2>
            <ul>
                <li>Enter accurate event details.</li>
                <li>For events spanning multiple days, add a slot for each day with its respective date &amp; time.</li>
                <li>Each day of a multiple-day event will require a separate Excel upload during segregation.</li>
            </ul>
            <button id="closeModal">I Understand</button>
        </div>
    </div>
</div><!-- /container -->

<!-- ===== PHP-INJECTED DATA (must stay inline) ===== -->
<script>
const eventsData             = <?php echo json_encode($events); ?>;
const allHistoryData         = <?php echo json_encode($adminHistory); ?>;
const allEventsData          = <?php echo json_encode($events); ?>;
const allEventsForAnalytics  = <?php echo json_encode(array_map(fn($e) => ['name'=>$e['name'],'date'=>$e['date'],'end_date'=>$e['end_date']??$e['date'],'event_type'=>$e['event_type']??'','multiday'=>(bool)$e['multiday'],'venue'=>$e['venue'],'faculty_coordinator'=>$e['faculty_coordinator']??''], $eventsRaw)); ?>;
const allEventsWithDates     = <?php echo json_encode(array_map(fn($e) => ['name'=>$e['name'],'date'=>$e['date'],'end_date'=>$e['end_date']??$e['date'],'event_type'=>$e['event_type']??''], $eventsRaw)); ?>;
const allPendingEventsData   = <?php echo json_encode(array_values($pendingForJS)); ?>;
const segregatedEventNamesSet= new Set(<?php echo json_encode(array_keys($segregatedEventNames)); ?>);
let   allSegHistoryJS        = <?php echo json_encode($segHistoryForJS); ?>;
</script>

<!-- ===== LIBRARIES ===== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<!-- ===== APP LOGIC ===== -->
<script src="register_event.js"></script>

<!-- ===== EVENT ADDED SUCCESS POPUP ===== -->
<?php if (isset($_GET['event_added']) && $_GET['event_added'] == '1'): ?>
<div id="successPopup" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:40px 48px;text-align:center;box-shadow:0 8px 40px rgba(27,0,93,0.25);max-width:420px;width:90%;animation:popIn 0.3s ease;">
        <div style="font-size:52px;margin-bottom:12px;">✅</div>
        <h2 style="color:rgb(27,0,93);margin-bottom:10px;font-size:20px;">Event Added Successfully!</h2>
        <p style="color:#555;font-size:14px;margin-bottom:24px;">The event has been registered in the system.</p>
        <button onclick="document.getElementById('successPopup').style.display='none'" style="padding:10px 32px;background:rgb(27,0,93);color:white;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;">OK</button>
    </div>
</div>
<?php endif; ?>

</body>
<footer class="page-footer">
    <p>
        Developed by: <strong>Nithesh Kumar T</strong>, <strong>Umair Ahmed R, Srishti Singh</strong> <br>
        Mentor: <strong>Dr Jothish Kumar M</strong>
    </p>
</footer>
</html>

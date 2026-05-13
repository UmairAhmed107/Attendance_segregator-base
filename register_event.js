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

/* ==================== BACK-BUTTON AUTO-LOGOUT ==================== */
history.pushState({ page: 'app' }, '', window.location.href);
window.addEventListener('popstate', function () {
    window.location.href = 'index.php?logout=1';
});

/* ==================== MODAL ==================== */
const modal = document.getElementById('rulesModal');
document.getElementById('closeModal').addEventListener('click', () => { modal.style.display = 'none'; });

/* ==================== MULTI-DAY REGISTRATION ==================== */
let dayCount = 0;
function makeTimeSelects(prefix, idx) {
    let h = `<select name="${prefix}_hour[${idx}]">`,
        m = `<select name="${prefix}_minute[${idx}]">`,
        ap = `<select name="${prefix}_ampm[${idx}]">`;
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
            <label>Date <span style="color:red">*</span></label>
            <input type="date" name="day_date[${idx}]" required onchange="enforceDayDateOrder(${idx})">
        </div>
        <div class="form-row">
            <label>Timing <span style="color:red">*</span></label>
            <div class="time-group">
                <div><span>From</span><br>${makeTimeSelects('day_from', idx)}</div>
                <div><span>To</span><br>${makeTimeSelects('day_to', idx)}</div>
            </div>
        </div>
        <div class="form-row">
            <label>Venue <span style="color:red">*</span></label>
            <input type="text" name="day_venue[${idx}]" required placeholder="Venue for Day ${idx + 1}" style="width:100%;box-sizing:border-box;">
        </div>`;
    container.appendChild(div);
}
function removeDaySlot(idx) {
    const el = document.getElementById('day_slot_' + idx);
    if (el) el.remove();
}
function enforceDayDateOrder() {
    const allDateInputs = Array.from(document.querySelectorAll('#day_slots_container input[type=date]'));
    for (let i = 1; i < allDateInputs.length; i++) {
        const prevVal = allDateInputs[i-1].value;
        if (prevVal) allDateInputs[i].min = prevVal;
        if (allDateInputs[i].value && prevVal && allDateInputs[i].value < prevVal) {
            allDateInputs[i].value = '';
        }
    }
}
function toggleMultiday() {
    const checked = document.getElementById('multiday_toggle').checked;
    document.getElementById('singleday_fields').style.display = checked ? 'none' : 'block';
    document.getElementById('multiday_fields').style.display  = checked ? 'block' : 'none';
    document.querySelectorAll('#singleday_fields input[type=date]').forEach(el => { el.required = !checked; });
    const venueInput = document.getElementById('main_venue_input');
    if (venueInput) venueInput.required = !checked;
    if (checked && dayCount === 0) addDaySlot();
}

/* ==================== SEGREGATION ==================== */
const filterDateFrom  = document.getElementById('filter_date_from');
const filterDateTo    = document.getElementById('filter_date_to');
const numEventsSelect = document.getElementById('num_events');
const eventToggles    = document.getElementById('event_toggles');
const segregResults   = document.getElementById('segregation_results');

let availableEvents = [];

function updateSegToMin() {
    const fromVal = filterDateFrom.value;
    const toEl    = filterDateTo;
    if (fromVal) toEl.min = fromVal;
    if (toEl.value && fromVal && toEl.value < fromVal) toEl.value = '';
}

function filterEventsByRange() {
    const from = filterDateFrom.value;
    const to   = filterDateTo.value;
    if (!from) { alert('Please select a start date.'); return; }
    if (!to)   { alert('Please select an end date.'); return; }
    if (to < from) { alert('End date cannot be before start date.'); return; }

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

function addFileInput(containerId, fieldName) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:6px;';
    wrapper.innerHTML = `
        <input type="file" name="${fieldName}" accept=".xlsx,.xls" required style="flex:1;">
        <button type="button" onclick="this.parentElement.remove()" style="background:#c0392b;color:white;border:none;border-radius:4px;padding:4px 10px;cursor:pointer;font-size:13px;white-space:nowrap;">✕ Remove</button>`;
    container.appendChild(wrapper);
}

function buildFileUploadSlots(evObj, eventIdx) {
    if (evObj.multiday && evObj.days && evObj.days.length > 0) {
        let html = `<div style="margin-top:10px;"><strong style="color:rgb(27,0,93);">📅 Upload Attendance Excel — Per Day (add multiple files if needed):</strong></div>`;
        evObj.days.forEach((day, dayIdx) => {
            const dateFormatted = day.date
                ? new Date(day.date + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'})
                : '';
            const venueStr = day.venue ? `<span class="day-time">📍 ${day.venue}</span>` : '';
            const containerId = `files_${eventIdx}_${dayIdx}`;
            const fieldName   = `excel_file_${eventIdx}_${dayIdx}[]`;
            html += `<div class="day-upload-slot" style="flex-direction:column;align-items:flex-start;">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%;flex-wrap:wrap;gap:6px;">
                    <div>
                        <span class="day-label">📆 Day ${dayIdx+1}: ${dateFormatted}</span>
                        ${venueStr}
                        <span class="day-time">${day.time || ''}</span>
                    </div>
                    <button type="button"
                        onclick="addFileInput('${containerId}','${fieldName}')"
                        style="background:rgb(27,0,93);color:white;border:none;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:12px;white-space:nowrap;">
                        + Add File
                    </button>
                </div>
                <div id="${containerId}" style="width:100%;margin-top:6px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="file" name="${fieldName}" accept=".xlsx,.xls" required style="flex:1;">
                    </div>
                </div>
            </div>`;
        });
        return html;
    } else {
        const containerId = `files_${eventIdx}_0`;
        const fieldName   = `excel_file_${eventIdx}_0[]`;
        return `<div style="margin-top:10px;border:1px solid #e0e0e0;border-radius:8px;padding:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <label style="font-weight:700;color:rgb(27,0,93);">📄 Upload Attendance Excel</label>
                <button type="button"
                    onclick="addFileInput('${containerId}','${fieldName}')"
                    style="background:rgb(27,0,93);color:white;border:none;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:12px;white-space:nowrap;">
                    + Add File
                </button>
            </div>
            <div id="${containerId}">
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="file" name="${fieldName}" accept=".xlsx,.xls" required style="flex:1;">
                </div>
            </div>
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
                val   = `ID:${ev.id}||MULTIDAY`;
                label = `📆 ${ev.name} (Multi-day: ${dStart} – ${dEnd})`;
            } else {
                val   = `ID:${ev.id}||SINGLE`;
                label = `📅 ${ev.name} (${ev.date} | ${ev.time} | ${ev.venue})`;
            }
            options += `<option value="${val}">${label}</option>`;
        });
        div.innerHTML = `
            <h3>Event ${i + 1}</h3>
            <div class="form-row">
                <label>Select Event</label>
                <select name="selected_event[]" class="event-name-select" data-idx="${i}">${options}</select>
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
            syncEventSelects();
            if (!val) return;
            const evId  = parseInt(val.split('||')[0].replace('ID:',''));
            const evObj = availableEvents.find(e => e.id === evId);
            if (!evObj) return;
            uploadContainer.innerHTML = buildFileUploadSlots(evObj, idx);
        });
    });
});

function syncEventSelects() {
    const allSelects = document.querySelectorAll('.event-name-select');
    const chosenVals = new Set();
    allSelects.forEach(s => { if (s.value) chosenVals.add(s.value); });
    allSelects.forEach(sel => {
        sel.querySelectorAll('option').forEach(opt => {
            if (!opt.value) return;
            const takenByOther = chosenVals.has(opt.value) && opt.value !== sel.value;
            opt.disabled = takenByOther;
            opt.style.color      = takenByOther ? '#bbb' : '';
            opt.style.fontStyle  = takenByOther ? 'italic' : '';
            opt.style.background = takenByOther ? '#f0f0f0' : '';
            if (takenByOther && !opt.text.startsWith('\u2715 ')) {
                opt.text = '\u2715 ' + opt.text;
            } else if (!takenByOther && opt.text.startsWith('\u2715 ')) {
                opt.text = opt.text.slice(2);
            }
        });
    });
}

/* ==================== ADMIN PANEL ==================== */
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
    if (tab === 'analytics') {
        if (!analyticsLoaded) loadAnalyticsData();
        else setTimeout(initCharts, 50);
    }
}

function getFilteredEvents() {
    const search     = document.getElementById('ev_search').value.toLowerCase();
    const dateFrom   = document.getElementById('ev_date_from').value;
    const dateTo     = document.getElementById('ev_date_to').value;
    const typeFilter = document.getElementById('ev_type_filter').value;
    const sort       = document.getElementById('ev_sort').value;

    let data = allEventsData.filter(ev => {
        const matchText = !search || ev.name.toLowerCase().includes(search) || ev.venue.toLowerCase().includes(search);
        const evEnd     = ev.end_date || ev.date;
        const matchFrom = !dateFrom || evEnd >= dateFrom;
        const matchTo   = !dateTo   || ev.date <= dateTo;
        const matchType = !typeFilter || ev.event_type === typeFilter;
        return matchText && matchFrom && matchTo && matchType;
    });

    data.sort((a, b) => {
        if (sort === 'newest')    return b.date.localeCompare(a.date);
        if (sort === 'oldest')    return a.date.localeCompare(b.date);
        if (sort === 'name_asc')  return a.name.localeCompare(b.name);
        if (sort === 'name_desc') return b.name.localeCompare(a.name);
        return 0;
    });
    return data;
}

function formatDate(ymd) {
    if (!ymd) return '';
    const p = ymd.split('-');
    return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : ymd;
}
function formatDateTime(dt) {
    if (!dt) return '–';
    const parts = dt.split(' ');
    const datePart = formatDate(parts[0]);
    if (!parts[1]) return datePart;
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
    html += `<table style="width:100%;"><tr>
        <th>#</th><th>Event Name</th><th>Faculty Coordinator</th>
        <th>School</th><th>Phone Number</th>
        <th>Event Type</th><th>Day Type</th><th style="width:170px;">Date, Venue &amp; Time</th>
        <th style="width:90px;">Action</th>
    </tr>`;

    slice.forEach((ev, i) => {
        const typeLabel = ev.multiday ? '<span class="badge-multi">Multi-day</span>' : 'Single Day';
        let dtDisplay = '';
        if (ev.multiday && ev.days && ev.days.length > 0) {
            dtDisplay = ev.days.map((d, idx) => {
                const dateStr  = formatDate(d.date);
                const timeStr  = d.time  || '';
                const venueStr = d.venue || '';
                return `<div style="margin-bottom:6px;padding-bottom:5px;border-bottom:1px dashed #eee;">` +
                    `<span style="font-weight:700;color:rgb(27,0,93);font-size:12px;">Day ${idx+1} &nbsp;${dateStr}</span>` +
                    (venueStr ? `<br><span style="color:rgb(27,0,93);font-size:10px;font-weight:600;">📍 ${venueStr}</span>` : '') +
                    (timeStr  ? `<br><span style="color:#555;font-size:11px;">${timeStr}</span>` : '') +
                    `</div>`;
            }).join('');
        } else {
            const dateStr  = formatDate(ev.date);
            const timeStr  = ev.time  || '';
            const venueStr = ev.venue || '';
            dtDisplay =
                `<span style="font-weight:700;color:rgb(27,0,93);font-size:12px;">${dateStr}</span>` +
                (venueStr ? `<br><span style="color:rgb(27,0,93);font-size:10px;font-weight:600;">📍 ${venueStr}</span>` : '') +
                (timeStr  ? `<br><span style="color:#555;font-size:11px;">${timeStr}</span>` : '');
        }

        const safeName = ev.name.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
        const evTypeBadge = ev.event_type
            ? `<span style="background:#e8e0ff;color:rgb(27,0,93);font-size:11px;padding:2px 7px;border-radius:10px;white-space:nowrap;">${ev.event_type}</span>`
            : '–';

        html += `<tr>
            <td>${start+i+1}</td>
            <td>${ev.name}</td>
            <td>${ev.faculty_coordinator || '–'}</td>
            <td>${ev.school || '–'}</td>
            <td>${ev.phone_number || '–'}</td>
            <td>${evTypeBadge}</td>
            <td>${typeLabel}</td>
            <td style="width:170px;line-height:1.5;font-size:11px;">${dtDisplay}</td>
            <td style="width:95px;text-align:center;">
                <form method="POST" onsubmit="return confirm('Delete event \\'${safeName}\\'. This cannot be undone.');">
                    <input type="hidden" name="event_id" value="${ev.id}">
                    <button type="submit" name="delete_event" class="btn-delete" style="padding:3px 6px;font-size:11px;">🗑 Delete</button>
                </form>
            </td>
        </tr>`;
    });
    html += '</table>';
    container.innerHTML = html;

    let pHtml = '';
    for (let p = 1; p <= pages; p++)
        pHtml += `<button class="${p === eventsCurrentPage ? 'active-page' : ''}" onclick="goEventsPage(${p})">${p}</button>`;
    pagination.innerHTML = pHtml;
}
function goEventsPage(p) { eventsCurrentPage = p; renderEventsPage(); }
function clearEvFilters() {
    document.getElementById('ev_search').value      = '';
    document.getElementById('ev_date_from').value   = '';
    document.getElementById('ev_date_to').value     = '';
    document.getElementById('ev_type_filter').value = '';
    document.getElementById('ev_sort').value        = 'newest';
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
        <th>Faculty Coordinator (School | Phone)</th><th>Count</th><th>Segregated On</th><th>Download</th><th>Action</th>
    </tr>`;

    slice.forEach((r, i) => {
        const drFrom = r.date_from ? formatDate(r.date_from) : '';
        const drTo   = r.date_to   ? formatDate(r.date_to)   : '';
        const dateDisplay = (drFrom && drTo && drFrom !== drTo) ? drFrom + ' – ' + drTo : drFrom;
        const evLines = r.events_text.split('\n').map(line => `<div style="margin-bottom:3px;">• ${line}</div>`).join('');
        const zipLinks = (r.zips||[]).filter(z => z.endsWith('.zip')).map(z =>
            `<a href="downloads/${z}" target="_blank" style="display:block;margin-bottom:3px;">📦 ${z}</a>`
        ).join('') || '–';

        html += `<tr>
            <td>${start+i+1}</td>
            <td style="white-space:nowrap;">${dateDisplay}</td>
            <td style="font-size:12px;line-height:1.6;">${evLines}</td>
            <td style="font-size:12px;line-height:1.7;">${r.organising_teams||'–'}</td>
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
    for (let p = 1; p <= pages; p++)
        pHtml += `<button class="${p === adminCurrentPage ? 'active-page' : ''}" onclick="goAdminPage(${p})">${p}</button>`;
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
    if (tab === 'segregation' || urlParams.has('segregation')) showPage('segregation');
    else if (tab === 'admin') showPage('admin');
    else modal.style.display = 'block';
    renderEventsTable();
    renderAdminTable();
});

/* ==================== ANALYTICS ==================== */
const monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const dowLabels   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

let analyticsLoaded      = false;
let segregMonthlyData    = [];
let heatmapByDateAll     = {};
let venueLabels          = [];
let venueCounts_         = [];
let teamLabels           = [];
let teamCounts_          = [];
let schoolLabelsJS       = [];
let schoolDateStats      = [];
let schoolCountsJS       = [];
let eventParticipationJS = [];
let totalSegRunsAll      = 0;
let totalPendingAll      = 0;
let totalStudentsAll     = 0;
let avgStudentsPerRunAll = 0;
let avgEventsPerRunAll   = 0;

const baseColor   = 'rgb(27,0,93)';
const accentColor = 'rgb(90,0,200)';
const lightColor  = 'rgba(27,0,93,0.15)';
const goldColor   = 'rgb(255,193,7)';
const typeColors  = ['#1b005d','#3d00c8','#ffc107','#e83e8c','#20c997','#fd7e14','#6610f2','#17a2b8'];
const dowBarColors= ['#6610f2','#6610f2','#e83e8c','#6610f2','#e83e8c','#e83e8c','rgb(255,193,7)'];
const _rankEmojis   = ['🥇','🥈','🥉'];
const _barColors    = ['#ffc107','#9e9e9e','#cd7f32','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d','#1b005d'];
const _schoolColors = ['#6366c1','#3bbfd8','#f4a03a','#222b5e','#1b005d','#27ae60','#56cfe1','#e67e22','#00b4d8','#e74c3c','#8e44ad','#2ecc71','#f39c12','#16a085','#c0392b','#2980b9'];

let chartEvM=null, chartSegM=null, chartComp=null, chartDOW=null, chartDay=null, chartType=null;
let chartsInitialized = false;
let currentTimePill = 'alltime';

function validateEventTiming() {
    function to24(h, m, ap) {
        let hh = parseInt(h);
        if (ap === 'AM' && hh === 12) hh = 0;
        if (ap === 'PM' && hh !== 12) hh += 12;
        return hh * 60 + parseInt(m);
    }
    const fH = document.querySelector('[name=from_hour]');
    const fM = document.querySelector('[name=from_minute]');
    const fA = document.querySelector('[name=from_ampm]');
    const tH = document.querySelector('[name=to_hour]');
    const tM = document.querySelector('[name=to_minute]');
    const tA = document.querySelector('[name=to_ampm]');
    const warn = document.getElementById('time_warn');
    if (!fH || !tH || !warn) return;
    const fromMins = to24(fH.value, fM.value, fA.value);
    const toMins   = to24(tH.value, tM.value, tA.value);
    warn.style.display = (toMins <= fromMins) ? 'block' : 'none';
}

function enforceMrangeOrder() {
    const fm = document.getElementById('filter_mrange_from_month');
    const fy = document.getElementById('filter_mrange_from_year');
    const tm = document.getElementById('filter_mrange_to_month');
    const ty = document.getElementById('filter_mrange_to_year');
    if (!fm || !fy || !tm || !ty) return;
    const fromVal = fy.value + fm.value;
    const toVal   = ty.value + tm.value;
    if (toVal < fromVal) { ty.value = fy.value; tm.value = fm.value; }
}

function enforceCustomRange(changed) {
    const fromEl = document.getElementById('filter_custom_from');
    const toEl   = document.getElementById('filter_custom_to');
    if (!fromEl || !toEl) return;
    const from = fromEl.value, to = toEl.value;
    if (!from || !to) return;
    if (changed === 'from' && from > to) toEl.value = from;
    else if (changed === 'to' && to < from) toEl.value = from;
}
function enforceCustomDateOrder() {}

function setTimePill(pill) {
    currentTimePill = pill;
    ['alltime','year','mrange','custom'].forEach(p => {
        document.getElementById('pill_'+p).classList.toggle('active', p===pill);
        const el = document.getElementById('time_inputs_'+p);
        if (el) el.style.display = (p!=='alltime' && p===pill) ? 'flex' : 'none';
    });
    const labels = {alltime:'All Time', year:'Year', mrange:'Month Range', custom:'Custom Range'};
    document.getElementById('showing_label').textContent = 'Showing: ' + labels[pill];
    applyAnalyticsFilter();
}

function getTimeBounds() {
    const pill = currentTimePill;
    if (pill === 'alltime') return [null, null];
    if (pill === 'year') {
        const y = document.getElementById('filter_year').value;
        return [`${y}-01-01`, `${y}-12-31`];
    }
    if (pill === 'mrange') {
        const fm = document.getElementById('filter_mrange_from_month').value;
        const fy = document.getElementById('filter_mrange_from_year').value;
        const tm = document.getElementById('filter_mrange_to_month').value;
        const ty = document.getElementById('filter_mrange_to_year').value;
        const lastDay = new Date(parseInt(ty), parseInt(tm), 0).getDate();
        const from = `${fy}-${fm}-01`;
        const to   = `${ty}-${tm}-${String(lastDay).padStart(2,'0')}`;
        return from <= to ? [from, to] : [to, from];
    }
    if (pill === 'custom') {
        const from = document.getElementById('filter_custom_from').value;
        const to   = document.getElementById('filter_custom_to').value;
        if (!from && !to) return [null, null];
        if (!from) return [null, to];
        if (!to)   return [from, null];
        return from <= to ? [from, to] : [to, from];
    }
    return [null, null];
}

function computeFilteredStudents(from, to, typeF) {
    if (!schoolDateStats || schoolDateStats.length === 0) return 0;
    return schoolDateStats
        .filter(r => {
            if ((!from || r.date >= from) && (!to || r.date <= to)) {
                if (typeF && typeF !== 'all' && r.event_type !== undefined) return r.event_type === typeF;
                return true;
            }
            return false;
        })
        .reduce((sum, r) => sum + r.total, 0);
}

function getAnalyticsFilteredEvents() {
    const typeF = (document.getElementById('analytics_type_filter')?.value) || 'all';
    const [from, to] = getTimeBounds();
    return allEventsForAnalytics.filter(ev => {
        if (typeF !== 'all' && ev.event_type !== typeF) return false;
        if (from && (ev.date||'') < from) return false;
        if (to   && (ev.date||'') > to)   return false;
        return true;
    });
}

function computeMonthly(evArr) {
    const m = Array(12).fill(0);
    evArr.forEach(ev => {
        const mo = parseInt((ev.date||'').split('-')[1]||'0') - 1;
        if (mo >= 0 && mo < 12) m[mo]++;
    });
    return m;
}
function computeDaySplit(evArr) {
    let s=0, m=0;
    evArr.forEach(ev => { if (ev.multiday) m++; else s++; });
    return [s, m];
}
function computeTypeCounts(evArr) {
    const c = {};
    evArr.forEach(ev => { if (ev.event_type) c[ev.event_type] = (c[ev.event_type]||0)+1; });
    return c;
}
function computeDOW(evArr) {
    const d = Array(7).fill(0);
    evArr.forEach(ev => {
        if (!ev.date) return;
        const dt = new Date(ev.date + 'T12:00:00');
        if (!isNaN(dt)) d[dt.getDay()]++;
    });
    return d;
}

function buildInsights(evArr) {
    const lines    = [];
    const monthly  = computeMonthly(evArr);
    const [s, m]   = computeDaySplit(evArr);
    const dow      = computeDOW(evArr);
    const typeCnts = computeTypeCounts(evArr);
    const total    = evArr.length;
    const [from, to] = getTimeBounds();
    const g = (v) => `<span style="color:#ffc107;font-weight:800;">${v}</span>`;

    if (total === 0) {
        lines.push('→ No events match the selected filter.');
    } else {
        const peakMoIdx = monthly.indexOf(Math.max(...monthly));
        if (Math.max(...monthly) > 0)
            lines.push(`→ 🗓️ ${g(monthLabels[peakMoIdx])} was the most active month (${monthly[peakMoIdx]} events).`);
        if (totalStudentsAll > 0)
            lines.push(`→ 🎓 ${g(totalStudentsAll.toLocaleString())} student records processed.`);
        if (m > 0) {
            const pct = Math.round(m / Math.max(total,1) * 100);
            lines.push(`→ 🗓️ ${g(pct+'%')} of events are multi-day (${m} of ${total}).`);
        }
        if (eventParticipationJS && eventParticipationJS.length > 0) {
            const top = eventParticipationJS[0];
            lines.push(`→ 🏆 Most attended event: ${g(top.name)} (${g(top.count.toLocaleString())} students across ${top.schools} school${top.schools!==1?'s':''}).`);
            if (eventParticipationJS.length > 1) {
                const least = eventParticipationJS[eventParticipationJS.length - 1];
                lines.push(`→ 📉 Least attended event: ${g(least.name)} (${least.count.toLocaleString()} students).`);
            }
        }
        if (schoolLabelsJS.length > 0)
            lines.push(`→ 🏫 Most participating school: ${g(schoolLabelsJS[0])} (${schoolCountsJS[0].toLocaleString()} students).`);
        const sortedTypes = Object.entries(typeCnts).sort((a,b)=>b[1]-a[1]);
        if (sortedTypes.length > 0 && sortedTypes[0][1] > 0)
            lines.push(`→ 🏷️ Most common event type: ${g(sortedTypes[0][0])} (${sortedTypes[0][1]} events).`);
        if (venueLabels.length > 0)
            lines.push(`→ 🏛️ Most utilised venue: ${g(venueLabels[0])} (${venueCounts_[0]} event${venueCounts_[0]>1?'s':''}).`);
        if (teamLabels.length > 0)
            lines.push(`→ 👥 Most active Faculty Coordinator: ${g(teamLabels[0].toUpperCase())} (${teamCounts_[0]} event${teamCounts_[0]>1?'s':''}).`);
        const peakDowIdx = dow.indexOf(Math.max(...dow));
        if (Math.max(...dow) > 0)
            lines.push(`→ 📆 Busiest day: ${g(dowLabels[peakDowIdx])} (${dow[peakDowIdx]} events).`);
    }

    const container = document.getElementById('insight_lines_dynamic');
    if (container) container.innerHTML = lines.map(l => `<div class="insight-line">${l}</div>`).join('');
    const box = document.getElementById('insight_box_dynamic');
    if (box) box.style.display = lines.length ? '' : 'none';
}

function renderPendingEventsList(from, to, typeF) {
    const container = document.getElementById('pending_events_list');
    if (!container) return;
    const filtered = allPendingEventsData.filter(ev => {
        if (typeF && typeF !== 'all' && ev.event_type !== typeF) return false;
        if (!from && !to) return true;
        const evStart = ev.date || '';
        const evEnd   = ev.end_date || ev.date || '';
        return (!from || evEnd >= from) && (!to || evStart <= to);
    });
    if (filtered.length === 0) {
        container.innerHTML = '<p style="color:green;font-weight:bold;margin-top:12px;">✅ No pending events in this period.</p>';
        return;
    }
    container.innerHTML = filtered.map(ev => {
        const startFmt = ev.date ? new Date(ev.date + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '';
        const endFmt   = ev.end_date ? new Date(ev.end_date + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'}) : '';
        const pdate    = ev.multiday && ev.end_date && ev.end_date !== ev.date ? `${startFmt} – ${endFmt}` : startFmt;
        const safeName  = ev.name.replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const safeVenue = ev.venue.replace(/</g,'&lt;').replace(/>/g,'&gt;');
        return `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid #f5dada;border-radius:8px;margin-bottom:8px;background:#fff9f9;">
            <div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:16px;">📋</span>
                    <span style="font-weight:700;color:#222;">${safeName}</span>
                    <span style="color:#888;font-size:12px;">— ${safeVenue}</span>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;">
                <span style="font-size:12px;color:#555;">${pdate}</span>
                <span style="background:#e74c3c;color:white;font-size:11px;font-weight:700;padding:3px 9px;border-radius:12px;">Pending</span>
            </div>
        </div>`;
    }).join('');
}

function loadAnalyticsData() {
    const loadingEl = document.getElementById('analytics_loading_msg');
    if (loadingEl) loadingEl.style.display = 'block';

    fetch('analytics_data.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            analyticsLoaded      = true;
            if (loadingEl) loadingEl.style.display = 'none';
            segregMonthlyData    = data.segregMonthly;
            heatmapByDateAll     = data.heatmapByDate;
            venueLabels          = data.venueLabels;
            venueCounts_         = data.venueCounts;
            teamLabels           = data.teamLabels;
            teamCounts_          = data.teamCounts;
            schoolLabelsJS       = data.schoolLabels;
            schoolCountsJS       = data.schoolCounts;
            schoolDateStats      = data.schoolDateStats || [];
            eventParticipationJS = data.eventParticipation;
            totalSegRunsAll      = data.totalSegRuns;
            totalPendingAll      = data.totalPending;
            totalStudentsAll     = data.totalStudents;
            avgStudentsPerRunAll = data.avgStudentsPerRun;
            avgEventsPerRunAll   = data.avgEventsPerRun;
            allSegHistoryJS      = data.segHistory;
            const el = document.getElementById('kpi_students');
            if (el) el.textContent = totalStudentsAll.toLocaleString('en-IN');
            setTimeout(initCharts, 50);
            const [initFrom, initTo] = getTimeBounds();
            const initTypeF2 = (document.getElementById('analytics_type_filter')?.value) || 'all';
            renderPendingEventsList(initFrom, initTo, initTypeF2);
        })
        .catch(err => {
            console.error('Analytics load failed:', err);
            if (loadingEl) loadingEl.innerHTML = '<p style="color:#c0392b;font-weight:700;">⚠ Could not load analytics. Please refresh the page.</p>';
        });
}

function renderSchoolAttendance(from, to, typeF) {
    const c = document.getElementById('schoolAttendanceContainer');
    if (!c) return;
    const totals = {};
    (schoolDateStats || []).forEach(r => {
        const inRange = (!from || r.date >= from) && (!to || r.date <= to);
        if (!inRange) return;
        if (typeF && typeF !== 'all' && r.event_type !== undefined && r.event_type !== typeF) return;
        totals[r.school] = (totals[r.school] || 0) + r.total;
    });
    const sorted = Object.entries(totals).sort((a, b) => b[1] - a[1]);
    if (sorted.length === 0) {
        c.innerHTML = '<p style="color:#888;font-style:italic;padding:14px 0;">No segregation data for this period.</p>';
        return;
    }
    const maxVal = sorted[0][1];
    c.innerHTML = sorted.map(([school, count], ci) => {
        const pct   = maxVal > 0 ? Math.round(count / maxVal * 100) : 0;
        const color = _schoolColors[ci % _schoolColors.length];
        return `<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <div style="min-width:70px;font-weight:700;font-size:13px;color:#222;">${school}</div>
            <div style="flex:1;background:#f0f0f0;border-radius:6px;height:28px;overflow:hidden;">
                <div style="width:${pct}%;background:${color};height:100%;border-radius:6px;display:flex;align-items:center;justify-content:flex-end;padding-right:8px;min-width:40px;">
                    <span style="color:white;font-size:12px;font-weight:700;white-space:nowrap;">${count.toLocaleString('en-IN')}</span>
                </div>
            </div>
            <div style="min-width:50px;text-align:right;font-weight:700;font-size:13px;color:#444;">${count.toLocaleString('en-IN')}</div>
        </div>`;
    }).join('');
}

function renderVenueLeaderboard(evArr) {
    const c = document.getElementById('venueLeaderboardDynamic');
    if (!c) return;
    const counts = {};
    evArr.forEach(ev => { const v = (ev.venue || '').trim(); if (v) counts[v] = (counts[v] || 0) + 1; });
    const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 10);
    if (sorted.length === 0) { c.innerHTML = '<p style="color:#888;font-style:italic;">No venue data for this period.</p>'; return; }
    const maxVal = sorted[0][1];
    c.innerHTML = sorted.map(([venue, cnt], i) => {
        const pct    = Math.round(cnt / maxVal * 100);
        const barCol = _barColors[i] || '#1b005d';
        const rankBadge = i < 3 ? `<span style="font-size:20px;">${_rankEmojis[i]}</span>` : `<span style="font-weight:700;font-size:14px;color:#555;">${i+1}.</span>`;
        return `<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f5f5f5;">
            <div style="min-width:28px;text-align:center;">${rankBadge}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;color:#222;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${venue.replace(/</g,'&lt;')}</div>
                <div style="background:#f0f0f0;border-radius:6px;height:10px;">
                    <div style="width:${pct}%;background:${barCol};height:10px;border-radius:6px;min-width:8px;"></div>
                </div>
            </div>
            <div style="min-width:70px;text-align:right;font-size:12px;color:#555;white-space:nowrap;">${cnt} event${cnt > 1 ? 's' : ''}</div>
        </div>`;
    }).join('');
}

function renderTeamLeaderboard(evArr) {
    const c = document.getElementById('teamLeaderboardDynamic');
    if (!c) return;
    const counts = {};
    evArr.forEach(ev => { const t = (ev.faculty_coordinator || '').trim(); if (t) counts[t] = (counts[t] || 0) + 1; });
    const sorted = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 10);
    if (sorted.length === 0) { c.innerHTML = '<p style="color:#888;font-style:italic;">No team data for this period.</p>'; return; }
    const maxVal = sorted[0][1];
    c.innerHTML = sorted.map(([team, cnt], i) => {
        const pct    = Math.round(cnt / maxVal * 100);
        const barCol = _barColors[i] || '#1b005d';
        const rankBadge = i < 3 ? `<span style="font-size:20px;">${_rankEmojis[i]}</span>` : `<span style="font-weight:700;font-size:14px;color:#555;">${i+1}.</span>`;
        return `<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f5f5f5;">
            <div style="min-width:28px;text-align:center;">${rankBadge}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:13px;color:#222;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${team.replace(/</g,'&lt;')}</div>
                <div style="background:#f0f0f0;border-radius:6px;height:10px;">
                    <div style="width:${pct}%;background:${barCol};height:10px;border-radius:6px;min-width:8px;"></div>
                </div>
            </div>
            <div style="min-width:70px;text-align:right;font-size:12px;color:#555;white-space:nowrap;">${cnt} event${cnt > 1 ? 's' : ''}</div>
        </div>`;
    }).join('');
}

function applyAnalyticsFilter() {
    const evArr      = getAnalyticsFilteredEvents();
    const monthly    = computeMonthly(evArr);
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const dow        = computeDOW(evArr);
    const mx_m       = Math.max(...monthly, 1);
    const mx_d       = Math.max(...dow, 1);
    const typeF      = (document.getElementById('analytics_type_filter')?.value) || 'all';
    const upd = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const [from, to] = getTimeBounds();
    const filteredStudents = computeFilteredStudents(from, to, typeF);
    const filteredPending  = allEventsWithDates.filter(ev => {
        if (segregatedEventNamesSet.has(ev.name)) return false;
        if (typeF !== 'all' && ev.event_type !== typeF) return false;
        const evStart = ev.date || '';
        const evEnd   = ev.end_date || ev.date || '';
        return (!from || evEnd >= from) && (!to || evStart <= to);
    }).length;

    upd('kpi_total_events', evArr.length);
    upd('kpi_single', s);
    upd('kpi_multi',  m);
    upd('kpi_students', filteredStudents.toLocaleString('en-IN'));
    upd('kpi_pending', filteredPending);

    const eventTypeByName = {};
    allEventsForAnalytics.forEach(ev => { if (ev.name) eventTypeByName[ev.name] = ev.event_type || ''; });

    const filteredSegRuns = allSegHistoryJS.filter(h => {
        const d = h.date_from || (h.segregated_on||'').split(' ')[0];
        const inDate = (!from || d >= from) && (!to || d <= to);
        if (!inDate) return false;
        if (typeF === 'all') return true;
        return (h.event_names || []).some(name => eventTypeByName[name] === typeF);
    });
    const filteredSegCount = filteredSegRuns.length;
    const filteredStudentTotal = computeFilteredStudents(from, to, typeF);
    const filteredAvgSt = filteredSegCount > 0 ? Math.round(filteredStudentTotal / filteredSegCount) : 0;

    const filteredRunEventCounts = filteredSegRuns.map(h => {
        if (typeF === 'all') {
            const rec = allHistoryData.find(r => r.segregated_on === h.segregated_on);
            return rec ? rec.event_count : 0;
        }
        return (h.event_names || []).filter(name => eventTypeByName[name] === typeF).length;
    });
    const filteredAvgEvPerRun = filteredSegCount > 0
        ? Math.round((filteredRunEventCounts.reduce((a,b)=>a+b,0) / filteredSegCount) * 10) / 10 : 0;

    const filteredDow = computeDOW(evArr);
    const peakDowIdx = filteredDow.indexOf(Math.max(...filteredDow));
    const busiestDow = Math.max(...filteredDow) > 0 ? dowLabels[peakDowIdx] : '–';

    upd('ss_runs', filteredSegCount);
    upd('ss_students', filteredStudentTotal.toLocaleString('en-IN'));
    upd('ss_avg_students', filteredAvgSt.toLocaleString('en-IN'));
    upd('ss_avg_events', filteredAvgEvPerRun);
    upd('ss_dow', busiestDow);
    upd('kpi_seg_runs', filteredSegCount);

    const segNoteEl = document.getElementById('kpi_seg_note');
    if (segNoteEl) segNoteEl.style.display = (typeF !== 'all') ? 'block' : 'none';

    renderPendingEventsList(from, to, typeF);
    buildInsights(evArr);
    renderSchoolAttendance(from, to, typeF);
    renderVenueLeaderboard(evArr);
    renderTeamLeaderboard(evArr);

    if (!chartsInitialized) return;

    if (chartEvM) {
        chartEvM.data.datasets[0].data = monthly;
        chartEvM.data.datasets[0].backgroundColor = monthly.map(v => v === mx_m && v > 0 ? goldColor : baseColor);
        chartEvM.update('none');
    }
    if (chartComp) { chartComp.data.datasets[0].data = monthly; chartComp.update('none'); }
    if (chartDay) { chartDay.data.datasets[0].data = [s, m]; chartDay.update('none'); }
    if (chartDOW) {
        chartDOW.data.datasets[0].data = dow;
        chartDOW.data.datasets[0].backgroundColor = dow.map((v,i) => v === mx_d && v > 0 ? goldColor : dowBarColors[i]);
        chartDOW.update('none');
    }
    if (chartType) {
        const lbs = Object.keys(typeCounts);
        const vs  = Object.values(typeCounts);
        chartType.data.labels = lbs;
        chartType.data.datasets[0].data = vs;
        chartType.data.datasets[0].backgroundColor = typeColors.slice(0, lbs.length);
        chartType.update('none');
    }
    renderEventTypeTable(typeCounts);
}

let heatmapYear = new Date().getFullYear();
function heatmapPrevYear() { heatmapYear--; document.getElementById('heatmapYearLabel').textContent = heatmapYear; renderGithubHeatmap(heatmapByDateAll, heatmapYear); }
function heatmapNextYear() { heatmapYear++; document.getElementById('heatmapYearLabel').textContent = heatmapYear; renderGithubHeatmap(heatmapByDateAll, heatmapYear); }

function renderGithubHeatmap(dateMap, year) {
    const container = document.getElementById('heatmapContainer');
    if (!container) return;
    const startDate   = new Date(year, 0, 1);
    const endDate     = new Date(year, 11, 31);
    const firstSunday = new Date(startDate);
    firstSunday.setDate(firstSunday.getDate() - startDate.getDay());
    const weeks = [];
    let cur = new Date(firstSunday);
    while (cur <= endDate) {
        const week = [];
        for (let d=0; d<7; d++) { week.push(new Date(cur)); cur.setDate(cur.getDate()+1); }
        weeks.push(week);
    }
    const maxVal = Math.max(1, ...Object.values(dateMap).map(Number));
    const shortMon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let monthRow = '<div style="display:flex;margin-bottom:2px;margin-left:32px;">';
    let lastMonth = -1;
    weeks.forEach(week => {
        const fiy = week.find(d => d.getFullYear() === year);
        let label = '';
        if (fiy) { const mo = fiy.getMonth(); if (mo !== lastMonth) { label = shortMon[mo]; lastMonth = mo; } }
        monthRow += `<div style="width:14px;flex-shrink:0;font-size:10px;color:#888;">${label}</div>`;
    });
    monthRow += '</div>';
    const dowShort = ['','Mon','','Wed','','Fri',''];
    let grid = '<div style="display:flex;"><div style="display:flex;flex-direction:column;margin-right:4px;">';
    for (let di=0; di<7; di++)
        grid += `<div style="height:14px;line-height:14px;font-size:10px;color:#888;white-space:nowrap;margin-bottom:2px;">${dowShort[di]}</div>`;
    grid += '</div>';
    weeks.forEach(week => {
        grid += '<div style="display:flex;flex-direction:column;gap:2px;margin-right:2px;">';
        week.forEach(day => {
            const iso  = day.toISOString().split('T')[0];
            const inY  = day.getFullYear() === year;
            const val  = inY ? (Number(dateMap[iso]) || 0) : 0;
            const pct  = val / maxVal;
            let bg = !inY ? 'transparent' : val === 0 ? '#edf2ff' : pct < 0.25 ? '#9b9fd4' : pct < 0.5 ? '#6366c1' : pct < 0.75 ? '#4338ca' : '#1b005d';
            const title = (inY && val > 0) ? `${val} event(s) on ${iso}` : iso;
            grid += `<div title="${title}" style="width:12px;height:12px;border-radius:2px;background:${bg};"></div>`;
        });
        grid += '</div>';
    });
    grid += '</div>';
    container.innerHTML = monthRow + grid;
}

function renderEventTypeTable(typeCounts) {
    const c = document.getElementById('event_type_table_container');
    if (!c) return;
    const total = Object.values(typeCounts).reduce((a,b) => a+b, 0);
    if (total === 0) { c.innerHTML = '<p style="color:#888;font-style:italic;">No events in this filter.</p>'; return; }
    let html = '<table><tr><th>Event Type</th><th>Count</th><th>Share</th></tr>';
    Object.entries(typeCounts).sort((a,b) => b[1]-a[1]).forEach(([type, count]) => {
        const pct = total > 0 ? Math.round(count/total*100) : 0;
        html += `<tr><td><span style="background:#e8e0ff;color:rgb(27,0,93);font-size:11px;padding:2px 7px;border-radius:10px;">${type}</span></td>
        <td><strong>${count}</strong></td>
        <td><div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;background:#f0f0f0;border-radius:4px;height:8px;">
                <div style="width:${pct}%;background:rgb(27,0,93);height:8px;border-radius:4px;"></div>
            </div>
            <span style="font-size:12px;color:#555;">${pct}%</span>
        </div></td></tr>`;
    });
    html += '</table>';
    c.innerHTML = html;
}

function initCharts() {
    if (chartsInitialized) return;
    chartsInitialized = true;
    const evArr      = getAnalyticsFilteredEvents();
    const monthly    = computeMonthly(evArr);
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const typeLabels = Object.keys(typeCounts);
    const typeVals   = Object.values(typeCounts);
    const dow        = computeDOW(evArr);
    const mx_m       = Math.max(...monthly, 1);
    const mx_s       = Math.max(...segregMonthlyData, 1);
    const mx_d       = Math.max(...dow, 1);

    chartEvM = new Chart(document.getElementById('chartEventsMonthly'), {
        type: 'bar',
        data: { labels: monthLabels, datasets: [{ label:'Events', data:monthly,
            backgroundColor: monthly.map(v => v===mx_m&&v>0 ? goldColor : baseColor),
            borderRadius:6, borderSkipped:false }] },
        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });
    chartSegM = new Chart(document.getElementById('chartSegregMonthly'), {
        type: 'bar',
        data: { labels: monthLabels, datasets: [{ label:'Segregations Done', data:segregMonthlyData,
            backgroundColor: segregMonthlyData.map(v => v===mx_s&&v>0 ? goldColor : accentColor),
            borderRadius:6, borderSkipped:false }] },
        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });
    chartComp = new Chart(document.getElementById('chartComparison'), {
        type: 'line',
        data: { labels: monthLabels, datasets: [
            { label:'Events Registered', data:monthly, borderColor:baseColor, backgroundColor:lightColor, fill:true, tension:0.4, pointBackgroundColor:baseColor, pointRadius:4 },
            { label:'Segregations Done', data:segregMonthlyData, borderColor:goldColor, backgroundColor:'rgba(255,193,7,0.1)', fill:true, tension:0.4, pointBackgroundColor:goldColor, pointRadius:4 }
        ]},
        options: { plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:12}}}}, scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},x:{grid:{display:false}}}, responsive:true, animation:false }
    });
    chartDay = new Chart(document.getElementById('chartTypeSplit'), {
        type: 'doughnut',
        data: { labels:['Single-Day','Multi-Day'], datasets:[{ data:[s,m], backgroundColor:[baseColor,goldColor], borderWidth:0, hoverOffset:8 }] },
        options: { cutout:'65%', plugins:{ legend:{position:'bottom',labels:{boxWidth:14,font:{size:12}}},
            tooltip:{callbacks:{label:ctx=>{const t=s+m;return ` ${ctx.label}: ${ctx.parsed} (${t>0?Math.round(ctx.parsed/t*100):0}%)`;}}}} ,
            responsive:true, animation:false }
    });
    chartDOW = new Chart(document.getElementById('chartDOW'), {
        type: 'bar',
        data: { labels: dowLabels, datasets: [{ label:'Events', data:dow,
            backgroundColor: dow.map((v,i) => v===mx_d&&v>0 ? goldColor : dowBarColors[i]),
            borderRadius:4 }] },
        options: { indexAxis:'y', plugins:{legend:{display:false}},
            scales:{x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#f0f0f0'}},y:{grid:{display:false},ticks:{font:{size:12}}}},
            responsive:true, animation:false }
    });
    chartType = new Chart(document.getElementById('chartEventTypeSplit'), {
        type: 'doughnut',
        data: { labels:typeLabels, datasets:[{ data:typeVals, backgroundColor:typeColors.slice(0,typeLabels.length), borderWidth:0, hoverOffset:8 }] },
        options: { cutout:'55%', plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}},
            tooltip:{callbacks:{label:ctx=>{const t=typeVals.reduce((a,b)=>a+b,0);return ` ${ctx.label}: ${ctx.parsed} (${t>0?Math.round(ctx.parsed/t*100):0}%)`;}}}} ,
            responsive:true, animation:false }
    });
    renderGithubHeatmap(heatmapByDateAll, heatmapYear);
    renderEventTypeTable(typeCounts);
    applyAnalyticsFilter();
}

function confirmAddEvent() {
    const isMulti = document.getElementById('multiday_toggle').checked;
    if (!isMulti) {
        const venueVal = (document.getElementById('main_venue_input')?.value || '').trim();
        if (!venueVal) { alert('⚠ Please enter the event venue.'); document.getElementById('main_venue_input').focus(); return false; }
        const dateVal = document.querySelector('[name=event_date]')?.value;
        if (!dateVal) { alert('⚠ Please select the event date.'); return false; }
        function to24(h, m, ap) {
            let hh = parseInt(h);
            if (ap === 'AM' && hh === 12) hh = 0;
            if (ap === 'PM' && hh !== 12) hh += 12;
            return hh * 60 + parseInt(m);
        }
        const fH = document.querySelector('[name=from_hour]').value;
        const fM = document.querySelector('[name=from_minute]').value;
        const fA = document.querySelector('[name=from_ampm]').value;
        const tH = document.querySelector('[name=to_hour]').value;
        const tM = document.querySelector('[name=to_minute]').value;
        const tA = document.querySelector('[name=to_ampm]').value;
        if (to24(tH, tM, tA) <= to24(fH, fM, fA)) {
            alert('⚠ End time must be later than start time. Please fix the event timing.');
            return false;
        }
    } else {
        const dayDates  = Array.from(document.querySelectorAll('[name^="day_date["]')).map(el => el.value).filter(Boolean);
        const dayVenues = Array.from(document.querySelectorAll('[name^="day_venue["]'));
        if (dayDates.length === 0) { alert('⚠ Please add at least one day.'); return false; }
        for (let i = 0; i < dayVenues.length; i++) {
            if (!dayVenues[i].value.trim()) { alert(`⚠ Please enter a venue for Day ${i + 1}.`); dayVenues[i].focus(); return false; }
        }
        for (let i = 1; i < dayDates.length; i++) {
            if (dayDates[i] < dayDates[i-1]) { alert(`⚠ Day ${i+1} date must be after Day ${i} date.`); return false; }
             }
        }
    }
    return confirm("Are you sure you want to add this event?\n\nClick OK to confirm or Cancel to go back.");
}

function downloadSegSummaryPDF(lines, fname) {
    const { jsPDF } = window.jspdf;
    const doc  = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    const PW   = 210, PH = 297, ML = 10, MR = 10, MT = 12, lineH = 4.5;
    const maxW = PW - ML - MR;
    doc.setFont('courier', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    let y = MT;
    lines.forEach(line => {
        const wrapped = doc.splitTextToSize(line === '' ? ' ' : line, maxW);
        wrapped.forEach(wline => {
            if (y + lineH > PH - 10) { doc.addPage(); y = MT; }
            doc.text(wline, ML, y);
            y += lineH;
        });
    });
    doc.save(fname);
}

function downloadAnalyticsTXT() {
    const evArr      = getAnalyticsFilteredEvents();
    const typeF      = (document.getElementById('analytics_type_filter')?.value) || 'all';
    const [from, to] = getTimeBounds();
    const pill       = currentTimePill;
    const pillLabels = {alltime:'All Time', year:'Year', mrange:'Month Range', custom:'Custom Range'};
    const [s, m]     = computeDaySplit(evArr);
    const typeCounts = computeTypeCounts(evArr);
    const monthly    = computeMonthly(evArr);
    const dow        = computeDOW(evArr);
    const now        = new Date().toLocaleString('en-IN');
    const typeLabel  = typeF === 'all' ? 'All Event Types' : typeF;
    let timeLabel    = pillLabels[pill] || 'All Time';
    if (from && to)  timeLabel += ` (${from} to ${to})`;
    else if (from)   timeLabel += ` (from ${from})`;
    else if (to)     timeLabel += ` (up to ${to})`;
    const totalType  = Object.values(typeCounts).reduce((a,b)=>a+b,0);
    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dayNamesL  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    const filteredSegRuns = allSegHistoryJS.filter(h => {
        const d = h.date_from || (h.segregated_on||'').split(' ')[0];
        return (!from || d >= from) && (!to || d <= to);
    });
    const filteredSegCount = filteredSegRuns.length;
    const filteredSchoolTotals = {};
    (schoolDateStats || []).forEach(r => {
        const inRange = (!from || r.date >= from) && (!to || r.date <= to);
        if (!inRange) return;
        if (typeF !== 'all' && r.event_type !== undefined && r.event_type !== typeF) return;
        filteredSchoolTotals[r.school] = (filteredSchoolTotals[r.school] || 0) + r.total;
    });
    const sortedSchools  = Object.entries(filteredSchoolTotals).sort((a,b) => b[1]-a[1]);
    const filteredTotal  = sortedSchools.reduce((s,[,v]) => s+v, 0);
    const filteredAvgSt  = filteredSegCount > 0 ? Math.round(filteredTotal / filteredSegCount).toLocaleString() : '0';
    const filteredParticipation = (eventParticipationJS || []).filter(ep => {
        const inDate = (!from || (ep.event_date||'') >= from) && (!to || (ep.event_date||'') <= to);
        const inType = typeF === 'all' || ep.event_type === typeF || ep.event_type === undefined;
        return inDate && inType;
    }).sort((a,b) => b.count - a.count);
    const filteredVenueCounts = {};
    evArr.forEach(ev => { const v = (ev.venue||'').trim(); if (v) filteredVenueCounts[v] = (filteredVenueCounts[v]||0)+1; });
    const sortedVenues = Object.entries(filteredVenueCounts).sort((a,b)=>b[1]-a[1]).slice(0,10);
    const filteredTeamCounts = {};
    evArr.forEach(ev => { const t = (ev.faculty_coordinator||'').trim(); if (t) filteredTeamCounts[t] = (filteredTeamCounts[t]||0)+1; });
    const sortedTeams = Object.entries(filteredTeamCounts).sort((a,b)=>b[1]-a[1]).slice(0,10);

    const sep  = '=======================================================';
    const dash = '-------------------------------------------------------';
    const lines = [];
    lines.push(sep);
    lines.push('  VIT SMART ATTENDANCE SEGREGATOR - Analytics Report');
    lines.push(`  Generated   : ${now}`);
    lines.push(`  Time Filter : ${timeLabel}`);
    lines.push(`  Event Type  : ${typeLabel}`);
    lines.push(sep);
    lines.push('');
    lines.push('--- KEY METRICS ---');
    lines.push(`  Total Events               : ${evArr.length}`);
    lines.push(`  Single-Day Events          : ${s}`);
    lines.push(`  Multi-Day Events           : ${m}`);
    lines.push(`  Segregation Runs           : ${filteredSegCount}`);
    lines.push(`  Total Students Processed   : ${filteredTotal.toLocaleString()}`);
    lines.push(`  Avg Students / Segregation : ${filteredAvgSt}`);
    lines.push('');
    lines.push('--- MONTHLY EVENT BREAKDOWN ---');
    monthly.forEach((cnt, i) => lines.push(`  ${monthNames[i].padEnd(5)}: ${cnt}`));
    lines.push('');
    lines.push('--- BUSIEST DAY OF WEEK ---');
    dow.forEach((cnt, i) => lines.push(`  ${dayNamesL[i].padEnd(12)}: ${cnt}`));
    lines.push('');
    lines.push('--- EVENT TYPE BREAKDOWN ---');
    if (totalType === 0) { lines.push('  No data yet.'); }
    else { Object.entries(typeCounts).sort((a,b)=>b[1]-a[1]).forEach(([t,c]) => { if (c > 0) lines.push(`  ${t.padEnd(38)}: ${c} (${Math.round(c/totalType*100)}%)`); }); }
    lines.push('');
    lines.push(dash);
    lines.push('--- EVENT PARTICIPATION ---');
    if (filteredParticipation.length === 0) { lines.push('  No data for this filter.'); }
    else { filteredParticipation.forEach((ep, i) => lines.push(`  ${(String(i+1)+'. '+ep.name).padEnd(42)}: ${ep.count.toLocaleString()} students (${ep.schools} school${ep.schools!==1?'s':''})`)); }
    lines.push('');
    lines.push('--- SCHOOL-WISE ATTENDANCE ---');
    if (sortedSchools.length === 0) { lines.push('  No data for this filter.'); }
    else { sortedSchools.forEach(([sc, cnt]) => lines.push(`  ${sc.padEnd(15)}: ${cnt.toLocaleString()} students`)); lines.push(`  ${'TOTAL'.padEnd(15)}: ${filteredTotal.toLocaleString()} students`); }
    lines.push('');
    lines.push('--- VENUE UTILISATION — Top 10 ---');
    if (sortedVenues.length === 0) { lines.push('  No data for this filter.'); }
    else { sortedVenues.forEach(([v, cnt], i) => lines.push(`  ${String(i+1).padEnd(3)}${v.padEnd(40)}: ${cnt} event${cnt!==1?'s':''}`)); }
    lines.push('');
    lines.push('--- FACULTY COORDINATOR LEADERBOARD — Top 10 ---');
    if (sortedTeams.length === 0) { lines.push('  No data for this filter.'); }
    else { sortedTeams.forEach(([t, cnt], i) => lines.push(`  ${String(i+1).padEnd(3)}${t.padEnd(40)}: ${cnt} event${cnt!==1?'s':''}`)); }
    lines.push('');
    lines.push(sep);
    lines.push('  VIT-IST | Office of Innovation, Startup & Technology Transfer');
    lines.push(sep);

    const { jsPDF } = window.jspdf;
    const doc  = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    const PW   = 210, PH = 297, ML = 10, MR = 10, MT = 12, lineH = 4.5;
    const maxW = PW - ML - MR;
    doc.setFont('courier', 'normal');
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    let y = MT;
    lines.forEach(line => {
        const wrapped = doc.splitTextToSize(line === '' ? ' ' : line, maxW);
        wrapped.forEach(wline => {
            if (y + lineH > PH - 10) { doc.addPage(); y = MT; }
            doc.text(wline, ML, y);
            y += lineH;
        });
    });
    const safeType = typeF === 'all' ? 'All' : typeF.replace(/[^a-z0-9]/gi,'_');
    doc.save(`VIT_Analytics_${pill}_${safeType}_${Date.now()}.pdf`);
}
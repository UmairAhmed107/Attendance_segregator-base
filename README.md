# Smart Attendance Segregator
### VIT-IST — Office of Innovation, Startup and Technology Transfer

A web-based attendance processing system built for VIT-IST that automates the segregation of mixed event attendance Excel sheets into school-wise reports — eliminating manual OD (On Duty) processing across 17 schools and 40+ department codes.

---

##  Overview

When students attend events on duty at VIT, attendance sheets contain registration numbers from multiple schools mixed together. Processing these manually for OD approval across 17 schools was time-consuming and error-prone.

**Smart Attendance Segregator** solves this by:
- Managing the complete event lifecycle from registration to post-event processing
- Parsing uploaded Excel attendance sheets and extracting valid VIT registration numbers
- Resolving department codes through a school-code mapping table
- Routing each entry into school-specific datasets automatically
- Generating formatted Excel workbooks per school, bundled into a ZIP archive
- Tracking all segregation history and providing an analytics dashboard

> **Deployed and actively used at VIT-IST.**
> Processed 900+ OD entries across 3 major events — InnoAI 2026 AI/ML Hackathon, Vinner'26 Hackathon, and Alumni Interaction Session on Innovations and Startups.

---

##  Features

###  Event Management
- Register and manage events with complete details (venue, timing, faculty coordinator, school, event type)
- Single-day and multi-day event support with per-day venue and timing slots
- Monitor pending vs completed segregation status
- Full admin controls — edit, delete events and history records

###  Segregation Engine
- Automated segregation for **17 schools** and **40+ department codes**
- Parses uploaded `.xlsx`/`.xls` files via PHPSpreadsheet
- Extracts valid 9-character VIT registration numbers
- Resolves department codes through a school-code mapping table
- Bulk multi-event support — segregate multiple events in a single run
- School-wise formatted Excel reports with colour-coded headers
- ZIP-packaged output with auto-generated text and PDF summary reports
- Every segregation run persisted to the database with full metadata

###  Analytics Dashboard
- KPI cards — events registered, segregations done, students processed, pending events
- Monthly trend analysis and event type breakdown
- School-wise participation distribution
- Venue utilisation and faculty coordinator leaderboards
- Key insights with advanced time-range and event-type filtering
- Downloadable analytics report as PDF
- Server-side JSON caching on analytics endpoint to avoid redundant aggregation queries
- AJAX-based lazy loading — analytics data fetched only when the tab is opened

### Security
- SQL injection prevention via parameterised PDO queries
- Session regeneration on login and server-side upload validation
- Back-button hijack prevention via `history.pushState` for session security
- Environment configuration via `.env` (never committed to version control)
- Upload verification using `is_uploaded_file()`
- Cache-control headers on all authenticated pages

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1, PDO |
| Database | MySQL 8 |
| Frontend | Vanilla JavaScript (ES6), AJAX |
| Excel Processing | PHPSpreadsheet |
| Reports | jsPDF, ZipArchive |
| Visualisation | Chart.js |
| Environment | vlucas/phpdotenv |

---

##  Database Schema

```
events               — event registration with metadata and day-wise JSON
segregation_history  — full run history with event and ZIP metadata
segregation_stats    — per-school student counts per run (for analytics)
schools              — school name to department code mapping
```

Full schema available in `setup.sql`.

---

##  Installation

### Prerequisites
- PHP 8.1+
- MySQL 8+
- Composer
- Apache/Nginx with `.htaccess` support

### Steps

**1. Clone the repository**
```bash
git clone https://github.com/yourusername/smart-attendance-segregator.git
cd smart-attendance-segregator
```

**2. Install dependencies**
```bash
composer install
```

**3. Configure environment**
```bash
cp _env .env
```
Edit `.env` with your database credentials:
```
DB_HOST=localhost
DB_NAME=attendance_segregator
DB_USER=your_db_user
DB_PASS=your_db_password
```

**4. Set up the database**
```bash
mysql -u root -p < setup.sql
```

**5. Configure web server**

Rename `_htaccess` to `.htaccess`:
```bash
mv _htaccess .htaccess
```

Ensure `downloads/` directory is writable:
```bash
mkdir -p downloads
chmod 777 downloads
```

**6. Access the application**
```
http://localhost/smart-attendance-segregator/
```

Default credentials are set in your `.env` file under `APP_USER` and `APP_PASS`.

---

## 📁 Project Structure

```
├── index.php              — Login page
├── register_event.php     — Main application (event management, segregation, admin panel)
├── analytics_data.php     — AJAX endpoint for analytics data with server-side caching
├── db.php                 — PDO database connection
├── setup.sql              — Database schema and seed data
├── style.css              — Global styles
├── register_event.css     — Application-specific styles
├── register_event.js      — All frontend logic (segregation, admin tables, analytics charts)
├── composer.json          — PHP dependencies
├── _env                   — Environment variable template
├── _htaccess              — Apache config (rename to .htaccess)
└── downloads/             — Generated Excel, ZIP and summary files (auto-created)
```

---

## 🏫 Supported Schools & Codes

| School | Department Codes |
|--------|-----------------|
| SENSE | BVD, BEC, BML |
| SCOPE | BBS, BDS, BCT, BCB, MIC, BAI, MID, BCI, BKT, BCE |
| SCORE | BIT, BCA, BCS, MCA, MAG, BYB, BDE, MIS |
| SELECT | BEE, BEL, BEI |
| SMEC | MMT, BMV, BST, BMA, BME, BMM |
| SBST | BBT, MSI |
| SSL | BFN, BBC, BCC, BBP |
| VITBS | BBA |
| + 9 more | See `setup.sql` for full list |

##  Team

| Role | Name |
|------|------|
| Developer | Umair Ahmed R |
| Developer | Nithesh Kumar T |
| Developer | Srishti Singh|

| Mentor | Dr. Jothish Kumar M |


## 📄 License

This project was built for internal use at VIT-IST. Please contact the authors before reuse or redistribution.

---

*Built for VIT-IST — Office of Innovation, Startup and Technology Transfer, VIT Vellore*

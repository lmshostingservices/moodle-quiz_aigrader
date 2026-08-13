<p align="center">
  <a href="https://lmshostingservices.com">
    <img src="https://raw.githubusercontent.com/lmshostingservices/lms-labs/main/attached_assets/lms-hosting-logo.png" alt="LMS Hosting Services" height="60">
  </a>
</p>

> **LMS Labs** is the Moodle plugin division of [LMS Hosting Services](https://lmshostingservices.com) — Australia's Moodle™ Certified Partner.

---

# AI Grader – Moodle Quiz Report Plugin

AI-powered essay grading for Moodle quizzes using a 3-point WHS (Workplace Health and Safety) rubric.

## Plugin Summary

- **Plugin type:** Quiz report  
- **Component name:** `quiz_aigrader`  
- **Location:** `/mod/quiz/report/aigrader/`  
- **Current Version:** 3.56.1  
- **Moodle Required:** 4.0+ (version 2022041900 or later)

---

## Features

- **AI-Powered Grading**
  - Sends the question text + student answer to the Essay Grader AI backend.
  - Returns suggested score, rubric breakdown, and feedback text.

- **3-Point Rubric (WHS-Focused)**
  - Hazard identification
  - Workplace example
  - Control measure

- **Competency-Based**
  - Converts rubric to S / NYS style grading.
  - Scales mark to the question's maximum grade in Moodle.

- **Teacher Control**
  - Teacher can:
    - Trigger AI suggestion per essay or "Grade all"
    - Review AI feedback
    - Manually adjust grades and feedback in Moodle afterward

- **Credit-Based Model**
  - Backend enforces 1 credit = 1 graded essay.
  - Credits are managed and stored externally (not in Moodle DB).

---

## Requirements

- **Moodle:** 4.0 or later (build 2022041900+)
- **PHP:** 7.4 or later
- **External Service:**
  - Active Essay Grader AI subscription
  - Valid **Site ID** and **API Key**

---

## Installation

1. **Download / copy** the plugin into:
   - `/mod/quiz/report/aigrader/`

2. **Visit**:  
   `Site administration → Notifications`  
   and let Moodle upgrade the database and register the new quiz report.

3. Ensure the plugin appears under:  
   `Site administration → Plugins → Activity modules → Quiz → Quiz reports`

---

## Configuration

1. Go to:  
   `Site administration → Plugins → Quiz reports → AI Grader`

2. Set:
   - **Site ID** – usually your Moodle site URL or ID provided by Essay Grader AI.
   - **API Key** – from your Essay Grader AI dashboard.

3. Save changes.

If Site ID or API Key is missing, the report will show a configuration warning and AI grading will be disabled.

---

## Usage

1. Create a quiz with **essay-like questions**, such as:
   - `essay`
   - `essayautograde`
   - other essay-type questions that contain student written responses.

2. Let students complete the quiz.

3. From the quiz page, open the **Reports** dropdown and choose **"AI Grader"**.

4. On the AI Grader screen you can:
   - See a table of students and their essay responses.
   - **Check credits** via the credits badge.
   - Click **"AI Grade"** on a single row to get a suggestion.
   - Or click **"Grade all"** to process all visible essays.

5. After grading:
   - Suggested marks are saved to the quiz attempts when you approve them.
   - You can still edit grades manually in the standard quiz grading interface.

---

## Credits & Billing

- 1 essay graded = **1 credit** consumed.
- The plugin calls the external API endpoint:
  - `https://lms-labs.com/api/grade-essay`
- Credit balance is retrieved from:
  - `https://lms-labs.com/api/credits`

No credit data is stored in Moodle's database; it is handled entirely by the external service.

---

## Privacy

This plugin **does not create its own database tables**.

Data sent to the external Essay Grader AI service:

- Question text (plain text)
- Student answer (plain text or stripped HTML)

See `classes/privacy/provider.php` for Moodle Privacy API implementation.

---


## ⭐ Why this plugin is unlike anything else available

**The only grader that writes the mark directly into Moodle's gradebook**

- Every other AI essay grading tool requires you to export submissions, grade externally, then re-import results manually. AI Essay Grader intercepts Moodle's own quiz report screen — the teacher clicks Grade, the AI scores the essay using a structured rubric, and the result writes to the Moodle gradebook in one action. No export/import cycle, no copy-paste.
- Uses a consistent 3-criterion rubric (not open-ended scoring), so results are comparable across submissions, across time, and across different teachers reviewing the same work. You get a score with a stated rationale, not a vague letter grade.
- Your API key, your data. Student essay text is sent to the AI provider you configure — it does not pass through any LMS Labs server. For institutions with data residency requirements, this is the only responsible path.

## Support

- **Portal:** [lms-labs.com](https://lms-labs.com)
- **Email:** support@lmshostingservices.com
- **Website:** [lmshostingservices.com](https://lmshostingservices.com)

LMS Labs is the plugin division of LMS Hosting Services, Australia's Moodle™ Certified Partner.

---

## Pricing

**$50 USD** — one-time purchase per site · lifetime updates · no subscription.

Download at [lms-labs.com/plugins](https://lms-labs.com/plugins).

## License

This plugin is licensed under the **GNU General Public License v3 or later**.  
See: http://www.gnu.org/copyleft/gpl.html

---

## Changelog

### 3.52.4 (December 2025)

- **FIX:** Pagination now works correctly with Moodle database layer

### 3.52.3 (December 2025)

- **PERF:** Added pagination - loads 50 essays per page for faster loading

### 3.52.2 (December 2025)

- **FIX:** Updated minified JS - Grade All count now works correctly

### 3.52.1 (December 2025)

- **NEW: Re-grade All (FREE)** - Bulk re-process already-graded essays at no credit cost
- Re-grading skips credit consumption since essays were already paid for
- Visual "FREE" badge next to Re-grade All button for transparency
- Fixed language bug - feedback now correctly uses configured language

### 3.52.0 (December 2025)

- **NEW: Re-grade All** - Bulk re-process essays with corrected language settings
- Teachers can fix incorrectly-graded feedback in one click
- Grader activity report protected from double-counting re-grades

### 3.51.1 (December 2025)

- Fixed idle timeout - reduced from 10 min to 2 min for accurate time tracking

### 3.51.0 (December 2025)

- **NEW: Combined Detailed Report** - Single table with students graded, questions approved, and time spent columns

### 3.50.0 (December 2025)

- **NEW: Grading Time Statistics** - Track essays graded and time spent with course/grader/date filters

### 3.49.0 (December 2025)

- **NEW: English variants** - Australian, British, American, NZ, Canadian, Irish, South African spelling support

### 1.6.0 (November 2025)

- Complete file audit and alignment with Moodle coding standards
- Simplified db/access.php to use core mod/quiz:viewreports capability
- Enhanced privacy provider with external API location metadata
- Improved settings page registration under quizreportsettings
- Better lang string coverage for all PHP and JS components

### 1.5.0 (November 2025)

- Improved essay detection across multiple essay-like question types
- More robust attempt and state handling for completed quiz attempts
- Better error handling and admin diagnostics in the report
- Scoped CSS styles under `.aigrader-container` to avoid interfering with core Moodle UI
- Updated AMD JS module with clearer alerts and credit handling

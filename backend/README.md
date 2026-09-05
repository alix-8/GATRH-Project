# GATRH

[Main Guide](https://docs.google.com/document/d/14BNCUfG_KwJc_5pU9YQWIesMHuSxkp0THvSnrWq3W_A/edit?usp=sharing) &nbsp;|&nbsp;
[Dev Journal/Notes](https://docs.google.com/document/d/1YuV9XRYu1kzWDhISpTRxleVu-HYdjwQjBa8x7t6Ki6Q/edit?usp=sharing)

---

## Table of Contents
- [Functions inside the Controllers](#-functions-inside-the-controllers)
  - [AuthController](#authcontroller)
  - [StudentController](#studentcontroller)
  - [AttendanceController](#attendancecontroller)
  - [AttendanceDraftController](#attendancedraftcontroller)
  - [EscalationController](#escalationcontroller)
  - [ReminderController](#remindercontroller)
- [Sample Attendance Form](#sample-attendance-form)

---

## 🔵 Functions inside the Controllers
The following are the functions declared inside the controllers. This will be helpful in navigating the backend while connecting it to the frontend.

### 💠AuthController
> Location: `app\Http\Controllers\Api`

| Function | Purpose | Page Location |
|----------|---------|----------|
| `passwordRules` (private) | Returns the shared password validation rule set (min 8 chars, mixed case, numbers, symbols) used across auth actions | called inside `login`|
| `login` | Validates credentials and issues a Sanctum bearer token on success | Login Page |
| `me` | Returns the currently authenticated user's information | |
| `logout` | Revokes the current access token | Sign Out Button |
| `forgotPassword` | Sends a password reset link to the user's email | Login Page |
| `resetPassword` | Resets the password via token, enforcing the password policy, and revokes all existing tokens for security | Profile Page |

### 💠StudentController

| Function | Purpose | Page Location |
|----------|---------|----------|
| `index` | Lists students, scoped to the user's program if Program Head/Secretary, or all students for Coordinators/Chancellors | Students Page |
| `store` *(commented out)* | Would create a new student and auto-initialize their escalation record | |

### 💠AttendanceController

| Function | Purpose | Page Location |
|----------|---------|----------|
| `generateTemplate` | Builds a pre-filled text template listing students (by program/year level) for attendance entry | Import Page |
| `parseText` | Parses raw submitted attendance text into structured records, flags unmatched/incomplete/format-error lines, and saves as a draft | Import Page |
| `store` | Validates and bulk-inserts attendance records, rejecting the batch if duplicates exist | Records Page |
| `validateRecord` | Approves/rejects all attendance records for a given program/year/gathering/day | Attendance Page *(Program Head Screen)* |
| `summaryReport` | Returns aggregated attendance analytics (present/no-response/excused counts, attendance rate) with optional filters | *(Program Head, CM Coordinator, Chancellor Screen)* |

### 💠AttendanceDraftController

| Function | Purpose | Page Location |
|----------|---------|----------|
| `show` | Retrieves a specific draft belonging to the user, if still in `draft` status | Students Page |
| `submit` | Moves a draft's parsed records into final attendance records, marks the draft submitted, and updates related reminders | |

### 💠EscalationController

| Function | Purpose | Page Location |
|----------|---------|----------|
| `index` | Lists escalations with `no_response_count > 0`, scoped by program for Program Heads, with optional level filter | Students Page (Level Column) |
| `show` | Displays a single escalation with student and attendance history | *(Program Head, CM Coor and Chancellor's Screen)* |
| `update` | Updates intervention status for an escalation | Students Page *(Program Head, CM Coor and Chancellor's Screen)* |

### 💠ReminderController

| Function | Purpose | Page Location |
|----------|---------|----------|
| `index` | Lists the user's reminders, refreshing countdown/status first, with optional status filter and summary counts | Reminder Page |
| `store` | Creates a new reminder from validated input |Reminder Page |
| `show` | Displays a single reminder (with countdown) if it belongs to the requesting user | Reminder Page|
| `update` | Updates an existing reminder after verifying ownership |Reminder Page |
| `storeGathering` | Creates a reminder specifically for a gathering event (PM, WS, TG, special) | Reminder Page|
| `gatheringLabel` *(private)* | Maps a gathering type code to a human-readable label |Reminder Page |
| `markCompleted` | Marks a reminder as completed |Reminder Page |

---

## 🔵Sample Attendance Form

```text
--- GATRH ATTENDANCE FORM ---
Gathering: PM
Program: BSIT
Year Level: 3

[FILL OUT YOUR DETAILS BELOW]
Name | Batch(Time) | Venue | Date(YYYY-MM-DD)
1. Juan Dela Cruz    | 08:00 AM | Chapel    | 2026-08-25
2. Maria Santos      | 08:15 AM | Chapel    | 2026-08-25
3. Joshua Villanueva | 09:00 AM | Main Hall | 2026-08-25
```
# GATRH

GATRH is a web application for managing gathering attendance and student follow-up across programs. It helps the Christian Ministry Department record and review gathering attendance, monitor student responses, and coordinate reminders and interventions.

> The project is based on the gathering attendance process of La Verdad Christian College.

<a id="key-features"></a>
<details>
<summary><strong>Key Features</strong></summary>

- Import attendance using a structured text format.
- Review, validate, and manage attendance records.
- View attendance summaries and reports with filters.
- Track student attendance history and response status.
- Manage reminders for users and gatherings.
- Record and follow up on student interventions.
- Provide role-specific dashboards and access to program data.

[Back to top (GATRH)](#gatrh) · [Back to top (Key Features)](#key-features)

</details>

<a id="user-roles-and-access"></a>
<details>
<summary><strong>User Roles and Access</strong></summary>

| Role | Access and responsibilities |
|---|---|
| **Administrator** | Manages system settings, users, programs, and student records. |
| **Secretary** | Manages student information and submits attendance records for their program. |
| **Program Head** | Reviews attendance and monitors students within their program. |
| **CM Coordinator** | Monitors attendance and student follow-up across programs. |
| **Chancellor** | Views system-wide attendance reports and dashboards. |

[Back to top (GATRH)](#gatrh) · [Back to top (User Roles and Access)](#user-roles-and-access)

</details>

<a id="tech-stack"></a>
<details>
<summary><strong>Tech Stack</strong></summary>

| Area | Technology |
|---|---|
| Frontend | React 19, React Router, and Vite |
| Backend | PHP 8.4.27 and Laravel 13 |
| API communication | HTTPS with JSON requests |
| Authentication | Laravel Sanctum using session cookies and CSRF protection |
| Database | MySQL for local development; TiDB in production |
| Frontend hosting | Vercel |
| Backend hosting | Render |
| Scheduled tasks | cron-job.org sends scheduled requests to the Render backend to help prevent it from sleeping after inactivity. |

[Back to top (GATRH)](#gatrh) · [Back to top (Tech Stack)](#tech-stack)

</details>

<a id="project-structure"></a>
<details>
<summary><strong>Project Structure</strong></summary>

```text
GATRH-Project/
├── backend/   # Laravel API, database, and server-side code
└── frontend/  # React application and user interface
```

[Back to top (GATRH)](#gatrh) · [Back to top (Project Structure)](#project-structure)

</details>

<a id="prerequisites"></a>
<details>
<summary><strong>Prerequisites</strong></summary>

Install the following before setting up the project:

- PHP 8.3 or later
- Composer
- Node.js and npm
- MySQL, if using MySQL for local development
- Git

[Back to top (GATRH)](#gatrh) · [Back to top (Prerequisites)](#prerequisites)

</details>

<a id="local-setup"></a>
<details>
<summary><strong>Local Setup</strong></summary>

1. Clone the repository and enter the project folder:

   ```bash
   git clone <repository-url>
   cd GATRH-Project
   ```

2. Install backend dependencies:

   ```bash
   cd backend
   composer install
   ```

3. Create the backend environment file:

   ```bash
   cp .env.example .env
   ```

4. Configure the database and frontend URL in `backend/.env`. See [Environment Configuration](#environment-configuration).

5. Generate the Laravel application key and run database migrations:

   ```bash
   php artisan key:generate
   php artisan migrate
   ```

6. In a separate terminal, install frontend dependencies and create its environment file:

   ```bash
   cd frontend
   npm install
   cp .env.example .env
   ```

7. Set the backend API URL in `frontend/.env`, then start the frontend:

   ```bash
   npm run dev
   ```

8. Start the Laravel backend in another terminal:

   ```bash
   cd backend
   php artisan serve
   ```

[Back to top (GATRH)](#gatrh) · [Back to top (Local Setup)](#local-setup)

</details>

<a id="environment-configuration"></a>
<details>
<summary><strong>Environment Configuration</strong></summary>

### Backend (`backend/.env`)

Set these values for local development:

| Variable | Purpose | Example |
|---|---|---|
| `APP_URL` | Local backend address | `http://localhost:8000` |
| `FRONTEND_URL` | Local frontend address | `http://localhost:5173` |
| `DB_CONNECTION` | Database driver | `mysql` or `sqlite` |
| `DB_HOST` | Database server address, when using MySQL | `127.0.0.1` |
| `DB_PORT` | Database server port, when using MySQL | `3306` |
| `DB_DATABASE` | Database name or SQLite database path | Set for your local database |
| `DB_USERNAME` | Database username, when using MySQL | Your local MySQL username |
| `DB_PASSWORD` | Database password, when using MySQL | Your local MySQL password |
| `SANCTUM_STATEFUL_DOMAINS` | Frontend hosts allowed for session authentication | `localhost,localhost:5173,127.0.0.1,127.0.0.1:5173` |

### Frontend (`frontend/.env`)

| Variable | Purpose | Example |
|---|---|---|
| `VITE_API_BASE_URL` | Laravel API base URL | `http://127.0.0.1:8000/api` |

Do not commit `.env` files or put production secrets in the README.

[Back to top (GATRH)](#gatrh) · [Back to top (Environment Configuration)](#environment-configuration)

</details>

<a id="development-commands"></a>
<details>
<summary><strong>Development Commands</strong></summary>

Run these commands from the indicated project directory.

| Task | Directory | Command |
|---|---|---|
| Start the Laravel backend | `backend/` | `php artisan serve` |
| Run database migrations | `backend/` | `php artisan migrate` |
| Run backend tests | `backend/` | `php artisan test` |
| Start the Vite development server | `frontend/` | `npm run dev` |
| Build the frontend for production | `frontend/` | `npm run build` |
| Preview the production frontend build | `frontend/` | `npm run preview` |
| Lint the frontend | `frontend/` | `npm run lint` |

[Back to top (GATRH)](#gatrh) · [Back to top (Development Commands)](#development-commands)

</details>

<a id="deployment-overview"></a>
<details>
<summary><strong>Deployment Overview</strong></summary>

- **Frontend:** Deployed on Vercel.
- **Backend:** Deployed on Render.
- **Production database:** TiDB.
- **Scheduled requests:** cron-job.org sends scheduled requests to the backend.

Set the required environment variables in each hosting service before deploying. Do not put secrets in the repository or README.

[Back to top (GATRH)](#gatrh) · [Back to top (Deployment Overview)](#deployment-overview)

</details>

<a id="versioning-and-change-log"></a>
<details>
<summary><strong>Versioning and Change Log</strong></summary>

The project uses [Semantic Versioning](https://semver.org/), written as `MAJOR.MINOR.PATCH`:

- **MAJOR** — incompatible changes.
- **MINOR** — new features that remain compatible.
- **PATCH** — compatible bug fixes.

Release versions are identified with Git tags.

---

Project changes are documented here, grouped by version and date.

### [0.1.0] - 2026-11-06

- THe version presented for our Final Project for AppDev, ISPM, and BPM.

[Back to top (GATRH)](#gatrh) · [Back to top (Versioning and Releases)](#versioning-and-releases)

</details>

<a id="contributing-and-support"></a>
<details>
<summary><strong>Contributing and Support</strong></summary>

This project is developed by **Group 6**.

| Role | Team member(s) |
|---|---|
| Project Manager | Diana Teves |
| Business Analyst (BA) | Diana Teves, Alexandrian Bon |
| UI/UX Designer | Rimer Rey Gabaleo |
| Backend Developer | Alexandrian Bon |
| Frontend Developer | Winston Gultiano |
| Software Quality Assurance (SQA) | Melissa Estrella |

For project questions or support, contact the project team.

[Back to top (GATRH)](#gatrh) · [Back to top (Contributing and Support)](#contributing-and-support)

</details>
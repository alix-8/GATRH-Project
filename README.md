# GATRH

[Main Guide](https://docs.google.com/document/d/14BNCUfG_KwJc_5pU9YQWIesMHuSxkp0THvSnrWq3W_A/edit?usp=sharing) &nbsp;|&nbsp;
[Dev Journal/Notes](https://docs.google.com/document/d/1YuV9XRYu1kzWDhISpTRxleVu-HYdjwQjBa8x7t6Ki6Q/edit?usp=sharing)
[Frontend](https://docs.google.com/document/d/16PsmXYDlfpZaJ77XsK3ExLWVPrw90-DSLalWU1f8oOY/edit?usp=sharing)
---

## Table of Contents
- [Local Installation & Setup](#local-installation--setup-for-development-and-testing)
  - [Backend Setup](#-backend-setup)
  - [Frontend Setup](#-frontend-setup)
  - [Important Commands](#-important-commands)🔵

---

## Local Installation & Setup (For Development and Testing)

The following instructions cover setup for both the `backend` and `frontend` projects.

### 🔵 Backend Setup

> **Prerequisites**
> Ensure you have the following installed on your local machine:
> 1. PHP >= 8.2
> 2. Composer >= 2.x
> 3. MySQL >= 8.0 (via XAMPP, Laragon, or standalone MySQL)
> 4. Git

1. **Clone the repository**
```bash
   git clone https://github.com/alix-8/GATRH-Project.git
   cd backend
```

2. **Install PHP dependencies**
```bash
   composer install
```

3. **Environment configuration**

   Duplicate `.env.example` as `.env`:
```bash
   cp .env.example .env
```

   > **Note:** Create an empty database named `gatrh` in your local MySQL instance (e.g., via phpMyAdmin or MySQL Workbench) before running migrations.

   Open `.env` and set your database connection details:
```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=gatrh
   DB_USERNAME=root
   DB_PASSWORD=
```

4. **Generate application key**
```bash
   php artisan key:generate
```

5. **Run database migrations**
```bash
   php artisan migrate
   php artisan db:seed
```

6. **Run the backend server**
```bash
   php artisan serve
```

### 🔵 Frontend Setup

> **Prerequisites**
> Ensure you have the following installed on your local machine:
> 1. Node.js: >= 18.x (Recommended: LTS version)
> 2. npm: >= 9.x (bundled with Node.js)

1. **Navigate to the frontend directory**
```bash
   cd frontend
```

2. **Install dependencies**
```bash
   npm install
```

3. **Environment configuration**
```bash
   cp .env.example .env.local
```
> Note: # Make sure Vite environment variables start with `VITE_
VITE_API_BASE_URL=http://127.0.0.1:8000/api`

4. **Run the frontend dev server**
```bash
   npm run dev
```

---
### 🔵 Important commands
| Component | Command              | Description                          |
|-----------|-----------------------|---------------------------------------|
| Backend   | `php artisan serve`  | Starts the Laravel backend dev server |
| Frontend  | `npm run dev`        | Starts the frontend dev server        |

### 🔵 Render Free plan scheduler

Render Cron Jobs are a paid service. On the Free plan, use an external HTTP scheduler such as [cron-job.org](https://cron-job.org/) to call Laravel's scheduler endpoint. The endpoint is protected by a bearer secret and runs the existing schedules in `backend/routes/console.php`.

1. Generate a random secret (for example, with `php -r "echo bin2hex(random_bytes(32));"`).
2. In the Render backend service, add the environment variable `SCHEDULER_SECRET` with that secret, then deploy the latest backend code.
3. In cron-job.org, create a job with URL `https://YOUR-BACKEND.onrender.com/api/internal/scheduler/run`, method `POST`, and schedule every minute (`* * * * *`).
4. Add the request header `Authorization` with value `Bearer YOUR_SCHEDULER_SECRET` (use the same secret as the Render variable). Leave the request body empty.
5. Run the job once and confirm it receives HTTP 200 with `Scheduler checked successfully.`

This keeps the free web service receiving traffic, so it can use nearly all of Render's 750 monthly free instance hours. Free services can still restart, and external scheduling can be delayed, so this is best effort rather than a production-grade guarantee. If the Render app sleeps, a request may take about a minute to wake it. [Render free instance limits](https://render.com/docs/free) · [Render Cron Job pricing](https://render.com/docs/cronjobs) · [cron-job.org frequency and headers](https://cron-job.org/en/faq/)

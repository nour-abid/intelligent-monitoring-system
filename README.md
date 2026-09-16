# Intelligent Monitoring System

AI-powered workplace monitoring system for real-time activity detection, identity-aware recognition, and operational analytics.

This project combines computer vision, backend services, and a dashboard to detect behavioral patterns in a workplace environment and present insights through a structured monitoring interface.

---

## Project Summary

The system ingests live camera streams or recorded video, tracks people over time, identifies known individuals, and classifies activity state using a multi-stage AI pipeline.

The core workflow is:

- video input
- YOLO-based person detection
- DeepSORT tracking
- identity matching using face embeddings
- activity classification
- event persistence and analytics reporting

---

## What I Built

- Real-time surveillance pipeline in Python
- Activity classification for workplace behaviors such as Working, Inactive, and Using Phone
- Identity-based monitoring using face recognition and embedding comparison
- PostgreSQL / TimescaleDB-backed event storage and analytics
- Laravel API for monitoring data access and admin workflows
- Angular dashboard for supervisor and administrative views

---

## Tech Stack

- Python, OpenCV, YOLO, DeepSORT
- Face recognition pipeline with embedding-based matching
- PHP, Laravel
- Angular
- PostgreSQL and TimescaleDB

---

## Architecture

The project follows a layered architecture:

1. Python surveillance runtime reads frames and performs inference.
2. Events are persisted in PostgreSQL / TimescaleDB for real-time and historical analytics.
3. Laravel exposes the monitoring data through API endpoints.
4. Angular renders the dashboard for authenticated users and role-scoped views.

The Python runtime does not expose a standalone HTTP server; it acts as the inference and storage component connected to the wider system.

---

## Why This Is Relevant

This project demonstrates practical AI application in a business environment, combining:

- computer vision
- full-stack integration
- analytics and reporting
- identity-aware monitoring
- role-based access and admin workflows

It reflects a real-world system design problem: turning raw video input into structured operational intelligence.

---

## Getting Started

### Prerequisites

- Python 3.10+
- PHP 8.2+
- Composer
- Node.js 20+
- npm

### Run the surveillance runtime

```bash
# Windows PowerShell
.\venv\Scripts\Activate.ps1

python -m surveillance.main_surveillance
```

### Start the Laravel API

```bash
cd mq-monitoring-api
composer install
php artisan serve --port=8081
```

### Start the dashboard

```bash
cd mq-monitoring-web
npm install
ng serve
```

Open the app in the browser at http://localhost:4200

---

## Project Scope

This repository includes the surveillance runtime, analytics backend, web dashboard, and documentation for the monitoring system as implemented in the current codebase.

---

## Repository Notes

The project includes supporting documentation in the shared-docs folder covering architecture, API behavior, feature scope, and verification notes.

---

## Status

This is an active implementation of a full-stack AI monitoring system, built to explore real-time detection, identity-aware analysis, and operational reporting in a workplace context.

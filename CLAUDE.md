# BMS — Claude Code Instructions

## Project Overview
Full-stack PHP/MySQL ERP for BJP Technologies Co. Ltd (Tanzania).
Stack: PHP, MySQL, Bootstrap 5, PDO, GitHub Actions CI/CD.
Live sites deploy automatically on push to `main` via `.github/workflows/deploy.yml`.

---

## General Rules

- Ask for go-ahead before making any edit; only modify exactly what is instructed
- Log every change to `changelog.md` with date, file, and description
- Never push directly to `main` — always use a feature branch and open a PR
- Use `git reset --hard HEAD && git pull origin main` (not plain `git pull`) in deploy scripts
- `script_stop: true` must remain in deploy.yml so a failed migration halts the deploy

---

## On-demand files (type the trigger word to load)

<!-- #dev      → .claude/dev-standards.md  (UI rules, DataTable, Select2, mobile, SweetAlert2) -->
<!-- #process  → .claude/process.md        (anti-patterns, PDO reference, page walkthrough)    -->
<!-- #migrate  → .claude/migrations.md     (DB changes)                                        -->
<!-- #newpage  → .claude/templates.md      (new page or API template)                          -->
<!-- #secure   → .claude/security.md       (API/upload/auth)                                   -->
<!-- #plan     → .claude/strategy.md       (feature planning)                                  -->

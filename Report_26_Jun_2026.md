# Work Report — 26 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 26 June 2026

---

## Overview

Today was entirely dedicated to the **audit, accountability, and intelligence** layer of the system — the part that answers: *who did what, when, from where, and on what device.*

The work unfolded in three interconnected directions. First, the **Activity Log** was transformed from a basic log list into a professionally capable audit tool: it can now handle tens of thousands of entries, every delete across the entire system is now recorded, and every view, create, and edit follows a single consistent format. Second, the **Customer, Supplier, and Sub-Contractor** forms were made self-sufficient — users can now introduce new dropdown values without requiring a developer. Third, an entirely new **Login History** section was built under Admin — showing who logged into the system, from which city and region, on what device, and for how long.

This report covers everything completed since the 25 June 2026 report.

---

## 1. Customers, Suppliers, and Sub-Contractors — Self-Growing Form Dropdowns

### The problem

Several fields in the Add and Edit forms for customers, suppliers, and sub-contractors — including *Type*, *Payment Terms*, *Currency*, *Category*, and *Year* — offered a fixed list of choices that could not be extended without editing the system setup. When a user needed a value that did not exist in the list, they had no option.

### What was built

These fields now work as **self-growing dropdowns**. Each one presents the existing choices as before — but also offers an **"Other"** option at the bottom. Choosing "Other" reveals a text box where the user types the new value. When the form is saved, that new value is permanently added to the list and will appear as a regular choice for every future user.

This applies to:

- **Suppliers** — Type, Payment Terms, Currency, Category, Year (both Add and Edit forms)
- **Sub-Contractors** — Type, Payment Terms, Currency, Category, Year (both Add and Edit forms)
- **Customers** — Type, Payment Terms, Currency, Category, Year (both Add and Edit forms)

No system configuration, no developer intervention, and no database editing is needed. The system learns the new value the moment the record is saved.

A global fix was also applied so that **all dropdowns throughout the system work correctly inside modals and pop-up forms** — previously, clicking an option inside a modal would sometimes close the dropdown before the selection registered.

---

## 2. Supplier and Sub-Contractor Lists — Display Fixes

Two visual improvements were applied to the Supplier and Sub-Contractor list pages:

**Action button** — The per-row action button previously showed a gear icon alongside the word "Actions". The text has been removed; the gear icon alone now serves as the button. This is cleaner and consistent with the rest of the system.

**Projects Involved panel** (on the detail/view page) — When a supplier or sub-contractor was registered, the project chosen at registration time was stored but never appeared in their "Projects Involved" panel. It now appears at the top of the list, clearly marked with a **Primary** badge. All subsequent project assignments appear below it in the usual way.

---

## 3. Activity Log — Full Professional Upgrade

The Activity Log page received the most substantial overhaul of the day. The changes are grouped below by what each one addresses.

### 3a. Consistent log format across the whole system

Every single action recorded in the Activity Log — View, Create, Edit, Delete, Review, Approve — now follows one standard format:

- **Type column** shows a short, clean label: *"View Customers"*, *"Create Invoice"*, *"Edit Employee"*, *"Delete Payment"*
- **Description column** shows the full detail: *"User viewed the customers list"*, *"User created a new invoice: INV-0042 (ID 12)"*, *"User deleted payment with ID 77"*

Previously, different modules used different wording — some said "Created", others "create", others "Added", others "UPDATE". These inconsistencies made the log hard to read and impossible to filter reliably. The standard has now been applied across all five phases of the system:

- **Phase 1** — Customers, Suppliers, Invoices, Products
- **Phase 2** — Finance (Purchase Orders, Sales Orders, Quotations, Expenses, Revenue, Payment Vouchers, Journals, Bank Reconciliation)
- **Phase 3** — HR (Employees, Leave, Payroll)
- **Phase 4** — Operations (Projects, Warehouses, Assets) and CRM (Leads, Campaigns, Activities)
- **Phase 5** — Settings (Chart of Accounts, Users, User Roles, Tenders) and a fix to a Leave reference that was causing a silent error

### 3b. Every delete in the system is now logged

Before today, most delete actions either wrote nothing to the Activity Log at all, or wrote only to a separate audit table that is not visible on the Activity Log page. A user could delete a supplier payment, a payroll record, a document, or a project and the Activity Log would show nothing.

This has been closed across **seven batches** covering the entire system — over 50 individual delete actions. Every one now records: *"User deleted [entity name] with ID [number]"*.

The modules covered include: HR (employees, attendance, leave, payroll), Finance (invoices, expenses, payment vouchers, journals, budgets, bank reconciliations, payments, credit and debit notes), Procurement and Stock (purchase orders, purchase returns, delivery notes, RFQs, products, stock adjustments, warehouses), Sales and CRM (customers, sales orders, quotations, sales returns, LPOs, leads, campaigns, activities), Operations (projects, IPCs, inspections, scope documents, project documents), Documents and Settings (document templates, email templates, SMS templates, signatures, brands, categories, compliance entries, notifications, collateral documents, asset categories, accounts, account categories, cash register shifts, held sales), and Parties (suppliers, sub-contractors).

No deletion behaviour was changed — only the recording of what happened.

### 3c. Filter by activity type

The Activity Log now has a **Type filter** with six clear options: View, Create, Edit, Delete, Review, and Approve. Selecting one shows only entries of that kind, regardless of the exact wording recorded in the log. Previously, the filter showed a dropdown of every distinct action phrase in the database — often hundreds of entries — making it unusable.

### 3d. Summary cards now reflect active filters

The four summary cards at the top of the Activity Log (Total, Views, Creates/Edits, Deletes) previously always showed today's totals regardless of any filters applied. They now update in real time to match whatever filter is active — if you filter by a specific user or a specific period, the cards show the totals for that user and that period only.

### 3e. Period-driven cards and custom date range

A set of quick period buttons — **Today, This Week, This Month, Last Month, This Year** — was added. Selecting a period updates both the table and the summary cards together. A **Custom (Specify)** option lets the user type any start and end date. The default view, when no period is selected, shows all records across all time.

### 3f. Performance — handles 65,000+ entries without slowing

The Activity Log table was previously loaded by fetching a large batch of records and building the table in the browser. As the log grows (the system currently holds over 65,000 entries), this approach becomes slow and eventually breaks.

The table is now **server-side**: the browser asks for only the current page of results, the server fetches exactly those rows, and pagination, sorting, and the search box all happen at the database level. The page loads in the same time regardless of whether the log holds 100 entries or 1,000,000.

### 3g. Login and logout events tracked in the Activity Log

The Activity Log now records every **login** and **logout** as entries in the log, so an admin can see when a particular user signed in and signed out without leaving the Activity Log page.

When viewing the log **filtered by a specific user**, a **Time in System** panel appears at the bottom of the filters section showing:

- Total time that user has spent in the system (all sessions combined)
- Number of sessions
- Average session length
- Date and time of their most recent login
- A table of their most recent sessions showing login time, logout time, duration, and how the session ended (manual logout, session timeout, or still active)

---

## 4. Admin — Login History (New Section)

A brand-new page has been added under **Admin → User Management → Login History**. This page gives administrators a complete, enriched record of every login to the system.

### What it shows

The page opens with four summary cards:

| Card | What it counts |
|---|---|
| Total Logins | Every login ever recorded |
| Today | Logins made today |
| Unique Users | Number of distinct users who have ever logged in |
| Active Now | Users whose session is currently open (no logout recorded) |

Below the cards is a filterable, paginated table. The available filters are: **User** (pick a specific user or leave blank for all), **From date**, **To date**, and a **free-text search** that searches across IP address, city, browser, and ISP simultaneously.

### Table columns — with example data

| Column | What it shows | Example |
|---|---|---|
| **#** | Row number | 1, 2, 3 … |
| **User** | Full name on top, email address below | *W. Nyagawa* / *w.nyagawa@bjp.co.tz* |
| **IP Address** | The network address the user connected from | *197.186.42.11* |
| **Location & Device** | City (bold) on top; Region and Country below; Browser + OS + device type on the next line; Timezone on the last line | *Dar es Salaam* / *Dar es Salaam Region, Tanzania* / 🖥 Chrome on Windows 11 (Desktop) / ⏰ Dar es Salaam (Africa) |
| **ISP / Org** | Internet provider and organisation name | *Vodacom Tanzania / Vodacom* |
| **Role** | The user's role badge, colour-coded | **Admin** (red), **Manager** (yellow), **Accountant** (blue) |
| **Login Time** | Date and time of login; "Active" badge if still open | *26/06/2026, 09:14:33* · **Active** |
| **Duration** | How long the session lasted | *2h 07m*, *45m 22s*, *—* (still open) |

### How the location and device data is captured

Every piece of data shown is **real and captured at the moment of login** — nothing is hardcoded or estimated.

**Location** (City, Region, Country, ISP, Timezone) is looked up from the user's IP address using a free geo-IP service at login time. The lookup takes no more than three seconds and, if it fails or times out, the login proceeds normally — it is best-effort and never blocks access.

For logins from inside the office network (private IP addresses), the location shows **Local / Internal Network** rather than making a pointless external lookup.

**Device** (Browser, OS, Device Type) is read from the browser's identification string that every browser sends automatically with every request:

- **Browser**: Chrome, Firefox, Edge, Safari, Opera, Samsung Browser, and others
- **Operating System**: Windows 11, Windows 10, Windows 10/11*, macOS, Android (with version), iOS (with version), iPadOS, Linux
- **Device Type**: Desktop, Mobile, or Tablet

> *Windows 10 and Windows 11 share the same identification string in most browsers, so the system cannot always distinguish them. Chrome and Edge send an additional signal that allows exact detection — those logins show either "Windows 10" or "Windows 11" precisely. Firefox does not send this signal, so Firefox users on Windows show "Windows 10/11" — this is honest and not a limitation of the system.

**Timezone** is reported in human-readable form — *"Dar es Salaam (Africa)"* instead of the raw code *"Africa/Dar_es_Salaam"*.

### How it differs from the Activity Log

The **Activity Log** and the **Login History** page both record login events, but they serve different purposes and show different information:

| | Activity Log | Login History |
|---|---|---|
| **Purpose** | Full audit trail of everything that happened — who viewed, created, edited, deleted anything | Dedicated record of *access* — who connected, from where, and on what device |
| **Login entry** | One line: *"User logged in to the system"* with user name and timestamp | Full row with IP, city, region, country, ISP, browser, OS, device type, timezone, duration |
| **Geo/device data** | None | Full location and device enrichment |
| **Time-in-System** | Per-user panel (shown when filtered by user) | Duration column on every row |
| **Best used for** | Investigating what was changed and when | Investigating access patterns, unusual locations, or unknown devices |

---

## 5. Security — NIP Materials Permission Alignment

A small but important security correction was made: a page inside the NIP (Notice of Intent to Purchase) module was using an incorrect permission key, which meant that the standard role-based access control could not properly govern who could edit NIP materials. The key has been corrected to match the rest of the module.

---

## What was achieved today

**Every dropdown in Customers, Suppliers, and Sub-Contractors is now self-sufficient.** Users can introduce new values on the spot without waiting for system configuration. The "Projects Involved" panel now shows the primary project that was always there but never displayed.

**The Activity Log is now a professional audit tool.** Every delete across the entire system is recorded. Every view, create, and edit follows a single consistent format. The page handles unlimited history without slowing. Filters are powerful and the summary cards always reflect what is filtered. Login and logout events are visible in context alongside all other activity.

**Login History is a new, intelligent admin section.** It answers the questions the Activity Log cannot: where was the user when they logged in, what device were they on, which network provider were they using, and how long did they stay? Every data point is captured live at login and presented in a clean, searchable, paginated table.

---

## Summary

Today consolidated the system's accountability layer end to end. Gaps that existed for months — delete actions that left no trace, inconsistent log wording, a log page that slowed as history grew, and no way to see *where* users logged in from — have all been closed in a single session.

The Activity Log is now a complete internal audit trail. The Login History page adds external-access intelligence that most basic systems never offer. And the self-growing dropdowns remove a persistent friction point that required developer involvement for what should be a routine task.

---

*This report covers the work completed on 26 June 2026.*

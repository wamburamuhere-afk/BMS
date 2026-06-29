# Work Report — 27 June 2026

**Prepared by:** W. Nyagawa
**System:** Business Management System (BMS) — BJP Technologies Co. Ltd
**Date:** 27 June 2026

---

## Overview

The work since the last report delivered several substantial upgrades that, together, make the system noticeably more **intelligent, professional, and trustworthy**.

The headline pieces are: an **AI-powered audit assistant** built into the Activity Log that can read the system's activity and explain it in plain language, scan for unusual behaviour, answer questions, and produce a formal audit report; a new **automatic numbering system** that gives every customer, supplier, product, invoice and document a clean, company-branded, sequential reference code; and an entirely new **Smart Notification System** that actively alerts the right staff — by email and on screen — whenever something needs their attention. Alongside these, posted financial records were sealed against tampering, document navigation was made predictable, and several lists were polished for speed and readability.

This report covers everything completed since the 26 June 2026 report.

---

## 1. AI Audit Intelligence in the Activity Log

The Activity Log already records everything that happens in the system. It has now been given an **AI assistant** that can actually *understand and interpret* that activity for an administrator, presented as four clear modes:

**Daily Briefing** — With one click, the AI reads the activity for the chosen period and writes a plain-English narrative: who did what, which parts of the system were used, and what deserves attention. It even assigns an overall **risk level**, so a manager can grasp the day's activity at a glance instead of scrolling through thousands of rows.

**Anomaly Scanner** — The AI scans the activity for anything **unusual or suspicious** — for example, activity at odd hours, an unusual burst of deletions, or a pattern that stands out from normal behaviour — and flags it for review. This turns the audit log from a passive record into an active watchdog.

**Ask the Log** — An administrator can simply **ask a question in plain language** — such as "what changed in payroll this week?" — and the AI answers based on the actual recorded activity, without the administrator needing to know how to build filters or queries.

**Audit Report** — The AI produces a **formal, structured audit report** for the selected period, suitable for management or compliance review, with a clean print layout that matches the rest of the system's printed documents.

This feature is **admin-only**, and it complements (does not replace) the existing log: the raw, factual record is always there; the AI simply adds a layer of understanding on top of it. The Activity Log's display was also refined so that long entries wrap neatly and remain readable, and repeated "view" entries are sensibly grouped rather than cluttering the list.

---

## 2. Automatic, Company-Branded Reference Numbers

Previously, many records and documents were numbered inconsistently — some used random numbers, some could leave gaps, and some required manual entry. This has been replaced with a single, professional **automatic numbering system**.

Every newly created record now receives a clean reference in the form **COMPANY-TYPE-NUMBER** — for example a customer becomes something like *BFS-CUST-0001*, an invoice *BFS-INV-0042*, a purchase order *BFS-PO-0007*. The company prefix is taken from the company profile (and can be adjusted by an administrator), the middle tag identifies the type of record, and the final number is **strictly sequential and gap-free**.

This now applies across the board:

- **Master records** — customers, suppliers, sub-contractors, leads, employees, and products (including non-inventory items).
- **Documents** — invoices, purchase orders, goods-received notes, delivery notes, quotations, sales orders, returns, payment vouchers, material lists and more.

Two important safeguards were built in. When an existing record is **edited**, the system tidies an old-style number into the new format — **but only while the record is still in an editable stage**; once a document has been posted into the accounts, its number is **frozen forever** so that statements and printed copies never change. And a clear assurance was established: because the accounts link back to each document by its permanent internal identity rather than its printed number, **upgrading a reference number can never disturb the financial reports**.

Existing non-inventory products that previously had no item code were also given proper codes automatically, so nothing is left without a clean reference.

---

## 3. The Smart Notification System

This was the largest single piece of work. The system previously had only basic on-screen notices and **no working email capability at all** — the email feature merely pretended to send. It has been replaced with a complete, professional notification platform that genuinely reaches staff and, crucially, reaches **only the right staff**.

The guiding principle is simple but powerful: **a notification about a particular area should only go to the people who are actually responsible for that area.** An HR matter reaches HR; a procurement matter reaches procurement; a finance matter reaches finance. Nobody is buried under alerts that are not theirs, and nothing is dumped into a single shared mailbox where it becomes impossible to know who should act.

### What the system now does

**It sends real emails.** A proper email engine was built and connected to the company's email settings. The previous "test email" that only pretended to work now genuinely sends, and the same engine powers every notification. It is safe by design — if email is not yet configured, the system simply records the situation and carries on rather than failing.

**It sends each alert only to the people with access to that area.** Every notification is matched against the system's existing roles-and-permissions structure, so a person only receives an alert if their role actually grants them access to that part of the system. This is enforced automatically and invisibly — there is no way to accidentally notify someone who should not even be able to see the information.

**It respects project boundaries.** When an alert concerns a specific project, only people assigned to that project (plus administrators) receive it.

**It lets each person quiet what they don't want.** Individual staff can mute categories of notifications they don't wish to receive, and that preference is always respected.

**Administrators control everything from one screen.** A new **Notification Rules** page under Settings lets an administrator decide, for each type of event, exactly who is notified (everyone with access, a specific role, or a specific named person) and how (email, on-screen, or both). A **"Preview recipients"** feature shows the exact list of people who would be notified — and warns if a chosen person would not actually receive it because they lack access. A **"Test send"** button confirms email is working end to end. Master on/off switches exist for the whole system, the email channel, and the optional daily summary.

**It works on its own schedule.** Each day the system checks, by itself, for situations that need attention even when nobody triggered them directly — invoices past their due date, quotations about to expire, and approaching tender submission deadlines — and notifies the responsible people, never sending the same reminder twice in a day.

**It notifies automatically as work happens.** Whenever a key document is created and needs attention or approval — purchase orders, invoices, goods-received notes, debit and credit notes, sales and purchase returns, expenses, and payment vouchers — the relevant people are alerted immediately, with a direct link to the document.

**Everything appears in the familiar places.** Notifications show on both the **dashboard's "System requires your attention"** panel and the **notification bell**, each person seeing only the items that are genuinely theirs.

**It offers an optional AI daily summary.** If switched on, each person can receive a single intelligent **daily digest** — one tidy email that summarises and prioritises everything currently needing their attention. When AI is not enabled, the same digest is still produced in a clear, organised plain format.

### Built to last and to be safe

Every notification is **fail-safe** — if anything ever went wrong while sending an alert, it can never interrupt or break the actual business task that triggered it. Nothing is ever sent twice, every notification is recorded for audit, and the design deliberately leaves room to add **WhatsApp and SMS** later with no rework.

---

## 4. Protecting Posted Financial Records From Being Altered

Every financial event — an approved invoice, a received goods note, a paid voucher — eventually gets **posted into the accounting records** that feed the financial statements. Professional accounting practice is absolute: **once something is posted, it must not be edited in place.** Otherwise the audit trail breaks, closed periods could shift, and approvals lose their meaning.

A careful review checked every document type that posts into the accounts. Most were already protected, but **three had a gap** — invoices, goods-received notes, and payment vouchers could still be edited after posting. Those gaps were closed: the system now firmly refuses to edit any of them once posted, approved, or paid, for **every** user without exception, enforced at the deepest level. Documents still in their early stages remain fully editable. To change a posted record, the correct route is to reverse it and re-issue — exactly as accounting standards require.

---

## 5. Smarter Navigation — Documents Stay Where You Open Them

Linked documents (purchase orders, goods-received notes, delivery notes, sales orders) used to throw the user **into a project** after saving, even when the user had opened them from the general company-wide area. The system now follows one intuitive rule: **it returns you to wherever you came from** — into the project if you started there, or back to the general area if that's where you were working. A document still belongs to its project exactly as before; it simply no longer drags you somewhere you didn't ask to go.

---

## 6. List & Page Refinements

Several lists were polished for speed and clarity:

- **Login History** (the access-tracking page introduced previously) was upgraded with professional pagination and a switch between a full table view and a mobile-friendly card view.
- **Non-inventory products (Services)** — the list was rebuilt as a proper sortable, searchable table with a wider Product Name column, a stable layout that no longer scrolls awkwardly sideways, a tidy Item Code column, and a corrected print layout. The restriction that limited how long a product name could be was also removed.
- **Activity Log and dashboard** — long text now wraps neatly so entries stay readable.

---

## 7. Quality Assurance

The new work was verified as it was built, not merely assumed to work — checked against real data to confirm, for example, that an alert reaches exactly the right people and nobody else, that a rule aimed at someone without access correctly reaches no one, that reminders never duplicate, and that posted records genuinely refuse edits while draft ones still allow them.

In addition, a **permanent automated test** was created for the entire notification system. From now on it runs by itself every time changes are submitted and will block any future change that accidentally damages the system. The full company-wide automated test suite was confirmed to pass cleanly with all of these additions in place.

---

## What was achieved

**The system became more intelligent.** The Activity Log can now explain itself, watch for unusual behaviour, answer plain-language questions, and produce formal audit reports — and the whole system can summarise each person's outstanding work in a daily digest.

**The system became more professional.** Every record and document now carries a clean, company-branded, sequential reference number, applied consistently and protected once posted.

**The system gained a genuine voice.** It now actively reaches the right people — and only the right people — by email and on screen, automatically as work happens and on a daily schedule, all controlled from a single administrator screen.

**The system became more trustworthy.** Posted financial records can no longer be altered, navigation is predictable, and the financial reports remain safe regardless of reference-number changes.

---

## Summary

Where the system could previously only wait passively for users to check it, it can now actively and intelligently support the business — interpreting its own audit trail, numbering everything cleanly and consistently, and reaching out privately and precisely to the people responsible whenever something needs doing. Combined with sealing posted records against alteration and making navigation dependable, the result is a system that is both safer to rely on and more capable of driving the business forward on its own.

---

*This report covers the work completed on 27 June 2026.*

# BMS AI Assistant — Setup & Feature Guide

BMS now includes an **AI Assistant**. It uses **your own** AI provider account, so you control
the model and the bill. It ships **disabled** — turn it on in 2 minutes.

---

## Connect in 2 minutes (admin)

1. Sign in as an admin → top-right **Settings menu → AI Assistant**.
2. Tick **Enable the AI Assistant**.
3. Choose a **Provider** and **Model**:
   | Provider | Good starter model | Where to get a key |
   |---|---|---|
   | OpenAI | `gpt-4o-mini` (cheap, fast) | platform.openai.com |
   | Anthropic | `claude-haiku-4-5` | console.anthropic.com |
   | Google Gemini | `gemini-2.0-flash` | aistudio.google.com |
   | OpenRouter | any model (+ Base URL `https://openrouter.ai/api/v1`) | openrouter.ai |
4. Paste your **API key** (stored encrypted; never shown again).
5. *(Optional)* set a **Monthly cost cap (USD)** to be safe — e.g. `5`.
6. Click **Save**, then **Test Connection** → you should see **Connected ✓**.

That's it. The AI menus and buttons now appear for permitted users.

---

## What you get

### 1. ✨ Generate with AI
Next to text fields (e.g. the **Expense → Description**) a small **AI** button appears. Click it,
say what you want ("a polite note that payment is due in 14 days"), pick a tone, and it drafts the
text — which you can edit before using.

### 2. Ask BMS (the headline)
**Comms menu → Ask BMS.** Ask in plain language:
- "What was my revenue this month?"
- "Who are my top 5 debtors?"
- "What is my current cash position?"
- "How much profit did I make last month?"
- "Which products are low on stock?"

Answers come **only from your own data**, through a fixed set of read-only summaries. Each answer
shows which figure it used.

### 3. This month, in words
On the **Dashboard**, the **"This month, in words"** card writes a short summary of revenue,
expenses, profit, cash and receivables on demand.

---

## Permissions
Grant the **AI Assistant** permission to a role (Settings → Roles & Permissions) to let those
users use Ask BMS / Generate. Only **admins** can change AI settings or see usage/spend.

## Safety & privacy (how it's built)
- The assistant **cannot change anything** — it only reads pre-defined summary figures.
- It **never** sees raw records, customer lists, or runs database queries.
- Your **API key is encrypted** on the server and never displayed again.
- A **monthly cost cap** and a per-user rate limit prevent runaway spend.
- Turning the master switch **off** instantly hides every AI feature.

## Cost
You pay your provider directly for what you use. Starter models (gpt-4o-mini / haiku / flash) cost
a fraction of a cent per question. The **AI Settings** page shows this month's estimated spend by
feature, and you can cap it.

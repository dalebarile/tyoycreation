# Data Retention Schedule & Policy
**Organization:** Tyoy Creation Event Styling  
**System:** Quick Event Styling (QES) Platform  
**Compliance Standard:** Republic Act No. 10173 (Philippine Data Privacy Act of 2012), National Privacy Commission (NPC) Advisory Circulars, and Philippine National Internal Revenue Code (NIRC)  
**Effective Date:** October 2026  
**Document Owner:** Data Protection Officer (DPO) & Lead Systems Administrator  

---

## 1. Executive Summary & Policy Objective

In accordance with the **General Data Privacy Principles of Transparency, Legitimate Purpose, and Proportionality** enshrined in Section 11 of Republic Act No. 10173, Tyoy Creation enforces a strict storage limitation policy. Personal data shall not be retained in identifiable form longer than necessary for the fulfillment of declared, specified, and legitimate event coordination purposes, contractual execution, or statutory tax obligations.

This document sets forth the mandatory operational retention schedules, legal justifications, disposal workflows, and automated system enforcement routines implemented within the QES codebase.

---

## 2. Regulatory & Legal Framework

1. **Republic Act No. 10173 (Data Privacy Act of 2012):**
   - *Section 11 (General Data Privacy Principles):* Personal data must be kept in a form which permits identification of data subjects for no longer than is necessary for the purposes for which the data were processed.
   - *Section 34 (Rights of the Data Subject):* Right to erasure, blocking, and data portability.
2. **National Privacy Commission (NPC) Advisory 2018-01 & Circular 16-01:**
   - Security of personal data in government and private personal information controllers (PIC).
3. **National Internal Revenue Code (NIRC) & BIR Revenue Regulations:**
   - Preservation of commercial books of accounts and records of gross sales/invoices for five (5) years for official audit and tax compliance.

---

## 3. Data Lifecycle & Retention Classification Matrix

| Data Category | Specific Data Elements | Lawful Basis (RA 10173) | Retention Period | Trigger / Expiry Event | Disposal / Deletion Method |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Two-Step Verification & OTP Sessions** | 6-digit OTP codes, temporary email staging payloads, verification tokens | Legitimate Interest / Security | **5 to 10 Minutes** | Code expiration or browser session close | Automatic session unset / cache purge (`BookingVerificationHelper`) |
| **Rejected / Declined Inquiries** | Client name, phone number, email, desired event date, venue, rejection reason | Legitimate Interest | **7 Days** | Timestamp of admin rejection | Automated SQL purge via `a_events.php` & `data_privacy_helper.php` |
| **Unconfirmed / Abandoned Inquiries** | Name, contact number, quotation inquiry notes with no client confirmation | Consent | **90 Days** | 90 days of client inactivity | Automated purge or administrative DSR cleanup |
| **Confirmed & Completed Event Records** | Client contact PII, styling theme, guest count, venue address, itemized contracts | Contractual Necessity | **1 Year post-event** | Date of event completion | Eligible for data subject pseudonymization / PII scrubbing |
| **Financial & Invoicing Totals** | Reference No (`EV-YYYY-XXXX`), monetary quotation totals, payment receipts | Legal Obligation (BIR Tax Code) | **5 Years** | End of taxable calendar year | Personal identifiers scrubbed; non-PII financial ledger preserved |
| **AI Chatbot Transcripts** | Inbound chat messages, session tokens, AI bot responses | Legitimate Interest | **30 Days** | Creation timestamp | Automated purge via `runRetentionSchedulePurge()`; zero PII cached in AI engine |
| **System Security & Audit Logs** | Admin login timestamps, IP addresses, session user-agents, device fingerprints | Security / Forensics | **12 Months** | Log creation date | Overwritten / purged after 1 year |

---

## 4. Automated Enforcement Mechanisms

Retention is actively enforced programmatically through the QES platform:

1. **7-Day Auto-Purge Rule (`a_events.php` & `DataPrivacyHelper`):**
   - Every execution triggers:
     ```sql
     DELETE FROM bookings
     WHERE status = 'rejected'
       AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
     ```
2. **Scheduled Housekeeping Purge (`qes/data_privacy_helper.php`):**
   - The platform provides `DataPrivacyHelper::runRetentionSchedulePurge($conn)`, which:
     - Automatically purges rejected bookings > 7 days old.
     - Automatically removes chat messages > 30 days old.
     - Automatically clears read notifications > 90 days old.
     - Cleans blocked and expired session tokens > 30 days old.
3. **Session Auto-Expiry:**
   - Verification OTP codes expire after 300 seconds (5 minutes) and are destroyed from active PHP server sessions.

---

## 5. Secure Disposal & Anonymization Standards

When personal data reaches the end of its designated retention period or upon a verified Data Subject Erasure Request:

1. **Permanent Cryptographic Deletion (Purge):**
   - For unconfirmed inquiries, declined quotes, and temporary records, the entire database row is permanently deleted (`DELETE FROM bookings WHERE id = ?`).
2. **Audit-Safe Pseudonymization (Tax & Contract Compliance):**
   - For events with fulfilled financial transactions, identifying PII is scrubbed:
     - `client_name` is replaced with `[Anonymized - Retention Schedule / DSR]`.
     - `client_phone`, `client_email`, and `client_address` are set to null/empty strings.
     - All related notification logs containing phone numbers or email addresses are deleted.
     - The financial total and event category remain for official accounting ledger balance without compromising client privacy.

---

## 6. Review & Audit Schedule

This Data Retention Schedule is reviewed annually by the Data Protection Officer or whenever major architectural changes to the storage or database infrastructure occur.

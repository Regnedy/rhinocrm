# Kiosk WhatsApp Waiting Template Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a partner-scoped, editable WhatsApp waiting-message template synchronized from RHINO CRM to all Kiosks logged into that partner.

**Architecture:** CRM owns one `partner_kiosk_settings` row per partner. Kiosk caches the resolved configuration per partner and syncs it during context refresh and when the settings screen opens/saves. WhatsApp delivery uses the cached template and `wa.me`, so it works before Meta Embedded Signup is approved.

**Tech Stack:** Laravel 13/PHP 8.3/MySQL; Android Kotlin/XML/SharedPreferences/HttpURLConnection.

## Global Constraints
- No global `.env` value for the partner message.
- Tenant isolation by authenticated `partner_id`.
- Unknown template variables are rejected.
- Kiosk works offline with the last valid cached template.
- Existing Quick Nutrition and WhatsApp Cloud API flows remain unchanged.

---

### Task 1: CRM partner kiosk settings
**Files:**
- Create migration `database/migrations/2026_08_09_000300_create_partner_kiosk_settings.php`
- Create `app/Modules/Mobile/Services/PartnerKioskSettingsService.php`
- Create `app/Modules/Mobile/Controllers/PartnerKioskSettingsController.php`
- Modify `routes/api_v1.php`
- Modify permission services.
- Test `tests/Standalone/PartnerKioskWaitingTemplateContractTest.php`

- [ ] Write failing contract test for table, routes, permission, default template and marker validation.
- [ ] Run and confirm RED.
- [ ] Implement service/controller/migration/routes/permission.
- [ ] Run and confirm GREEN.

### Task 2: Kiosk local cache and sync
**Files:**
- Modify `config/AppSettings.kt`
- Modify `crm/CrmApiClient.kt`
- Modify `LoginActivity.kt`
- Test `tools/validate_waiting_whatsapp_template_275.py`

- [ ] Write failing validator for partner-scoped cache, sync and offline fallback.
- [ ] Run RED.
- [ ] Implement cache and API sync.
- [ ] Run GREEN.

### Task 3: Kiosk settings editor
**Files:**
- Create `WhatsappWaitingSettingsActivity.kt`
- Create `activity_whatsapp_waiting_settings.xml`
- Modify `SettingsMenuActivity.kt`, `activity_settings_menu.xml`, `AndroidManifest.xml`.

- [ ] Extend validator for editor, preview, save/reset and sync status.
- [ ] Run RED.
- [ ] Implement screen and menu entry.
- [ ] Run GREEN.

### Task 4: Waiting-message delivery flow
**Files:**
- Modify `KioskActivity.kt`
- Create `whatsapp/WaitingWhatsappTemplate.kt`

- [ ] Add tests for `{nombre}`, `{socio}`, `{plazo}` render and direct `wa.me` flow.
- [ ] Run RED.
- [ ] Implement render and reuse existing country/number capture.
- [ ] Run GREEN.

### Task 5: Release and regression
**Files:**
- Update Kiosk version to 2.7.5.
- Add `CAMBIOS_V2_7_6.md` and README.
- Package CRM patch and Kiosk source/patch.

- [ ] Run PHP syntax/contracts/static audit.
- [ ] Run Android validators: waiting template, Quick Nutrition, country selector, test mode, direct media, ticket/social, multi-scale, SQLite, XML.
- [ ] Apply packaged patches to clean bases and rerun validations.
- [ ] Scan packages for secrets.

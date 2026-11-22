# Partner WhatsApp Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert RHINO WhatsApp sending from one global Meta credential set to isolated per-partner WhatsApp channels while preserving Kiosk APIs.

**Architecture:** Add an encrypted `partner_whatsapp_channels` table and resolver. Extend `MetaWhatsappProvider` with an optional partner context, explicitly pass plan ownership from Quick Nutrition, and expose Organization endpoints/UI for partner admins and SUPER_ADMIN.

**Tech Stack:** Laravel 13, PHP 8.3, MySQL, Laravel Crypt, Fetch/vanilla JS CRM UI, Meta Graph API.

## Global Constraints
- Kiosk request contract remains unchanged.
- Tokens are encrypted at rest and never returned.
- No cross-tenant fallback to global sender credentials.
- Migration `down()` is non-destructive.
- One primary WhatsApp channel per partner in this phase.

---

### Task 1: Multi-tenant contract and schema
- [ ] Write failing standalone contract test.
- [ ] Add non-destructive migration and permission grant.
- [ ] Verify test passes.

### Task 2: Encrypted channel service/resolver
- [ ] Test secret masking, tenant authorization and resolver contract.
- [ ] Implement DTO, resolver and management service.
- [ ] Add API controller/routes.
- [ ] Verify PHP/static tests.

### Task 3: Meta provider partner awareness
- [ ] Extend interface with optional partner id.
- [ ] Resolve credentials per partner in uploads/messages.
- [ ] Explicitly pass plan partner id from Quick Nutrition delivery.
- [ ] Update HTTP fake test to prove two partners use different endpoints/tokens.

### Task 4: Organization UI
- [ ] Add WhatsApp status card and configuration modal.
- [ ] Add connection test and disable actions.
- [ ] Ensure token value is never rendered.
- [ ] Validate JS syntax/template contract.

### Task 5: Release verification and patch
- [ ] Run standalone contracts, PHP lint, Blade/JS checks and static audit.
- [ ] Build sanitized patch with manifest and README.
- [ ] Apply patch to clean current CRM copy and rerun verification.

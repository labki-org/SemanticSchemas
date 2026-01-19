# Roadmap: Page-Type Property Display

## Overview

Enhancement to SemanticSchemas extension enabling Page-type property values to render as clickable wiki links. Two phases: template creation followed by system integration. Estimated scope is ~13 lines of code across 3 files.

---

## Phase 1: Template Foundation

**Goal:** Template:Property/Page exists and correctly renders Page-type values as wiki links.

**Dependencies:** None

**Plans:** 1 plan

Plans:
- [ ] 01-01-PLAN.md — Add Property/Page template to extension config and verify

**Requirements:**
- REQ-003: Empty Value Handling
- REQ-005: Template:Property/Page Implementation

**Success Criteria:**
1. Template:Property/Page is defined in extension-config.json Layer 0
2. Template handles empty/missing values by producing no output
3. Template renders single value as clickable wiki link
4. Template renders comma-separated values as individual links joined by ", "
5. Template uses `@@item@@` variable (not `x`) to avoid name collision

**Implementation:**
- Add to `resources/extension-config.json` in Layer 0 property templates section
- Content: `<includeonly>{{#if:{{{value|}}}|{{#arraymap:{{{value|}}}|,|@@item@@|[[@@item@@]]|, }}|}}</includeonly>`

---

## Phase 2: System Integration

**Goal:** Page-type properties automatically use Template:Property/Page through smart fallback logic.

**Dependencies:** Phase 1

**Plans:** (created by /gsd:plan-phase)

**Requirements:**
- REQ-001: Clickable Wiki Links for Page-Type Properties
- REQ-002: Multi-Value Support
- REQ-004: Smart Template Fallback Logic

**Success Criteria:**
1. PropertyModel.getRenderTemplate() returns custom template when Has_template is set
2. PropertyModel.getRenderTemplate() returns 'Template:Property/Page' for Page-type properties without custom template
3. PropertyModel.getRenderTemplate() returns 'Template:Property/Default' for other property types
4. Existing display templates regenerated show Page-type values as clickable links
5. Multi-value Page-type properties display as comma-separated link list

**Implementation:**
- Modify `src/Schema/PropertyModel.php` getRenderTemplate() method (~5 lines)
- Run artifact regeneration for existing categories with Page-type properties

---

## Progress

| Phase | Status | Requirements | Completion |
|-------|--------|--------------|------------|
| 1 - Template Foundation | Planned | REQ-003, REQ-005 | 0% |
| 2 - System Integration | Not Started | REQ-001, REQ-002, REQ-004 | 0% |

**Overall:** 0/5 requirements complete

---

## Coverage

| Requirement | Phase | Status |
|-------------|-------|--------|
| REQ-001 | Phase 2 | Pending |
| REQ-002 | Phase 2 | Pending |
| REQ-003 | Phase 1 | Pending |
| REQ-004 | Phase 2 | Pending |
| REQ-005 | Phase 1 | Pending |

**Coverage:** 5/5 requirements mapped

---
*Roadmap created: 2026-01-19*
*Phase 1 planned: 2026-01-19*

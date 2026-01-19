# Requirements: Page-Type Property Display

## v1 Requirements

### REQ-001: Clickable Wiki Links for Page-Type Properties
**Priority:** Must Have
**Source:** User request, research validation

Properties with `Has_type::Page` must render their values as clickable wiki links in display templates. Links should use standard MediaWiki wikilink syntax `[[Target]]`.

**Acceptance Criteria:**
- Page-type property values display as clickable links
- Links navigate to the correct wiki page
- Works with existing three-template system (dispatcher/semantic/display)

---

### REQ-002: Multi-Value Support
**Priority:** Must Have
**Source:** User request

Comma-separated property values must each render as individual clickable links.

**Example:**
- Input: `Has parent category, Has target namespace`
- Output: `[[Property:Has parent category]], [[Property:Has target namespace]]`

**Acceptance Criteria:**
- Multiple values separated by commas are split correctly
- Each value becomes a separate wiki link
- Links are joined with comma-space separator in display

---

### REQ-003: Empty Value Handling
**Priority:** Must Have
**Source:** Research recommendation

Empty or missing property values must render nothing (no broken links, no placeholder text).

**Acceptance Criteria:**
- Empty string values produce no output
- Missing parameter produces no output
- No "[[]]" broken links in output

---

### REQ-004: Smart Template Fallback Logic
**Priority:** Must Have
**Source:** User selection

Template selection for property display must follow this hierarchy:
1. If `Has_template` is set -> Use that custom template
2. Else if `Has_type` is `Page` -> Use `Template:Property/Page`
3. Else -> Use `Template:Property/Default`

**Acceptance Criteria:**
- PropertyModel.getRenderTemplate() implements this fallback logic
- Custom Has_template overrides always take precedence
- Page-type detection uses existing isPageType() method

---

### REQ-005: Template:Property/Page Implementation
**Priority:** Must Have
**Source:** Research architecture recommendation

Create new display template for Page-type properties using #arraymap for multi-value handling.

**Template Content:**
```wikitext
<includeonly>{{#if:{{{value|}}}|{{#arraymap:{{{value|}}}|,|@@item@@|[[@@item@@]]|, }}|}}</includeonly>
```

**Acceptance Criteria:**
- Template handles single values correctly
- Template handles comma-separated multiple values
- Template handles empty values (produces nothing)
- Uses `@@item@@` variable (not `x`) to avoid collision

---

## v2 Requirements (Future)

### REQ-F01: Hide Namespace Prefix in Display
**Priority:** Nice to Have
**Status:** Deferred to v2

Option to display links without namespace prefix while preserving correct link target.

**Example:**
- Storage: `Property:Has parent category`
- Display: `[[Property:Has parent category|Has parent category]]`

---

## Out of Scope

- **SMW Storage Changes** - SMW already stores data correctly with namespace prefixes
- **Custom Link Formatting Per-Property** - Use Has_template for custom cases
- **Non-Page Datatypes** - This work is specific to Page-type properties
- **JavaScript Link Enhancement** - Anti-feature per research
- **#ifexist Validation** - Performance killer, anti-feature per research

---

## Traceability

| REQ-ID | Feature | Phase | Status |
|--------|---------|-------|--------|
| REQ-001 | Wiki links | Phase 2 | Pending |
| REQ-002 | Multi-value | Phase 2 | Pending |
| REQ-003 | Empty handling | Phase 1 | Complete |
| REQ-004 | Smart fallback | Phase 2 | Pending |
| REQ-005 | Page template | Phase 1 | Complete |

---
*Requirements defined: 2026-01-19*
*Traceability updated: 2026-01-19*

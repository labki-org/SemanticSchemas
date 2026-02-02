---
phase: 04-conditional-templates
plan: 01
subsystem: template-generation
tags: [semantic-mediawiki, wikitext, template-generation, multi-category]

# Dependency graph
requires:
  - phase: 03-feature-branch-bug-fix
    provides: Silent property promotion pattern in CategoryModel
provides:
  - Conditional #if guards wrapping all property values in semantic templates
  - +sep=, parameter for multi-value properties
  - #if-wrapped #arraymap for multi-value Page properties with namespace
  - TemplateGenerator producing conditional output for all property types
affects: [05-multi-category-pages, 06-shared-property-deduplication, template-generation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Conditional template generation: wrap all #set properties in {{#if:{{{param|}}}|...|}} guards"
    - "Multi-value separator: use |+sep=, parameter instead of bare comma-separated values"
    - "Inline annotation guarding: wrap #arraymap in #if to prevent empty value processing"

key-files:
  created: []
  modified:
    - src/Generator/TemplateGenerator.php
    - tests/phpunit/unit/Generator/TemplateGeneratorTest.php

key-decisions:
  - "All properties get #if guards by default, even when property not found in store"
  - "Multi-value properties use |+sep=, parameter for SMW comma-separated list handling"
  - "Single-value Page properties with namespace keep existing conditional prefix pattern"
  - "Subobject 'Has subobject type' constant value does NOT get #if guard (not a parameter)"

patterns-established:
  - "generatePropertyLine() branching: null propModel → default #if guard, then check Page-type + namespace + multi-value combinations"
  - "Four output patterns: (1) default #if guard, (2) multi-value #if + +sep, (3) Page namespace #if prefix, (4) null for inline annotation"
  - "Test pattern: mock PropertyModel and WikiPropertyStore for detailed behavior verification"

# Metrics
duration: 2min
completed: 2026-02-02
---

# Phase 4 Plan 1: Conditional Template Guards Summary

**All semantic template properties wrapped in #if guards to prevent empty values, with +sep=, for multi-value properties**

## Performance

- **Duration:** 2 min
- **Started:** 2026-02-02T18:11:49Z
- **Completed:** 2026-02-02T18:14:22Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- generatePropertyLine() produces #if-guarded output for all property types
- Multi-value properties include |+sep=, parameter for proper SMW handling
- Inline annotations (#arraymap) wrapped in #if guards to prevent empty processing
- All existing template generation tests updated with new conditional assertions
- Four new tests covering #if guard behavior, +sep parameter, namespace prefix, and inline annotation patterns

## Task Commits

Each task was committed atomically:

1. **Task 1: Add #if guards and +sep to TemplateGenerator output** - `14efe70` (feat)
2. **Task 2: Update tests for conditional template output** - `1b870c7` (test)

## Files Created/Modified
- `src/Generator/TemplateGenerator.php` - Modified generatePropertyLine() to wrap all properties in #if guards, added +sep for multi-value properties, updated generateInlineAnnotation() to wrap #arraymap in #if
- `tests/phpunit/unit/Generator/TemplateGeneratorTest.php` - Updated existing tests to assert #if patterns, added 4 new tests for conditional behavior with PropertyModel mocking

## Decisions Made

**1. Default #if guard for unknown properties**
- When readProperty() returns null (property not in store), generatePropertyLine() returns basic #if-guarded line without +sep
- Rationale: We don't know if the property is multi-value, so use safe default pattern
- Impact: All properties get conditional guards even if property definition missing

**2. Multi-value detection requires PropertyModel**
- +sep=, parameter only added when PropertyModel confirms allowsMultipleValues() = true
- Rationale: Prevents incorrect +sep for single-value properties
- Impact: Unknown properties treated as single-value (safe default)

**3. Subobject type constant excluded from guards**
- The fixed line `Has subobject type = Subobject:Name` in generateSubobjectTemplate() does NOT get #if guard
- Rationale: It's a constant value, not a template parameter - always set
- Impact: Subobject templates have one unguarded line (by design)

**4. Restructured branching logic**
- New order: (1) check propModel null, (2) check Page+namespace+multi, (3) check Page+namespace+single, (4) check multi-value, (5) default single-value
- Rationale: Clearer logic flow with early exits, handles all combinations explicitly
- Impact: More maintainable code, easier to reason about which pattern applies

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None - implementation proceeded smoothly with clear requirements.

## Next Phase Readiness

**Ready for Phase 4-02 (Multi-Category Page Creation):**
- Conditional template guards prevent property collision issues
- Empty values no longer stored when multiple templates set same property
- Multi-value properties use +sep for correct SMW list handling
- All test assertions updated to match new output patterns

**Foundation complete for:**
- Multi-category page support (Phase 5)
- Shared property deduplication (Phase 6)
- Multi-template form generation (Phase 7)

**No blockers.**

---
*Phase: 04-conditional-templates*
*Completed: 2026-02-02*

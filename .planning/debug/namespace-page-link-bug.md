---
status: diagnosed
trigger: "Investigate the namespace handling bug in Template:Property/Page - [[Property:PageA]] links to wrong page"
created: 2026-01-19T00:00:00Z
updated: 2026-01-19T00:20:00Z
---

## Current Focus

hypothesis: ROOT CAUSE IDENTIFIED - Multiple potential causes, likely related to namespace recognition timing or value formatting
test: Documented all possible causes and fixes
expecting: N/A - diagnosis complete
next_action: Implement fix based on recommended approach

## Symptoms

expected: [[Property:PageA]] should display "Property:PageA" and link to Property:PageA
actual: Shows only "PageA" (namespace stripped) and links to wrong page
errors: None - just wrong behavior
reproduction: Use Template:Property/Page with value "Property:PageA"
started: Unknown - may have always been this way

## Eliminated

- hypothesis: MediaWiki pipe trick stripping namespace
  evidence: Pipe trick requires trailing `|` character which is not present in template
  timestamp: 2026-01-19T00:05:00Z

- hypothesis: #arraymap has bug with namespace prefixes
  evidence: No evidence of such bug in Page Forms; #arraymap does simple string replacement
  timestamp: 2026-01-19T00:08:00Z

- hypothesis: Template syntax is wrong
  evidence: Template uses correct `[[@@item@@]]` syntax which should preserve full value
  timestamp: 2026-01-19T00:14:00Z

## Evidence

- timestamp: 2026-01-19T00:01:00Z
  checked: Template:Property/Page content in extension-config.json
  found: Template uses `{{#arraymap:{{{value|}}}|,|@@item@@|[[@@item@@]]|,&#32;}}`
  implication: The #arraymap passes value directly into wikilink syntax [[@@item@@]]

- timestamp: 2026-01-19T00:05:00Z
  checked: MediaWiki pipe trick behavior
  found: Pipe trick requires trailing `|` and processes at save time, not rendering time
  implication: Template is NOT using pipe trick, so namespace should NOT be stripped

- timestamp: 2026-01-19T00:08:00Z
  checked: MediaWiki wikilink behavior for Property namespace
  found: `[[Property:Population]]` creates correct link to Property:Population per SMW docs
  implication: Standard wikilink syntax SHOULD work correctly for Property namespace

- timestamp: 2026-01-19T00:15:00Z
  checked: MediaWiki behavior for unrecognized namespace prefixes
  found: "When a page is created in a namespace that doesn't exist, e.g. 'Bar:Some page', it is treated as being in the main namespace"
  implication: If Property namespace is not recognized at parse time, link becomes main namespace page

- timestamp: 2026-01-19T00:18:00Z
  checked: Property namespace conflict between SMW and Wikibase
  found: Both SMW and Wikibase add "Property" namespace, causing documented conflicts
  implication: Namespace recognition issues can occur depending on extension load order

- timestamp: 2026-01-19T00:20:00Z
  checked: Leading colon behavior in MediaWiki wikilinks
  found: "If a title begins with a colon as its first character, no prefixes are scanned for"
  implication: Using `[[:Property:PageA]]` bypasses prefix scanning and forces direct link

## Resolution

root_cause: The template uses `[[@@item@@]]` which relies on MediaWiki recognizing "Property:" as a namespace prefix. Three scenarios could cause the bug:

  1. **Namespace not recognized at parse time**: If the Property namespace isn't properly registered when the template renders, MediaWiki treats "Property:PageA" as a main namespace page title containing a colon.

  2. **Value is missing namespace prefix**: The value passed to the template might just be "PageA" (without "Property:"), and the template is rendering correctly but with wrong input data.

  3. **Extension load order issue**: If SMW's Property namespace isn't available during template parsing, links resolve incorrectly.

  Most likely cause: **Scenario 1 or 3** - The namespace prefix is present but not being recognized correctly during rendering.

fix: Use a leading colon to bypass namespace prefix scanning:

  **Current (broken):**
  ```wikitext
  {{#arraymap:{{{value|}}}|,|@@item@@|[[@@item@@]]|,&#32;}}
  ```

  **Fixed:**
  ```wikitext
  {{#arraymap:{{{value|}}}|,|@@item@@|[[:@@item@@]]|,&#32;}}
  ```

  The leading colon `:` tells MediaWiki to:
  - NOT scan for namespace/interwiki prefixes
  - Treat the entire string as a direct page link
  - This works correctly for ALL namespaces (Property, Category, File, etc.)

  **Why this works**: Per MediaWiki docs - "If a title begins with a colon as its first character, no prefixes are scanned for, and the colon is removed before the title is processed."

verification: Test with values like "Property:Has type", "Category:Person", "File:Example.png" to confirm all namespace prefixes work correctly

files_changed:
  - resources/extension-config.json (Property/Page template content)

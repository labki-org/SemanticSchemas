# StructureSync Quick Reference

Quick reference guide for common tasks with StructureSync.

## Hierarchy Visualization

### View Hierarchy in Special Page
```
Special:StructureSync/hierarchy?category=CategoryName
```

### Embed in Category Page
```wiki
{{#structuresync_hierarchy:}}
```

### API Call
```bash
curl "http://wiki.example.org/api.php?action=structuresync-hierarchy&category=Faculty&format=json"
```

## Property Views

The properties section has two tabs:

| Tab | View |
|-----|------|
| **By Category** | Properties grouped by source category (default) |
| **By Type** | Properties grouped as Required/Optional with counts |

## Color Coding

| Color | Meaning |
|-------|---------|
| 🟥 Red/Pink | Required property |
| 🟩 Green | Optional property |

## Common Workflows

### 1. Understand a Category Structure
1. Go to `Special:StructureSync/hierarchy`
2. Enter category name (e.g., "PhDStudent")
3. Review inheritance tree and properties

### 2. Category Page Hierarchy Display
**Automatic**: Hierarchy is auto-added to generated `Template:<Category>/display` when regenerating artifacts.

**Manual**: To add elsewhere:
1. Edit any page
2. Add: `{{#structuresync_hierarchy:}}`
3. Save and view the embedded visualization

### 3. Add Form Preview (Auto or Manual)
**Automatic**: If category has `Has parent category` property, preview is auto-injected when forms are regenerated.

**Manual**:
1. Edit Form:Category
2. Add: `{{#structuresync_load_form_preview:}}`
3. Add: `<div id="ss-form-hierarchy-preview"></div>`
4. See [Form Preview Setup](form-preview-setup.md) for details

### 4. Export Hierarchy Data
```bash
curl "http://wiki/api.php?action=structuresync-hierarchy&category=NAME&format=json" > hierarchy.json
```

## See Also

- [Full Hierarchy Documentation](hierarchy-visualization.md)
- [Main README](../README.md)
- [Implementation Notes](../IMPLEMENTATION.md)

## Subobjects at a Glance

1. **Define Subobject schema**
   ```wiki
   Subobject:PublicationAuthor
   [[Has description::Repeatable author entry]]
   [[Has required property::Property:Has author]]
   [[Has required property::Property:Has author order]]
   [[Has optional property::Property:Is co-first author]]
   [[Has optional property::Property:Is corresponding author]]
   ```
2. **Reference from Category**
   ```wiki
   Category:Publication
   [[Has required subgroup::Subobject:PublicationAuthor]]
   ```
3. **Use generated template on pages**
   ```wiki
   {{Publication_PublicationAuthor
    |author=Dr. Jane Doe
    |author_order=1
    |is_corresponding_author=true
   }}
   ```
4. **Display output**
   - Generated `Template:Publication/display` will automatically list subgroup tables via `{{#StructureSyncRenderAllProperties:}}`.

